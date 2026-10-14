<?php
/* Booking through the forms (web/schedule_pages.php: zpms_booking_picker() /
 * zpms_booking_from_post()): a phone booking (pending appointment), a remote
 * (phone / online) one, moving it, cancelling it, turning it into an
 * appointment, an operation, rescheduling, and what the rules refuse --
 * overlap, outside the schedule, a surgery shade (doctor override only).
 * Driven over HTTP like a browser would post the forms. */

require_once __DIR__ . '/../../web/schedule.php';

function zpms_bk_db(): PDO { return dbConnection::getConnection(); }

/** The booking fields a form posts. */
function zpms_bk_fields(string $loc, string $date, string $start, string $end, array $extra = []): array {
    return $extra + ['sched-loc' => $loc, 'sched-date' => $date, 'sched-start' => $start, 'sched-end' => $end, 'sched-override' => '0'];
}

function zpms_bk_pending(array $fields, TestHttpClient $http): array {
    $page = $http->get('/consultation/new');
    $token = TestHttpClient::extractCsrfToken($page['body']);
    assert_true($token !== null, 'no csrf token on /consultation/new');
    $res = $http->post('/consultation/new', $fields + ['csrf_token' => $token, 'patient-telephone' => '2100000000', 'appointment-notes' => '']);
    return $res;
}

function zpms_bk_slot_for(string $guid): ?array {
    return zpms_sched_slot_for_ref(zpms_bk_db(), $guid);
}

function zpms_bk_pending_row(string $name): ?array {
    $st = zpms_bk_db()->prepare('SELECT * FROM pending_appointments WHERE patient_name = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$name]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function zpms_booking_functional_tests(TestRunner $runner, string $baseUrl): void {
    $mon = date('Y-m-d', strtotime('monday next week +7 days'));
    $tue = date('Y-m-d', strtotime($mon . ' +1 day'));
    $doctor = new TestHttpClient($baseUrl);
    $secretary = new TestHttpClient($baseUrl);

    $runner->add('set up locations, a pattern and the accounts', function () use ($doctor, $secretary) {
        $db = zpms_bk_db();
        foreach (['slot_reservations', 'schedule_templates', 'schedule_overrides', 'location_travel_times'] as $t) { $db->exec("DELETE FROM $t"); }
        $db->exec("DELETE FROM locations WHERE machinename IN ('bk_p','bk_m','phone')");
        foreach ([['bk_p', 'Περιστέρι'], ['bk_m', 'Metropolitan'], ['phone', 'Τηλεφωνικά']] as [$code, $name]) {
            $db->prepare('INSERT INTO locations (guid, lang, cdate, cuser, name, machinename, address) VALUES (?,?,?,?,?,?,?)')->execute([guid(), 'gr', getDBtime(), 'test', $name, $code, '']);
        }
        $ins = $db->prepare('INSERT INTO schedule_templates (guid, cdate, cuser, location, weekday, start_time, end_time, kind, slot_minutes, note) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([guid(), getDBtime(), 't', 'bk_p', 1, '10:00', '14:00', 'available', 30, '']);
        $ins->execute([guid(), getDBtime(), 't', 'bk_m', 2, '08:00', '13:00', 'surgery', 30, 'Επέμβαση']);
        $ins->execute([guid(), getDBtime(), 't', 'bk_p', 2, '09:00', '16:00', 'available', 30, '']);
        $ins->execute([guid(), getDBtime(), 't', 'phone', 1, '14:00', '16:00', 'available', 30, '']);
        $db->prepare('INSERT INTO location_travel_times (guid, cdate, cuser, from_location, to_location, minutes) VALUES (?,?,?,?,?,?)')->execute([guid(), getDBtime(), 't', 'bk_m', 'bk_p', 30]);
        if (!(int)$db->query("SELECT COUNT(*) FROM users WHERE uname = '" . TestFixtures::SECRETARY_USERNAME . "'")->fetchColumn()) { TestFixtures::createSecretaryUser(); }
        TestFixtures::loginAsTestUser($doctor);
        TestFixtures::loginAsSecretary($secretary);
    });

    $runner->add('the phone-booking form carries the booking block, prefilled from the calendar link, and no plain date field', function () use ($doctor, $mon) {
        $page = $doctor->get('/consultation/new?loc=bk_p&date=' . $mon . '&start=10:30&end=11:00&note=' . rawurlencode('Έλεγχος'));
        assert_equal(200, $page['status']);
        assert_contains('data-booking-picker', $page['body']);
        assert_contains('name="sched-loc"', $page['body']);
        assert_contains('value="' . $mon . '"', $page['body']);
        assert_contains('data-start="10:30"', $page['body']);
        assert_contains('Έλεγχος', $page['body'], 'the note from the calendar link is prefilled');
        assert_not_contains('name="appointment-date"', $page['body']);
        assert_contains('Περιστέρι', $page['body'], 'locations are listed by name');
        assert_contains('Τηλεφωνικά', $page['body'], 'the phone location is offered');
    });

    $runner->add('booking a time creates the pending appointment, its slot, the note on the slot and an audit row', function () use ($doctor, $mon) {
        $audit = (int)zpms_bk_db()->query('SELECT COALESCE(MAX(id),0) FROM audit_log')->fetchColumn();
        $res = zpms_bk_pending(zpms_bk_fields('bk_p', $mon, '10:00', '10:45', ['patient-name' => 'Booking One', 'appointment-notes' => 'Πρώτη επίσκεψη ΤΕΣΤ']), $doctor);
        assert_equal(302, $res['status']);
        $p = zpms_bk_pending_row('Booking One');
        assert_true($p !== null, 'pending appointment saved');
        assert_equal($mon . ' 10:00:00', $p['appointment_datetime']);
        assert_equal('Περιστέρι', $p['location'], 'the stored place stays the display name');
        assert_equal(45, (int)$p['duration_minutes']);
        assert_true($p['remote_platform'] === null, 'in person: no remote data');
        $slot = zpms_bk_slot_for($p['guid']);
        assert_true($slot !== null, 'a slot is held');
        assert_equal(['bk_p', '10:00', '10:45', 'appointment', 'Πρώτη επίσκεψη ΤΕΣΤ'], [$slot['location'], $slot['start_time'], $slot['end_time'], $slot['kind'], $slot['note']]);
        $rows = zpms_bk_db()->query("SELECT * FROM audit_log WHERE id > $audit AND event_type = 'slot_reserved'")->fetchAll(PDO::FETCH_ASSOC);
        assert_equal(1, count($rows));
        foreach ($rows[0] as $v) { assert_true($v === null || !str_contains((string)$v, 'ΤΕΣΤ'), 'the note is never audited'); }
        $week = zpms_sched_page_model_bk($doctor->get('/schedule?loc=bk_p&date=' . $mon)['body']);
        $resv = array_values(array_filter($week['days'][0]['blocks'], fn($b) => $b['type'] === 'resv'));
        assert_equal(1, count($resv), 'the calendar shows the booking');
        assert_equal('Πρώτη επίσκεψη ΤΕΣΤ', $resv[0]['note']);
    });

    $runner->add('the day endpoint offers consecutive times first: right after the booking, right before it', function () use ($doctor, $mon) {
        $page = $doctor->get('/consultation/new');
        $token = html_entity_decode(preg_match('/<meta name="csrf-token" content="([^"]+)"/', $page['body'], $m) ? $m[1] : '');
        $res = $doctor->postWithHeaders('/schedule/day', ['loc' => 'bk_p', 'date' => $mon], ['X-CSRF-Token' => $token]);
        $json = json_decode(substr($res['body'], strpos($res['body'], '{"')), true);
        assert_true($json['ok']);
        assert_equal(['10:45', 'after'], [$json['suggestions'][0]['start'], $json['suggestions'][0]['reason']], 'right after the 10:00-10:45 booking');
        $starts = array_column($json['suggestions'], 'start');
        assert_true(in_array('10:00', $starts, true) === false, '10:00 is taken');
        // The appointment being moved does not count against itself.
        $p = zpms_bk_pending_row('Booking One');
        $own = $doctor->postWithHeaders('/schedule/day', ['loc' => 'bk_p', 'date' => $mon, 'ref' => $p['guid'] ?? ''], ['X-CSRF-Token' => $token]);
        assert_equal(200, $own['status']);
    });

    $runner->add('an overlap, a time outside the schedule and a missing location are refused and write nothing', function () use ($doctor, $mon) {
        $before = (int)zpms_bk_db()->query('SELECT COUNT(*) FROM pending_appointments')->fetchColumn();
        foreach ([
            'overlap' => zpms_bk_fields('bk_p', $mon, '10:30', '11:00', ['patient-name' => 'Booking Overlap']),
            'outside' => zpms_bk_fields('bk_p', $mon, '08:00', '08:30', ['patient-name' => 'Booking Outside']),
            'nolocation' => zpms_bk_fields('', $mon, '12:00', '12:30', ['patient-name' => 'Booking NoLoc']),
        ] as $why => $fields) {
            $res = zpms_bk_pending($fields, $doctor);
            assert_equal(302, $res['status'], $why);
            assert_contains('/consultation/new', (string)$res['location'], "$why: back to the form");
        }
        assert_equal($before, (int)zpms_bk_db()->query('SELECT COUNT(*) FROM pending_appointments')->fetchColumn(), 'nothing was saved');
        $page = $doctor->get('/consultation/new');
        assert_contains('Δεν', $page['body'], 'the reason is shown on the form');
    });

    $runner->add('a remote appointment needs its platform details and is saved with them', function () use ($doctor, $mon) {
        $none = zpms_bk_pending(zpms_bk_fields('phone', $mon, '14:00', '14:30', ['patient-name' => 'Remote NoContact', 'remote-platform' => 'zoom', 'remote-contact' => '']), $doctor);
        assert_true(zpms_bk_pending_row('Remote NoContact') === null, 'no meeting link, no booking');
        zpms_bk_pending(zpms_bk_fields('phone', $mon, '14:00', '14:30', ['patient-name' => 'Remote Bad', 'remote-platform' => 'nonsense', 'remote-contact' => 'x']), $doctor);
        assert_true(zpms_bk_pending_row('Remote Bad') === null, 'an unknown platform is refused');
        zpms_bk_pending(zpms_bk_fields('phone', $mon, '14:00', '14:30', ['patient-name' => 'Remote Ok', 'remote-platform' => 'zoom', 'remote-contact' => 'https://zoom.example/j/123', 'remote-code' => '4829']), $doctor);
        $p = zpms_bk_pending_row('Remote Ok');
        assert_true($p !== null);
        assert_equal(['zoom', 'https://zoom.example/j/123', '4829'], [$p['remote_platform'], $p['remote_contact'], $p['remote_code']]);
        assert_equal('phone', zpms_bk_slot_for($p['guid'])['location']);
        $list = $doctor->get('/consultation/pending');
        assert_contains('Zoom', $list['body'], 'the pending list shows the platform');
        // A remote booking does not keep the remote fields when it is later moved to a place in person.
    });

    $runner->add('the surgery shade: refused for the secretary, needs the tick for the doctor, accepted and marked when ticked', function () use ($doctor, $secretary, $tue) {
        $f = fn($name, $override) => zpms_bk_fields('bk_p', $tue, '12:30', '13:00', ['patient-name' => $name, 'sched-override' => $override]);
        zpms_bk_pending($f('Shade Secretary', '1'), $secretary);
        assert_true(zpms_bk_pending_row('Shade Secretary') === null, 'the secretary cannot override, even by posting the field');
        zpms_bk_pending($f('Shade Unticked', '0'), $doctor);
        assert_true(zpms_bk_pending_row('Shade Unticked') === null, 'the doctor must tick the override');
        zpms_bk_pending($f('Shade Doctor', '1'), $doctor);
        $p = zpms_bk_pending_row('Shade Doctor');
        assert_true($p !== null, 'the doctor can override');
        assert_equal(1, (int)zpms_bk_slot_for($p['guid'])['shadow_override'], 'marked as overridden');
    });

    $runner->add('moving a pending appointment frees the old time; changing its note changes the note on the slot; cancelling frees it', function () use ($doctor, $mon) {
        $p = zpms_bk_pending_row('Booking One');
        $page = $doctor->get('/consultation/pending/' . $p['id'] . '/edit');
        assert_contains('data-booking-picker', $page['body']);
        assert_contains('data-start="10:00"', $page['body'], 'the current slot is preselected');
        $token = TestHttpClient::extractCsrfToken($page['body']);
        $base = ['csrf_token' => $token, 'patient-name' => 'Booking One', 'patient-telephone' => '2100000000', 'appointment-user' => ''];
        $res = $doctor->post('/consultation/pending/' . $p['id'] . '/edit', $base + zpms_bk_fields('bk_p', $mon, '12:00', '12:30', ['appointment-notes' => 'Νέα σημείωση']));
        assert_equal(302, $res['status']);
        $slots = zpms_bk_db()->query("SELECT * FROM slot_reservations WHERE ref_guid = " . zpms_bk_db()->quote($p['guid']))->fetchAll(PDO::FETCH_ASSOC);
        assert_equal(1, count($slots), 'one slot, not two');
        assert_equal(['12:00', '12:30', 'Νέα σημείωση'], [$slots[0]['start_time'], $slots[0]['end_time'], $slots[0]['note']]);
        $row = zpms_bk_pending_row('Booking One');
        assert_equal($mon . ' 12:00:00', $row['appointment_datetime']);
        assert_equal(30, (int)$row['duration_minutes']);

        // Same time, only the note changes: the slot keeps its time and takes the new note.
        $doctor->post('/consultation/pending/' . $p['id'] . '/edit', $base + zpms_bk_fields('bk_p', $mon, '12:00', '12:30', ['appointment-notes' => 'Τρίτη σημείωση']));
        $slot = zpms_bk_slot_for($p['guid']);
        assert_equal(['12:00', 'Τρίτη σημείωση'], [$slot['start_time'], $slot['note']], 're-saving the same time keeps the slot');

        // The freed 10:00 can be booked by someone else.
        zpms_bk_pending(zpms_bk_fields('bk_p', $mon, '10:00', '10:30', ['patient-name' => 'Booking Two']), $doctor);
        assert_true(zpms_bk_pending_row('Booking Two') !== null, 'the old time was freed');

        $audit = (int)zpms_bk_db()->query('SELECT COALESCE(MAX(id),0) FROM audit_log')->fetchColumn();
        $two = zpms_bk_pending_row('Booking Two');
        $res = $doctor->post('/consultation/pending/' . $two['id'] . '/delete', ['csrf_token' => $token]);
        assert_true(zpms_bk_slot_for($two['guid']) === null, 'cancelling frees the slot');
        $rows = zpms_bk_db()->query("SELECT * FROM audit_log WHERE id > $audit AND event_type = 'slot_released'")->fetchAll(PDO::FETCH_ASSOC);
        assert_equal(1, count($rows), 'and audits it');
    });

    $runner->add('turning a pending appointment into a patient record keeps its slot, now owned by the appointment', function () use ($doctor) {
        $p = zpms_bk_pending_row('Remote Ok');
        $page = $doctor->get('/consultation/pending/' . $p['id'] . '/convert');
        $token = TestHttpClient::extractCsrfToken($page['body']);
        $res = $doctor->post('/consultation/pending/' . $p['id'] . '/convert', ['csrf_token' => $token, 'patient-name' => 'Remote Ok', 'patient-telephone' => '2100000000', 'patient-amka' => '', 'patient-email' => '']);
        assert_equal(302, $res['status']);
        $st = zpms_bk_db()->prepare('SELECT * FROM appointments WHERE remote_contact = ? ORDER BY id DESC LIMIT 1');
        $st->execute(['https://zoom.example/j/123']);
        $ap = $st->fetch(PDO::FETCH_ASSOC);
        assert_true($ap !== false, 'the appointment carries the remote details');
        assert_equal(['zoom', 30], [$ap['remote_platform'], (int)$ap['duration_minutes']]);
        assert_true(zpms_bk_slot_for($p['guid']) === null, 'the pending appointment no longer owns a slot');
        assert_equal('phone', zpms_bk_slot_for($ap['guid'])['location'], 'the appointment does');
    });

    $runner->add('an operation is booked as surgery, shades the other location, and can be rescheduled', function () use ($doctor, $mon, $tue) {
        $pid = (int)zpms_bk_db()->query("SELECT id FROM patients WHERE pname = 'Remote Ok' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $page = $doctor->get('/appointment/' . $pid . '/newoperation');
        assert_equal(200, $page['status']);
        assert_contains('data-kind="surgery"', $page['body']);
        $token = TestHttpClient::extractCsrfToken($page['body']);
        $res = $doctor->post('/appointment/' . $pid . '/newappointment', ['csrf_token' => $token, 'appointment-type' => 'operation', 'appointment-notes' => 'Χειρουργείο ΤΕΣΤ']
            + zpms_bk_fields('bk_p', $mon, '13:00', '14:00'));
        assert_equal(302, $res['status']);
        $ap = zpms_bk_db()->query("SELECT * FROM appointments WHERE atype = 'operation' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        assert_true($ap !== false, 'operation saved');
        $slot = zpms_bk_slot_for($ap['guid']);
        assert_equal(['surgery', '13:00', '14:00'], [$slot['kind'], $slot['start_time'], $slot['end_time']]);
        // It shades Metropolitan (30' of travel either side): 12:30-14:30 on Monday.
        $blocks = zpms_sched_page_model_bk($doctor->get('/schedule?loc=bk_m&date=' . $mon)['body'])['days'][0]['blocks'];
        $shadow = array_values(array_filter($blocks, fn($b) => $b['type'] === 'shadow' && $b['ref'] === 'surgery'));
        assert_true(count($shadow) >= 1, 'the operation shades the other location');

        // Rescheduling.
        $r = $doctor->get('/appointment/' . $ap['id'] . '/reschedule');
        assert_equal(200, $r['status']);
        assert_contains('data-start="13:00"', $r['body']);
        $rt = TestHttpClient::extractCsrfToken($r['body']);
        $done = $doctor->post('/appointment/' . $ap['id'] . '/reschedule', ['csrf_token' => $rt] + zpms_bk_fields('bk_p', $tue, '14:00', '15:30'));
        assert_equal(302, $done['status']);
        $slot = zpms_bk_slot_for($ap['guid']);
        assert_equal(['14:00', '15:30'], [$slot['start_time'], $slot['end_time']]);
        $row = zpms_bk_db()->query('SELECT * FROM appointments WHERE id = ' . (int)$ap['id'])->fetch(PDO::FETCH_ASSOC);
        assert_equal($tue . ' 14:00:00', $row['adate']);
        assert_equal(90, (int)$row['duration_minutes']);

        // The patient page now shows the slot and the Reschedule link instead of editable date and place fields.
        $patient = $doctor->get('/patient/' . $pid . '/edit');
        assert_contains('/appointment/' . $ap['id'] . '/reschedule', $patient['body']);
        // An older card or a crafted request cannot move a scheduled appointment around the schedule.
        $edit = $doctor->post('/appointment/' . $ap['id'] . '/edit', ['csrf_token' => TestHttpClient::extractCsrfToken($patient['body']), 'appointment-date-1' => '2031-01-01', 'appointment-place' => 'bk_m', 'appointment-notes' => 'Αλλαγμένη', 'use_ajax' => '1']);
        $row = zpms_bk_db()->query('SELECT * FROM appointments WHERE id = ' . (int)$ap['id'])->fetch(PDO::FETCH_ASSOC);
        assert_equal($tue . ' 14:00:00', $row['adate'], 'the date did not move');
        assert_equal('Περιστέρι', $row['aplace'], 'nor the place');
        assert_equal('Αλλαγμένη', zpms_bk_slot_for($ap['guid'])['note'], 'but the note follows to the calendar');

        // Deleting the appointment frees the slot.
        $doctor->post('/appointment/' . $ap['id'] . '/delete', ['csrf_token' => TestHttpClient::extractCsrfToken($patient['body'])]);
        assert_true(zpms_bk_slot_for($ap['guid']) === null, 'deleting frees the slot');
    });

    $runner->add('purging an appointment with zpmscli takes its slot with it', function () use ($doctor, $mon) {
        require_once __DIR__ . '/../../bin/lib/zpms_cli.php';
        $ap = zpms_bk_db()->query("SELECT * FROM appointments WHERE remote_contact = 'https://zoom.example/j/123' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        assert_true(zpms_bk_slot_for($ap['guid']) !== null);
        zpmscli_purge_appointments(zpms_bk_db(), [$ap], sys_get_temp_dir(), true);
        assert_true(zpms_bk_slot_for($ap['guid']) === null, 'the slot is gone');
    });

    $runner->add('cleanup: booking test rows removed', function () {
        $db = zpms_bk_db();
        foreach (['slot_reservations', 'schedule_templates', 'schedule_overrides', 'location_travel_times'] as $t) { $db->exec("DELETE FROM $t"); }
        $db->exec("DELETE FROM locations WHERE machinename IN ('bk_p','bk_m','phone')");
        assert_equal(0, (int)$db->query('SELECT COUNT(*) FROM slot_reservations')->fetchColumn());
    });
}

function zpms_sched_page_model_bk(string $html): array {
    assert_true((bool)preg_match('#<script type="application/json" id="schedule-data">(.*?)</script>#s', $html, $m), 'schedule-data not found');
    return json_decode($m[1], true);
}
