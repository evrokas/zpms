<?php
/**
 * What goes to Google Calendar from the schedule, and the bookkeeping around it.
 *
 * Synced (ZPMS -> Calendar): pending appointments (web/index.php, unchanged),
 * appointments and operations made on a patient record, and one-off surgery
 * and reservations set on a single date of the schedule. NOT synced: free
 * time (it would bury the real events), nor the weekly pattern.
 *
 * Calendar -> ZPMS (bin/sync_google_calendar.php): a move or cancellation made
 * in the Calendar app is applied to the appointment, and its slot on the
 * schedule follows (zpms_sched_follow_calendar()). Events of schedule entries
 * are push-only and are ignored on the way back.
 *
 * Every function that talks to Google takes an optional $api object (create /
 * update / delete / enabled) so tests can use a recording stand-in; the
 * default talks to googleCalendarClass. Nothing here ever throws or blocks the
 * save it follows: a Calendar problem is logged and the ZPMS data stays right.
 */

require_once __DIR__ . '/schedule.php';
require_once __DIR__ . '/schedule_pages.php';
require_once __DIR__ . '/zpms_mailer.php';

/** The default API: googleCalendarClass behind the small interface the functions below use. */
function zpms_gcal_api(): object {
    return new class {
        function enabled(): bool { return googleCalendarClass::isEnabled(); }
        function create(string $property, string $id, string $summary, string $description, string $start, int $minutes): ?string {
            return googleCalendarClass::createLinkedEvent($property, $id, $summary, $description, $start, $minutes);
        }
        function update(string $eventId, string $summary, string $description, string $start, int $minutes): bool {
            return googleCalendarClass::updateLinkedEvent($eventId, $summary, $description, $start, $minutes);
        }
        function delete(string $eventId): bool { return googleCalendarClass::deleteEvent($eventId); }
    };
}

/**
 * Title and text of an appointment's event. $a: name, phone, amka, email,
 * place, note, operation (bool), remote (platform label, contact, code).
 * Same fields as a pending appointment's event, plus the type in the title and
 * how to reach a remote patient.
 */
function zpms_appointment_event_texts(array $a): array {
    $texts = googleCalendarClass::buildEventFields((string)$a['name'], (string)($a['phone'] ?? ''), (string)($a['amka'] ?? ''),
        (string)($a['email'] ?? ''), (string)($a['place'] ?? ''), (string)($a['note'] ?? ''));
    if (!empty($a['operation'])) {
        $texts['summary'] = 'Χειρουργείο: ' . $a['name'];
    }
    if (!empty($a['remote']['platform'])) {
        $remote = $a['remote']['platform'] . ': ' . $a['remote']['contact'] . (!empty($a['remote']['code']) ? ' (' . $a['remote']['code'] . ')' : '');
        $texts['description'] .= "\n" . $remote;
    }
    return $texts;
}

/**
 * Creates or updates the Calendar event of an appointment or operation. An
 * appointment that came from a pending one already has its event (and keeps
 * that event's link); a new one gets its own, linked by appointments.id.
 * Past appointments that were never synced are left out.
 */
function zpms_appointment_sync_to_calendar(appointmentsClass $ap, ?object $api = null): void {
    $api = $api ?? zpms_gcal_api();
    try {
        if (!$api->enabled() || $ap->getdeleted() !== null) {
            return;
        }
        $pat = patientsClassEx::sgetByGuid($ap->getpguid());
        if (!$pat || !$ap->getadate()) {
            return;
        }
        $texts = zpms_appointment_event_texts([
            'name' => $pat->getpname(), 'phone' => $pat->getptel(), 'amka' => $pat->getpamka(), 'email' => $pat->getpemail(),
            'place' => $ap->getaplace(), 'note' => $ap->getanote(), 'operation' => $ap->getatype() === 'operation',
            'remote' => ['platform' => zpms_remote_label($ap->getremote_platform()), 'contact' => $ap->getremote_contact(), 'code' => $ap->getremote_code()],
        ]);
        $minutes = (int)$ap->getduration_minutes() ?: ZPMS_CONSULTATION_DEFAULT_DURATION_MINUTES;

        if ($ap->getgoogle_event_id() !== null) {
            $api->update($ap->getgoogle_event_id(), $texts['summary'], $texts['description'], $ap->getadate(), $minutes);
        } elseif (strtotime($ap->getadate()) >= strtotime('today')) {
            $eventId = $api->create(GOOGLE_CALENDAR_APPOINTMENT_PROPERTY, (string)$ap->getid(), $texts['summary'], $texts['description'], $ap->getadate(), $minutes);
            if ($eventId === null) {
                return;
            }
            $ap->setgoogle_event_id($eventId);
        } else {
            return;
        }
        $ap->setgoogle_synced_at(getDBtime());
        $ap->update();
    } catch (Throwable $e) {
        error_log('zpms_appointment_sync_to_calendar failed: ' . $e->getMessage());
    }
}

/** Removes the Calendar event of an appointment that is being deleted. */
function zpms_appointment_calendar_delete(appointmentsClass $ap, ?object $api = null): void {
    $api = $api ?? zpms_gcal_api();
    try {
        if ($api->enabled() && $ap->getgoogle_event_id() !== null) {
            $api->delete($ap->getgoogle_event_id());
        }
    } catch (Throwable $e) {
        error_log('zpms_appointment_calendar_delete failed: ' . $e->getMessage());
    }
}

/** The Google event ids that single-date surgery/reservation rows of a date currently carry. */
function zpms_gcal_override_event_ids(PDO $db, string $date): array {
    $st = $db->prepare('SELECT DISTINCT google_event_id FROM schedule_overrides WHERE odate = ? AND google_event_id IS NOT NULL');
    $st->execute([$date]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Brings Calendar in line with the single-date surgery and reservation rows
 * of $date after a change: every such row without an event gets one (rows of
 * the same time, kind and reason -- a reservation at every location -- share a
 * single event), and an event that no row carries any more is deleted.
 * 'available' rows never get an event. $before = zpms_gcal_override_event_ids()
 * taken before the change.
 *
 * @return array ['created' => int, 'deleted' => int]
 */
function zpms_gcal_reconcile_overrides(PDO $db, string $date, array $before, ?object $api = null): array {
    $api = $api ?? zpms_gcal_api();
    $out = ['created' => 0, 'deleted' => 0];
    try {
        if (!$api->enabled()) {
            return $out;
        }
        $names = function_exists('zpms_sched_location_names') ? zpms_sched_location_names($db) : [];
        $allCount = count($names) ?: count(zpms_sched_locations($db));

        $st = $db->prepare("SELECT * FROM schedule_overrides WHERE odate = ? AND kind IN ('surgery','hold') AND google_event_id IS NULL ORDER BY location");
        $st->execute([$date]);
        $groups = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groups[$row['kind'] . '|' . $row['start_time'] . '|' . $row['end_time'] . '|' . (string)$row['note']][] = $row;
        }
        $set = $db->prepare('UPDATE schedule_overrides SET google_event_id = ? WHERE guid = ?');
        foreach ($groups as $rows) {
            $first = $rows[0];
            $where = count($rows) >= $allCount && $allCount > 1 ? 'Όλες οι τοποθεσίες' : implode(', ', array_map(fn($r) => $names[$r['location']] ?? $r['location'], $rows));
            $title = ($first['kind'] === 'surgery' ? 'Χειρουργείο' : 'Δέσμευση') . ((string)$first['note'] !== '' ? ': ' . $first['note'] : '');
            $minutes = zpms_sched_row_end($first) - zpms_sched_row_start($first);
            $eventId = $api->create(GOOGLE_CALENDAR_SCHEDULE_PROPERTY, $first['guid'], $title, 'Τοποθεσία: ' . $where, $date . ' ' . $first['start_time'] . ':00', $minutes);
            if ($eventId === null) {
                continue;
            }
            foreach ($rows as $r) {
                $set->execute([$eventId, $r['guid']]);
            }
            $out['created']++;
        }

        $still = array_flip(zpms_gcal_override_event_ids($db, $date));
        $used = $db->prepare('SELECT COUNT(*) FROM schedule_overrides WHERE google_event_id = ?');
        foreach (array_unique($before) as $eventId) {
            if (isset($still[$eventId])) {
                continue;
            }
            $used->execute([$eventId]);
            if ((int)$used->fetchColumn() === 0) {
                $api->delete($eventId);
                $out['deleted']++;
            }
        }
    } catch (Throwable $e) {
        error_log('zpms_gcal_reconcile_overrides failed: ' . $e->getMessage());
    }
    return $out;
}


/**
 * A pending appointment whose event was moved in the Google Calendar app
 * (called by bin/sync_google_calendar.php): takes the new date/time, makes its
 * slot on the schedule follow (or frees it when the new time is not allowed),
 * and tells the assigned doctor, as when the move is made in ZPMS. Does not
 * save: the caller updates the row. $mail is for tests.
 *
 * @return array ['changed' => bool, 'slot' => string (see zpms_sched_follow_calendar), 'notified' => ?bool]
 *   'notified' is null when there is nobody to tell (no active doctor assigned).
 */
function zpms_calendar_pending_moved(pendingAppointmentsClass $pending, string $newDatetime, PDO $db, $mail = null): array {
    $old = (string)$pending->getappointment_datetime();
    if (!zpms_pending_time_changed($old, $newDatetime)) {
        return ['changed' => false, 'slot' => 'unchanged', 'notified' => null];
    }
    $pending->setappointment_datetime($newDatetime);
    $slot = zpms_sched_follow_calendar($db, $pending->getguid(), $newDatetime);
    $doctor = zpms_assigned_doctor_for($pending);
    $notified = $doctor ? zpms_send_appointment_rescheduled_email($pending, $doctor, $old, $mail, 'Google Calendar') : null;
    return ['changed' => true, 'slot' => $slot, 'notified' => $notified];
}
