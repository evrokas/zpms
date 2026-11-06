<?php
/* The patient API for MedScribe (web/api_patients.php): key checks on both endpoints, fetch by guid
 * (exact field set, 404 for unknown/deleted, 400 for a malformed guid), search with MedScribe's key,
 * the audit trail (who asked, never who was found) and the patient page's "New document" button.
 * The test server gets MEDSCRIBE_API_KEY from tests/lib/prepend_test_db.php. */

function zpms_medscribe_api_get(string $baseUrl, string $path, ?string $key): array {
    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $key === null ? [] : ['X-MedScribe-Api-Key: ' . $key],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // The test server prints PHP "constant already defined" warnings (by design, see prepend_test_db.php)
    // in front of the JSON, so decode from the first brace.
    $start = strpos((string)$body, '{"');
    return ['status' => $status, 'json' => $start === false ? null : json_decode(substr((string)$body, $start), true), 'body' => (string)$body];
}

function zpms_medscribe_api_functional_tests(TestRunner $runner, string $baseUrl): void {
    $key = 'test-medscribe-key';
    $guid = null; $deletedGuid = null;

    $runner->add('fixtures: a patient and a soft-deleted patient', function () use (&$guid, &$deletedGuid) {
        TestSchema::assertSafeToMutate();
        $db = dbConnection::getConnection();
        $ins = $db->prepare('INSERT INTO patients (guid, cuser, pname, pdob, pgender, pamka, ptel, paddr, pemail, pnote, deleted) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $guid = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
        $deletedGuid = 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb';
        $ins->execute([$guid, 'test', 'ΜedScribe Δοκιμή', '1980-03-15 00:00:00', 'F', '22222222222', '2100000000', 'Οδός 1', 'x@example.invalid', 'secret note', null]);
        $ins->execute([$deletedGuid, 'test', 'Διαγραμμένος Ασθενής', '1970-01-01 00:00:00', 'M', '33333333333', null, null, null, null, '2026-01-01 00:00:00']);
    });

    $runner->add('both endpoints refuse a request with no key or a wrong key (401)', function () use ($baseUrl, &$guid) {
        foreach (["/api/v1/patients/$guid", '/api/v1/patients/search/Med'] as $path) {
            foreach ([null, 'nope', ''] as $k) {
                $r = zpms_medscribe_api_get($baseUrl, $path, $k);
                assert_equal(401, $r['status'], "$path with key " . var_export($k, true) . ' was not refused');
                assert_equal(['error' => 'unauthorized'], $r['json'], 'wrong error body');
                assert_not_contains('Δοκιμή', $r['body'], 'refusal leaked data');
            }
        }
    });

    $runner->add('fetch by guid returns exactly the agreed fields', function () use ($baseUrl, $key, &$guid) {
        $r = zpms_medscribe_api_get($baseUrl, "/api/v1/patients/$guid", $key);
        assert_equal(200, $r['status'], 'fetch failed: ' . $r['body']);
        assert_equal(['patient' => ['guid' => $guid, 'name' => 'ΜedScribe Δοκιμή', 'dob' => '1980-03-15', 'gender' => 'F', 'amka' => '22222222222']],
            $r['json'], 'unexpected patient payload');
        foreach (['2100000000', 'Οδός', 'example.invalid', 'secret note'] as $private) {
            assert_not_contains($private, $r['body'], "phone/address/e-mail/note ($private) must not be disclosed");
        }
    });

    $runner->add('fetch: unknown and soft-deleted patients are 404, a malformed guid is 400', function () use ($baseUrl, $key, &$deletedGuid) {
        assert_equal(404, zpms_medscribe_api_get($baseUrl, '/api/v1/patients/cccccccc-3333-4333-8333-cccccccccccc', $key)['status'], 'unknown guid');
        $r = zpms_medscribe_api_get($baseUrl, "/api/v1/patients/$deletedGuid", $key);
        assert_equal(404, $r['status'], 'soft-deleted patient must not be served');
        assert_not_contains('Διαγραμμένος', $r['body'], 'deleted patient leaked');
        assert_equal(400, zpms_medscribe_api_get($baseUrl, '/api/v1/patients/not-a-guid', $key)['status'], 'malformed guid');
    });

    $runner->add('search accepts MedScribe\'s key and keeps its minimal fields', function () use ($baseUrl, $key, &$guid) {
        $r = zpms_medscribe_api_get($baseUrl, '/api/v1/patients/search/' . rawurlencode('Δοκιμή'), $key);
        assert_equal(200, $r['status'], 'search failed: ' . $r['body']);
        $names = array_column($r['json']['matches'], 'name');
        assert_true(in_array('ΜedScribe Δοκιμή', $names, true), 'the patient was not found');
        foreach ($r['json']['matches'] as $m) assert_equal(['guid', 'name', 'amka'], array_keys($m), 'search fields');
        assert_not_contains('Διαγραμμένος', $r['body'], 'search returned a deleted patient');
        assert_equal(400, zpms_medscribe_api_get($baseUrl, '/api/v1/patients/search/a', $key)['status'], 'one-character term');
    });

    $runner->add('the audit trail records the caller, never the patient', function () {
        $db = dbConnection::getConnection();
        $rows = $db->query("SELECT * FROM audit_log WHERE event_type IN ('patient_get_api','patient_search_api') AND cuser = 'medscribe'")->fetchAll();
        assert_true(count($rows) >= 2, 'no audit rows for the MedScribe calls');
        foreach ($rows as $row) {
            assert_equal('api', $row['actor_type'], 'actor_type');
            assert_not_contains('Δοκιμή', json_encode($row, JSON_UNESCAPED_UNICODE), 'the patient name is in the audit row');
        }
        $details = array_column($rows, 'detail');
        assert_true(in_array('found=1', $details, true) && in_array('found=0', $details, true), 'found=1 / found=0 details expected');
    });

    $runner->add('the patient page shows the "New document" button for MedScribe', function () use ($baseUrl, &$guid) {
        TestFixtures::createTestUser();
        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);
        $id = dbConnection::getConnection()->query("SELECT id FROM patients WHERE guid = '$guid'")->fetchColumn();
        $page = $http->get("/patient/$id/edit");
        assert_equal(200, $page['status'], 'patient page');
        assert_contains('https://medscribe.test/documents/new?patient=' . $guid, $page['body'], 'button link missing');
    });
}
