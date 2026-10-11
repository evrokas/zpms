<?php
/* The GDPR audit log (web/audit.php): that the real pages record who did
 * what, that no row ever carries a patient's name or other free text, that
 * the plain-text copy (logs/audit.log) gets one line per event, and that
 * only holders of audit-log-view can read /audit. Driven through the real
 * HTTP endpoints, like the other functional suites. */

const ZPMS_AUDIT_TEST_NAME = 'Ελεγκτική Καταγραφή Ασθενής';

/** Audit rows newer than $afterId, optionally of one event type. */
function zpms_audit_test_rows(int $afterId, ?string $event = null): array {
    $db = dbConnection::getConnection();
    $sql = 'SELECT * FROM audit_log WHERE id > ' . $afterId . ($event ? ' AND event_type = ' . $db->quote($event) : '') . ' ORDER BY id';
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** The is_superuser test account, created once whichever suite runs first. */
function zpms_audit_test_admin(): void {
    $exists = (int)dbConnection::getConnection()->query("SELECT COUNT(*) FROM users WHERE uname = '" . TestFixtures::ADMIN_USERNAME . "'")->fetchColumn();
    if (!$exists) TestFixtures::createAdministratorUser();
}

function zpms_audit_test_last_id(): int {
    return (int)dbConnection::getConnection()->query('SELECT COALESCE(MAX(id), 0) FROM audit_log')->fetchColumn();
}

/** Fails if any column of any row contains $needle (a name, a note, a file name). */
function zpms_audit_test_assert_absent(array $rows, string $needle, string $what): void {
    foreach ($rows as $row) {
        foreach ($row as $column => $value) {
            assert_true($value === null || !str_contains((string)$value, $needle), "audit_log.$column contains the $what");
        }
    }
}

function zpms_functional_audit_log(TestRunner $runner, string $baseUrl): void {
    $runner->add('sign-in, failed sign-in and sign-out are logged; the typed name of a failed attempt cannot forge log lines', function () use ($baseUrl) {
        $start = zpms_audit_test_last_id();
        $http = new TestHttpClient($baseUrl);

        $login = $http->get('/login');
        $token = TestHttpClient::extractCsrfToken($login['body']);
        $http->post('/login', ['csrf_token' => $token, 'username' => "no_such_user\nevent=forged", 'password' => 'wrong']);

        TestFixtures::loginAsTestUser($http);
        $http->get('/logout');

        $failed = zpms_audit_test_rows($start, 'login_failed');
        assert_equal(1, count($failed), 'expected exactly one login_failed row');
        assert_equal('anonymous', $failed[0]['actor_type'], 'a failed sign-in should have no signed-in actor');
        assert_equal('-', $failed[0]['cuser'], 'a failed sign-in should be recorded with actor "-"');
        assert_true(!str_contains((string)$failed[0]['target_user'], "\n"), 'a newline survived into target_user');

        $ok = zpms_audit_test_rows($start, 'login_success');
        assert_equal(1, count($ok), 'expected one login_success row');
        assert_equal(TestFixtures::USERNAME, $ok[0]['cuser'], 'sign-in recorded for the wrong user');
        assert_contains('method=password', (string)$ok[0]['detail'], 'sign-in method not recorded');
        assert_equal(1, count(zpms_audit_test_rows($start, 'logout')), 'expected one logout row');

        $mirror = (string)@file_get_contents(ZPMS_TEST_APPDIR . '/logs/audit.log');
        assert_contains('event=login_failed', $mirror, 'logs/audit.log has no login_failed line');
        assert_true(!preg_match('/^event=forged/m', $mirror), 'a crafted username forged a line in logs/audit.log');
    });

    $runner->add('patient create / view / change / delete are logged by id, never by name; a save that changes nothing is not', function () use ($baseUrl) {
        TestSchema::assertSafeToMutate();
        $start = zpms_audit_test_last_id();
        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);

        $form = $http->get('/patient/new');
        $fields = [
            'csrf_token' => TestHttpClient::extractCsrfToken($form['body']),
            'submit' => '1',
            'patient-name' => ZPMS_AUDIT_TEST_NAME,
            'patient-dob' => '1980-02-03',
            'patient-amka' => '03028012345',
            'patient-telephone' => '2100000001',
            'patient-address' => 'Οδός Καταγραφής 7',
            'patient-email' => 'audit.patient@example.invalid',
            'patient-note' => 'σημείωση που δεν πρέπει να καταγραφεί',
        ];
        $http->post('/patient/new', $fields);
        $id = (int)dbConnection::getConnection()->query("SELECT id FROM patients WHERE pamka = '03028012345'")->fetchColumn();
        assert_true($id > 0, 'fixture patient was not created');

        $page = $http->get("/patient/$id/edit");
        $fields['csrf_token'] = TestHttpClient::extractCsrfToken($page['body']);
        $fields['patient-telephone'] = '2100000002';
        $http->post("/patient/$id/edit", $fields);
        // The same values again (what autosave sends between keystrokes): nothing changes.
        $http->post("/patient/$id/edit", $fields);
        $http->post("/patient/$id/delete", ['csrf_token' => $fields['csrf_token']]);

        $rows = zpms_audit_test_rows($start);
        $events = array_column(array_filter($rows, fn($r) => (int)$r['patient_id'] === $id), 'event_type');
        foreach (['patient_created', 'patient_viewed', 'patient_updated', 'patient_deleted'] as $e) {
            assert_true(in_array($e, $events, true), "no $e row for the patient");
        }
        $updates = array_values(array_filter($rows, fn($r) => $r['event_type'] === 'patient_updated' && (int)$r['patient_id'] === $id));
        assert_equal(1, count($updates), 'a save that changed nothing was still logged');
        assert_equal('fields=ptel', $updates[0]['detail'], 'the changed field was not recorded by name');

        zpms_audit_test_assert_absent($rows, ZPMS_AUDIT_TEST_NAME, "patient's name");
        zpms_audit_test_assert_absent($rows, 'σημείωση', "patient's note");
        zpms_audit_test_assert_absent($rows, '2100000002', "patient's telephone number");
        $mirror = (string)@file_get_contents(ZPMS_TEST_APPDIR . '/logs/audit.log');
        assert_true(!str_contains($mirror, ZPMS_AUDIT_TEST_NAME), "logs/audit.log contains the patient's name");
    });

    $runner->add('appointments and their files are logged; file names are not', function () use ($baseUrl) {
        TestSchema::assertSafeToMutate();
        $start = zpms_audit_test_last_id();
        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);

        $p = new patientsClass(['guid' => guid(), 'cuser' => 'test-fixture', 'cdate' => getDBtime(), 'pname' => ZPMS_AUDIT_TEST_NAME . ' Β',
            'pdob' => '1990-01-01 00:00:00', 'pamka' => '', 'ptel' => '', 'paddr' => '', 'pemail' => '', 'pnote' => '']);
        $p->insert();
        $pid = (int)$p->getid();

        $form = $http->get("/appointment/$pid/newappointment");
        $http->post("/appointment/$pid/newappointment", ['csrf_token' => TestHttpClient::extractCsrfToken($form['body']), 'submit' => '1',
            'appointment-date' => '2026-09-01T10:30', 'appointment-place' => 'x', 'appointment-notes' => 'audit note']);
        $apptId = (int)dbConnection::getConnection()->query("SELECT id FROM appointments WHERE pguid = '" . $p->getguid() . "'")->fetchColumn();
        assert_true($apptId > 0, 'appointment was not created');

        $page = $http->get("/patient/$pid/edit");
        $token = TestHttpClient::extractCsrfToken($page['body']);
        $http->post("/appointment/$apptId/edit", ['csrf_token' => $token, 'submit' => '1',
            "appointment-date-$apptId" => '2026-09-01', 'appointment-place' => 'x', 'appointment-notes' => 'changed note']);

        $tmp = sys_get_temp_dir() . '/' . ZPMS_AUDIT_TEST_NAME . '-scan.png';
        $img = imagecreatetruecolor(1, 1); imagepng($img, $tmp); imagedestroy($img);
        $up = json_decode($http->postMultipart("/appointment/$apptId/files", ['csrf_token' => $token], ['appointmentFile' => $tmp])['body'], true);
        @unlink($tmp);
        $fileId = (int)($up['file']['id'] ?? 0);
        assert_true($fileId > 0, 'upload failed');
        $http->get("/appointment/$apptId/files/$fileId/download");
        $http->post("/appointment/$apptId/files/$fileId/delete", ['csrf_token' => $token]);
        $http->post("/appointment/$apptId/delete", ['csrf_token' => $token]);

        $rows = array_values(array_filter(zpms_audit_test_rows($start), fn($r) => (int)$r['appointment_id'] === $apptId));
        $events = array_column($rows, 'event_type');
        foreach (['appointment_created', 'appointment_updated', 'file_uploaded', 'file_downloaded', 'file_deleted', 'appointment_deleted'] as $e) {
            assert_true(in_array($e, $events, true), "no $e row for the appointment");
        }
        foreach ($rows as $r) {
            assert_equal($pid, (int)$r['patient_id'], "{$r['event_type']} row does not point at the patient");
        }
        zpms_audit_test_assert_absent($rows, '-scan', 'file name');
        zpms_audit_test_assert_absent($rows, 'audit note', "appointment's note");
        zpms_audit_test_assert_absent($rows, 'changed note', "appointment's note");
    });

    $runner->add('/audit: refused without audit-log-view, readable by an administrator, filters work, shows no names', function () use ($baseUrl) {
        $doctor = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($doctor);
        $refused = $doctor->get('/audit');
        assert_contains('401', $refused['body'], 'a doctor (no audit-log-view) could open /audit');
        assert_not_contains('href="/audit"', $doctor->get('/patients')['body'], 'the menu offers /audit to someone who cannot open it');

        zpms_audit_test_admin();
        $admin = new TestHttpClient($baseUrl);
        TestFixtures::loginAsAdministrator($admin);
        $page = $admin->get('/audit');
        assert_equal(200, $page['status'], '/audit did not return 200 for an administrator');
        assert_contains('class="audit-table"', $page['body'], 'the audit table is missing');
        assert_contains('href="/audit"', $page['body'], 'the menu does not offer /audit to an administrator');
        assert_not_contains(ZPMS_AUDIT_TEST_NAME, $page['body'], 'the audit page shows a patient name');

        $filtered = $admin->get('/audit?event=login_failed');
        assert_contains('audit-login_failed', $filtered['body'], 'the event filter shows no matching rows');
        assert_not_contains('audit-patient_viewed', $filtered['body'], 'the event filter let other events through');
    });

    $runner->add('user management on /admin is logged with field names only', function () use ($baseUrl) {
        TestSchema::assertSafeToMutate();
        $start = zpms_audit_test_last_id();
        zpms_audit_test_admin();
        $admin = new TestHttpClient($baseUrl);
        TestFixtures::loginAsAdministrator($admin);

        $form = $admin->get('/admin/permissions/new');
        $admin->post('/admin/permissions/new', ['csrf_token' => TestHttpClient::extractCsrfToken($form['body']), 'name' => 'audit-test-perm', 'label' => 'Audit test']);
        $permId = (int)dbConnection::getConnection()->query("SELECT id FROM permissions WHERE name = 'audit-test-perm'")->fetchColumn();
        $edit = $admin->get("/admin/permissions/$permId/edit");
        $admin->post("/admin/permissions/$permId/edit", ['csrf_token' => TestHttpClient::extractCsrfToken($edit['body']), 'name' => 'audit-test-perm', 'label' => 'Audit test changed']);
        $admin->post("/admin/permissions/$permId/delete", ['csrf_token' => TestHttpClient::extractCsrfToken($edit['body'])]);

        $rows = zpms_audit_test_rows($start);
        $byEvent = [];
        foreach ($rows as $r) $byEvent[$r['event_type']][] = $r;
        assert_true(isset($byEvent['admin_created'], $byEvent['admin_updated'], $byEvent['admin_deleted']), 'an /admin change was not logged');
        assert_contains('fields=label', (string)$byEvent['admin_updated'][0]['detail'], 'the changed field name was not recorded');
        assert_not_contains('Audit test changed', (string)$byEvent['admin_updated'][0]['detail'], 'a field value was logged');
        assert_equal(TestFixtures::ADMIN_USERNAME, $byEvent['admin_created'][0]['cuser'], 'the change was recorded for the wrong user');
    });

    $runner->add('saving your own profile no longer switches your account to inactive', function () use ($baseUrl) {
        TestSchema::assertSafeToMutate();
        $start = zpms_audit_test_last_id();
        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);
        $profile = $http->get('/profile');
        $http->post('/profile', ['csrf_token' => TestHttpClient::extractCsrfToken($profile['body']), 'action' => 'update_account',
            'user_name' => 'ZPMS Test User Renamed', 'user_email' => 'zpms-test@example.invalid']);
        $row = dbConnection::getConnection()->query("SELECT active, name FROM users WHERE uname = '" . TestFixtures::USERNAME . "'")->fetch();
        assert_equal('ZPMS Test User Renamed', $row['name'], 'the profile save did not happen');
        assert_equal(1, (int)$row['active'], 'saving the profile switched the account to inactive');
        $logged = zpms_audit_test_rows($start, 'profile_updated');
        assert_equal(1, count($logged), 'profile change not logged');
        assert_equal('fields=name', $logged[0]['detail'], 'profile change should list only the changed field');
    });

    $runner->add('zpmscli purges are logged as command-line actions, one row per patient', function () {
        TestSchema::assertSafeToMutate();
        $start = zpms_audit_test_last_id();
        $p = new patientsClass(['guid' => guid(), 'cuser' => 'test-fixture', 'cdate' => getDBtime(), 'pname' => ZPMS_AUDIT_TEST_NAME . ' Γ',
            'pdob' => '1990-01-01 00:00:00', 'pamka' => '', 'ptel' => '', 'paddr' => '', 'pemail' => '', 'pnote' => '']);
        $p->insert();
        $env = 'ZPMS_DB_CONFIG=' . escapeshellarg(ZPMS_TEST_APPDIR . '/config/db.test.php');
        exec($env . ' php ' . escapeshellarg(ZPMS_TEST_APPDIR . '/bin/zpmscli.php') . ' patients:purge ' . (int)$p->getid() . ' --yes 2>&1', $out, $code);
        assert_equal(0, $code, 'zpmscli purge failed: ' . implode("\n", $out));

        $rows = zpms_audit_test_rows($start, 'patient_purged');
        assert_equal(1, count($rows), 'expected one patient_purged row');
        assert_equal('cli', $rows[0]['actor_type'], 'a command-line purge was not marked as such');
        assert_equal((int)$p->getid(), (int)$rows[0]['patient_id'], 'the purge row points at the wrong patient');
        zpms_audit_test_assert_absent($rows, ZPMS_AUDIT_TEST_NAME, "patient's name");
    });
}
