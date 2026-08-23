<?php
/* End-to-end, HTTP-level regression coverage for the patient-embedded
 * appointment flow -- create/edit/delete an appointment from inside a
 * patient's own record (the only way to reach an appointment at all,
 * since the standalone appointments list/new-appointment pages were
 * removed). Uses a patient created directly via the entity class (not
 * through HTTP) since patient creation itself is already covered by
 * patient_crud.php -- this suite is about appointments. */

function zpms_functional_appointment_crud(TestRunner $runner, TestHttpClient $http): void {
    $runner->add('set up a fixture patient', function () {
        TestSchema::assertSafeToMutate();

        $p = new patientsClass([
            'guid' => guid(),
            'cuser' => 'test-fixture',
            'cdate' => getDBtime(),
            'pname' => 'Ασθενής Για Ραντεβού',
            'pdob' => '1985-05-05 00:00:00',
            'pamka' => '22222222222',
            'ptel' => '2101112222',
            'paddr' => '',
            'pemail' => '',
            'pnote' => '',
        ]);
        $p->insert();
        $GLOBALS['zpms_test_appt_patient_id'] = $p->getid();
        $GLOBALS['zpms_test_appt_patient_guid'] = $p->getguid();
    });

    $runner->add('create a new appointment from the patient record', function () use ($http) {
        TestSchema::assertSafeToMutate();
        $patientId = $GLOBALS['zpms_test_appt_patient_id'] ?? null;
        assert_not_null($patientId, 'fixture patient was not created');

        $form = $http->get("/appointment/$patientId/newappointment");
        assert_equal(200, $form['status'], 'GET .../newappointment did not return 200');
        $token = TestHttpClient::extractCsrfToken($form['body']);
        assert_not_null($token, 'no csrf_token field on the new-appointment form');

        $res = $http->post("/appointment/$patientId/newappointment", [
            'csrf_token' => $token,
            'submit' => '1',
            'appointment-date' => '2026-09-01T10:30',
            'appointment-place' => 'test-clinic',
            'appointment-notes' => 'created by the regression suite',
        ]);
        assert_equal(302, $res['status'], "new-appointment POST did not redirect (got {$res['status']})");
        assert_contains("/patient/$patientId/edit", (string)$res['location'], 'new-appointment POST did not redirect back to the patient record');

        $patientGuid = $GLOBALS['zpms_test_appt_patient_guid'];
        $row = dbConnection::getConnection()
            ->prepare("SELECT id, anote, deleted FROM appointments WHERE pguid = ?");
        $row->execute([$patientGuid]);
        $appt = $row->fetch();
        assert_not_null($appt, 'no appointment row was inserted for the fixture patient');
        assert_equal('created by the regression suite', $appt['anote'], 'inserted appointment has the wrong note');
        assert_null($appt['deleted'], 'a freshly created appointment should not be marked deleted');

        $GLOBALS['zpms_test_appointment_id'] = (int)$appt['id'];
    });

    $runner->add('appointment appears on the patient record', function () use ($http) {
        $patientId = $GLOBALS['zpms_test_appt_patient_id'];
        $page = $http->get("/patient/$patientId/edit");
        assert_equal(200, $page['status'], "GET /patient/$patientId/edit did not return 200");
        assert_contains('created by the regression suite', $page['body'], 'appointment note does not appear on the patient record');
    });

    $runner->add('edit the appointment', function () use ($http) {
        TestSchema::assertSafeToMutate();
        $apptId = $GLOBALS['zpms_test_appointment_id'] ?? null;
        assert_not_null($apptId, 'previous test did not record an appointment id');

        // The inline appointment card (view_appointment.zetem, embedded in
        // the patient record) posts straight to /appointment/{id}/edit --
        // there's no separate GET page for it, so the token comes from the
        // patient page that already renders the card.
        $patientId = $GLOBALS['zpms_test_appt_patient_id'];
        $page = $http->get("/patient/$patientId/edit");
        $token = TestHttpClient::extractCsrfToken($page['body']);
        assert_not_null($token, 'no csrf_token field found on the patient record (for the appointment card)');

        $res = $http->post("/appointment/$apptId/edit", [
            'csrf_token' => $token,
            'submit' => '1',
            "appointment-date-$apptId" => '2026-09-02',
            'appointment-place' => 'test-clinic',
            'appointment-notes' => 'edited by the regression suite',
        ]);
        assert_equal(302, $res['status'], "edit-appointment POST did not redirect (got {$res['status']})");

        $row = dbConnection::getConnection()
            ->query("SELECT anote FROM appointments WHERE id = $apptId")
            ->fetch();
        assert_equal('edited by the regression suite', $row['anote'], 'appointment note was not updated');
    });

    $runner->add('delete (soft-delete) the appointment', function () use ($http) {
        TestSchema::assertSafeToMutate();
        $apptId = $GLOBALS['zpms_test_appointment_id'] ?? null;
        assert_not_null($apptId, 'previous test did not record an appointment id');

        $res = $http->get("/appointment/$apptId/delete");
        assert_equal(302, $res['status'], "delete-appointment GET did not redirect (got {$res['status']})");

        $patientId = $GLOBALS['zpms_test_appt_patient_id'];
        assert_contains("/patient/$patientId/edit", (string)$res['location'], 'delete-appointment did not redirect back to the patient record');

        $row = dbConnection::getConnection()
            ->query("SELECT deleted FROM appointments WHERE id = $apptId")
            ->fetch();
        assert_not_null($row, 'appointment row disappeared entirely -- deletion must be a soft-delete, not a hard DELETE');
        assert_not_null($row['deleted'], 'appointment was not marked deleted');
    });

    $runner->add('deleting a patient cascades to soft-delete their appointments', function () use ($http) {
        TestSchema::assertSafeToMutate();

        // Fresh patient + appointment (independent of the deleted one
        // above) so this test doesn't depend on the exact soft-deleted
        // state left by the previous test.
        $p = new patientsClass([
            'guid' => guid(), 'cuser' => 'test-fixture', 'cdate' => getDBtime(),
            'pname' => 'Ασθενής Για Cascade', 'pdob' => '1980-01-01 00:00:00',
            'pamka' => '33333333333', 'ptel' => '', 'paddr' => '', 'pemail' => '', 'pnote' => '',
        ]);
        $p->insert();

        $ap = new appointmentsClass([
            'guid' => guid(), 'cuser' => 'test-fixture', 'cdate' => getDBtime(),
            'pguid' => $p->getguid(), 'adate' => getDBtime(), 'aplace' => '', 'anote' => 'cascade fixture',
        ]);
        $ap->insert();

        $res = $http->get('/patient/' . $p->getid() . '/delete');
        assert_equal(302, $res['status'], "delete-patient GET did not redirect (got {$res['status']})");

        $row = dbConnection::getConnection()
            ->query('SELECT deleted FROM appointments WHERE id = ' . $ap->getid())
            ->fetch();
        assert_not_null($row['deleted'], "deleting the patient did not cascade-delete their appointment");
    });
}
