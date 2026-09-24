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

    $runner->add('appointment history aggregates consecutive edits into one 5-minute session', function () use ($http) {
        // zpms_record_appointment_change() (web/appointment_history.php)
        // is the write side of this feature -- called from
        // appointment_edit_post() every time a field's posted value
        // actually differs from what was stored. The previous test just
        // changed appointment-place + appointment-notes, so exactly one
        // appointment_history row should now exist for this appointment,
        // recording both fields.
        TestSchema::assertSafeToMutate();
        $apptId = $GLOBALS['zpms_test_appointment_id'];
        $db = dbConnection::getConnection();

        $rows = $db->query("SELECT * FROM appointment_history WHERE appointment_id = $apptId ORDER BY id")->fetchAll();
        assert_equal(1, count($rows), 'expected exactly one appointment_history row after the first edit');
        assert_equal(1, (int)$rows[0]['change_count'], 'a fresh session should start with change_count = 1');
        // appointment-notes is the one field guaranteed to genuinely
        // differ from what was stored (each edit in this suite posts a
        // distinct note) -- appointment-place/-date may or may not also
        // show up here depending on how the fixture's "test-clinic"
        // machine name (which doesn't resolve to a real locationsClassEx
        // row) and the exact date string happen to normalize, so this
        // test doesn't assert on those two either way.
        assert_contains('appointment-notes', $rows[0]['changed_fields'], 'the notes change was not recorded');
        $firstRowId = (int)$rows[0]['id'];

        // A second, immediate edit on the same appointment by the same
        // user (well under 5 minutes after the first) must extend that
        // same row rather than create a new one.
        $patientId = $GLOBALS['zpms_test_appt_patient_id'];
        $page = $http->get("/patient/$patientId/edit");
        $token = TestHttpClient::extractCsrfToken($page['body']);

        $res = $http->post("/appointment/$apptId/edit", [
            'csrf_token' => $token,
            'submit' => '1',
            "appointment-date-$apptId" => '2026-09-02',
            'appointment-place' => 'test-clinic',
            'appointment-notes' => 'edited again, moments later',
        ]);
        assert_equal(302, $res['status'], "second edit-appointment POST did not redirect (got {$res['status']})");

        $rows = $db->query("SELECT * FROM appointment_history WHERE appointment_id = $apptId ORDER BY id")->fetchAll();
        assert_equal(1, count($rows), 'a second edit within 5 minutes created a new session row instead of extending the existing one');
        assert_equal($firstRowId, (int)$rows[0]['id'], 'the extended row is not the same row as the first session');
        assert_equal(2, (int)$rows[0]['change_count'], 'change_count was not incremented on the extended session');

        // Backdate that session's last_change_at by 6 minutes (past the
        // 5-minute window) to deterministically exercise the "gap too
        // long -- start a fresh session" branch without sleeping in the
        // test suite.
        $db->exec("UPDATE appointment_history SET last_change_at = DATE_SUB(last_change_at, INTERVAL 6 MINUTE) WHERE id = $firstRowId");

        $page = $http->get("/patient/$patientId/edit");
        $token = TestHttpClient::extractCsrfToken($page['body']);
        $res = $http->post("/appointment/$apptId/edit", [
            'csrf_token' => $token,
            'submit' => '1',
            "appointment-date-$apptId" => '2026-09-02',
            'appointment-place' => 'test-clinic',
            'appointment-notes' => 'edited after the session window elapsed',
        ]);
        assert_equal(302, $res['status'], "third edit-appointment POST did not redirect (got {$res['status']})");

        $rows = $db->query("SELECT * FROM appointment_history WHERE appointment_id = $apptId ORDER BY id")->fetchAll();
        assert_equal(2, count($rows), 'an edit after the 5-minute window should start a new session row, not extend the stale one');
        assert_equal(1, (int)$rows[1]['change_count'], 'the new session should start its own change_count at 1');

        // The patient page renders the aggregated sessions, newest first,
        // with the resolved Greek field label and a change count.
        $editPage = $http->get("/patient/$patientId/edit");
        assert_contains('Ιστορικό Αλλαγών', $editPage['body'], 'the appointment card is missing the history section');
        assert_contains('Σημειώσεις', $editPage['body'], 'the history section does not show the resolved "Σημειώσεις" field label');
        assert_contains('2 αλλαγές', $editPage['body'], 'the history section does not show the merged session\'s change count');
    });

    $runner->add('delete (soft-delete) the appointment', function () use ($http) {
        TestSchema::assertSafeToMutate();
        $apptId = $GLOBALS['zpms_test_appointment_id'] ?? null;
        assert_not_null($apptId, 'previous test did not record an appointment id');

        // Deletion is a POST + CSRF token now, not a bare GET link.
        $patientId = $GLOBALS['zpms_test_appt_patient_id'];
        $page = $http->get("/patient/$patientId/edit");
        $token = TestHttpClient::extractCsrfToken($page['body']);
        assert_not_null($token, 'no csrf_token field found on the patient record');

        $res = $http->post("/appointment/$apptId/delete", ['csrf_token' => $token]);
        assert_equal(302, $res['status'], "delete-appointment POST did not redirect (got {$res['status']})");

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

        $page = $http->get('/patient/' . $p->getid() . '/edit');
        $token = TestHttpClient::extractCsrfToken($page['body']);
        assert_not_null($token, 'no csrf_token field found on the fixture patient record');

        $res = $http->post('/patient/' . $p->getid() . '/delete', ['csrf_token' => $token]);
        assert_equal(302, $res['status'], "delete-patient POST did not redirect (got {$res['status']})");

        $row = dbConnection::getConnection()
            ->query('SELECT deleted FROM appointments WHERE id = ' . $ap->getid())
            ->fetch();
        assert_not_null($row['deleted'], "deleting the patient did not cascade-delete their appointment");
    });

    $runner->add('nav has the Calendar Sync menu item, and /consultation/pending lists pending appointments before previous ones', function () use ($http) {
        TestSchema::assertSafeToMutate();

        // Restored menu item -- see config/settings.info.yaml's
        // `calendar_sync` submenu entry docblock: Calendar-native events
        // land in the same pending-appointments list now, so this just
        // points at that page under a calendar-flavored label rather than
        // reviving the old, removed review-queue page.
        $nav = $http->get('/patients');
        assert_contains('Συγχρονισμός Ημερολογίου', $nav['body'], 'nav is missing the Calendar Sync menu item');
        assert_contains('href="/consultation/pending"', $nav['body'], 'Calendar Sync menu item does not link to /consultation/pending');

        // A past, Calendar-sourced pending_appointments row (google_event_id
        // set, appointment_datetime already elapsed) -- this is what the
        // "Προηγούμενα Ραντεβού" section should list now (sourced from the
        // calendar sync, not the `appointments`/patient-record table --
        // see pendingAppointmentsClassEx::getPreviousFromCalendar()'s own
        // docblock). Deliberately left unconverted/uncancelled, to confirm
        // the section doesn't require either state.
        $pa = new pendingAppointmentsClass([
            'guid' => guid(), 'cuser' => 'test-fixture', 'cdate' => getDBtime(),
            'patient_name' => 'Ασθενής Προηγούμενου Ραντεβού',
            'patient_phone' => '2109998888',
            'appointment_datetime' => date('Y-m-d H:i:s', strtotime('-30 days')),
            'location' => 'Previous Appt Clinic',
            'google_event_id' => 'test-fixture-calendar-event-' . guid(),
            'google_synced_at' => getDBtime(),
        ]);
        $pa->insert();

        // A past pending_appointments row with NO google_event_id (a plain
        // phone booking that simply elapsed, never touched by the calendar
        // sync) must NOT appear in "Προηγούμενα Ραντεβού" -- this section
        // is specifically the calendar's own history, not "every past
        // pending_appointments row".
        $paNoCalendar = new pendingAppointmentsClass([
            'guid' => guid(), 'cuser' => 'test-fixture', 'cdate' => getDBtime(),
            'patient_name' => 'Ασθενής Χωρίς Ημερολόγιο',
            'appointment_datetime' => date('Y-m-d H:i:s', strtotime('-30 days')),
            'location' => 'Non-Calendar Clinic',
        ]);
        $paNoCalendar->insert();

        // A CANCELLED calendar-sourced pending_appointments row -- must NOT
        // appear in "Προηγούμενα Ραντεβού" either, even though it has a
        // google_event_id and its date has passed. Both
        // pending_appointment_delete() and bin/sync_google_calendar.php
        // delete the actual Calendar event when cancelling one of these
        // (see either's own docblock), so a cancelled row was never a real
        // appointment that happened -- it was undone, and this section is
        // "what actually took place," not "everything ZPMS ever synced."
        $paCancelled = new pendingAppointmentsClass([
            'guid' => guid(), 'cuser' => 'test-fixture', 'cdate' => getDBtime(),
            'patient_name' => 'Ασθενής Ακυρωμένου Ραντεβού',
            'appointment_datetime' => date('Y-m-d H:i:s', strtotime('-30 days')),
            'location' => 'Cancelled Appt Clinic',
            'google_event_id' => 'test-fixture-calendar-event-' . guid(),
            'google_synced_at' => getDBtime(),
            'cancelled_at' => getDBtime(),
        ]);
        $paCancelled->insert();

        $page = $http->get('/consultation/pending');
        assert_equal(200, $page['status'], 'GET /consultation/pending did not return 200');

        $posPending = strpos($page['body'], 'Εκκρεμή Ραντεβού');
        $posPrevious = strpos($page['body'], 'Προηγούμενα Ραντεβού');
        assert_not_null($posPending, 'pending-appointments heading is missing');
        assert_not_null($posPrevious, '/consultation/pending is missing the "previous appointments" section');
        assert_true($posPending < $posPrevious, 'pending appointments must be listed before previous appointments');

        // The non-calendar past booking belongs in the still-pending
        // section above (nothing has converted/cancelled it, so it's
        // correctly still "outstanding" by that section's own rule) --
        // checked against that slice specifically, not the whole page,
        // since its name would otherwise trivially satisfy a page-wide
        // assert_contains regardless of which section it actually landed in.
        $pendingSection = substr($page['body'], $posPending, $posPrevious - $posPending);
        $previousSection = substr($page['body'], $posPrevious);

        assert_contains('Ασθενής Χωρίς Ημερολόγιο', $pendingSection, 'the non-calendar past booking should still list as "still pending"');

        assert_contains('Ασθενής Προηγούμενου Ραντεβού', $previousSection, 'previous appointments section does not list the calendar-sourced fixture');
        assert_contains('Previous Appt Clinic', $previousSection, 'previous appointments section does not show the fixture\'s location');
        assert_not_contains('Ασθενής Χωρίς Ημερολόγιο', $previousSection, 'previous appointments section listed a past booking with no google_event_id');
        assert_not_contains('Ασθενής Ακυρωμένου Ραντεβού', $previousSection, 'previous appointments section listed a cancelled/deleted appointment');
        assert_not_contains('Ασθενής Ακυρωμένου Ραντεβού', $pendingSection, 'a cancelled appointment should not show as still pending either');
    });

    $runner->add('the Home Screen has an appointments card linking to the pending/previous appointments page', function () use ($http) {
        // homepage() (web/index.php) gates this card on
        // ZPMS_PERM_PENDING_APPOINTMENTS_MANAGE, the same permission
        // /consultation/pending itself requires -- deliberately points at
        // that existing page rather than a second, separate appointments
        // list. The doctor test user holds this permission (see
        // web/rbac_seed.php).
        $page = $http->get('/');
        assert_equal(200, $page['status'], 'GET / did not return 200 for a logged-in doctor account');
        assert_contains('dashboard-card', $page['body'], 'homepage did not render as the dashboard grid');
        assert_contains('Ραντεβού', $page['body'], 'homepage is missing the appointments card');
        assert_contains('href="/consultation/pending"', $page['body'], 'the appointments card does not link to /consultation/pending');
    });
}
