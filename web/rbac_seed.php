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
// permissions going forward via the /admin/role_permissions admin UI
// (zeusfw core's core/modules/admin/admin_crud.php) or
// role_permissionsClassEx directly, not by editing this array and
// re-running the migration.
//
// Four roles, matching four real job functions at this practice --
// 'user' (a generic view-only role nobody's actual account mapped to a
// real job) and 'power-user' (renamed) are both retired here; see
// bin/migrate_role_refactor.php for the one-time transition that renames
// an existing deployment's 'power-user' role to 'doctor' in place
// (preserving its id, so already-assigned accounts keep working) and
// safely retires 'user' (refusing to delete it if any account still holds
// it, rather than silently orphaning that account).
// 'administrator' needs no permissions list at all -- is_superuser bypasses
// the permission check entirely, see rbacClass::isPermitted() in
// zeusfw's core/lib/Rbac.php.
function zpms_role_seed_definitions(): array {
    return [
        // Full clinical access -- the exact permission set 'power-user'
        // held before this role was renamed to match its real job title.
        'doctor' => [
            'label' => 'Ιατρός',
            'is_superuser' => false,
            'permissions' => [
                ZPMS_PERM_PATIENTS_VIEW_LIST,
                ZPMS_PERM_PATIENTS_NEW_PATIENT,
                ZPMS_PERM_PATIENTS_DELETE_PATIENT,
                ZPMS_PERM_PATIENTS_EDIT_PATIENT,
                ZPMS_PERM_APPOINTMENT_EDIT,
                ZPMS_PERM_BACKUP_ACCESS,
                ZPMS_PERM_SETTINGS_MANAGE,
                ZPMS_PERM_PENDING_APPOINTMENTS_MANAGE,
                ZPMS_PERM_PATIENT_FINANCIAL_VIEW,
            ],
        ],
        // A front-desk account that books/manages phone appointments
        // (the "Εκκρεμή Ραντεβού" waiting room) and can look up/open a
        // patient's record read-only (name/AMKA/appointment history --
        // see ZPMS_PERM_PATIENTS_VIEW_LIST's own docblock in web/rbac.php
        // for what "read-only" means in practice), without being able to
        // create, edit, or delete a patient record, or touch appointment
        // data itself -- deliberately no ZPMS_PERM_PATIENTS_NEW_PATIENT/
        // _EDIT_PATIENT/_DELETE_PATIENT/ZPMS_PERM_APPOINTMENT_EDIT, since
        // "convert this into a patient record" is a doctor decision, made
        // once the patient actually shows up (see
        // pending_appointment_convert_post() in web/index.php, gated on
        // exactly the two permissions this role doesn't have).
        // ZPMS_PERM_APPOINTMENT_VIEW grants full read-only appointment
        // detail (notes/dates/attachments -- see that constant's own
        // docblock in web/rbac.php), so a secretary can actually look at
        // what happened at a past visit, not just that one existed on a
        // given date. Deliberately no ZPMS_PERM_PATIENT_FINANCIAL_VIEW --
        // billing history stays doctor-only.
        'secretary' => [
            'label' => 'Γραμματεία',
            'is_superuser' => false,
            'permissions' => [
                ZPMS_PERM_PATIENTS_VIEW_LIST,
                ZPMS_PERM_PENDING_APPOINTMENTS_MANAGE,
                ZPMS_PERM_APPOINTMENT_VIEW,
            ],
        ],
        // A technical/ops account for whoever administers the server --
        // deliberately zero patient-data permissions (no
        // ZPMS_PERM_PATIENTS_*/ZPMS_PERM_APPOINTMENT_EDIT/
        // ZPMS_PERM_PENDING_APPOINTMENTS_MANAGE), since this role exists
        // for infrastructure tasks (checking backups ran, keeping the
        // clinics/doctors reference data current), not clinical work.
        // Also deliberately no ZEUSFW_PERM_MANAGE_USERS -- account/role
        // administration stays an administrator-only concern.
        'maintenance' => [
            'label' => 'Συντήρηση',
            'is_superuser' => false,
            'permissions' => [
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
        ZPMS_PERM_PATIENTS_VIEW_LIST => 'View the patient list and open a patient\'s record (read-only)',
        ZPMS_PERM_PATIENTS_NEW_PATIENT => 'Create a new patient',
        ZPMS_PERM_PATIENTS_EDIT_PATIENT => 'Edit a patient record',
        ZPMS_PERM_PATIENTS_DELETE_PATIENT => 'Delete a patient record',
        ZPMS_PERM_APPOINTMENT_EDIT => 'Create/edit/delete appointments and their attachments',
        ZPMS_PERM_APPOINTMENT_VIEW => 'View full appointment/operation details (read-only)',
        ZPMS_PERM_BACKUP_ACCESS => 'View backup status',
        ZPMS_PERM_SETTINGS_MANAGE => 'Manage clinics/doctors reference data',
        ZPMS_PERM_PENDING_APPOINTMENTS_MANAGE => 'View/create/edit/delete pending (not-yet-a-patient) appointments',
        ZPMS_PERM_PATIENT_FINANCIAL_VIEW => 'View a patient\'s APYweb financial info (invoices/operations/fee reports)',
        ZEUSFW_PERM_MANAGE_USERS => 'Manage user accounts and roles/permissions',
    ];
}

// Renames a role in place (preserving its id, and therefore every
// existing role_permissions/user_roles row referencing it) -- used for a
// one-time role-vocabulary change (power-user -> doctor) where the *set
// of permissions* doesn't change, only what the role is called, so every
// account already assigned the old name keeps its exact access under the
// new one with zero manual reassignment.
//
// A no-op (not an error) when $oldName doesn't exist -- a fresh install
// that was seeded straight from the current (already-renamed)
// zpms_role_seed_definitions() never had a 'power-user' row to rename.
//
// If a role already named $newName ALSO exists (e.g. bin/migrate_roles.php's
// own additive seeding already ran once against this database after the
// code was updated, so it created a fresh 'doctor' role from scratch while
// the old 'power-user' row was still sitting there untouched), the two are
// merged instead: every user_roles row pointing at $oldName's id is
// repointed at $newName's id, then $oldName's own role_permissions/role
// rows are deleted -- so no account loses its assignment and no orphaned
// role is left behind, regardless of which order this script and the
// normal additive seed happen to run in.
function zpms_rename_role(string $oldName, string $newName, string $newLabel, bool $dryRun, callable $log): void {
    $old = rolesClassEx::sgetByName($oldName);
    if (!$old) {
        $log("  '$oldName' not found -- nothing to rename.");
        return;
    }

    $db = dbConnection::getConnection();
    $new = rolesClassEx::sgetByName($newName);

    if ($new) {
        $log("  both '$oldName' (id=" . $old->getid() . ") and '$newName' (id=" . $new->getid()
            . ") exist -- merging '$oldName' assignments into '$newName' and removing '$oldName'.");
        if (!$dryRun) {
            $stmt = $db->prepare("SELECT user_id FROM user_roles WHERE role_id = :old");
            $stmt->bindValue(':old', (int)$old->getid(), PDO::PARAM_INT);
            $stmt->execute();
            while ($row = $stmt->fetch()) {
                user_rolesClassEx::assignRole((int)$row['user_id'], (int)$new->getid(), 'migration-script');
            }
            $db->prepare("DELETE FROM user_roles WHERE role_id = :old")
                ->execute([':old' => (int)$old->getid()]);
            $db->prepare("DELETE FROM role_permissions WHERE role_id = :old")
                ->execute([':old' => (int)$old->getid()]);
            $db->prepare("DELETE FROM roles WHERE id = :old")
                ->execute([':old' => (int)$old->getid()]);
        }
        return;
    }

    $log(($dryRun ? "  would rename: " : "  renaming: ") . "'$oldName' (id=" . $old->getid() . ") -> '$newName'");
    if (!$dryRun) {
        $old->setname($newName);
        $old->setlabel($newLabel);
        $old->update();
    }
}

// Deletes a role entirely -- but only if no account currently holds it.
// Refuses (writes nothing, whether $dryRun or not) and reports which
// usernames are still assigned it otherwise, rather than silently
// orphaning a real account the moment its only role disappears --
// same "don't guess, surface it for a human" rule this app's sibling
// repos (apyweb's invoice/operation conflict detection, DocArc's audit
// log) already follow for exactly this kind of consequential ambiguity.
// Re-run after reassigning those accounts elsewhere (e.g. via
// /admin/user_roles once 'secretary'/'doctor'/'maintenance' all exist).
//
// Returns ['retired' => bool, 'blockedUsers' => string[]].
function zpms_retire_role(string $name, bool $dryRun, callable $log): array {
    $role = rolesClassEx::sgetByName($name);
    if (!$role) {
        $log("  '$name' not found -- nothing to retire.");
        return ['retired' => false, 'blockedUsers' => []];
    }

    $db = dbConnection::getConnection();
    $stmt = $db->prepare(
        "SELECT u.uname FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE ur.role_id = :r"
    );
    $stmt->bindValue(':r', (int)$role->getid(), PDO::PARAM_INT);
    $stmt->execute();
    $holders = array_column($stmt->fetchAll(), 'uname');

    if ($holders) {
        $log("  '$name' still assigned to: " . implode(', ', $holders)
            . " -- NOT deleting. Reassign these account(s) to another role first, then re-run.");
        return ['retired' => false, 'blockedUsers' => $holders];
    }

    $log(($dryRun ? "  would delete: " : "  deleting: ") . "'$name' role (id=" . $role->getid() . ")");
    if (!$dryRun) {
        $db->prepare("DELETE FROM role_permissions WHERE role_id = :r")
            ->execute([':r' => (int)$role->getid()]);
        $db->prepare("DELETE FROM roles WHERE id = :r")
            ->execute([':r' => (int)$role->getid()]);
    }
    return ['retired' => true, 'blockedUsers' => []];
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
