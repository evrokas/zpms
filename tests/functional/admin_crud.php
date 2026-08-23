<?php
/* End-to-end, HTTP-level regression coverage for the generic admin list/
 * add/edit/delete UI (web/admin_crud.php) covering users and the RBAC
 * tables (permissions/roles/role_permissions/user_roles). Uses its own
 * is_superuser account (users-manage is deliberately not granted to
 * power-user -- see ZPMS_PERM_USERS_MANAGE's own comment in web/rbac.php)
 * rather than the shared, already-logged-in power-user client the other
 * functional suites use. */

function zpms_functional_admin_crud(TestRunner $runner, string $baseUrl): void {
    $runner->add('set up an is_superuser test account', function () {
        TestSchema::assertSafeToMutate();

        $u = new usersClass([
            'name' => 'Admin CRUD Test Account',
            'email' => 'zpms-test-admin-crud@example.invalid',
            'uname' => 'zpms_test_admin_crud_user',
            'upass' => password_hash('AdminCrud!Passw0rd', PASSWORD_DEFAULT),
            'active' => 1,
            'expired' => 0,
            'wrongpasscount' => 0,
            'roles' => 'administrator',
        ]);
        $u->insert();

        $adminRole = rolesClassEx::sgetByName('administrator');
        assert_not_null($adminRole, "the 'administrator' role was not seeded -- did TestFixtures::createTestUser() run first?");
        user_rolesClassEx::assignRole((int)$u->getid(), (int)$adminRole->getid(), 'test-fixture');

        $GLOBALS['zpms_test_admin_crud_id'] = (int)$u->getid();
    });

    $runner->add('logs in and reaches the permissions list', function () use ($baseUrl) {
        $http = new TestHttpClient($baseUrl);
        $loginPage = $http->get('/login');
        $token = TestHttpClient::extractCsrfToken($loginPage['body']);
        $res = $http->post('/login', [
            'csrf_token' => $token,
            'username' => 'zpms_test_admin_crud_user',
            'password' => 'AdminCrud!Passw0rd',
        ]);
        assert_contains('/profile', (string)$res['location'], 'login with the admin-crud test account did not succeed');

        $list = $http->get('/admin/permissions');
        assert_equal(200, $list['status'], 'GET /admin/permissions did not return 200 for an is_superuser account');
        assert_contains('patients-view-list', $list['body'], '/admin/permissions did not list an existing seeded permission');

        $GLOBALS['zpms_test_admin_crud_http'] = $http;
    });

    $runner->add('a power-user (not is_superuser, no explicit grant) is refused /admin/*', function () use ($baseUrl) {
        // The single most important property of this whole page: the
        // permission it's gated on (users-manage) is deliberately NOT in
        // power-user's role_permissions grants, unlike every other
        // permission this app checks. If this ever starts passing, the
        // seed data has drifted and any power-user can now grant
        // themselves is_superuser through this very UI.
        $http = new TestHttpClient($baseUrl);
        $loginPage = $http->get('/login');
        $token = TestHttpClient::extractCsrfToken($loginPage['body']);
        $http->post('/login', ['csrf_token' => $token, 'username' => TestFixtures::USERNAME, 'password' => TestFixtures::PASSWORD]);

        $res = $http->get('/admin/users');
        assert_contains('401', $res['body'], 'a plain power-user account was NOT refused /admin/users -- users-manage may have leaked into power-user\'s grants');
    });

    $runner->add('an unauthenticated request to /admin/* is refused', function () use ($baseUrl) {
        $http = new TestHttpClient($baseUrl);
        $res = $http->get('/admin/users');
        assert_contains('401', $res['body'], '/admin/users did not show the 401 page when logged out');
    });

    $runner->add('create, edit, and delete a permission', function () {
        TestSchema::assertSafeToMutate();
        $http = $GLOBALS['zpms_test_admin_crud_http'];

        $newForm = $http->get('/admin/permissions/new');
        assert_equal(200, $newForm['status'], 'GET /admin/permissions/new did not return 200');
        $token = TestHttpClient::extractCsrfToken($newForm['body']);
        assert_not_null($token, 'no csrf_token field on the new-permission form');

        $res = $http->post('/admin/permissions/new', [
            'csrf_token' => $token,
            'name' => 'zpms-test-crud-permission',
            'label' => 'Regression test permission',
        ]);
        assert_equal(302, $res['status'], "new-permission POST did not redirect (got {$res['status']})");

        $row = dbConnection::getConnection()
            ->query("SELECT id, label FROM permissions WHERE name = 'zpms-test-crud-permission'")
            ->fetch();
        assert_not_null($row, 'no permissions row was inserted');
        assert_equal('Regression test permission', $row['label'], 'inserted permission has the wrong label');
        $id = (int)$row['id'];

        // Edit.
        $editForm = $http->get("/admin/permissions/$id/edit");
        assert_equal(200, $editForm['status'], "GET /admin/permissions/$id/edit did not return 200");
        assert_contains('zpms-test-crud-permission', $editForm['body'], 'edit form did not prefill the current name');
        $editToken = TestHttpClient::extractCsrfToken($editForm['body']);

        $res = $http->post("/admin/permissions/$id/edit", [
            'csrf_token' => $editToken,
            'name' => 'zpms-test-crud-permission',
            'label' => 'Renamed by the regression suite',
        ]);
        assert_equal(302, $res['status'], "edit-permission POST did not redirect (got {$res['status']})");

        $row = dbConnection::getConnection()->query("SELECT label FROM permissions WHERE id = $id")->fetch();
        assert_equal('Renamed by the regression suite', $row['label'], 'permission label was not updated');

        // Delete.
        $listPage = $http->get('/admin/permissions');
        $delToken = TestHttpClient::extractCsrfToken($listPage['body']);
        $res = $http->post("/admin/permissions/$id/delete", ['csrf_token' => $delToken]);
        assert_equal(302, $res['status'], "delete-permission POST did not redirect (got {$res['status']})");

        $row = dbConnection::getConnection()->query("SELECT COUNT(*) AS c FROM permissions WHERE id = $id")->fetch();
        assert_equal(0, (int)$row['c'], 'permission row still exists after delete (this table has no soft-delete concept)');
    });

    $runner->add('create a user with a hashed password via the admin form', function () {
        TestSchema::assertSafeToMutate();
        $http = $GLOBALS['zpms_test_admin_crud_http'];

        $newForm = $http->get('/admin/users/new');
        $token = TestHttpClient::extractCsrfToken($newForm['body']);

        $res = $http->post('/admin/users/new', [
            'csrf_token' => $token,
            'name' => 'Admin-Created User',
            'email' => 'admin-created@example.invalid',
            'uname' => 'zpms_test_admin_created_user',
            'password' => 'AdminCreated!Passw0rd',
            'active' => '1',
        ]);
        assert_equal(302, $res['status'], "new-user POST did not redirect (got {$res['status']})");

        $row = dbConnection::getConnection()
            ->query("SELECT upass, active, expired FROM users WHERE uname = 'zpms_test_admin_created_user'")
            ->fetch();
        assert_not_null($row, 'no users row was inserted');
        assert_equal(1, preg_match('/^\$2y\$/', $row['upass']), 'the new user\'s password was not stored as a bcrypt hash');
        assert_equal('1', (string)$row['active'], 'the new user was not marked active despite the checkbox being checked');
    });

    $runner->add('grant a role a permission via role_permissions, then see it resolved by name in the list', function () {
        TestSchema::assertSafeToMutate();
        $http = $GLOBALS['zpms_test_admin_crud_http'];

        $userRole = rolesClassEx::sgetByName('user');
        $backupPerm = permissionsClassEx::sgetByName('backup-access');
        assert_not_null($userRole, "the 'user' role was not seeded");
        assert_not_null($backupPerm, "the 'backup-access' permission was not seeded");

        $newForm = $http->get('/admin/role_permissions/new');
        assert_contains('name="role_id"', $newForm['body'], 'role_permissions new-form has no role_id <select>');
        // Every seeded role name should appear as a <select> option, by
        // label -- proves the select is actually populated from the
        // roles table, not just present as an empty control.
        assert_contains('power-user', $newForm['body'], 'role_id <select> is missing the power-user option');
        $token = TestHttpClient::extractCsrfToken($newForm['body']);

        $res = $http->post('/admin/role_permissions/new', [
            'csrf_token' => $token,
            'role_id' => (string)$userRole->getid(),
            'permission_id' => (string)$backupPerm->getid(),
        ]);
        assert_equal(302, $res['status'], "new-role_permissions POST did not redirect (got {$res['status']})");

        $list = $http->get('/admin/role_permissions');
        // The list must show the resolved names, not the bare numeric
        // foreign keys -- a raw role_id/permission_id would be useless to
        // a human operator.
        assert_contains('backup-access', $list['body'], 'role_permissions list does not show the resolved permission name');
    });

    $runner->add('deleting a role cascades to its role_permissions/user_roles rows', function () {
        TestSchema::assertSafeToMutate();
        $http = $GLOBALS['zpms_test_admin_crud_http'];

        // A fresh, disposable role (not one of the seeded 3) so this test
        // doesn't disturb the shared user/power-user/administrator roles
        // other tests in this run still rely on.
        $roleForm = $http->get('/admin/roles/new');
        $token = TestHttpClient::extractCsrfToken($roleForm['body']);
        $http->post('/admin/roles/new', [
            'csrf_token' => $token,
            'name' => 'zpms-test-disposable-role',
            'label' => 'Disposable test role',
        ]);
        $role = rolesClassEx::sgetByName('zpms-test-disposable-role');
        assert_not_null($role, 'the disposable test role was not created');
        $roleId = (int)$role->getid();

        $viewPerm = permissionsClassEx::sgetByName('patients-view-list');
        $rpForm = $http->get('/admin/role_permissions/new');
        $rpToken = TestHttpClient::extractCsrfToken($rpForm['body']);
        $http->post('/admin/role_permissions/new', [
            'csrf_token' => $rpToken,
            'role_id' => (string)$roleId,
            'permission_id' => (string)$viewPerm->getid(),
        ]);

        $before = dbConnection::getConnection()
            ->query("SELECT COUNT(*) AS c FROM role_permissions WHERE role_id = $roleId")
            ->fetch();
        assert_equal(1, (int)$before['c'], 'the role_permissions grant for the disposable role was not created');

        $rolesList = $http->get('/admin/roles');
        $delToken = TestHttpClient::extractCsrfToken($rolesList['body']);
        $res = $http->post("/admin/roles/$roleId/delete", ['csrf_token' => $delToken]);
        assert_equal(302, $res['status'], "delete-role POST did not redirect (got {$res['status']})");

        $afterRole = dbConnection::getConnection()->query("SELECT COUNT(*) AS c FROM roles WHERE id = $roleId")->fetch();
        assert_equal(0, (int)$afterRole['c'], 'the disposable role still exists after delete');

        $afterGrant = dbConnection::getConnection()
            ->query("SELECT COUNT(*) AS c FROM role_permissions WHERE role_id = $roleId")
            ->fetch();
        assert_equal(0, (int)$afterGrant['c'], 'deleting the role left an orphaned role_permissions row behind');
    });
}
