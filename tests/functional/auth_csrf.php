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

    $runner->add('an unauthenticated request to the clinics management route is refused', function () use ($baseUrl) {
        // clinics_edit() (web/index.php) used to have no SecurityClass::require()
        // call at all -- a logged-out visitor who knew the URL got the
        // clinics management form directly. Now gated behind settings-manage.
        $http = new TestHttpClient($baseUrl);
        $res = $http->get('/apps/edit_clinics');
        assert_contains('401', $res['body'], '/apps/edit_clinics did not show the 401/unauthorized page when logged out');
    });

    $runner->add('an unauthenticated POST to the webform processor is refused', function () use ($baseUrl) {
        // formsClass::processform() (zeusfw core) has no permission check
        // of its own -- the route-level access: on webforms_post
        // (config/settings.info.yaml) is what actually protects the
        // clinics/doctors reference-data forms it's used for in this app.
        $http = new TestHttpClient($baseUrl);
        $res = $http->post('/webform/processform/anything', ['submit' => '1']);
        assert_contains('401', $res['body'], 'an unauthenticated POST to /webform/processform/... was not refused');
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

    $runner->add('a successful login upgrades a legacy sha256 password hash to bcrypt', function () use ($baseUrl) {
        TestSchema::assertSafeToMutate();

        // A second, single-purpose account (not TestFixtures::USERNAME --
        // that one is already logged in, and thus already upgraded, by the
        // shared $http client run_all.php sets up before any suite runs).
        // Seeds upass as a raw sha256 hex digest (the old storage format)
        // to confirm login_post() (zeusfw core) both accepts it and
        // transparently rehashes it in place, so every account migrates
        // off the weak format the first time its owner logs in, with no
        // separate migration step required.
        $uname = 'zpms_test_legacy_hash_user';
        $password = 'LegacyHash!Passw0rd';
        $u = new usersClass([
            'name' => 'Legacy Hash Test User',
            'email' => 'zpms-test-legacy@example.invalid',
            'uname' => $uname,
            'upass' => hash('sha256', $password),
            'active' => 1,
            'expired' => 0,
            'wrongpasscount' => 0,
            'roles' => 'power-user',
        ]);
        $u->insert();

        $before = dbConnection::getConnection()
            ->query("SELECT upass FROM users WHERE uname = '$uname'")
            ->fetch();
        assert_equal(1, preg_match('/^[a-f0-9]{64}$/', $before['upass']), 'fixture user did not start on the legacy sha256 format');

        $http = new TestHttpClient($baseUrl);
        $loginPage = $http->get('/login');
        $token = TestHttpClient::extractCsrfToken($loginPage['body']);
        $res = $http->post('/login', ['csrf_token' => $token, 'username' => $uname, 'password' => $password]);
        assert_contains('/profile', (string)$res['location'], 'login with the legacy-hash account did not succeed');

        $after = dbConnection::getConnection()
            ->query("SELECT upass FROM users WHERE uname = '$uname'")
            ->fetch();
        assert_equal(1, preg_match('/^\$2y\$/', $after['upass']), 'upass was not rehashed to bcrypt after a successful login');
        assert_not_equal($before['upass'], $after['upass'], 'upass is unchanged after login');
    });

    $runner->add('login with correct credentials establishes a session', function () use ($baseUrl) {
        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);

        $profile = $http->get('/patients');
        assert_equal(200, $profile['status'], 'GET /patients did not return 200 after login');
        assert_not_contains('401', $profile['body'], '/patients still shows the 401 page after a successful login');
    });

    $runner->add('a logged-in power-user can still reach clinics management', function () use ($baseUrl) {
        // Confirms settings-manage (granted to power-user, see
        // config/settings.info.yaml) preserves existing access -- this is
        // a permission-scoping fix, not a lockout of current staff.
        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);

        $res = $http->get('/apps/edit_clinics');
        assert_equal(200, $res['status'], 'GET /apps/edit_clinics did not return 200 for a logged-in power-user');
        assert_not_contains('401', $res['body'], '/apps/edit_clinics still shows the 401 page for a logged-in power-user');
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
