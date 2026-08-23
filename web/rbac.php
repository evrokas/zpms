<?php

/**
 * ZPMS's own permission vocabulary for the RBAC (role-based access
 * control) system.
 *
 * The reusable *engine* -- the roles/permissions/role_permissions/
 * user_roles schema, the rolesClassEx/permissionsClassEx/
 * role_permissionsClassEx/user_rolesClassEx extension classes, and the
 * rbacClass::isPermitted()/rbacClass::require() permission-check functions
 * -- now lives in zeusfw core (core/classes/yaml/{roles,permissions,
 * role_permissions,user_roles}.yaml, core/ClassExFW.php, core/lib/Rbac.php)
 * so every app on the framework gets it for free, the same "define once in
 * core, every app just consumes it" pattern already used for the `users`
 * table. See core/lib/Rbac.php's own docblock for the full permission-check
 * design and the history of the two zeusfw-core bugs
 * (SecurityClass::require()'s "authenticated" auto-pass, and
 * 'administrator' => 'all' being a fatal TypeError) that motivated moving
 * off SecurityClass::require() in the first place.
 *
 * What's left here -- and what stays app-specific for any app adopting
 * this framework-level RBAC engine -- is purely this app's own
 * permission *vocabulary*: which permission slugs actually exist, and
 * their human-readable labels. See web/rbac_seed.php for the seed role
 * definitions (which roles exist, and which permissions each one grants)
 * and bin/migrate_roles.php for the one-time migration/seeding CLI tool
 * that populates the schema from this vocabulary.
 *
 * rbacClass::require($perm) (zeusfw core) is a drop-in replacement for
 * SecurityClass::require($perm) at every zpms call site (same ?string
 * return contract: null on success, a rendered 401 page on failure).
 */

// Every permission this app actually checks somewhere via
// rbacClass::require()/rbacClass::isPermitted(). Kept as named constants
// (rather than bare string literals at each call site) so a typo in a
// permission name is a PHP fatal (undefined constant) at the first
// request that hits it, instead of a silently-always-false check -- same
// failure-mode reasoning as bin/migrate_roles.php seeding this exact list
// into the permissions table.
const ZPMS_PERM_PATIENTS_VIEW_LIST = 'patients-view-list';
const ZPMS_PERM_PATIENTS_NEW_PATIENT = 'patients-new-patient';
const ZPMS_PERM_PATIENTS_EDIT_PATIENT = 'patients-edit-patient';
const ZPMS_PERM_PATIENTS_DELETE_PATIENT = 'patients-delete-patient';
const ZPMS_PERM_APPOINTMENT_EDIT = 'appointment-edit';
const ZPMS_PERM_BACKUP_ACCESS = 'backup-access';
const ZPMS_PERM_SETTINGS_MANAGE = 'settings-manage';

// Every permission slug above, plus the framework's own
// ZEUSFW_PERM_MANAGE_USERS (core/lib/Rbac.php -- gates zeusfw core's
// generic /admin/{entity} CRUD UI), in one place -- the single list
// bin/migrate_roles.php seeds into the permissions table and the
// power-user role's role_permissions rows from. Keep in sync by hand with
// the constants above (and with every rbacClass::require() call site) --
// there's no reflection-based discovery in this codebase, same as every
// other config surface in this app. This app no longer defines its own
// "manage users/roles/permissions" permission constant -- that's now a
// framework-owned concern, since the admin UI it gates is framework-owned
// too; ZEUSFW_PERM_MANAGE_USERS is the exact same string value the old
// ZPMS_PERM_USERS_MANAGE constant held, so no permissions/role_permissions
// data migration was needed when adopting this.
function zpms_all_permission_slugs(): array {
    return [
        ZPMS_PERM_PATIENTS_VIEW_LIST,
        ZPMS_PERM_PATIENTS_NEW_PATIENT,
        ZPMS_PERM_PATIENTS_EDIT_PATIENT,
        ZPMS_PERM_PATIENTS_DELETE_PATIENT,
        ZPMS_PERM_APPOINTMENT_EDIT,
        ZPMS_PERM_BACKUP_ACCESS,
        ZPMS_PERM_SETTINGS_MANAGE,
        ZEUSFW_PERM_MANAGE_USERS,
    ];
}
