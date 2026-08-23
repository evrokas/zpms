<?php

/**
 * Modernized role/permission system for zpms.
 *
 * ## What this replaces
 *
 * Before this file existed, "authorization" in this app was:
 *   - users.roles: a single CHAR(36) column (an unrelated '@guid' type
 *     macro, reused only because it happened to produce a plausible-looking
 *     fixed-width string column) holding a raw, space-separated list of
 *     role names, e.g. "power-user".
 *   - config/settings.info.yaml's roles: block, a hardcoded array mapping
 *     each role name to either a permission array or a magic string
 *     ('administrator: all', 'authenticated: authenticated_content').
 *   - SecurityClass::require($permission) (zeusfw core, core/lib/Security.php),
 *     which checked the logged-in user's session role list against that
 *     YAML array.
 *
 * Two serious, currently-live bugs were found auditing that system:
 *
 *   1. SecurityClass::require()'s role loop has a hardcoded special case:
 *      any role literally named "authenticated" auto-passes the check for
 *      ANY permission, regardless of what was actually asked for. Kernel::
 *      loginUser() (zeusfw core, core/kernel/Kernel.php) unconditionally
 *      appends 'authenticated' to every logged-in user's session role list
 *      -- so in practice, SecurityClass::require($anything) has always
 *      returned success for any logged-in user, no matter their actual
 *      role. Every fine-grained permission check in this app (patient
 *      delete, settings management, backups, ...) was silently inert;
 *      the only real gate was "are you logged in at all".
 *   2. 'administrator: all' -- self::$roles['administrator'] is the string
 *      "all", not an array. SecurityClass::require()'s default branch does
 *      in_array($perm, self::$roles[$role]), and in_array() against a
 *      string haystack is a fatal TypeError on PHP 8. Confirmed by direct
 *      test. No account with that literal role was ever created in
 *      practice (there's no user-management UI), so this specific crash
 *      was latent rather than observed -- but it made the 'administrator'
 *      role completely unusable as configured.
 *
 * Both bugs live in zeusfw core, shared by every app on this framework
 * (mweb, zpms, zweb, erweb). Fixing SecurityClass::require() itself would
 * change behavior for all of them without their maintainers having signed
 * off on the blast radius (see zeusfw's own CLAUDE.md policy on this) --
 * so instead, zpms stops calling SecurityClass::require() anywhere in its
 * own code and uses the functions in this file instead, which have none of
 * the above problems. SecurityClass::userIsPermitted() (used for route-level
 * access: in config/settings.info.yaml, and for the nav menu's own
 * access: gating) is unaffected by either bug -- it does a plain role-
 * identity check, not a permission-array lookup -- and is left exactly as
 * it was.
 *
 * ## The new model
 *
 * Four tables (web/classes/yaml/permissions.yaml, roles.yaml,
 * role_permissions.yaml, user_roles.yaml), standard RBAC shape:
 *   permissions       registry of permission slugs (see PERMISSION
 *                      constants below)
 *   roles             assignable bundles (e.g. 'power-user'), with an
 *                      is_superuser flag replacing the broken 'all' string
 *   role_permissions  many-to-many: which permissions a role grants
 *   user_roles        many-to-many: which roles a user has (replaces the
 *                      old users.roles column, which is left in place --
 *                      it's defined in zeusfw core's shared users.yaml --
 *                      but is no longer read or written by this app)
 *
 * zpms_require_permission($perm) is a drop-in replacement for
 * SecurityClass::require($perm) at every zpms call site (same ?string
 * return contract: null on success, a rendered 401 page on failure), so
 * migrating a call site is a straight function-name swap. It queries the
 * database directly for the current user's actual roles/permissions on
 * every call rather than trusting the session role list, so it's
 * unaffected by whatever zeusfw core's session role array happens to
 * contain (including the always-present 'authenticated' entry above).
 *
 * ## Session roles (login) and route-level access:
 *
 * zeusfw core's login_post() (core/lib/UserLogin.php) still needs *some*
 * role list to hand to Kernel::loginUser() for SecurityClass::
 * userIsPermitted()'s route-level access: checks (e.g. this app's own
 * `access: power-user` on the webforms_post route) to keep working. It
 * does so via zeusfw_app_resolve_user_roles() below, an opt-in extension
 * point login_post() checks for with function_exists() -- the same
 * pattern already used for csrf_field() (core/lib/FormElement.php). Core
 * is otherwise untouched; an app that doesn't define this function keeps
 * reading users.roles exactly as before.
 *
 * ## Migrating an existing install
 *
 * See bin/migrate_roles.php -- seeds the permissions/roles/role_permissions
 * tables (matching today's actual power-user permission set) and transfers
 * every user's existing users.roles tokens into user_roles rows. Idempotent
 * and safe to re-run.
 */

// Every permission this app actually checks somewhere via
// zpms_require_permission(). Kept as named constants (rather than bare
// string literals at each call site) so a typo in a permission name is a
// PHP fatal (undefined constant) at the first request that hits it,
// instead of a silently-always-false check -- same failure-mode reasoning
// as bin/migrate_roles.php seeding this exact list into the permissions
// table.
const ZPMS_PERM_PATIENTS_VIEW_LIST = 'patients-view-list';
const ZPMS_PERM_PATIENTS_NEW_PATIENT = 'patients-new-patient';
const ZPMS_PERM_PATIENTS_EDIT_PATIENT = 'patients-edit-patient';
const ZPMS_PERM_PATIENTS_DELETE_PATIENT = 'patients-delete-patient';
const ZPMS_PERM_APPOINTMENT_EDIT = 'appointment-edit';
const ZPMS_PERM_BACKUP_ACCESS = 'backup-access';
const ZPMS_PERM_SETTINGS_MANAGE = 'settings-manage';
// Deliberately NOT granted to power-user by default (see
// web/rbac_seed.php) -- this is the one permission that can grant every
// other one, transitively, by letting its holder create a new
// is_superuser role and assign it to themselves. Reserve it for
// is_superuser accounts, or grant it explicitly and deliberately.
const ZPMS_PERM_USERS_MANAGE = 'users-manage';

// Every permission slug above, in one place -- the single list
// bin/migrate_roles.php seeds into the permissions table and the
// power-user role's role_permissions rows from. Keep in sync by hand with
// the constants above (and with every zpms_require_permission() call
// site) -- there's no reflection-based discovery in this codebase, same
// as every other config surface in this app.
function zpms_all_permission_slugs(): array {
    return [
        ZPMS_PERM_PATIENTS_VIEW_LIST,
        ZPMS_PERM_PATIENTS_NEW_PATIENT,
        ZPMS_PERM_PATIENTS_EDIT_PATIENT,
        ZPMS_PERM_PATIENTS_DELETE_PATIENT,
        ZPMS_PERM_APPOINTMENT_EDIT,
        ZPMS_PERM_BACKUP_ACCESS,
        ZPMS_PERM_SETTINGS_MANAGE,
        ZPMS_PERM_USERS_MANAGE,
    ];
}

class rolesClassEx extends rolesClass {
    static function sgetByName(string $name): ?rolesClass {
        $sql = "SELECT * FROM roles WHERE name=:name";
        $st = dbConnection::getConnection()->prepare($sql);
        $st->bindValue(":name", $name, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch();

        if ($row) {
            $rclass = new rolesClass();
            $rclass->loadFields($row);
            return $rclass;
        }
        return null;
    }
}

class permissionsClassEx extends permissionsClass {
    static function sgetByName(string $name): ?permissionsClass {
        $sql = "SELECT * FROM permissions WHERE name=:name";
        $st = dbConnection::getConnection()->prepare($sql);
        $st->bindValue(":name", $name, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch();

        if ($row) {
            $rclass = new permissionsClass();
            $rclass->loadFields($row);
            return $rclass;
        }
        return null;
    }
}

class role_permissionsClassEx extends role_permissionsClass {
    // Every permission NAME (not id) a role grants directly -- empty for
    // an is_superuser role, which needs no rows here at all (see
    // zpms_user_has_permission() below).
    static function getPermissionNamesForRole(int $roleId): array {
        $sql = "SELECT p.name FROM role_permissions rp
                JOIN permissions p ON p.id = rp.permission_id
                WHERE rp.role_id = :role_id";
        $st = dbConnection::getConnection()->prepare($sql);
        $st->bindValue(":role_id", $roleId, PDO::PARAM_INT);
        $st->execute();

        $names = [];
        while ($row = $st->fetch()) {
            $names[] = $row['name'];
        }
        return $names;
    }
}

class user_rolesClassEx extends user_rolesClass {
    // Every role this user has, each carrying its own is_superuser flag
    // and granted-permission-name list -- exactly what
    // zpms_user_has_permission() needs, in one query per role (there are
    // only ever a handful of roles per user, so this isn't worth
    // collapsing into a single join).
    static function getRolesForUser(int $userId): array {
        $sql = "SELECT r.* FROM user_roles ur
                JOIN roles r ON r.id = ur.role_id
                WHERE ur.user_id = :user_id";
        $st = dbConnection::getConnection()->prepare($sql);
        $st->bindValue(":user_id", $userId, PDO::PARAM_INT);
        $st->execute();

        $roles = [];
        while ($row = $st->fetch()) {
            $roles[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'label' => $row['label'],
                'is_superuser' => (bool)$row['is_superuser'],
                'permissions' => $row['is_superuser']
                    ? zpms_all_permission_slugs()
                    : role_permissionsClassEx::getPermissionNamesForRole((int)$row['id']),
            ];
        }
        return $roles;
    }

    static function roleNamesForUser(int $userId): array {
        return array_column(self::getRolesForUser($userId), 'name');
    }

    // Idempotent -- used by both bin/migrate_roles.php and any future
    // role-management UI, so a double-submit/re-run can never create a
    // duplicate assignment.
    static function assignRole(int $userId, int $roleId, string $cuser): void {
        $existing = dbConnection::getConnection()->prepare(
            "SELECT id FROM user_roles WHERE user_id=:user_id AND role_id=:role_id"
        );
        $existing->bindValue(":user_id", $userId, PDO::PARAM_INT);
        $existing->bindValue(":role_id", $roleId, PDO::PARAM_INT);
        $existing->execute();
        if ($existing->fetch()) {
            return;
        }

        $ur = new user_rolesClass([
            'guid' => guid(),
            'cuser' => $cuser,
            'cdate' => getDBtime(),
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);
        $ur->insert();
    }

    static function removeRole(int $userId, int $roleId): void {
        $st = dbConnection::getConnection()->prepare(
            "DELETE FROM user_roles WHERE user_id=:user_id AND role_id=:role_id"
        );
        $st->bindValue(":user_id", $userId, PDO::PARAM_INT);
        $st->bindValue(":role_id", $roleId, PDO::PARAM_INT);
        $st->execute();
    }
}

// The actual check every zpms_require_permission() call ultimately
// resolves to. Always re-queries the database for the current user's
// roles rather than trusting $_SESSION['user_roles'] (the session array
// zeusfw core's Kernel::loginUser() builds) -- that array always carries
// an extra 'authenticated' entry (see this file's own docblock above), so
// trusting it here would just reintroduce the same bug this file exists
// to get away from.
function zpms_user_has_permission(string $permission): bool {
    global $kernel;

    $uname = $kernel->getUserName();
    if (!$uname) {
        return false;
    }

    $user = UsersClassEx::getUserAccount($uname);
    if (!$user) {
        return false;
    }

    foreach (user_rolesClassEx::getRolesForUser((int)$user->getid()) as $role) {
        if ($role['is_superuser']) {
            return true;
        }
        if (in_array($permission, $role['permissions'], true)) {
            return true;
        }
    }
    return false;
}

// Drop-in replacement for SecurityClass::require($permission) at every
// zpms call site -- same return contract (null on success, a rendered 401
// page on failure), so switching a call site is a straight function-name
// swap: SecurityClass::require('x') -> zpms_require_permission('x').
// Callers must actually check the return value (`if (($errmsg =
// zpms_require_permission(...))) return $errmsg;`) for this to do
// anything -- same as SecurityClass::require() always required, but
// several existing call sites skipped that check; see CLAUDE.md/the
// commit that introduced this file for the full list that was fixed.
function zpms_require_permission(string $permission): ?string {
    if (zpms_user_has_permission($permission)) {
        return null;
    }
    return error_401();
}

// Opt-in extension point zeusfw core's login_post() (core/lib/UserLogin.php)
// checks for via function_exists() before falling back to the legacy
// users.roles column -- same pattern as csrf_field() (core/lib/
// FormElement.php). Returns a space-separated role-name string, the exact
// shape usersClass::getroles() itself returns, so Kernel::loginUser()
// (which just splits on spaces) needs no changes either. Returns null --
// "no opinion, use the legacy column" -- for a user with zero user_roles
// rows, rather than an empty string: SecurityClass::processRoles() treats
// an empty role list as invalid input and Kernel::loginUser() hard-exits
// the request on that, so this is a real safety net for an account that
// hasn't been through bin/migrate_roles.php yet, not just a style choice.
function zeusfw_app_resolve_user_roles(usersClass $user): ?string {
    $names = user_rolesClassEx::roleNamesForUser((int)$user->getid());
    return $names ? implode(' ', $names) : null;
}
