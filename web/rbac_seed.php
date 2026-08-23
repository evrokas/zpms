<?php

/**
 * Seed data and migration logic for the modernized roles/permissions
 * system (see web/rbac.php's own docblock for the full design). Split out
 * from bin/migrate_roles.php (a thin CLI wrapper around the functions
 * below) so tests/lib/TestFixtures.php can call the exact same seeding
 * logic a real deploy uses, rather than a parallel, driftable copy of it.
 */

// name => ['label' => ..., 'is_superuser' => bool, 'permissions' => [...]]
// Matches config/settings.info.yaml's *former* roles: block exactly, as a
// one-time snapshot taken when this system was introduced -- not
// something that stays in sync automatically afterward; edit role
// permissions going forward via role_permissionsClassEx/a future admin UI,
// not by editing this array and re-running the migration.
//
// 'user' only ever had patients-view-list in practice
// (appointments-view-list was listed in the old YAML but never actually
// checked anywhere in code -- see web/rbac.php's docblock -- so it's
// deliberately not carried forward as a real permission here).
// 'administrator' needs no permissions list at all -- is_superuser bypasses
// the permission check entirely, see zpms_user_has_permission() in
// web/rbac.php.
function zpms_role_seed_definitions(): array {
    return [
        'user' => [
            'label' => 'Χρήστης',
            'is_superuser' => false,
            'permissions' => [
                ZPMS_PERM_PATIENTS_VIEW_LIST,
            ],
        ],
        'power-user' => [
            'label' => 'Προχωρημένος Χρήστης',
            'is_superuser' => false,
            'permissions' => [
                ZPMS_PERM_PATIENTS_VIEW_LIST,
                ZPMS_PERM_PATIENTS_NEW_PATIENT,
                ZPMS_PERM_PATIENTS_DELETE_PATIENT,
                ZPMS_PERM_PATIENTS_EDIT_PATIENT,
                ZPMS_PERM_APPOINTMENT_EDIT,
                ZPMS_PERM_BACKUP_ACCESS,
                ZPMS_PERM_SETTINGS_MANAGE,
            ],
        ],
        'administrator' => [
            'label' => 'Διαχειριστής',
            'is_superuser' => true,
            'permissions' => [],
        ],
    ];
}

function zpms_permission_label_seed(): array {
    return [
        ZPMS_PERM_PATIENTS_VIEW_LIST => 'View the patient list',
        ZPMS_PERM_PATIENTS_NEW_PATIENT => 'Create a new patient',
        ZPMS_PERM_PATIENTS_EDIT_PATIENT => 'Edit a patient record',
        ZPMS_PERM_PATIENTS_DELETE_PATIENT => 'Delete a patient record',
        ZPMS_PERM_APPOINTMENT_EDIT => 'Create/edit/delete appointments and their attachments',
        ZPMS_PERM_BACKUP_ACCESS => 'View backup status',
        ZPMS_PERM_SETTINGS_MANAGE => 'Manage clinics/doctors reference data',
    ];
}

// Idempotent: skips any permission/role/grant that already exists by
// name. $log is called with one line of progress text per step (pass a
// no-op closure to run silently, e.g. from a test fixture). $dryRun=true
// logs what WOULD happen and writes nothing -- the returned id maps will
// be empty in that case, since nothing was actually inserted.
//
// Returns ['permissionIdsByName' => [...], 'roleIdsByName' => [...]].
function zpms_seed_permissions_and_roles(bool $dryRun, callable $log): array {
    $permissionLabels = zpms_permission_label_seed();
    $roleSeed = zpms_role_seed_definitions();

    $log("-- Permissions --");
    $permissionIdsByName = [];
    foreach (zpms_all_permission_slugs() as $name) {
        $existing = permissionsClassEx::sgetByName($name);
        if ($existing) {
            $log("  exists: $name");
            $permissionIdsByName[$name] = (int)$existing->getid();
            continue;
        }
        $log(($dryRun ? "  would create: " : "  creating: ") . $name);
        if (!$dryRun) {
            $p = new permissionsClass([
                'guid' => guid(),
                'cuser' => 'migration-script',
                'cdate' => getDBtime(),
                'name' => $name,
                'label' => $permissionLabels[$name] ?? $name,
            ]);
            $p->insert();
            $permissionIdsByName[$name] = (int)$p->getid();
        }
    }

    $log("-- Roles --");
    $roleIdsByName = [];
    foreach ($roleSeed as $name => $def) {
        $existing = rolesClassEx::sgetByName($name);
        if ($existing) {
            $log("  exists: $name");
            $roleIdsByName[$name] = (int)$existing->getid();
            continue;
        }
        $log(($dryRun ? "  would create: " : "  creating: ") . $name . ($def['is_superuser'] ? ' (is_superuser)' : ''));
        if (!$dryRun) {
            $r = new rolesClass([
                'guid' => guid(),
                'cuser' => 'migration-script',
                'cdate' => getDBtime(),
                'name' => $name,
                'label' => $def['label'],
                'is_superuser' => $def['is_superuser'] ? 1 : 0,
            ]);
            $r->insert();
            $roleIdsByName[$name] = (int)$r->getid();
        }
    }

    $log("-- Role permissions --");
    $db = dbConnection::getConnection();
    foreach ($roleSeed as $roleName => $def) {
        $roleId = $roleIdsByName[$roleName] ?? null;
        foreach ($def['permissions'] as $permName) {
            $permId = $permissionIdsByName[$permName] ?? null;
            if (!$roleId || !$permId) {
                // Only possible in dry-run mode -- nothing was actually
                // inserted above, so there's no id to check an existing
                // grant against yet.
                $log("  would grant: $roleName -> $permName");
                continue;
            }
            $existing = $db->prepare("SELECT id FROM role_permissions WHERE role_id=:r AND permission_id=:p");
            $existing->bindValue(':r', $roleId, PDO::PARAM_INT);
            $existing->bindValue(':p', $permId, PDO::PARAM_INT);
            $existing->execute();
            if ($existing->fetch()) {
                $log("  exists: $roleName -> $permName");
                continue;
            }
            if ($dryRun) {
                $log("  would grant: $roleName -> $permName");
                continue;
            }
            $log("  granting: $roleName -> $permName");
            $rp = new role_permissionsClass([
                'guid' => guid(),
                'cuser' => 'migration-script',
                'cdate' => getDBtime(),
                'role_id' => $roleId,
                'permission_id' => $permId,
            ]);
            $rp->insert();
        }
    }

    return ['permissionIdsByName' => $permissionIdsByName, 'roleIdsByName' => $roleIdsByName];
}

// Transfers every users.roles (legacy CHAR(36) column) value into
// user_roles rows, using $roleIdsByName (as returned by
// zpms_seed_permissions_and_roles()) to resolve each recognized token.
// Returns ['usersProcessed' => int, 'unrecognizedTokens' => ['uname' => [...]]].
function zpms_migrate_users_roles(bool $dryRun, array $roleIdsByName, callable $log): array {
    $knownRoleNames = array_keys($roleIdsByName) ?: array_keys(zpms_role_seed_definitions());
    $db = dbConnection::getConnection();

    $log("-- Migrating users.roles --");
    $unrecognizedTokens = [];
    $usersProcessed = 0;

    $stmt = $db->query("SELECT id, uname, roles FROM users");
    while ($row = $stmt->fetch()) {
        $usersProcessed++;
        $uname = $row['uname'];
        $legacyRoles = trim((string)$row['roles']);
        $tokens = $legacyRoles === '' ? [] : preg_split('/\s+/', $legacyRoles);

        $matched = [];
        $unmatched = [];
        foreach ($tokens as $token) {
            if (in_array($token, $knownRoleNames, true)) {
                $matched[] = $token;
            } elseif ($token !== '') {
                $unmatched[] = $token;
            }
        }

        $log("  $uname: legacy roles = '" . $legacyRoles . "' -> " . ($matched ? implode(', ', $matched) : '(none)'));
        if ($unmatched) {
            $unrecognizedTokens[$uname] = $unmatched;
            $log("    ! unrecognized token(s), not migrated: " . implode(', ', $unmatched));
        }

        if ($dryRun) {
            continue;
        }
        foreach ($matched as $roleName) {
            $roleId = $roleIdsByName[$roleName] ?? null;
            if ($roleId) {
                user_rolesClassEx::assignRole((int)$row['id'], $roleId, 'migration-script');
            }
        }
    }

    return ['usersProcessed' => $usersProcessed, 'unrecognizedTokens' => $unrecognizedTokens];
}
