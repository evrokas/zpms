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

    $runner->add('a logged-in account with the plain user role cannot delete a patient', function () use ($baseUrl) {
        // The actual bug this whole system replaces: SecurityClass::require()
        // (zeusfw core) special-cases any role literally named
        // 'authenticated' as an unconditional pass for ANY permission, and
        // Kernel::loginUser() unconditionally adds that role to every
        // logged-in user's session -- so in the old system, EVERY
        // permission check silently passed for ANY logged-in user
        // regardless of their actual role. rbacClass::require() (zeusfw
        // core/lib/Rbac.php) has no such bypass; this proves it by
        // creating an account with only the 'user' role
        // (patients-view-list only, no delete) and confirming it's
        // actually refused.
        TestSchema::assertSafeToMutate();

        $uname = 'zpms_test_plain_user';
        $password = 'PlainUser!Passw0rd';
        $u = new usersClass([
            'name' => 'Plain User Test Account',
            'email' => 'zpms-test-plain-user@example.invalid',
            'uname' => $uname,
            'upass' => password_hash($password, PASSWORD_DEFAULT),
            'active' => 1,
            'expired' => 0,
            'wrongpasscount' => 0,
            'roles' => 'user',
        ]);
        $u->insert();

        $userRole = rolesClassEx::sgetByName('user');
        assert_not_null($userRole, "the 'user' role was not seeded -- did TestFixtures::createTestUser() run first?");
        user_rolesClassEx::assignRole((int)$u->getid(), (int)$userRole->getid(), 'test-fixture');

        $http = new TestHttpClient($baseUrl);
        $loginPage = $http->get('/login');
        $token = TestHttpClient::extractCsrfToken($loginPage['body']);
        $loginRes = $http->post('/login', ['csrf_token' => $token, 'username' => $uname, 'password' => $password]);
        assert_contains('/profile', (string)$loginRes['location'], 'login with the plain-user account did not succeed');

        // Can still view the patient list (patients-view-list, granted).
        $listPage = $http->get('/patients');
        assert_equal(200, $listPage['status'], 'plain-user account could not view the patient list');
        assert_not_contains('401', $listPage['body'], 'plain-user account was refused a permission it does have');

        // Cannot reach clinics management (settings-manage, not granted).
        $settingsPage = $http->get('/apps/edit_clinics');
        assert_contains('401', $settingsPage['body'], 'plain-user account was NOT refused settings-manage -- the permission bypass bug is back');

        // Cannot delete a patient (patients-delete-patient, not granted) --
        // set up a throwaway patient via a power-user-equivalent direct
        // insert (not through this account, which can't create one either).
        $p = new patientsClass([
            'guid' => guid(), 'cuser' => 'test-fixture', 'cdate' => getDBtime(),
            'pname' => 'Ασθενής Για Δικαιώματα', 'pdob' => '1990-01-01 00:00:00',
            'pamka' => '44444444444', 'ptel' => '', 'paddr' => '', 'pemail' => '', 'pnote' => '',
        ]);
        $p->insert();

        // The token itself is session-wide, not tied to a specific page --
        // /patient/{id}/edit would be the natural place to scrape one from,
        // but this account can't reach it (patients-edit-patient isn't
        // granted either), so /patients (already confirmed reachable above)
        // supplies an equally valid token.
        $editToken = TestHttpClient::extractCsrfToken($listPage['body']);
        assert_not_null($editToken, 'no csrf_token field found on /patients (as seen by the plain-user account)');

        $delRes = $http->post('/patient/' . $p->getid() . '/delete', ['csrf_token' => $editToken]);
        assert_contains('401', $delRes['body'], 'plain-user account was NOT refused patients-delete-patient -- the permission bypass bug is back');

        $row = dbConnection::getConnection()
            ->query('SELECT deleted FROM patients WHERE id = ' . $p->getid())
            ->fetch();
        assert_null($row['deleted'], 'the patient was deleted despite the acting account lacking patients-delete-patient');
    });

    $runner->add('a logged-in administrator (is_superuser) account passes every permission check', function () use ($baseUrl) {
        // The old system's 'administrator: all' config value crashed with a
        // fatal TypeError the moment any permission check actually reached
        // it (in_array() against the literal string "all" -- confirmed by
        // direct test; see web/rbac.php's docblock) -- so no account with
        // that role could ever function. is_superuser (roles.is_superuser)
        // is the correctly-implemented replacement: confirms it grants
        // access to a permission-gated page (settings-manage) without
        // crashing and without needing any role_permissions rows at all.
        TestSchema::assertSafeToMutate();

        $uname = 'zpms_test_admin_user';
        $password = 'AdminUser!Passw0rd';
        $u = new usersClass([
            'name' => 'Admin Test Account',
            'email' => 'zpms-test-admin@example.invalid',
            'uname' => $uname,
            'upass' => password_hash($password, PASSWORD_DEFAULT),
            'active' => 1,
            'expired' => 0,
            'wrongpasscount' => 0,
            'roles' => 'administrator',
        ]);
        $u->insert();

        $adminRole = rolesClassEx::sgetByName('administrator');
        assert_not_null($adminRole, "the 'administrator' role was not seeded -- did TestFixtures::createTestUser() run first?");
        assert_equal(1, (int)$adminRole->getis_superuser(), "the 'administrator' role is not marked is_superuser");
        user_rolesClassEx::assignRole((int)$u->getid(), (int)$adminRole->getid(), 'test-fixture');

        $http = new TestHttpClient($baseUrl);
        $loginPage = $http->get('/login');
        $token = TestHttpClient::extractCsrfToken($loginPage['body']);
        $loginRes = $http->post('/login', ['csrf_token' => $token, 'username' => $uname, 'password' => $password]);
        assert_contains('/profile', (string)$loginRes['location'], 'login with the administrator account did not succeed');

        $settingsPage = $http->get('/apps/edit_clinics');
        assert_equal(200, $settingsPage['status'], 'administrator account did not get 200 from /apps/edit_clinics');
        assert_not_contains('401', $settingsPage['body'], 'administrator (is_superuser) account was refused settings-manage');
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
