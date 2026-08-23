<?php
/* End-to-end regression coverage for appointment file attachments --
 * upload, download, and delete, through the real multipart endpoints in
 * web/appointment_files.php. */

function zpms_functional_file_upload(TestRunner $runner, TestHttpClient $http): void {
    $runner->add('set up a fixture patient + appointment', function () {
        TestSchema::assertSafeToMutate();

        $p = new patientsClass([
            'guid' => guid(), 'cuser' => 'test-fixture', 'cdate' => getDBtime(),
            'pname' => 'Ασθενής Για Αρχεία', 'pdob' => '1975-03-03 00:00:00',
            'pamka' => '44444444444', 'ptel' => '', 'paddr' => '', 'pemail' => '', 'pnote' => '',
        ]);
        $p->insert();

        $ap = new appointmentsClass([
            'guid' => guid(), 'cuser' => 'test-fixture', 'cdate' => getDBtime(),
            'pguid' => $p->getguid(), 'adate' => getDBtime(), 'aplace' => '', 'anote' => 'file upload fixture',
        ]);
        $ap->insert();

        $GLOBALS['zpms_test_file_appt_id'] = $ap->getid();
        $GLOBALS['zpms_test_file_patient_id'] = $p->getid();
    });

    $runner->add('upload a file to an appointment', function () use ($http) {
        TestSchema::assertSafeToMutate();
        $apptId = $GLOBALS['zpms_test_file_appt_id'] ?? null;
        assert_not_null($apptId, 'fixture appointment was not created');

        // A real, valid 1x1 PNG -- appointment_file_upload() sniffs the
        // actual file content (finfo) and, for an image, fully decodes it
        // through GD, so a fake/placeholder file would fail both checks.
        $tmpFile = tempnam(sys_get_temp_dir(), 'zpms_test_upload_') . '.png';
        $img = imagecreatetruecolor(1, 1);
        imagepng($img, $tmpFile);
        imagedestroy($img);

        // A GET first, purely to obtain a CSRF token from a page rendered
        // for this session -- the patient record already carries one.
        $patientId = $GLOBALS['zpms_test_file_patient_id'];
        $page = $http->get("/patient/$patientId/edit");
        $token = TestHttpClient::extractCsrfToken($page['body']);
        assert_not_null($token, 'no csrf_token field found on the patient record');

        $res = $http->postMultipart(
            "/appointment/$apptId/files",
            ['csrf_token' => $token],
            ['appointmentFile' => $tmpFile]
        );
        @unlink($tmpFile);

        assert_equal(200, $res['status'], "file upload did not return 200 (got {$res['status']}): " . $res['body']);
        $json = json_decode($res['body'], true);
        assert_true(is_array($json), 'file upload response was not valid JSON: ' . $res['body']);
        assert_true($json['success'] ?? false, 'file upload reported success=false: ' . $res['body']);
        assert_true(isset($json['file']['id']), 'file upload response did not include a file id');

        $GLOBALS['zpms_test_file_id'] = (int)$json['file']['id'];

        $row = dbConnection::getConnection()
            ->query("SELECT appointment_id, file_size FROM appointment_files WHERE id = " . $GLOBALS['zpms_test_file_id'])
            ->fetch();
        assert_not_null($row, 'no appointment_files row was inserted');
        // (int) both sides -- entity getters return DB values as plain
        // strings (getid() included), so comparing against an un-cast
        // fixture id would fail this strict (!==) assertion on a type
        // mismatch alone, even when the actual linkage is correct.
        assert_equal((int)$apptId, (int)$row['appointment_id'], 'uploaded file is linked to the wrong appointment');
        assert_true((int)$row['file_size'] > 0, 'uploaded file has a zero/invalid recorded size');
    });

    $runner->add('download the uploaded file', function () use ($http) {
        $apptId = $GLOBALS['zpms_test_file_appt_id'];
        $fileId = $GLOBALS['zpms_test_file_id'] ?? null;
        assert_not_null($fileId, 'previous test did not record a file id');

        $res = $http->get("/appointment/$apptId/files/$fileId/download");
        assert_equal(200, $res['status'], "file download did not return 200 (got {$res['status']})");
        assert_contains('image/png', $res['headers'], 'download response is missing the expected Content-Type');
        assert_true(strlen($res['body']) > 0, 'downloaded file body was empty');
    });

    $runner->add('delete the uploaded file', function () use ($http) {
        TestSchema::assertSafeToMutate();
        $apptId = $GLOBALS['zpms_test_file_appt_id'];
        $fileId = $GLOBALS['zpms_test_file_id'] ?? null;
        assert_not_null($fileId, 'previous test did not record a file id');

        $patientId = $GLOBALS['zpms_test_file_patient_id'];
        $page = $http->get("/patient/$patientId/edit");
        $token = TestHttpClient::extractCsrfToken($page['body']);

        $res = $http->post("/appointment/$apptId/files/$fileId/delete", ['csrf_token' => $token]);
        assert_equal(200, $res['status'], "file delete did not return 200 (got {$res['status']})");
        $json = json_decode($res['body'], true);
        assert_true($json['success'] ?? false, 'file delete reported success=false: ' . $res['body']);

        $row = dbConnection::getConnection()
            ->query("SELECT COUNT(*) AS c FROM appointment_files WHERE id = $fileId")
            ->fetch();
        assert_equal(0, (int)$row['c'], 'appointment_files row still exists after delete');
    });

    $runner->add('a deleted file can no longer be downloaded', function () use ($http) {
        $apptId = $GLOBALS['zpms_test_file_appt_id'];
        $fileId = $GLOBALS['zpms_test_file_id'] ?? null;

        $res = $http->get("/appointment/$apptId/files/$fileId/download");
        assert_equal(404, $res['status'], "downloading a deleted file did not return 404 (got {$res['status']})");
    });
}
