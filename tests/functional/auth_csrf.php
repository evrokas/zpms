<?php
/* Login/session and CSRF-enforcement regression coverage. Uses its own
 * TestHttpClient instances (not the shared, already-logged-in one the
 * other functional suites use) so it can freely exercise both the
 * logged-out and logged-in states without disturbing them. */

function zpms_functional_auth_csrf(TestRunner $runner, string $baseUrl): void {
    $runner->add('an unauthenticated request to a protected page is refused', function () use ($baseUrl) {
        $http = new TestHttpClient($baseUrl);
        $res = $http->get('/patients');
        // patients_list carries a route-level `access:` (config/settings.info.yaml),
        // so this goes through Router.php's own 401 handling, not a
        // handler-internal rbacClass::require() call -- and
        // SecurityClass::enableLoginRedirect('/login') (web/index.php)
        // turns that into a redirect straight to /login rather than the
        // inline error_401() page every *handler-checked* permission
        // failure (e.g. /apps/edit_clinics below) still renders.
        assert_contains('/login', (string)$res['location'], '/patients did not redirect to /login when logged out');
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
        // webforms_post also carries a route-level `access:` -- same
        // Router.php redirect-to-/login path as the /patients case above.
        assert_contains('/login', (string)$res['location'], 'an unauthenticated POST to /webform/processform/... was not refused');
    });

    $runner->add('a logged-in secretary account can view but not modify/add/remove a patient', function () use ($baseUrl) {
        // The actual bug this whole system replaces: SecurityClass::require()
        // (zeusfw core) special-cases any role literally named
        // 'authenticated' as an unconditional pass for ANY permission, and
        // Kernel::loginUser() unconditionally adds that role to every
        // logged-in user's session -- so in the old system, EVERY
        // permission check silently passed for ANY logged-in user
        // regardless of their actual role. rbacClass::require() (zeusfw
        // core/lib/Rbac.php) has no such bypass; this proves it against
        // the 'secretary' role (patients-view-list +
        // pending-appointments-manage only -- see web/rbac_seed.php),
        // which is deliberately the narrowest non-superuser role with any
        // patient-data access at all.
        TestSchema::assertSafeToMutate();
        TestFixtures::createSecretaryUser();

        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsSecretary($http);

        // Can view the patient list (patients-view-list, granted).
        $listPage = $http->get('/patients');
        assert_equal(200, $listPage['status'], 'secretary account could not view the patient list');
        assert_not_contains('401', $listPage['body'], 'secretary account was refused a permission it does have');
        // The "Add new patient" button and per-row delete forms are
        // patients-new-patient/patients-delete-patient gated -- neither is
        // granted to secretary, so neither control should even render
        // (see patients_list.zetem).
        assert_not_contains('btn-add-patient', $listPage['body'], 'secretary account saw the "Add new patient" button');
        assert_not_contains('inline-delete-form', $listPage['body'], 'secretary account saw a per-row delete form');

        // Cannot reach clinics management (settings-manage, not granted).
        $settingsPage = $http->get('/apps/edit_clinics');
        assert_contains('401', $settingsPage['body'], 'secretary account was NOT refused settings-manage -- the permission bypass bug is back');

        // Set up a throwaway patient via a direct insert (not through this
        // account, which can't create one either) to exercise the
        // read-only patient page and the delete refusal below.
        $p = new patientsClass([
            'guid' => guid(), 'cuser' => 'test-fixture', 'cdate' => getDBtime(),
            'pname' => 'Ασθενής Για Δικαιώματα', 'pdob' => '1990-01-01 00:00:00',
            'pamka' => '44444444444', 'ptel' => '', 'paddr' => '', 'pemail' => '', 'pnote' => '',
        ]);
        $p->insert();

        // Can OPEN the patient's page read-only (patients-view-list also
        // covers this -- see ZPMS_PERM_PATIENTS_VIEW_LIST's own docblock
        // in web/rbac.php), but the page renders with no save controls
        // and every field disabled.
        $editPage = $http->get('/patient/' . $p->getid() . '/edit');
        assert_equal(200, $editPage['status'], 'secretary account could not open the patient page read-only');
        assert_not_contains('401', $editPage['body'], 'secretary account was refused patients-view-list on an individual patient page');
        assert_contains('Ασθενής Για Δικαιώματα', $editPage['body'], "the patient's name did not render on the read-only page");
        assert_contains('<fieldset disabled', $editPage['body'], 'the patient page did not render with a disabled fieldset for a view-only account');
        assert_not_contains('name="submit" value="Αποθήκευση"', $editPage['body'], 'secretary account saw a save button on the read-only patient page');

        // Cannot actually save a change (patients-edit-patient not
        // granted) -- even a crafted POST with a valid token is refused.
        $editToken = TestHttpClient::extractCsrfToken($editPage['body']);
        assert_not_null($editToken, 'no csrf_token field found on the read-only patient page');
        $saveRes = $http->post('/patient/' . $p->getid() . '/edit', [
            'csrf_token' => $editToken,
            'submit' => '1',
            'patient-name' => 'Tampered Name',
            'patient-dob' => '1990-01-01',
            'patient-amka' => '44444444444',
            'patient-telephone' => '', 'patient-address' => '', 'patient-email' => '', 'patient-note' => '',
        ]);
        assert_contains('401', $saveRes['body'], 'secretary account was NOT refused patients-edit-patient on a crafted POST -- the permission bypass bug is back');
        $unchanged = dbConnection::getConnection()
            ->query('SELECT pname FROM patients WHERE id = ' . $p->getid())
            ->fetch();
        assert_equal('Ασθενής Για Δικαιώματα', $unchanged['pname'], 'the patient was renamed despite the acting account lacking patients-edit-patient');

        // Cannot delete a patient (patients-delete-patient, not granted).
        $delRes = $http->post('/patient/' . $p->getid() . '/delete', ['csrf_token' => $editToken]);
        assert_contains('401', $delRes['body'], 'secretary account was NOT refused patients-delete-patient -- the permission bypass bug is back');

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
            'roles' => 'doctor',
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

    $runner->add('the topbar user block shows the account name and username', function () use ($baseUrl) {
        // web/templates/modules/userblock.zetem overrides zeusfw core's
        // own core/templates/modules/userblock.zetem (same filename,
        // scanned after core's per config/settings.info.yaml's
        // templates: list -- see that file's own docblock) so this app
        // shows the real Όνομα (users.name) next to the bare username the
        // framework module renders on its own, without any change to
        // zeusfw core or any other app on it.
        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);

        $profile = $http->get('/patients');
        assert_contains('user-block', $profile['body'], 'no .user-block rendered for a logged-in request');
        assert_contains(TestFixtures::USERNAME, $profile['body'], 'user-block does not show the bare username');
        // The account name sits inside its own <a href="/profile">...</a>
        // (see web/templates/modules/userblock.zetem), so the rendered
        // fragment reads "...>ZPMS Test User</a> (zpms_test_user)" rather
        // than the two pieces being directly adjacent text.
        assert_contains('>ZPMS Test User</a> (' . TestFixtures::USERNAME . ')', $profile['body'], 'user-block does not show "name (username)"');
    });

    $runner->add('a logged-in doctor can still reach clinics management', function () use ($baseUrl) {
        // Confirms settings-manage (granted to doctor, see
        // config/settings.info.yaml) preserves existing access -- this is
        // a permission-scoping fix, not a lockout of current staff.
        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);

        $res = $http->get('/apps/edit_clinics');
        assert_equal(200, $res['status'], 'GET /apps/edit_clinics did not return 200 for a logged-in doctor');
        assert_not_contains('401', $res['body'], '/apps/edit_clinics still shows the 401 page for a logged-in doctor');
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
