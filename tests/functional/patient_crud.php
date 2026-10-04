<?php
/* End-to-end, HTTP-level regression coverage for creating, editing, and
 * (soft-)deleting a patient record -- driven exactly the way a browser
 * would (real cookies, real CSRF tokens scraped out of the rendered
 * form), against web/index.php's real route handlers and the real
 * patientsClass entity. */

function zpms_functional_patient_crud(TestRunner $runner, TestHttpClient $http): void {
    $runner->add('create a new patient', function () use ($http) {
        TestSchema::assertSafeToMutate();

        $form = $http->get('/patient/new');
        assert_equal(200, $form['status'], 'GET /patient/new did not return 200');
        $token = TestHttpClient::extractCsrfToken($form['body']);
        assert_not_null($token, 'no csrf_token field on the new-patient form');

        $res = $http->post('/patient/new', [
            'csrf_token' => $token,
            'submit' => '1',
            'patient-name' => 'Δοκιμαστική Ασθενής',
            'patient-dob' => '1990-01-01',
            'patient-amka' => '11111111111',
            'patient-telephone' => '2101234567',
            'patient-address' => 'Οδός Δοκιμής 1',
            'patient-email' => 'test.patient@example.invalid',
            'patient-note' => 'created by the regression suite',
        ]);
        assert_equal(302, $res['status'], "new-patient POST did not redirect (got {$res['status']})");
        assert_equal('/patients', (string)$res['location'], 'new-patient POST did not redirect to /patients');

        $row = dbConnection::getConnection()
            ->query("SELECT id, pname, pamka, deleted FROM patients WHERE pamka = '11111111111'")
            ->fetch();
        assert_not_null($row, 'no patient row was inserted');
        assert_equal('Δοκιμαστική Ασθενής', $row['pname'], 'inserted patient has the wrong name');
        assert_null($row['deleted'], 'a freshly created patient should not be marked deleted');

        $GLOBALS['zpms_test_patient_id'] = (int)$row['id'];
    });

    $runner->add('edit an existing patient', function () use ($http) {
        TestSchema::assertSafeToMutate();
        $id = $GLOBALS['zpms_test_patient_id'] ?? null;
        assert_not_null($id, 'previous test did not record a patient id');

        $form = $http->get("/patient/$id/edit");
        assert_equal(200, $form['status'], "GET /patient/$id/edit did not return 200");
        $token = TestHttpClient::extractCsrfToken($form['body']);
        assert_not_null($token, 'no csrf_token field on the edit-patient form');

        $res = $http->post("/patient/$id/edit", [
            'csrf_token' => $token,
            'submit' => '1',
            'patient-name' => 'Δοκιμαστική Ασθενής Ενημερωμένη',
            'patient-dob' => '1990-01-01',
            'patient-amka' => '11111111111',
            'patient-telephone' => '2109999999',
            'patient-address' => 'Οδός Δοκιμής 1',
            'patient-email' => 'test.patient@example.invalid',
            'patient-note' => 'updated by the regression suite',
        ]);
        assert_equal(302, $res['status'], "edit-patient POST did not redirect (got {$res['status']})");

        $row = dbConnection::getConnection()
            ->query("SELECT pname, ptel FROM patients WHERE id = $id")
            ->fetch();
        assert_equal('Δοκιμαστική Ασθενής Ενημερωμένη', $row['pname'], 'patient name was not updated');
        assert_equal('2109999999', $row['ptel'], 'patient telephone was not updated');
    });

    $runner->add('patient appears in the patient list', function () use ($http) {
        $list = $http->get('/patients');
        assert_equal(200, $list['status'], 'GET /patients did not return 200');
        assert_contains('Δοκιμαστική Ασθενής Ενημερωμένη', $list['body'], 'updated patient does not appear on /patients');
    });

    $runner->add('delete (soft-delete) a patient', function () use ($http) {
        TestSchema::assertSafeToMutate();
        $id = $GLOBALS['zpms_test_patient_id'] ?? null;
        assert_not_null($id, 'previous test did not record a patient id');

        // Deletion is a POST + CSRF token, not a bare GET link -- the
        // control itself lives on the patient's own record now
        // (edit_patient.zetem's "Διαγραφή Ασθενή" danger-zone form), not
        // as a per-row action on /patients (see the next test below).
        // The token is session-wide, so any already-rendered page's copy
        // works; /patients renders one regardless.
        $listPage = $http->get('/patients');
        $token = TestHttpClient::extractCsrfToken($listPage['body']);
        assert_not_null($token, 'no csrf_token field found on /patients');

        $res = $http->post("/patient/$id/delete", ['csrf_token' => $token]);
        assert_equal(302, $res['status'], "delete-patient POST did not redirect (got {$res['status']})");

        $row = dbConnection::getConnection()
            ->query("SELECT deleted FROM patients WHERE id = $id")
            ->fetch();
        assert_not_null($row, 'patient row disappeared entirely -- deletion must be a soft-delete, not a hard DELETE');
        assert_not_null($row['deleted'], 'patient was not marked deleted');
    });

    $runner->add('deleted patient no longer appears in the patient list', function () use ($http) {
        // The delete redirect's own one-shot flash notice ("Ο φάκελος του
        // ασθενή <name> διαγράφθηκε...") names the patient too -- an
        // expected, single-use message, not the patient list itself. Load
        // the page once to consume/clear it before making the real
        // assertion, the same way a browser following the redirect would.
        $http->get('/patients');

        $list = $http->get('/patients');
        assert_equal(200, $list['status'], 'GET /patients did not return 200');
        assert_not_contains('Δοκιμαστική Ασθενής Ενημερωμένη', $list['body'], 'deleted patient still appears on /patients');
    });

    $runner->add('patient deletion is a per-record control, not a per-row action on the patient list', function () use ($http) {
        // The delete button used to be a per-row action on /patients
        // itself; it now lives inside the patient's own record
        // (edit_patient.zetem's "Διαγραφή Ασθενή" danger zone) instead --
        // confirmed here for an account (the doctor test user) that DOES
        // hold patients-delete-patient, so this is a UI-placement check,
        // not a permission-gating one (auth_csrf.php's secretary test
        // already covers the permission being absent).
        TestSchema::assertSafeToMutate();

        $p = new patientsClass([
            'guid' => guid(), 'cuser' => 'test-fixture', 'cdate' => getDBtime(),
            'pname' => 'Ασθενής Για Θέση Διαγραφής', 'pdob' => '1990-01-01 00:00:00',
            'pamka' => '66666666666', 'ptel' => '', 'paddr' => '', 'pemail' => '', 'pnote' => '',
        ]);
        $p->insert();

        $list = $http->get('/patients');
        assert_not_contains('inline-delete-form', $list['body'], '/patients still renders a per-row delete form');
        assert_not_contains('Ενέργειες', $list['body'], '/patients still has an "Ενέργειες" (actions) column header');

        $editPage = $http->get('/patient/' . $p->getid() . '/edit');
        assert_contains('inline-delete-form', $editPage['body'], "the patient's own record does not render the delete control");
        assert_contains('Διαγραφή Ασθενή', $editPage['body'], "the patient's own record is missing the delete button label");
    });

    $runner->add('the patient record opens with an identity header, closed details, appointment rows and a documents list', function () use ($http) {
        TestSchema::assertSafeToMutate();
        $db = dbConnection::getConnection();

        $p = new patientsClass([
            'guid' => guid(), 'cuser' => 'test-fixture', 'cdate' => getDBtime(),
            'pname' => 'Ασθενής Κεφαλίδας', 'pdob' => '1980-02-03 00:00:00',
            'pamka' => '77777777777', 'ptel' => '2105550100', 'paddr' => 'Οδός Κεφαλίδας 5', 'pemail' => 'header@example.invalid',
            'pnote' => 'σημείωση κεφαλίδας',
        ]);
        $p->insert();

        // One visit and one operation, the operation carrying one file --
        // inserted directly (this test is about how the record page
        // renders them, not about the create/upload flows covered elsewhere).
        $ins = $db->prepare('INSERT INTO appointments (guid, cuser, pguid, adate, aplace, anote, atype) VALUES (?,?,?,?,?,?,?)');
        $ins->execute([guid(), 'test-fixture', $p->getguid(), '2020-03-04 10:00:00', 'Αθήνα', 'παλιό ραντεβού', 'appointment']);
        $ins->execute([guid(), 'test-fixture', $p->getguid(), '2020-05-06 08:00:00', 'Πειραιάς', 'παλιό χειρουργείο', 'operation']);
        $opId = (int)$db->query("SELECT id FROM appointments WHERE pguid = '" . $p->getguid() . "' AND atype = 'operation'")->fetchColumn();
        $db->prepare('INSERT INTO appointment_files (guid, cuser, appointment_id, file_name, file_path, file_size, mime_type, file_hash) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([guid(), 'test-fixture', $opId, 'εξέταση-κεφαλίδας.pdf', 'x', 2048, 'application/pdf', str_repeat('a', 64)]);

        $page = $http->get('/patient/' . $p->getid() . '/edit');
        assert_equal(200, $page['status'], 'GET the patient record did not return 200');
        $body = $page['body'];

        assert_contains('patient-identity', $body, 'the identity header is missing');
        assert_contains('2105550100', $body, 'the phone number is missing from the identity header');
        assert_contains('header@example.invalid', $body, 'the email is missing from the identity header');
        assert_contains('>ΑΚ</div>', $body, 'the avatar initials are missing');
        assert_contains('Επεξεργασία στοιχείων', $body, 'the details toggle is missing for an editor');

        // The details panel exists (the form is still there) but starts hidden.
        assert_true(preg_match('/id="patient-details"\s+hidden/', $body) === 1, 'the details panel does not start hidden');
        assert_contains('name="patient-name"', $body, 'the patient form is missing from the details panel');

        // Two rows, newest first; only the first starts open.
        assert_equal(2, preg_match_all('/class="appt-row /', $body), 'expected one row per appointment/operation');
        assert_true(strpos($body, '06-05-2020') < strpos($body, '04-03-2020'), 'appointment rows are not newest first');
        assert_equal(1, preg_match_all('/aria-expanded="true" aria-controls="appt-panel-/', $body), 'exactly one appointment row should start open');

        // Documents list names the file and points back at its appointment.
        assert_contains('εξέταση-κεφαλίδας.pdf', $body, 'the documents list is missing the attached file');
        assert_contains('side-files', $body, 'the documents sidebar is missing');
        assert_contains('href="#app-', $body, 'a document does not link back to its appointment');
    });
}
