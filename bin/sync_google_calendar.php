#!/usr/bin/env php
<?php
/*
 * Pulls changes from the synced Google Calendar into ZPMS -- the
 * "Calendar -> ZPMS" half of the design in README.md, "Google Calendar
 * sync" (the "ZPMS -> Calendar" half is synchronous, in
 * web/index.php's consultation_new_post()/zpms_consultation_sync_to_
 * calendar(), not here).
 *
 * Run this every few minutes via cron (deploy/zpms-calendar-sync.cron
 * or the systemd timer beside it -- same "reference, install manually"
 * convention as this app's own backup timer). A few minutes' latency on
 * reflecting a drag-to-reschedule made directly in the Calendar app is
 * an accepted tradeoff for not needing a public-facing webhook endpoint
 * (Google's alternative, push notifications via events.watch(), needs
 * exactly that plus a channel-renewal job -- more moving parts this
 * single-practice deployment doesn't need).
 *
 * What it does, per changed event (googleCalendarClass::listChangedEvents(),
 * which itself follows Calendar API pagination and Google's documented
 * "410 Gone means resync from scratch" signal):
 *
 *   - status=cancelled + carries our zpms_appointment_id extendedProperty
 *     -> that appointment was created here and then cancelled directly in
 *        Calendar; soft-delete it, mirroring appointment_delete()'s own
 *        `deleted` timestamp convention.
 *   - status=cancelled, no extendedProperty, but it matches an
 *     unresolved calendar_pending_events row by google_event_id -> the
 *     event was pulled into the review queue on an earlier run and then
 *     cancelled before anyone acted on it; mark that row resolved with
 *     no appointment created (nothing to review any more).
 *   - active + carries our zpms_appointment_id -> just a reschedule of
 *     an appointment this app already knows about; update its adate to
 *     match Calendar's new time. Never touches patient_name/phone/AMKA/
 *     notes -- those live in ZPMS, Calendar has no opinion on them.
 *   - active, no extendedProperty -> a Calendar-native event (created
 *     directly in the Calendar app, or by hand during a call when ZPMS
 *     wasn't at hand). Upserted into calendar_pending_events by
 *     google_event_id so staff can complete it via the "Νέα από
 *     Ημερολόγιο" review queue (calendar_review_queue() in
 *     web/index.php) -- this script never guesses a patient link on its
 *     own, same "don't guess, surface it for a human" rule this app's
 *     sibling repos (apyweb's invoice-matching, in particular) already
 *     follow for exactly this kind of ambiguity.
 *
 * Idempotent per run: re-running immediately after a clean run finds
 * nothing new (the stored sync_token already covers everything seen),
 * and reprocessing the same event twice (e.g. a retried cron run) just
 * re-applies the same, already-current values.
 *
 * Targets config/db.php (the real database) by default, same as the live
 * app itself -- set ZPMS_DB_CONFIG to point at a different config file
 * (e.g. config/db.test.php) to sync against a test database instead.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

define('__APPDIR__', dirname(__DIR__));

$configPath = getenv('ZPMS_DB_CONFIG') ?: (__APPDIR__ . '/config/db.php');
if (!is_file($configPath)) {
    fwrite(STDERR, "Database config not found: $configPath\n");
    exit(1);
}
require_once $configPath;

if (!defined('__FWDIR__')) {
    define('__FWDIR__', __APPDIR__ . '/web/core');
}
if (!is_dir(__FWDIR__)) {
    fwrite(STDERR, __FWDIR__ . " not found -- is web/core vendored (symlinked) in this checkout?\n");
    exit(1);
}

// Same cwd/whitespace workarounds as bin/migrate_roles.php -- see that
// script's own comments for why both are needed.
chdir(__APPDIR__ . '/web');
ob_start();
require_once __FWDIR__ . '/bootstrap.php';
require_once __APPDIR__ . '/web/google_calendar_client.php';
ob_end_clean();

dbConnection::init(DB_HOST, DB_USER, DB_PASS, DB_NAME);

$log = function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
};

if (!googleCalendarClass::isEnabled()) {
    $log('Google Calendar sync is not configured (config/google_calendar.php missing/invalid) -- nothing to do.');
    exit(0);
}

$config = require __APPDIR__ . '/config/google_calendar.php';
$calendarId = $config['calendar_id'];
$pdo = dbConnection::getConnection();

$stateRows = calendarSyncStateClass::sgetAll('calendar_id = ' . $pdo->quote($calendarId), 1);
$state = $stateRows[0] ?? null;
if ($state === null) {
    $state = new calendarSyncStateClass(['calendar_id' => $calendarId, 'sync_token' => null]);
    $state->insert();
}

$syncToken = $state->getsync_token();
$events = googleCalendarClass::listChangedEvents($syncToken);

if ($events === null) {
    $log('Sync failed -- see the [google-calendar] error above. Sync token left untouched (or cleared, if it was rejected as expired) for the next run.');
    if ($syncToken !== $state->getsync_token()) {
        $state->setsync_token($syncToken);
        $state->update();
    }
    exit(1);
}

$countRescheduled = 0;
$countCancelled = 0;
$countQueued = 0;
$countDismissed = 0;

foreach ($events as $event) {
    $googleEventId = $event['id'] ?? null;
    if ($googleEventId === null) {
        continue;
    }

    $zpmsAppointmentId = $event['extendedProperties']['private'][GOOGLE_CALENDAR_APPOINTMENT_PROPERTY] ?? null;
    $isCancelled = ($event['status'] ?? '') === 'cancelled';

    if ($zpmsAppointmentId !== null) {
        $app = appointmentsClass::sgetById((int)$zpmsAppointmentId);
        if ($app === null || $app->getgoogle_event_id() !== $googleEventId) {
            // Stale/mismatched link (the appointment was since deleted
            // and its id reused, or the extendedProperty was tampered
            // with) -- never act on an id-based link that doesn't
            // actually point back at this event.
            continue;
        }

        if ($isCancelled) {
            if ($app->getdeleted() === null) {
                $app->setdeleted(getDBtime());
                $app->update();
                $countCancelled++;
            }
        } else {
            $newStart = $event['start']['dateTime'] ?? $event['start']['date'] ?? null;
            if ($newStart !== null) {
                $newAdate = (new DateTime($newStart))->format('Y-m-d H:i:s');
                if ($newAdate !== $app->getadate()) {
                    $app->setadate($newAdate);
                    $countRescheduled++;
                }
                $app->setgoogle_synced_at(getDBtime());
                $app->update();
            }
        }
        continue;
    }

    // No zpms_appointment_id -- a Calendar-native event.
    $pendingRows = calendarPendingEventsClass::sgetAll('google_event_id = ' . $pdo->quote($googleEventId), 1);
    $pending = $pendingRows[0] ?? null;

    if ($isCancelled) {
        if ($pending !== null && $pending->getresolved_at() === null) {
            $pending->setresolved_at(getDBtime());
            $pending->update();
            $countDismissed++;
        }
        continue;
    }

    $summary = $event['summary'] ?? '(χωρίς τίτλο)';
    $startRaw = $event['start']['dateTime'] ?? $event['start']['date'] ?? null;
    $startDatetime = $startRaw !== null ? (new DateTime($startRaw))->format('Y-m-d H:i:s') : null;
    $rawJson = json_encode($event, JSON_UNESCAPED_UNICODE);

    if ($pending === null) {
        $pending = new calendarPendingEventsClass([
            'guid' => guid(),
            'cdate' => getDBtime(),
            'google_event_id' => $googleEventId,
            'summary' => $summary,
            'start_datetime' => $startDatetime,
            'raw_json' => $rawJson,
        ]);
        $pending->insert();
        $countQueued++;
    } elseif ($pending->getresolved_at() === null) {
        // Already queued, not yet acted on -- refresh in case the event
        // was edited again (a different time, a renamed title) before
        // anyone got to it.
        $pending->setsummary($summary);
        $pending->setstart_datetime($startDatetime);
        $pending->setraw_json($rawJson);
        $pending->update();
    }
}

$state->setsync_token($syncToken);
$state->setlast_synced_at(getDBtime());
$state->update();

$log(sprintf(
    'Synced %d event(s): %d rescheduled, %d cancelled, %d queued for review, %d dismissed.',
    count($events),
    $countRescheduled,
    $countCancelled,
    $countQueued,
    $countDismissed
));
