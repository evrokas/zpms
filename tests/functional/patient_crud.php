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
}
