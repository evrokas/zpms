<?php
/* End-to-end, HTTP-level regression coverage for the generic admin list/
 * add/edit/delete UI (zeusfw core's core/modules/admin/admin_crud.php,
 * framework-level as of the RBAC-engine migration -- see zeusfw's own
 * CLAUDE.md entry) covering users and the RBAC tables (permissions/roles/
 * role_permissions/user_roles). Uses its own is_superuser account
 * (users-manage, i.e. ZEUSFW_PERM_MANAGE_USERS, is deliberately not
 * granted to doctor -- see web/rbac.php's own comment) rather than
 * the shared, already-logged-in doctor client the other functional
 * suites use. */

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

    $runner->add('a doctor (not is_superuser, no explicit grant) is refused /admin/*', function () use ($baseUrl) {
        // The single most important property of this whole page: the
        // permission it's gated on (users-manage) is deliberately NOT in
        // doctor's role_permissions grants, unlike every other
        // permission this app checks. If this ever starts passing, the
        // seed data has drifted and any doctor can now grant
        // themselves is_superuser through this very UI.
        $http = new TestHttpClient($baseUrl);
        $loginPage = $http->get('/login');
        $token = TestHttpClient::extractCsrfToken($loginPage['body']);
        $http->post('/login', ['csrf_token' => $token, 'username' => TestFixtures::USERNAME, 'password' => TestFixtures::PASSWORD]);

        $res = $http->get('/admin/users');
        assert_contains('401', $res['body'], 'a plain doctor account was NOT refused /admin/users -- users-manage may have leaked into doctor\'s grants');
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

    $runner->add('assign and unassign roles for a user via the Roles checklist on the edit form', function () {
        TestSchema::assertSafeToMutate();
        $http = $GLOBALS['zpms_test_admin_crud_http'];

        // Reuses the account the previous test just created via the admin
        // form -- it has no user_roles rows yet, so this exercises the
        // checklist's "starts fully unchecked" path too.
        $row = dbConnection::getConnection()
            ->query("SELECT id FROM users WHERE uname = 'zpms_test_admin_created_user'")
            ->fetch();
        assert_not_null($row, 'zpms_test_admin_created_user was not found -- did the previous test run first?');
        $userId = (int)$row['id'];

        $doctorRole = rolesClassEx::sgetByName('doctor');
        $secretaryRole = rolesClassEx::sgetByName('secretary');
        assert_not_null($doctorRole, "the 'doctor' role was not seeded");
        assert_not_null($secretaryRole, "the 'secretary' role was not seeded");

        $editForm = $http->get("/admin/users/$userId/edit");
        assert_equal(200, $editForm['status'], "GET /admin/users/$userId/edit did not return 200");
        assert_contains('name="roles[]"', $editForm['body'], 'edit form has no Roles checklist');
        assert_contains('doctor', $editForm['body'], 'Roles checklist is missing the doctor role');
        assert_contains('secretary', $editForm['body'], 'Roles checklist is missing the secretary role');
        // A fresh user has no roles yet -- neither checkbox should be
        // pre-checked.
        assert_equal(0, preg_match('/name="roles\[\]"\s+value="' . $doctorRole->getid() . '"\s+checked/', $editForm['body']), 'a brand-new user\'s doctor checkbox was pre-checked');

        $token = TestHttpClient::extractCsrfToken($editForm['body']);

        // Check both roles.
        $res = $http->post("/admin/users/$userId/edit", [
            'csrf_token' => $token,
            'name' => 'Admin-Created User',
            'email' => 'admin-created@example.invalid',
            'uname' => 'zpms_test_admin_created_user',
            'active' => '1',
            'roles' => [(string)$doctorRole->getid(), (string)$secretaryRole->getid()],
        ]);
        assert_equal(302, $res['status'], "edit-user POST (assign 2 roles) did not redirect (got {$res['status']})");

        $assigned = dbConnection::getConnection()
            ->query("SELECT role_id FROM user_roles WHERE user_id = $userId ORDER BY role_id")
            ->fetchAll(PDO::FETCH_COLUMN);
        sort($assigned);
        $expected = [(int)$doctorRole->getid(), (int)$secretaryRole->getid()];
        sort($expected);
        assert_equal(json_encode($expected), json_encode(array_map('intval', $assigned)), 'user_roles does not hold exactly the 2 submitted roles');

        // Re-open the edit form -- both boxes must now render checked.
        $editForm2 = $http->get("/admin/users/$userId/edit");
        assert_equal(1, preg_match('/name="roles\[\]"\s+value="' . $doctorRole->getid() . '"\s+checked/', $editForm2['body']), 'doctor checkbox was not re-rendered as checked after assignment');
        assert_equal(1, preg_match('/name="roles\[\]"\s+value="' . $secretaryRole->getid() . '"\s+checked/', $editForm2['body']), 'secretary checkbox was not re-rendered as checked after assignment');
        $token2 = TestHttpClient::extractCsrfToken($editForm2['body']);

        // Uncheck secretary (submit doctor only) -- must remove exactly
        // the secretary row and leave doctor alone.
        $res = $http->post("/admin/users/$userId/edit", [
            'csrf_token' => $token2,
            'name' => 'Admin-Created User',
            'email' => 'admin-created@example.invalid',
            'uname' => 'zpms_test_admin_created_user',
            'active' => '1',
            'roles' => [(string)$doctorRole->getid()],
        ]);
        assert_equal(302, $res['status'], "edit-user POST (unassign 1 role) did not redirect (got {$res['status']})");

        $assigned = dbConnection::getConnection()
            ->query("SELECT role_id FROM user_roles WHERE user_id = $userId")
            ->fetchAll(PDO::FETCH_COLUMN);
        assert_equal(json_encode([(int)$doctorRole->getid()]), json_encode(array_map('intval', $assigned)), 'unassigning secretary did not leave exactly [doctor] in user_roles');

        // Submit with no 'roles' field at all (as if every box were
        // unchecked) -- must clear every remaining assignment, matching
        // this feature's documented "no special-case self-protection,
        // extends admin_delete()'s own trust model" design.
        $editForm3 = $http->get("/admin/users/$userId/edit");
        $token3 = TestHttpClient::extractCsrfToken($editForm3['body']);
        $res = $http->post("/admin/users/$userId/edit", [
            'csrf_token' => $token3,
            'name' => 'Admin-Created User',
            'email' => 'admin-created@example.invalid',
            'uname' => 'zpms_test_admin_created_user',
            'active' => '1',
        ]);
        assert_equal(302, $res['status'], "edit-user POST (clear all roles) did not redirect (got {$res['status']})");

        $count = dbConnection::getConnection()
            ->query("SELECT COUNT(*) AS c FROM user_roles WHERE user_id = $userId")
            ->fetch();
        assert_equal(0, (int)$count['c'], 'submitting the edit form with no roles[] field did not clear every assignment');

        // A tampered POST naming a role id that doesn't exist must never
        // create a dangling user_roles row -- no FK constraint anywhere
        // in this framework would otherwise catch it.
        $bogusId = 999999;
        $editForm4 = $http->get("/admin/users/$userId/edit");
        $token4 = TestHttpClient::extractCsrfToken($editForm4['body']);
        $res = $http->post("/admin/users/$userId/edit", [
            'csrf_token' => $token4,
            'name' => 'Admin-Created User',
            'email' => 'admin-created@example.invalid',
            'uname' => 'zpms_test_admin_created_user',
            'active' => '1',
            'roles' => [(string)$bogusId],
        ]);
        assert_equal(302, $res['status'], "edit-user POST (bogus role id) did not redirect (got {$res['status']})");
        $count = dbConnection::getConnection()
            ->query("SELECT COUNT(*) AS c FROM user_roles WHERE user_id = $userId")
            ->fetch();
        assert_equal(0, (int)$count['c'], 'a nonexistent role id in roles[] was inserted into user_roles');

        // The 'new user' form (no id yet) must not render the checklist
        // at all -- there is nothing to assign roles to before the user
        // exists.
        $newForm = $http->get('/admin/users/new');
        assert_equal(0, preg_match('/name="roles\[\]"/', $newForm['body']), 'the new-user form unexpectedly rendered a Roles checklist');
    });

    $runner->add('grant a role a permission via role_permissions, then see it resolved by name in the list', function () {
        TestSchema::assertSafeToMutate();
        $http = $GLOBALS['zpms_test_admin_crud_http'];

        // 'secretary' doesn't hold backup-access (see web/rbac_seed.php)
        // -- a good, narrow role to grant a NEW permission to for this test.
        $secretaryRole = rolesClassEx::sgetByName('secretary');
        $backupPerm = permissionsClassEx::sgetByName('backup-access');
        assert_not_null($secretaryRole, "the 'secretary' role was not seeded");
        assert_not_null($backupPerm, "the 'backup-access' permission was not seeded");

        $newForm = $http->get('/admin/role_permissions/new');
        assert_contains('name="role_id"', $newForm['body'], 'role_permissions new-form has no role_id <select>');
        // Every seeded role name should appear as a <select> option, by
        // label -- proves the select is actually populated from the
        // roles table, not just present as an empty control.
        assert_contains('doctor', $newForm['body'], 'role_id <select> is missing the doctor option');
        $token = TestHttpClient::extractCsrfToken($newForm['body']);

        $res = $http->post('/admin/role_permissions/new', [
            'csrf_token' => $token,
            'role_id' => (string)$secretaryRole->getid(),
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

        // A fresh, disposable role (not one of the seeded ones) so this
        // test doesn't disturb the shared doctor/secretary/maintenance/
        // administrator roles other tests in this run still rely on.
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
