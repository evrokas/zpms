<?php
/* What is sent to Google Calendar from the schedule (web/schedule_calendar.php,
 * the pull side in zpms_sched_follow_calendar()). Google itself is replaced by
 * a recording stand-in: the point is WHAT would be sent -- appointments,
 * operations, one-off surgery and reservations, never free time -- and that the
 * schedule follows a move made in Calendar. */

require_once __DIR__ . '/../../web/schedule.php';
require_once __DIR__ . '/../../web/google_calendar_client.php';
require_once __DIR__ . '/../../web/schedule_calendar.php';

class ZpmsFakeCalendar {
    public array $calls = [];
    public bool $on = true;
    private static int $n = 0;
    function enabled(): bool { return $this->on; }
    function create(string $property, string $id, string $summary, string $description, string $start, int $minutes): ?string {
        $this->calls[] = ['create', $property, $id, $summary, $description, $start, $minutes];
        return 'ev' . (++self::$n);
    }
    function update(string $eventId, string $summary, string $description, string $start, int $minutes): bool {
        $this->calls[] = ['update', $eventId, $summary, $description, $start, $minutes];
        return true;
    }
    function delete(string $eventId): bool { $this->calls[] = ['delete', $eventId]; return true; }
    function kinds(): array { return array_column($this->calls, 0); }
}

function zpms_cs_db(): PDO { return dbConnection::getConnection(); }

function zpms_cs_appointment(string $date, string $type = 'appointment', ?int $duration = 45, array $remote = []): appointmentsClass {
    $db = zpms_cs_db();
    $pguid = guid();
    $db->prepare('INSERT INTO patients (guid, cdate, cuser, pname, ptel, pamka) VALUES (?,?,?,?,?,?)')->execute([$pguid, getDBtime(), 'test', 'Ημερολόγιο Ασθενής', '2101234567', '01010100000']);
    $db->prepare('INSERT INTO appointments (guid, cdate, cuser, pguid, adate, aplace, anote, atype, duration_minutes, remote_platform, remote_contact, remote_code) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([guid(), getDBtime(), 'test', $pguid, $date, 'Περιστέρι', 'σημείωση', $type, $duration, $remote['platform'] ?? null, $remote['contact'] ?? null, $remote['code'] ?? null]);
    return appointmentsClass::sgetById((int)$db->lastInsertId());
}

function zpms_calendar_sync_functional_tests(TestRunner $runner): void {
    $future = date('Y-m-d', strtotime('monday next week +7 days'));
    $m = fn(string $t) => zpms_sched_time_to_min($t);

    $runner->add('event body: time, length, the property that links it, and nothing else linked', function () use ($future) {
        $e = googleCalendarClass::buildLinkedEvent(GOOGLE_CALENDAR_APPOINTMENT_PROPERTY, '17', 'Τίτλος', 'Περιγραφή', "$future 10:00:00", 45);
        assert_equal('Τίτλος', $e['summary']);
        assert_equal(['zpms_appointment_id' => '17'], $e['extendedProperties']['private']);
        $start = new DateTime($e['start']['dateTime']);
        $end = new DateTime($e['end']['dateTime']);
        assert_equal(45 * 60, $end->getTimestamp() - $start->getTimestamp(), 'the event is as long as the booking');
        assert_equal('10:00', $start->format('H:i'));
        assert_equal('zpms_schedule_override_id', GOOGLE_CALENDAR_SCHEDULE_PROPERTY);
    });

    $runner->add('appointment texts: patient, place, note, type in the title, how to reach a remote patient', function () {
        $t = zpms_appointment_event_texts(['name' => 'Μαρία', 'phone' => '210', 'place' => 'Μάνδρα', 'note' => 'ΣΗΜ', 'operation' => true,
            'remote' => ['platform' => 'Zoom', 'contact' => 'https://z.example/1', 'code' => '99']]);
        assert_equal('Χειρουργείο: Μαρία', $t['summary']);
        assert_contains('Μάνδρα', $t['description']);
        assert_contains('ΣΗΜ', $t['description']);
        assert_contains('Zoom: https://z.example/1 (99)', $t['description']);
        $plain = zpms_appointment_event_texts(['name' => 'Μαρία', 'operation' => false, 'remote' => []]);
        assert_equal('Μαρία', $plain['summary']);
        assert_not_contains('Zoom', $plain['description']);
    });

    $runner->add('an appointment is created in Calendar once, then updated; the link is stored', function () use ($future) {
        $api = new ZpmsFakeCalendar();
        $ap = zpms_cs_appointment("$future 10:00:00", 'appointment', 45, ['platform' => 'zoom', 'contact' => 'https://z.example/2']);
        zpms_appointment_sync_to_calendar($ap, $api);
        assert_equal(['create'], $api->kinds());
        $c = $api->calls[0];
        assert_equal([GOOGLE_CALENDAR_APPOINTMENT_PROPERTY, (string)$ap->getid(), 'Ημερολόγιο Ασθενής', 45], [$c[1], $c[2], $c[3], $c[6]]);
        assert_contains('Zoom: https://z.example/2', $c[4]);
        $ap = appointmentsClass::sgetById((int)$ap->getid());
        assert_true(str_starts_with((string)$ap->getgoogle_event_id(), 'ev'), 'the event id is stored');
        $eventId = $ap->getgoogle_event_id();
        assert_true($ap->getgoogle_synced_at() !== null);

        $ap->setadate("$future 11:00:00");
        $ap->update();
        zpms_appointment_sync_to_calendar(appointmentsClass::sgetById((int)$ap->getid()), $api);
        assert_equal(['create', 'update'], $api->kinds(), 'the second sync updates the same event');
        assert_equal($eventId, $api->calls[1][1]);
    });

    $runner->add('an operation is titled as one; a past never-synced appointment, a deleted one and a disabled calendar send nothing', function () use ($future) {
        $api = new ZpmsFakeCalendar();
        zpms_appointment_sync_to_calendar(zpms_cs_appointment("$future 09:00:00", 'operation'), $api);
        assert_equal('Χειρουργείο: Ημερολόγιο Ασθενής', $api->calls[0][3]);

        $api = new ZpmsFakeCalendar();
        zpms_appointment_sync_to_calendar(zpms_cs_appointment('2020-01-06 10:00:00'), $api);
        assert_equal([], $api->calls, 'a past appointment that was never synced stays out');

        $deleted = zpms_cs_appointment("$future 10:00:00");
        $deleted->setdeleted(getDBtime());
        $deleted->update();
        zpms_appointment_sync_to_calendar(appointmentsClass::sgetById((int)$deleted->getid()), $api);
        assert_equal([], $api->calls, 'a deleted appointment');

        $off = new ZpmsFakeCalendar();
        $off->on = false;
        zpms_appointment_sync_to_calendar(zpms_cs_appointment("$future 10:00:00"), $off);
        assert_equal([], $off->calls, 'calendar not configured');
    });

    $runner->add('deleting an appointment removes its event', function () use ($future) {
        $api = new ZpmsFakeCalendar();
        $ap = zpms_cs_appointment("$future 10:00:00");
        zpms_appointment_sync_to_calendar($ap, $api);
        zpms_appointment_calendar_delete(appointmentsClass::sgetById((int)$ap->getid()), $api);
        assert_equal(['create', 'delete'], $api->kinds());
        $none = new ZpmsFakeCalendar();
        zpms_appointment_calendar_delete(zpms_cs_appointment("$future 10:00:00"), $none);
        assert_equal([], $none->calls, 'nothing to delete when it was never synced');
    });

    $runner->add('surgery and reservations of a date go to Calendar, free time never; one event for a reservation at every location', function () use ($future) {
        $db = zpms_cs_db();
        $db->exec('DELETE FROM schedule_overrides');
        $db->exec("DELETE FROM locations WHERE machinename IN ('cs_a','cs_b')");
        foreach (['cs_a', 'cs_b'] as $code) {
            $db->prepare('INSERT INTO locations (guid, lang, cdate, cuser, name, machinename, address) VALUES (?,?,?,?,?,?,?)')->execute([guid(), 'gr', getDBtime(), 't', strtoupper($code), $code, '']);
        }
        $api = new ZpmsFakeCalendar();
        $apply = function (string $loc, string $s, string $e, ?string $kind, string $note) use ($db, $future) {
            zpms_sched_override_apply($db, $loc, $future, zpms_sched_time_to_min($s), zpms_sched_time_to_min($e), $kind, $note, 'test');
        };
        $reconcile = function () use ($db, $future, $api, &$before) {
            $r = zpms_gcal_reconcile_overrides($db, $future, $before, $api);
            $before = zpms_gcal_override_event_ids($db, $future);
            return $r;
        };
        $before = [];

        $apply('cs_a', '09:00', '12:00', 'available', '');
        assert_equal(['created' => 0, 'deleted' => 0], $reconcile(), 'opened time is not sent');

        $apply('cs_a', '10:00', '12:00', 'surgery', 'Επέμβαση Α');
        assert_equal(['created' => 1, 'deleted' => 0], $reconcile());
        $c = $api->calls[0];
        assert_equal(['Χειρουργείο: Επέμβαση Α', 120, GOOGLE_CALENDAR_SCHEDULE_PROPERTY], [$c[3], $c[6], $c[1]]);
        assert_contains('CS_A', $c[4]);

        foreach (['cs_a', 'cs_b', 'phone'] as $loc) { $apply($loc, '14:00', '15:00', 'hold', 'Σύσκεψη'); }
        assert_equal(['created' => 1, 'deleted' => 0], $reconcile(), 'one event for a reservation at every location');
        $hold = end($api->calls);
        assert_equal('Δέσμευση: Σύσκεψη', $hold[3]);
        assert_contains('Όλες', $hold[4]);
        $ids = $db->query("SELECT DISTINCT google_event_id FROM schedule_overrides WHERE kind = 'hold'")->fetchAll(PDO::FETCH_COLUMN);
        assert_equal(1, count($ids), 'all rows carry the same event');

        // Cutting the reservation at one location keeps the shared event while the other rows still use it.
        $apply('cs_a', '14:00', '15:00', null, '');
        assert_equal(['created' => 0, 'deleted' => 0], $reconcile());

        // Cutting the surgery in the middle: the old event goes, the two remainders get their own.
        $apply('cs_a', '10:45', '11:15', null, '');
        $r = $reconcile();
        assert_equal(['created' => 2, 'deleted' => 1], $r);

        // Clearing everything removes every event.
        $apply('cs_a', '07:00', '21:00', null, '');
        $apply('cs_b', '07:00', '21:00', null, '');
        $apply('phone', '07:00', '21:00', null, '');
        $r = $reconcile();
        assert_equal(0, $r['created']);
        assert_equal([], zpms_gcal_override_event_ids($db, $future), 'no row keeps an event');
        $deletes = count(array_filter($api->kinds(), fn($k) => $k === 'delete'));
        $creates = count(array_filter($api->kinds(), fn($k) => $k === 'create'));
        assert_equal($creates, $deletes, 'every event that was created was deleted again');

        $db->exec('DELETE FROM schedule_overrides');
        $db->exec("DELETE FROM locations WHERE machinename IN ('cs_a','cs_b')");
    });

    $runner->add('a move made in Calendar: the slot follows, or is freed when the new time is not allowed', function () use ($future, $m) {
        $db = zpms_cs_db();
        foreach (['slot_reservations', 'schedule_templates', 'schedule_overrides'] as $t) { $db->exec("DELETE FROM $t"); }
        $ins = $db->prepare('INSERT INTO schedule_templates (guid, cdate, cuser, location, weekday, start_time, end_time, kind, slot_minutes, note) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([guid(), getDBtime(), 't', 'cs_p', zpms_sched_weekday($future), '10:00', '14:00', 'available', 30, '']);

        $ref = guid();
        assert_equal('none', zpms_sched_follow_calendar($db, $ref, "$future 11:00:00"), 'it holds no slot');
        $r = zpms_sched_reserve($db, 'cs_p', $future, $m('10:00'), $m('10:45'), 'appointment', $ref, 'ΣΗΜ', 'test');
        assert_true($r['ok'], 'setup: ' . json_encode($r['problems']));
        assert_equal('unchanged', zpms_sched_follow_calendar($db, $ref, "$future 10:00:00"));
        assert_equal('moved', zpms_sched_follow_calendar($db, $ref, "$future 12:15:00"));
        $slot = zpms_sched_slot_for_ref($db, $ref);
        assert_equal(['12:15', '13:00', 'ΣΗΜ'], [$slot['start_time'], $slot['end_time'], $slot['note']], 'same length, same note');

        // Another booking takes 10:00; moving onto it is not allowed -> the slot is freed.
        $other = guid();
        assert_true(zpms_sched_reserve($db, 'cs_p', $future, $m('10:00'), $m('10:30'), 'appointment', $other, '', 'test')['ok']);
        assert_equal('released', zpms_sched_follow_calendar($db, $ref, "$future 10:15:00"));
        assert_true(zpms_sched_slot_for_ref($db, $ref) === null, 'no phantom booking is left behind');
        assert_true(zpms_sched_slot_for_ref($db, $other) !== null, 'the other booking is untouched');

        $db->exec('DELETE FROM slot_reservations');
        $db->exec('DELETE FROM schedule_templates');
    });
}
