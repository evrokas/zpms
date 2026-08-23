<?php
/* Login/session and CSRF-enforcement regression coverage. Uses its own
 * TestHttpClient instances (not the shared, already-logged-in one the
 * other functional suites use) so it can freely exercise both the
 * logged-out and logged-in states without disturbing them. */

function zpms_functional_auth_csrf(TestRunner $runner, string $baseUrl): void {
    $runner->add('an unauthenticated request to a protected page is refused', function () use ($baseUrl) {
        $http = new TestHttpClient($baseUrl);
        $res = $http->get('/patients');
        // error_401() renders inline (200 OK carrying the 401 page's
        // content) rather than sending a real HTTP 401 or redirecting --
        // see core/router/ErrorHandlers.php -- so the regression signal is
        // the rendered content, not the status line.
        assert_contains('401', $res['body'], '/patients did not show the 401/unauthorized page when logged out');
        assert_not_contains('Δοκιμαστική', $res['body'], '/patients leaked patient data to a logged-out request');
    });

    $runner->add('login with the wrong password is rejected', function () use ($baseUrl) {
        $http = new TestHttpClient($baseUrl);
        $loginPage = $http->get('/login');
        $token = TestHttpClient::extractCsrfToken($loginPage['body']);
        assert_not_null($token, 'no csrf_token field on the login page');

        $res = $http->post('/login', [
            'csrf_token' => $token,
            'username' => TestFixtures::USERNAME,
            'password' => 'definitely-the-wrong-password',
        ]);
        assert_not_equal('/profile', (string)$res['location'], 'login succeeded with a wrong password');
    });

    $runner->add('login with correct credentials establishes a session', function () use ($baseUrl) {
        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);

        $profile = $http->get('/patients');
        assert_equal(200, $profile['status'], 'GET /patients did not return 200 after login');
        assert_not_contains('401', $profile['body'], '/patients still shows the 401 page after a successful login');
    });

    $runner->add('a POST without a CSRF token is rejected and does not write data', function () use ($baseUrl) {
        TestSchema::assertSafeToMutate();

        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);

        // Deliberately omit csrf_token entirely.
        $res = $http->post('/patient/new', [
            'submit' => '1',
            'patient-name' => 'CSRF Bypass Attempt',
            'patient-dob' => '1990-01-01',
            'patient-amka' => '99999999999',
            'patient-telephone' => '',
            'patient-address' => '',
            'patient-email' => '',
            'patient-note' => '',
        ]);

        assert_not_equal('/patients', (string)$res['location'], 'the request without a CSRF token was accepted as if it succeeded');

        $row = dbConnection::getConnection()
            ->query("SELECT COUNT(*) AS c FROM patients WHERE pamka = '99999999999'")
            ->fetch();
        assert_equal(0, (int)$row['c'], 'a patient was inserted despite a missing CSRF token');
    });

    $runner->add('a POST with a tampered CSRF token is rejected', function () use ($baseUrl) {
        TestSchema::assertSafeToMutate();

        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);

        $res = $http->post('/patient/new', [
            'csrf_token' => 'not-the-real-token-0000000000000000000000000000000000000000000',
            'submit' => '1',
            'patient-name' => 'CSRF Bypass Attempt 2',
            'patient-dob' => '1990-01-01',
            'patient-amka' => '88888888888',
            'patient-telephone' => '',
            'patient-address' => '',
            'patient-email' => '',
            'patient-note' => '',
        ]);

        assert_not_equal('/patients', (string)$res['location'], 'the request with a tampered CSRF token was accepted as if it succeeded');

        $row = dbConnection::getConnection()
            ->query("SELECT COUNT(*) AS c FROM patients WHERE pamka = '88888888888'")
            ->fetch();
        assert_equal(0, (int)$row['c'], 'a patient was inserted despite a tampered CSRF token');
    });
}
