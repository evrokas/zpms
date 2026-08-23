<?php
/* Shared setup helpers for the functional (HTTP-level) test suites --
 * creating a login-capable test user (there's no user-management UI to
 * drive for this, so it goes directly through the entity class) and
 * logging that user in through the real HTTP login form, exactly the way
 * a person would. */
class TestFixtures {
    const USERNAME = 'zpms_test_user';
    const PASSWORD = 'ZpmsTest!Passw0rd';

    /** Inserts a `power-user` test account directly (no admin UI exists to create one via HTTP). */
    static function createTestUser(): void {
        TestSchema::assertSafeToMutate();

        $u = new usersClass([
            'name' => 'ZPMS Test User',
            'email' => 'zpms-test@example.invalid',
            'uname' => self::USERNAME,
            // Legacy unsalted-sha256 format -- login_post() (zeusfw core)
            // accepts this format and transparently rehashes to bcrypt on
            // first successful login, so this also exercises that upgrade
            // path every time the suite runs.
            'upass' => hash('sha256', self::PASSWORD),
            'active' => 1,
            'expired' => 0,
            'wrongpasscount' => 0,
            // This legacy column is no longer what actually grants
            // permissions (see web/rbac.php) -- kept populated anyway so
            // this fixture also exercises zeusfw_app_resolve_user_roles()'s
            // fallback path and so a from-scratch bin/migrate_roles.php
            // run against this same test database has something real to
            // migrate. The real grant is the user_roles row assigned below.
            'roles' => 'power-user',
        ]);
        $u->insert();

        // Same seeding bin/migrate_roles.php runs against a real deploy --
        // idempotent, so calling it here on every test run is harmless.
        // zpms_require_permission() (web/rbac.php) checks user_roles
        // directly, never the legacy column above, so without this line
        // every permission check in the suite would fail regardless of
        // that column's value.
        $seeded = zpms_seed_permissions_and_roles(false, function () {});
        user_rolesClassEx::assignRole((int)$u->getid(), $seeded['roleIdsByName']['power-user'], 'test-fixture');
    }

    /** Logs in through the real /login form (scrapes the CSRF token like a browser would) and returns the client. */
    static function loginAsTestUser(TestHttpClient $http): void {
        $loginPage = $http->get('/login');
        assert_equal(200, $loginPage['status'], 'GET /login did not return 200');

        $token = TestHttpClient::extractCsrfToken($loginPage['body']);
        assert_not_null($token, 'could not find a csrf_token field on the login page');

        $res = $http->post('/login', [
            'csrf_token' => $token,
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
        ]);
        assert_equal(302, $res['status'], "login POST did not redirect (got {$res['status']}) -- body:\n" . $res['body']);
        assert_contains('/profile', (string)$res['location'], 'login did not redirect to /profile');
    }
}
