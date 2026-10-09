<?php
/* End-to-end, HTTP-level regression coverage for creating, editing, and
 * (soft-)deleting a patient record -- driven exactly the way a browser
 * would (real cookies, real CSRF tokens scraped out of the rendered
 * form), against web/index.php's real route handlers and the real
 * patientsClass entity. */

require_once __DIR__ . '/../../bin/lib/dictionary_import.php';

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
        // The token is session-wide, so any already-rendered form's copy
        // works. The list itself no longer carries one (its search box is a
        // plain GET form), so take it from the patient's own record.
        $recordPage = $http->get("/patient/$id/edit");
        $token = TestHttpClient::extractCsrfToken($recordPage['body']);
        assert_not_null($token, "no csrf_token field found on /patient/$id/edit");

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
        // First use of a t() term adds it to the dictionary untranslated (the
        // Greek column holds the English text); loading config/dictionary.gr.php
        // is what makes the Greek interface read Greek, exactly as on a deploy.
        zpms_import_dictionary_terms(dbConnection::getConnection(), 'gr', require __DIR__ . '/../../config/dictionary.gr.php');
        $page = $http->get('/patient/' . $p->getid() . '/edit');
        $body = $page['body'];
        assert_not_contains('Επεξεργασία Στοιχείων Ασθενή', $body, 'the record still has a title repeating the breadcrumb');

        assert_contains('class="page-back page-back-top"', $body, 'the back-to-list link is missing from the record');
        assert_contains('Πίσω στη λίστα ασθενών', $body, 'the back link is not in the current (Greek) language');
        assert_true(preg_match('#href="[^"]*/patients"[^>]*>\s*<i class="bx bx-arrow-back"#', $body) === 1, 'the back link does not point at the patient list');
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

    $runner->add('gender: M, F or not set -- stored as NULL when blank or tampered, untouched when the form omits it', function () use ($http) {
        TestSchema::assertSafeToMutate();
        $db = dbConnection::getConnection();

        $form = $http->get('/patient/new');
        assert_contains('name="patient-gender"', $form['body'], 'the new-patient form has no gender radios');
        $token = TestHttpClient::extractCsrfToken($form['body']);
        $base = [
            'csrf_token' => $token, 'submit' => '1',
            'patient-name' => 'Ασθενής Φύλου', 'patient-dob' => '1985-05-05', 'patient-amka' => '77700000001',
            'patient-telephone' => '', 'patient-address' => '', 'patient-email' => '', 'patient-note' => '',
        ];
        $res = $http->post('/patient/new', $base + ['patient-gender' => 'M']);
        assert_equal(302, $res['status'], 'new-patient POST with a gender did not redirect');
        $row = $db->query("SELECT id, pgender FROM patients WHERE pamka = '77700000001'")->fetch();
        assert_equal('M', $row['pgender'], 'gender M was not stored on create');
        $id = (int)$row['id'];
        $gender = fn() => $db->query("SELECT pgender FROM patients WHERE id = $id")->fetchColumn();

        $page = $http->get("/patient/$id/edit");
        assert_true(preg_match('/value="M"[^>]*checked/', $page['body']) === 1, 'the M radio is not checked on the record');
        $token = TestHttpClient::extractCsrfToken($page['body']);
        $edit = ['csrf_token' => $token, 'submit' => '1'] + $base;

        $http->post("/patient/$id/edit", $edit + ['patient-gender' => 'F']);
        assert_equal('F', $gender(), 'gender was not changed to F');

        $http->post("/patient/$id/edit", $edit);   // no gender field at all
        assert_equal('F', $gender(), 'a request without the field must leave the gender alone');

        $http->post("/patient/$id/edit", $edit + ['patient-gender' => 'x']);
        assert_null($gender(), 'a tampered value must be stored as not set');

        $http->post("/patient/$id/edit", $edit + ['patient-gender' => 'F']);
        $http->post("/patient/$id/edit", $edit + ['patient-gender' => '']);
        assert_null($gender(), 'the "not set" radio (empty value) must clear the gender');
        $page = $http->get("/patient/$id/edit");
        assert_true(preg_match('/value=""[^>]*checked/', $page['body']) === 1, 'the "not set" radio is not checked for a patient with no gender');
    });

    $runner->add('patients list: rows per page, paging, "all", sorting, search, area, bad values, and ? in the address', function () use ($http) {
        TestSchema::assertSafeToMutate();
        $db = dbConnection::getConnection();

        // 12 patients with a shared name stem, so every check below can be
        // scoped to them with ?q=. Ζ01..Ζ12; Ζ12 is 'Πειραιάς', the rest 'Αθήνα'.
        $ins = $db->prepare('INSERT INTO patients (guid, cuser, pname, pdob, pamka, ptel, pemail, cdate) VALUES (?,?,?,?,?,?,?,?)');
        $app = $db->prepare('INSERT INTO appointments (guid, cuser, pguid, adate, aplace, anote, atype, deleted) VALUES (?,?,?,?,?,?,?,?)');
        for ($i = 1; $i <= 12; $i++) {
            $guid = guid();
            $name = sprintf('Λιστάκης Ζ%02d', $i);
            $ins->execute([$guid, 'test-fixture', $name, '1980-01-01 00:00:00', sprintf('9990000%04d', $i), '2100000' . sprintf('%03d', $i), '', date('Y-m-d H:i:s')]);
            $app->execute([guid(), 'test-fixture', $guid, sprintf('2019-01-%02d 10:00:00', $i), ($i === 12) ? 'Πειραιάς' : 'Αθήνα', '', 'appointment', null]);
            if ($i === 1) {
                // Newer appointment that was deleted: must not count as the latest.
                $app->execute([guid(), 'test-fixture', $guid, '2025-06-06 10:00:00', 'Αθήνα', '', 'appointment', '2025-06-07 10:00:00']);
            }
        }
        $rows = fn($body) => preg_match_all('/<tr data-href=/', $body);

        $all = $http->get('/patients?q=' . rawurlencode('Λιστάκης') . '&per_page=all');
        assert_contains('class="page-title sr-only"', $all['body'], 'the list title should be visually hidden (the breadcrumb says the same)');
        assert_equal(200, $all['status'], '/patients?per_page=all did not return 200');
        assert_equal(12, $rows($all['body']), 'per_page=all should list every match');
        assert_not_contains('class="pt-pages"', $all['body'], '"all" should hide the pager');

        $p1 = $http->get('/patients?q=' . rawurlencode('Λιστάκης') . '&per_page=10');
        assert_equal(10, $rows($p1['body']), 'per_page=10 page 1 should have 10 rows');
        assert_contains('Εμφάνιση 1–10 από 12 ασθενείς', $p1['body'], 'wrong count line on page 1');
        $p2 = $http->get('/patients?q=' . rawurlencode('Λιστάκης') . '&per_page=10&page=2');
        assert_equal(2, $rows($p2['body']), 'page 2 of 12 at 10 per page should have 2 rows');
        assert_contains('Εμφάνιση 11–12 από 12 ασθενείς', $p2['body'], 'wrong count line on page 2');

        // Sorting by name, both ways, and the default (latest appointment, newest first).
        $asc = $http->get('/patients?q=' . rawurlencode('Λιστάκης') . '&sort=name&dir=asc&per_page=all');
        assert_true(strpos($asc['body'], 'Ζ01') < strpos($asc['body'], 'Ζ12'), 'sort=name&dir=asc is not A-Z');
        $desc = $http->get('/patients?q=' . rawurlencode('Λιστάκης') . '&sort=name&dir=desc&per_page=all');
        assert_true(strpos($desc['body'], 'Ζ12') < strpos($desc['body'], 'Ζ01'), 'sort=name&dir=desc is not Z-A');
        $def = $http->get('/patients?q=' . rawurlencode('Λιστάκης') . '&per_page=all');
        assert_true(strpos($def['body'], 'Ζ12') < strpos($def['body'], 'Ζ02'), 'default order is not latest appointment first');
        // Ζ01's only live appointment is 2019-01-01; the deleted 2025 one must not make it the newest.
        assert_true(strpos($def['body'], 'Ζ01') > strpos($def['body'], 'Ζ02'), 'a deleted appointment is being counted as the latest');
        assert_not_contains('06-06-2025', $def['body'], 'a deleted appointment date is shown');

        // Search: accent-insensitive prefix match, phone, and no match.
        $accent = $http->get('/patients?q=' . rawurlencode('λιστακης ζ05') . '&per_page=all');
        assert_equal(1, $rows($accent['body']), 'accent-insensitive search for "λιστακης ζ05" should find exactly one patient');
        $phone = $http->get('/patients?q=2100000007&per_page=all');
        assert_equal(1, $rows($phone['body']), 'search by telephone failed');
        $none = $http->get('/patients?q=' . rawurlencode('Ουδείς Ανύπαρκτος'));
        assert_contains('Κανένας ασθενής δεν ταιριάζει', $none['body'], 'an empty search result has no message');

        // Area filter = place of the latest appointment.
        $area = $http->get('/patients?q=' . rawurlencode('Λιστάκης') . '&area=' . rawurlencode('Πειραιάς') . '&per_page=all');
        assert_equal(1, $rows($area['body']), 'area filter should keep only the patient whose latest appointment is there');

        // Values outside the allowed lists fall back instead of erroring.
        $bad = $http->get('/patients?q=' . rawurlencode('Λιστάκης') . '&per_page=7&page=999&dir=zzz&sort=pamka');
        assert_equal(200, $bad['status'], 'bad parameters should not error');
        assert_contains('Εμφάνιση 1–12 από 12 ασθενείς', $bad['body'], 'bad per_page should fall back to 25 and page to the last');
        $sql = $http->get("/patients?q=" . rawurlencode("x' OR 1=1 --") . '&per_page=all');
        assert_equal(200, $sql['status'], 'a quote in the search term broke the page');
        assert_contains('Κανένας ασθενής δεν ταιριάζει', $sql['body'], 'a quote in the search term matched something');

        // Old addresses redirect to the query-string ones.
        $legacy = $http->get('/patients/sort/name/1');
        assert_equal(302, $legacy['status'], 'the old sort address should redirect');
        assert_contains('/patients?sort=name&dir=asc', (string)$legacy['location'], 'the old sort address went to the wrong place');
        $term = $http->get('/patients/search/' . rawurlencode('Smith & Sons'));
        assert_equal(302, $term['status'], 'the old search address should redirect');
        assert_contains('q=Smith+%26+Sons', (string)$term['location'], 'a "&" inside a search term was cut off by the router');
    });

    $runner->add('patients list: one area entry per place even when appointments were saved in Greek and English', function () use ($http) {
        TestSchema::assertSafeToMutate();
        $db = dbConnection::getConnection();

        // One place, two names (locations has a row per language, appointments
        // store whichever name was active when they were saved), plus a place
        // that is not in the locations table at all.
        $loc = $db->prepare('INSERT INTO locations (guid, lang, cuser, name, machinename, address) VALUES (?,?,?,?,?,?)');
        $loc->execute([guid(), 'gr', 'test-fixture', 'Αθήνα', 'zz-athens', '']);
        $loc->execute([guid(), 'en', 'test-fixture', 'Athens', 'zz-athens', '']);
        $ins = $db->prepare('INSERT INTO patients (guid, cuser, pname, pdob, pamka, ptel, pemail, cdate) VALUES (?,?,?,?,?,?,?,?)');
        $app = $db->prepare('INSERT INTO appointments (guid, cuser, pguid, adate, aplace, anote, atype) VALUES (?,?,?,?,?,?,?)');
        foreach ([['Χωρίς Τόπου Α', 'Αθήνα'], ['Χωρίς Τόπου Β', 'Athens'], ['Χωρίς Τόπου Γ', 'Κρήτη ΖΖ']] as $i => [$name, $place]) {
            $g = guid();
            $ins->execute([$g, 'test-fixture', $name, '1980-01-01 00:00:00', sprintf('5550000%04d', $i), '', '', date('Y-m-d H:i:s')]);
            $app->execute([guid(), 'test-fixture', $g, '2018-02-0' . ($i + 1) . ' 10:00:00', $place, '', 'appointment']);
        }

        try {
            $page = $http->get('/patients?q=' . rawurlencode('Χωρίς Τόπου') . '&per_page=all');
            assert_equal(1, preg_match_all('/<option value="zz-athens"/', $page['body']), 'Athens/Αθήνα must be a single area option');
            assert_contains('>Αθήνα</option>', $page['body'], 'the area option should be labelled in the current language');
            assert_not_contains('>Athens</option>', $page['body'], 'the English name should not be a second option');
            assert_contains('<option value="Κρήτη ΖΖ"', $page['body'], 'a place missing from the locations table should still be offered');

            // A patient whose appointment was saved as "Athens" shows the Greek name.
            assert_equal(0, preg_match('/Athens<\/span>/', $page['body']), 'a place saved in English should be shown in the current language');

            // Filtering by the one entry finds the patients saved under either name.
            $f = $http->get('/patients?q=' . rawurlencode('Χωρίς Τόπου') . '&area=zz-athens&per_page=all');
            assert_equal(2, preg_match_all('/<tr data-href=/', $f['body']), 'filtering by Athens should find both the Greek-name and the English-name appointments');
            $other = $http->get('/patients?q=' . rawurlencode('Χωρίς Τόπου') . '&area=' . rawurlencode('Κρήτη ΖΖ') . '&per_page=all');
            assert_equal(1, preg_match_all('/<tr data-href=/', $other['body']), 'a free-text place should still filter by itself');
        } finally {
            $db->exec("DELETE FROM locations WHERE machinename = 'zz-athens'");
        }
    });

    $runner->add('dictionary import: adds new terms, translates untranslated ones, never overwrites an edited translation', function () {
        TestSchema::assertSafeToMutate();
        $db = dbConnection::getConnection();
        $row = fn($en) => $db->query('SELECT en, gr, gr_set FROM dictionary WHERE en = ' . $db->quote($en))->fetch(PDO::FETCH_ASSOC);

        // A term a template has already used once but nobody translated: the
        // table has it with the English text in the Greek column, gr_set = 0.
        $db->exec("INSERT INTO dictionary (en, en_set, gr, gr_set) VALUES ('ZZ term untranslated', 1, 'ZZ term untranslated', 0)");
        // A term someone translated by hand.
        $db->exec("INSERT INTO dictionary (en, en_set, gr, gr_set) VALUES ('ZZ term edited', 1, 'ΧΕΙΡΟΚΙΝΗΤΗ', 1)");

        $terms = ['ZZ term untranslated' => 'ΑΜΕΤΑΦΡΑΣΤΟ', 'ZZ term edited' => 'ΑΠΟ ΑΡΧΕΙΟ', 'ZZ term new' => 'ΝΕΟ'];
        $dry = zpms_import_dictionary_terms($db, 'gr', $terms, true);
        assert_equal(['added' => 1, 'translated' => 1, 'kept' => 1], $dry, 'dry run counted the wrong things');
        assert_equal(0, (int)$row('ZZ term untranslated')['gr_set'], 'a dry run must not write');
        assert_equal(false, $row('ZZ term new'), 'a dry run must not insert');

        $res = zpms_import_dictionary_terms($db, 'gr', $terms);
        assert_equal(['added' => 1, 'translated' => 1, 'kept' => 1], $res, 'import counted the wrong things');
        assert_equal('ΑΜΕΤΑΦΡΑΣΤΟ', $row('ZZ term untranslated')['gr'], 'an untranslated term was not translated');
        assert_equal('ΧΕΙΡΟΚΙΝΗΤΗ', $row('ZZ term edited')['gr'], 'a hand-edited translation was overwritten');
        assert_equal('ΝΕΟ', $row('ZZ term new')['gr'], 'a new term was not added');
        assert_equal(1, (int)$row('ZZ term new')['gr_set'], 'a new term should be marked translated');

        $again = zpms_import_dictionary_terms($db, 'gr', $terms);
        assert_equal(['added' => 0, 'translated' => 0, 'kept' => 3], $again, 'running the import twice should change nothing');

        $threw = false;
        try { zpms_import_dictionary_terms($db, 'xx; DROP TABLE dictionary', $terms); } catch (InvalidArgumentException $e) { $threw = true; }
        assert_true($threw, 'a language that is not a dictionary column must be refused');

        $db->exec("DELETE FROM dictionary WHERE en LIKE 'ZZ term %'");
    });
}
