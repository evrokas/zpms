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
            // usersClassEx::getUser() matches on sha256(password) -- see
            // web/ClassesEx.php -- same hashing login_post() itself uses.
            'upass' => hash('sha256', self::PASSWORD),
            'active' => 1,
            'expired' => 0,
            'wrongpasscount' => 0,
            // Space-separated role string (SecurityClass::processRoles()) --
            // 'power-user' maps to a real permission array in
            // config/settings.info.yaml's roles: block (patients CRUD,
            // appointment-edit, backup access) and, unlike
            // 'administrator', is never passed anywhere that expects an
            // array where the config only supplies the string 'all'.
            'roles' => 'power-user',
        ]);
        $u->insert();
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
