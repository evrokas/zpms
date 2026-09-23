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
// Covers both the patient list page AND opening an individual patient's
// page to look at it (name/AMKA/contact details/appointment history) --
// deliberately one permission for "can see patient data, read-only"
// rather than a separate view-list/view-record split, matching this app's
// existing granularity (see ZPMS_PERM_APPOINTMENT_EDIT below, one
// permission for create/edit/delete together). A holder of only this
// permission (see 'secretary' in web/rbac_seed.php) gets a fully
// read-only patient page: patient_edit() (web/index.php) renders it with
// every field disabled and no save/appointment/attachment controls
// whenever ZPMS_PERM_PATIENTS_EDIT_PATIENT/ZPMS_PERM_APPOINTMENT_EDIT
// aren't also held -- actually writing a change still goes through
// patient_edit_post(), gated separately below, regardless of what a
// tampered request submits.
const ZPMS_PERM_PATIENTS_VIEW_LIST = 'patients-view-list';
const ZPMS_PERM_PATIENTS_NEW_PATIENT = 'patients-new-patient';
const ZPMS_PERM_PATIENTS_EDIT_PATIENT = 'patients-edit-patient';
const ZPMS_PERM_PATIENTS_DELETE_PATIENT = 'patients-delete-patient';
const ZPMS_PERM_APPOINTMENT_EDIT = 'appointment-edit';
// Read-only counterpart to ZPMS_PERM_APPOINTMENT_EDIT -- a holder of only
// this (e.g. 'secretary' in web/rbac_seed.php) sees the SAME full
// appointment/operation card view_appointment.zetem renders for an
// editor (notes, dates, attachments), just with every field disabled and
// no save/delete/upload controls (patient_edit() in web/index.php passes
// 'can_edit' => false into that template whenever ZPMS_PERM_APPOINTMENT_EDIT
// isn't also held), instead of the bare date+location summary a viewer
// with neither permission gets. Deliberately still gated separately from
// ZPMS_PERM_PATIENTS_VIEW_LIST -- a future role could plausibly want the
// patient list/record without full appointment detail, so this stays its
// own permission rather than folding into that one.
const ZPMS_PERM_APPOINTMENT_VIEW = 'appointment-view';
const ZPMS_PERM_BACKUP_ACCESS = 'backup-access';
const ZPMS_PERM_SETTINGS_MANAGE = 'settings-manage';
// Gates the read-only APYweb financial-info block on a patient's own
// record (invoices/operations/fee-reports pulled from that sibling app --
// see zpms_apyweb_fetch_financials(), web/apyweb_client.php) --
// deliberately separate from ZPMS_PERM_PATIENTS_VIEW_LIST, since a
// patient's billing history is more sensitive than their name/AMKA/
// appointment dates and a front-desk account (secretary) that can already
// open a patient's record has no clinical or administrative need to see
// it. patient_edit() only calls zpms_apyweb_fetch_financials() at all when
// this permission is held, rather than fetching it and merely hiding the
// rendered block, so a viewer without it never causes that outbound
// lookup in the first place.
const ZPMS_PERM_PATIENT_FINANCIAL_VIEW = 'patient-financial-view';
// Covers the whole "Εκκρεμή Ραντεβού" waiting room -- view/create/edit/
// delete a pending_appointments row -- but deliberately NOT the ability
// to convert one into a real patient record, which stays gated on
// ZPMS_PERM_PATIENTS_NEW_PATIENT + ZPMS_PERM_APPOINTMENT_EDIT below (a
// secretary can schedule and manage phone bookings without being able to
// create/edit real patient records, which stays a doctor-only action --
// see web/rbac_seed.php's 'secretary' role for exactly this split).
const ZPMS_PERM_PENDING_APPOINTMENTS_MANAGE = 'pending-appointments-manage';

// Every permission slug above, plus the framework's own
// ZEUSFW_PERM_MANAGE_USERS (core/lib/Rbac.php -- gates zeusfw core's
// generic /admin/{entity} CRUD UI), in one place -- the single list
// bin/migrate_roles.php seeds into the permissions table and the
// doctor role's role_permissions rows from. Keep in sync by hand with
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
        ZPMS_PERM_APPOINTMENT_VIEW,
        ZPMS_PERM_BACKUP_ACCESS,
        ZPMS_PERM_SETTINGS_MANAGE,
        ZPMS_PERM_PENDING_APPOINTMENTS_MANAGE,
        ZPMS_PERM_PATIENT_FINANCIAL_VIEW,
        ZEUSFW_PERM_MANAGE_USERS,
    ];
}
