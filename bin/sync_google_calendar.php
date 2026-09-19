#!/usr/bin/env php
<?php
/*
 * Pulls changes from the synced Google Calendar into ZPMS -- the
 * "Calendar -> ZPMS" half of the design in README.md, "Google Calendar
 * sync" (the "ZPMS -> Calendar" half is synchronous, in
 * web/index.php's consultation_new_post()/pending_appointment_edit_post()/
 * zpms_pending_appointment_sync_to_calendar(), not here).
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
 * Every booking is a pending_appointments row first, regardless of
 * origin (see that yaml's own docblock) -- a real patient/appointment
 * only exists once pending_appointment_convert_post() creates one. So
 * this script's extendedProperty (GOOGLE_CALENDAR_PENDING_APPOINTMENT_
 * PROPERTY) always names a pending_appointments.id, never an
 * appointments.id, even after conversion -- see that constant's own
 * docblock for why it's never rewritten. What it does, per changed event
 * (googleCalendarClass::listChangedEvents(), which itself follows
 * Calendar API pagination and Google's documented "410 Gone means resync
 * from scratch" signal):
 *
 *   - carries our extendedProperty, and that pending_appointments row is
 *     already converted -> the booking became a real appointment since
 *     this event was created; reschedule/cancel the linked *appointments*
 *     row instead (mirroring appointment_delete()'s own `deleted`
 *     timestamp convention on cancel). Never touches patient_name/phone/
 *     AMKA/notes on either table -- those live in ZPMS, Calendar has no
 *     opinion on them.
 *   - carries our extendedProperty, still pending (not converted, not
 *     cancelled) -> reschedule/cancel that pending_appointments row
 *     directly.
 *   - carries our extendedProperty, but it doesn't resolve to any row at
 *     all (deleted from ZPMS some other way) -> skip; nothing local left
 *     to update.
 *   - no extendedProperty -> a Calendar-native event (created directly in
 *     the Calendar app, or by hand during a call when ZPMS wasn't at
 *     hand). Upserted into pending_appointments by google_event_id (only
 *     patient_name, from the event summary, and appointment_datetime are
 *     known -- phone/AMKA/email/location are left blank for staff to
 *     fill in) so it shows up in the same "Εκκρεμή Ραντεβού" list as
 *     everything booked through /consultation/new -- this script never
 *     guesses a patient link on its own, same "don't guess, surface it
 *     for a human" rule this app's sibling repos (apyweb's invoice-
 *     matching, in particular) already follow for exactly this kind of
 *     ambiguity.
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

    $pendingId = $event['extendedProperties']['private'][GOOGLE_CALENDAR_PENDING_APPOINTMENT_PROPERTY] ?? null;
    $isCancelled = ($event['status'] ?? '') === 'cancelled';
    $newStart = $event['start']['dateTime'] ?? $event['start']['date'] ?? null;
    $newDatetime = $newStart !== null ? (new DateTime($newStart))->format('Y-m-d H:i:s') : null;

    if ($pendingId !== null) {
        $pending = pendingAppointmentsClass::sgetById((int)$pendingId);
        if ($pending === null || $pending->getgoogle_event_id() !== $googleEventId) {
            // Stale/mismatched link (the row was since deleted and its id
            // reused, or the extendedProperty was tampered with) -- never
            // act on an id-based link that doesn't actually point back at
            // this event.
            continue;
        }

        if ($pending->getconverted_at() !== null) {
            // Already became a real appointment -- that's the row Calendar's
            // change actually applies to now.
            $app = appointmentsClass::sgetById((int)$pending->getconverted_appointment_id());
            if ($app === null || $app->getgoogle_event_id() !== $googleEventId) {
                continue;
            }
            if ($isCancelled) {
                if ($app->getdeleted() === null) {
                    $app->setdeleted(getDBtime());
                    $app->update();
                    $countCancelled++;
                }
            } elseif ($newDatetime !== null) {
                if ($newDatetime !== $app->getadate()) {
                    $app->setadate($newDatetime);
                    $countRescheduled++;
                }
                $app->setgoogle_synced_at(getDBtime());
                $app->update();
            }
            continue;
        }

        if ($pending->getcancelled_at() !== null) {
            // Already terminal on the ZPMS side -- nothing left to apply.
            continue;
        }

        if ($isCancelled) {
            $pending->setcancelled_at(getDBtime());
            $pending->update();
            $countCancelled++;
        } elseif ($newDatetime !== null) {
            if ($newDatetime !== $pending->getappointment_datetime()) {
                $pending->setappointment_datetime($newDatetime);
                $countRescheduled++;
            }
            $pending->setgoogle_synced_at(getDBtime());
            $pending->update();
        }
        continue;
    }

    // No extendedProperty -- a Calendar-native event, matched (if seen
    // before) by google_event_id instead, since it carries no ZPMS id at
    // all until this script itself assigns one by creating the row below.
    $pendingRows = pendingAppointmentsClass::sgetAll('google_event_id = ' . $pdo->quote($googleEventId), 1);
    $pending = $pendingRows[0] ?? null;

    if ($isCancelled) {
        if ($pending !== null && $pending->getconverted_at() === null && $pending->getcancelled_at() === null) {
            $pending->setcancelled_at(getDBtime());
            $pending->update();
            $countDismissed++;
        }
        continue;
    }

    $summary = $event['summary'] ?? '(χωρίς τίτλο)';

    if ($pending === null) {
        $pending = new pendingAppointmentsClass([
            'guid' => guid(),
            'cuser' => 'google-calendar-sync',
            'cdate' => getDBtime(),
            'patient_name' => $summary,
            'appointment_datetime' => $newDatetime,
            'google_event_id' => $googleEventId,
            'google_synced_at' => getDBtime(),
        ]);
        $pending->insert();
        $countQueued++;
    } elseif ($pending->getconverted_at() === null && $pending->getcancelled_at() === null) {
        // Already queued, not yet acted on -- refresh in case the event
        // was edited again (a different time, a renamed title) before
        // anyone got to it. Never overwrites patient_phone/amka/email --
        // those are ZPMS-only fields Calendar has no way to supply, and a
        // human may have already started filling them in on the pending
        // row's own edit page.
        $pending->setpatient_name($summary);
        $pending->setappointment_datetime($newDatetime);
        $pending->setgoogle_synced_at(getDBtime());
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
