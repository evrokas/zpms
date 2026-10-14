<?php
/* Booking through the forms (web/schedule_pages.php: zpms_booking_picker() /
 * zpms_booking_from_post()): a phone booking (pending appointment), a remote
 * (phone / online) one, moving it, cancelling it, turning it into an
 * appointment, an operation, rescheduling, and what the rules refuse --
 * overlap, outside the schedule, a surgery shade (doctor override only).
 * Driven over HTTP like a browser would post the forms. */

require_once __DIR__ . '/../../web/schedule.php';
require_once __DIR__ . '/../../web/zpms_mailer.php';

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
        $live = array_values(array_filter($slots, fn($x) => $x['released_at'] === null));
        assert_equal(1, count($live), 'one live slot');
        assert_equal(2, count($slots), 'the old time is kept as history');
        $slots = $live;
        $old = zpms_bk_db()->query("SELECT * FROM slot_reservations WHERE ref_guid = " . zpms_bk_db()->quote($p['guid']) . " AND released_at IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
        assert_equal(['10:00', 'moved', TestFixtures::USERNAME], [$old['start_time'], $old['release_reason'], $old['released_by']], 'released as moved, by the user who moved it');
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
        $kept = zpms_bk_db()->query("SELECT * FROM slot_reservations WHERE ref_guid = " . zpms_bk_db()->quote($two['guid']))->fetch(PDO::FETCH_ASSOC);
        assert_equal(['cancelled', TestFixtures::USERNAME], [$kept['release_reason'], $kept['released_by']], 'kept as history, with who and why');
        $rows = zpms_bk_db()->query("SELECT * FROM audit_log WHERE id > $audit AND event_type = 'slot_released'")->fetchAll(PDO::FETCH_ASSOC);
        assert_equal(1, count($rows), 'and audits it');
    });

    $runner->add('rescheduling a pending appointment emails the assigned doctor; editing something else does not', function () use ($doctor, $mon) {
        $doctorId = (string)zpms_bk_db()->query("SELECT id FROM users WHERE uname = '" . TestFixtures::USERNAME . "'")->fetchColumn();
        // No SMTP is configured in the tests, so an attempted send shows up as the "could not be sent" warning.
        $warning = 'Δεν ήταν δυνατή η αποστολή email';
        zpms_bk_pending(zpms_bk_fields('bk_p', $mon, '11:00', '11:15', ['patient-name' => 'Mail Move', 'appointment-user' => $doctorId]), $doctor);
        $doctor->get('/consultation/pending');   // the booking's own flash messages
        $p = zpms_bk_pending_row('Mail Move');
        assert_true($p !== null);
        $page = $doctor->get('/consultation/pending/' . $p['id'] . '/edit');
        $token = TestHttpClient::extractCsrfToken($page['body']);
        $base = ['csrf_token' => $token, 'patient-name' => 'Mail Move', 'patient-telephone' => '2100000000', 'appointment-user' => $doctorId];

        // Only the note changes: same slot, no email.
        $doctor->post('/consultation/pending/' . $p['id'] . '/edit', $base + zpms_bk_fields('bk_p', $mon, '11:00', '11:15', ['appointment-notes' => 'μόνο σημείωση']));
        assert_not_contains($warning, $doctor->get('/consultation/pending')['body'], 'a note change sends nothing');

        // A new time: the doctor is written to again (and here that fails, which is told).
        $doctor->post('/consultation/pending/' . $p['id'] . '/edit', $base + zpms_bk_fields('bk_p', $mon, '11:15', '11:30', ['appointment-notes' => 'μόνο σημείωση']));
        assert_contains($warning, $doctor->get('/consultation/pending')['body'], 'a new time sends an email to the assigned doctor');

        // A new time with nobody assigned: nobody to write to.
        $doctor->post('/consultation/pending/' . $p['id'] . '/edit', ['appointment-user' => ''] + $base + zpms_bk_fields('bk_p', $mon, '11:30', '11:45'));
        assert_not_contains($warning, $doctor->get('/consultation/pending')['body'], 'no assigned doctor, no email');
    });

    $runner->add('the reschedule email: what counts as a change, what it says, and that it is sent through the mailer', function () {
        assert_false(zpms_pending_time_changed('2026-12-14 10:00:00', '2026-12-14 10:00:30'), 'seconds are not a change');
        assert_true(zpms_pending_time_changed('2026-12-14 10:00:00', '2026-12-14 10:15:00'));
        assert_true(zpms_pending_time_changed('2026-12-14 10:00:00', '2026-12-15 10:00:00'));
        assert_false(zpms_pending_time_changed(null, '2026-12-14 10:00:00'));

        Renderer::init([ZPMS_TEST_APPDIR . '/web/templates/email/'], false, sys_get_temp_dir(), false);
        $pending = new pendingAppointmentsClass(['patient_name' => 'Μαρία Τεστ', 'patient_phone' => '2101112223', 'appointment_datetime' => '2026-12-15 11:30:00',
            'location' => 'Μάνδρα', 'notes' => 'σημείωση ΤΕΣΤ', 'remote_platform' => 'zoom', 'remote_contact' => 'https://z.example/9', 'remote_code' => '77']);
        $doctor = ['id' => 3, 'name' => 'Ιατρός Τεστ', 'uname' => 'doc', 'email' => 'doc@example.invalid'];
        $vars = zpms_reschedule_email_vars($pending, $doctor, '2026-12-14 10:00:00');
        assert_equal(['15/12/2026', '11:30', '14/12/2026', '10:00'], [$vars['new_date'], $vars['new_time'], $vars['old_date'], $vars['old_time']]);
        assert_contains('Zoom: https://z.example/9 (77)', $vars['remote']);

        $mail = new class {
            public array $to = [];
            public string $Subject = '', $Body = '', $AltBody = '';
            public bool $sent = false;
            function addAddress($a, $n = '') { $this->to[] = $a; }
            function isHTML($v) {}
            function send() { $this->sent = true; return true; }
        };
        assert_true(zpms_send_appointment_rescheduled_email($pending, $doctor, '2026-12-14 10:00:00', $mail));
        assert_true($mail->sent);
        assert_equal(['doc@example.invalid'], $mail->to);
        assert_contains('Μαρία Τεστ', $mail->Subject);
        foreach (['Ιατρός Τεστ', '15/12/2026', '11:30', '14/12/2026', 'Μάνδρα', 'σημείωση ΤΕΣΤ', 'https://z.example/9'] as $needle) {
            assert_contains($needle, $mail->Body, "the email mentions $needle");
        }
        $none = new class { function addAddress() {} function isHTML() {} function send() { throw new Exception('x'); } public string $Subject = '', $Body = '', $AltBody = ''; };
        assert_false(zpms_send_appointment_rescheduled_email($pending, $doctor, '2026-12-14 10:00:00', $none), 'a failing mailer is reported, not thrown');
        assert_false(zpms_send_appointment_rescheduled_email($pending, ['id' => 4, 'name' => 'x', 'uname' => 'x', 'email' => ''], '2026-12-14 10:00:00', $mail), 'a doctor without an address is skipped');
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

    $runner->add('appointments on a patient record are free: no booking block, any date, time and place, no slot', function () use ($doctor, $mon) {
        $pid = (int)zpms_bk_db()->query("SELECT id FROM patients WHERE pname = 'Remote Ok' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $page = $doctor->get('/appointment/' . $pid . '/newoperation');
        assert_equal(200, $page['status']);
        assert_contains('name="appointment-date"', $page['body'], 'the plain date and time field');
        assert_not_contains('data-booking-picker', $page['body'], 'no schedule calendar here');
        $token = TestHttpClient::extractCsrfToken($page['body']);
        // Monday 10:30 at bk_p is taken by a booking, 03:00 is outside any schedule: both are accepted.
        foreach (['10:30', '03:00'] as $time) {
            $res = $doctor->post('/appointment/' . $pid . '/newappointment', ['csrf_token' => $token, 'appointment-type' => 'operation', 'appointment-notes' => "Ελεύθερο $time",
                'appointment-date' => "$mon $time", 'appointment-place' => 'bk_p']);
            assert_equal(302, $res['status']);
            $ap = zpms_bk_db()->query("SELECT * FROM appointments WHERE anote = " . zpms_bk_db()->quote("Ελεύθερο $time"))->fetch(PDO::FETCH_ASSOC);
            assert_true($ap !== false, "an operation at $time is saved");
            assert_equal("$mon $time:00", $ap['adate']);
            assert_equal('Περιστέρι', $ap['aplace']);
            assert_true(zpms_bk_slot_for($ap['guid']) === null, 'and holds no slot');
        }
        // The patient page shows editable date and place fields on every card, and no Reschedule link.
        $patient = $doctor->get('/patient/' . $pid . '/edit');
        assert_contains('name="appointment-date-1"', $patient['body']);
        assert_not_contains('appointment-reschedule', $patient['body']);
        assert_not_contains('appointment-slot', $patient['body'], 'no read-only slot display');
    });

    $runner->add('editing a patient appointment that still holds a slot: a note keeps it, a changed date or place gives it up, delete releases it', function () use ($doctor, $mon) {
        $db = zpms_bk_db();
        $pid = (int)$db->query("SELECT id FROM patients WHERE pname = 'Remote Ok' ORDER BY id DESC LIMIT 1")->fetchColumn();
        $pguid = (string)$db->query("SELECT guid FROM patients WHERE id = $pid")->fetchColumn();
        $newAppointment = function (string $time) use ($db, $pguid, $mon) {
            $guid = guid();
            $db->prepare('INSERT INTO appointments (guid, cdate, cuser, pguid, adate, aplace, anote, atype, duration_minutes) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$guid, getDBtime(), 'test', $pguid, "$mon $time:00", 'Τηλεφωνικά', 'αρχική', 'appointment', 30]);
            $id = (int)$db->lastInsertId();
            $m = zpms_sched_time_to_min($time);
            assert_true(zpms_sched_reserve($db, 'phone', $mon, $m, $m + 30, 'appointment', $guid, 'αρχική', 'test')['ok'], 'setup slot');
            return [$id, $guid];
        };
        $token = TestHttpClient::extractCsrfToken($doctor->get('/patient/' . $pid . '/edit')['body']);

        [$id, $guid] = $newAppointment('15:00');
        // The card posts the date only (autosave): same day, the booked time must stay, and the slot too.
        $doctor->post("/appointment/$id/edit", ['csrf_token' => $token, 'use_ajax' => '1', "appointment-date-1" => $mon, 'appointment-place' => 'phone', 'appointment-notes' => 'νέα σημείωση']);
        $row = $db->query("SELECT * FROM appointments WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        assert_equal("$mon 15:00:00", $row['adate'], 'the time survives a note edit');
        $slot = zpms_bk_slot_for($guid);
        assert_true($slot !== null, 'the slot is kept');
        assert_equal('νέα σημείωση', $slot['note'], 'and shows the new note');

        // A different date: allowed with no check, and the slot is given up (kept as history, reason edited).
        $other = date('Y-m-d', strtotime($mon . ' +3 days'));
        $doctor->post("/appointment/$id/edit", ['csrf_token' => $token, 'use_ajax' => '1', "appointment-date-1" => $other, 'appointment-place' => 'phone', 'appointment-notes' => 'νέα σημείωση']);
        $row = $db->query("SELECT * FROM appointments WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        assert_equal("$other 00:00:00", $row['adate'], 'the new date is accepted as typed');
        assert_true(zpms_bk_slot_for($guid) === null, 'no longer on the schedule');
        $kept = $db->query("SELECT * FROM slot_reservations WHERE ref_guid = " . $db->quote($guid))->fetch(PDO::FETCH_ASSOC);
        assert_equal(['edited', TestFixtures::USERNAME], [$kept['release_reason'], $kept['released_by']]);

        // A changed place gives it up too.
        [$id2, $guid2] = $newAppointment('15:30');
        $doctor->post("/appointment/$id2/edit", ['csrf_token' => $token, 'use_ajax' => '1', "appointment-date-1" => $mon, 'appointment-place' => 'bk_p', 'appointment-notes' => 'αρχική']);
        assert_true(zpms_bk_slot_for($guid2) === null, 'a changed place gives up the slot');

        // Deleting frees it as "deleted".
        [$id3, $guid3] = $newAppointment('15:00');
        $doctor->post("/appointment/$id3/delete", ['csrf_token' => $token]);
        assert_true(zpms_bk_slot_for($guid3) === null);
        assert_equal('deleted', $db->query("SELECT release_reason FROM slot_reservations WHERE ref_guid = " . $db->quote($guid3) . " AND released_at IS NOT NULL ORDER BY id DESC LIMIT 1")->fetchColumn());
    });

    $runner->add('the cancelled-bookings list: names, reasons, moved only on request, filters, secretary access, calendar overlay', function () use ($doctor, $secretary, $mon) {
        $page = $doctor->get('/schedule/cancelled');
        assert_equal(200, $page['status']);
        assert_contains('Booking Two', $page['body'], 'the cancelled pending appointment is listed with the name');
        assert_contains('Remote Ok', $page['body'], 'the deleted operation (patient Remote Ok) is listed');
        assert_not_contains('Booking One', $page['body'], 'a booking that was only moved is not a cancellation');
        $withMoved = $doctor->get('/schedule/cancelled?moved=1');
        assert_contains('Booking One', $withMoved['body'], 'moved bookings on request');
        $edited = '<td>Άλλαξε στον φάκελο ασθενούς</td>';   // a table cell: the reason also appears as a filter option
        assert_not_contains($edited, $page['body'], 'a slot given up by editing the appointment is not a cancellation');
        assert_contains($edited, $withMoved['body'], 'but is listed on request');
        $byReason = $doctor->get('/schedule/cancelled?reason=deleted');
        assert_contains('Remote Ok', $byReason['body']);
        assert_not_contains('Booking Two', $byReason['body'], 'filtered by reason');
        $byLoc = $doctor->get('/schedule/cancelled?loc=bk_m');
        assert_not_contains('Booking Two', $byLoc['body'], 'filtered by location');
        assert_equal(200, $secretary->get('/schedule/cancelled')['status'], 'the secretary can read it');

        $week = zpms_sched_page_model_bk($doctor->get('/schedule?loc=bk_p&date=' . $mon . '&cancelled=1')['body']);
        assert_true(count((array)$week['cancelled']) >= 1, 'the calendar can show where bookings were cancelled');
        $plain = zpms_sched_page_model_bk($doctor->get('/schedule?loc=bk_p&date=' . $mon)['body']);
        assert_equal(0, count((array)$plain['cancelled']), 'and does not by default');
    });

    $runner->add('a released slot blocks nothing', function () use ($doctor, $mon) {
        // Booking Two's 10:00-10:30 was cancelled above, so the time is free again.
        zpms_bk_pending(zpms_bk_fields('bk_p', $mon, '10:00', '10:30', ['patient-name' => 'Booking Three']), $doctor);
        assert_true(zpms_bk_pending_row('Booking Three') !== null, 'a cancelled time can be booked again');
    });

    $runner->add('purging an appointment with zpmscli takes its slot with it', function () use ($doctor, $mon) {
        require_once __DIR__ . '/../../bin/lib/zpms_cli.php';
        $ap = zpms_bk_db()->query("SELECT * FROM appointments WHERE remote_contact = 'https://zoom.example/j/123' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        assert_true(zpms_bk_slot_for($ap['guid']) !== null);
        zpmscli_purge_appointments(zpms_bk_db(), [$ap], sys_get_temp_dir(), true);
        assert_true(zpms_bk_slot_for($ap['guid']) === null, 'the slot is gone');
        $left = (int)zpms_bk_db()->query("SELECT COUNT(*) FROM slot_reservations WHERE ref_guid = " . zpms_bk_db()->quote($ap['guid']))->fetchColumn();
        assert_equal(0, $left, 'released rows (history, with the note text) go too: erasure leaves nothing behind');
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
