#!/usr/bin/env php
<?php
/*
 * One-time role-vocabulary transition: renames the 'power-user' role to
 * 'doctor' (matching its real job function), retires the plain 'user'
 * role (nothing in the new lineup needs a generic view-only role), and
 * seeds the new 'maintenance' role (ops-only: backup-access +
 * settings-manage, zero patient-data access) -- see web/rbac_seed.php's
 * own docblock for the full target shape
 * (administrator/doctor/secretary/maintenance).
 *
 * Safe to run against a live deployment that already ran
 * bin/migrate_roles.php at least once: the rename step preserves the
 * role's id (see zpms_rename_role()'s own docblock), so every account
 * already assigned 'power-user' keeps its exact access under the 'doctor'
 * name with zero manual reassignment. The retire step refuses to delete
 * 'user' if any account is still assigned it -- it reports who, changes
 * nothing else, and expects you to reassign that account (e.g. via
 * /admin/user_roles once 'doctor'/'secretary'/'maintenance' all exist)
 * before re-running.
 *
 * Usage:
 *   php bin/migrate_role_refactor.php --dry-run     # show what WOULD change, write nothing
 *   php bin/migrate_role_refactor.php --yes         # actually rename/retire/seed
 *
 * Run --dry-run first, always -- same convention as bin/migrate_roles.php.
 *
 * Targets config/db.php (the real database) by default -- set
 * ZPMS_DB_CONFIG to point at a different config file (e.g.
 * config/db.test.php) to migrate a test database instead.
 *
 * Run this BEFORE (or in the same deploy as) re-running
 * bin/migrate_roles.php once web/rbac_seed.php has been updated to this
 * new role shape -- if the plain additive seeding in that script runs
 * first against an old database, it will simply create a brand-new
 * 'doctor' role from scratch and leave the old 'power-user' role (and
 * every account assigned to it) sitting there untouched and orphaned.
 * This script's rename step (zpms_rename_role()) detects and safely
 * merges that case too if it ever happens out of order, but running this
 * one first is the intended sequence. Either way, run this script's own
 * final seeding step (or bin/migrate_roles.php afterward) to make sure
 * 'maintenance' and every permission/grant actually exist.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

$options = getopt('', ['dry-run', 'yes']);
$dryRun = isset($options['dry-run']);
$confirmed = isset($options['yes']);

if (!$dryRun && !$confirmed) {
    fwrite(STDERR, <<<USAGE
    Usage:
      php bin/migrate_role_refactor.php --dry-run     # show what WOULD change, write nothing
      php bin/migrate_role_refactor.php --yes         # actually rename/retire/seed

    Run --dry-run first -- see this file's own header comment.

    USAGE);
    exit(1);
}

define('__APPDIR__', dirname(__DIR__));

$configPath = getenv('ZPMS_DB_CONFIG') ?: (__APPDIR__ . '/config/db.php');
if (!is_file($configPath)) {
    fwrite(STDERR, "Database config not found: $configPath\n");
    fwrite(STDERR, "(copy config/db.php.in to config/db.php first, or set ZPMS_DB_CONFIG)\n");
    exit(1);
}
require_once $configPath;

if (!defined('__FWDIR__')) {
    define('__FWDIR__', __APPDIR__ . '/web/core');
}
if (!is_dir(__FWDIR__)) {
    fwrite(STDERR, __FWDIR__ . " not found -- is web/core vendored (symlinked) in this checkout?\n");
    exit(1);
}

// Same cwd/whitespace workarounds as bin/migrate_roles.php -- see that
// script's own comments for why both are needed.
chdir(__APPDIR__ . '/web');
ob_start();
require_once __FWDIR__ . '/bootstrap.php';
require_once __APPDIR__ . '/web/rbac.php';
require_once __APPDIR__ . '/web/rbac_seed.php';
ob_end_clean();

dbConnection::init(DB_HOST, DB_USER, DB_PASS, DB_NAME);

$log = function (string $msg): void {
    echo $msg . "\n";
};

$log($dryRun ? '=== DRY RUN -- no changes will be written ===' : '=== Migrating ===');
echo "\n";

$log("-- Renaming 'power-user' -> 'doctor' --");
zpms_rename_role('power-user', 'doctor', 'Ιατρός', $dryRun, $log);
echo "\n";

$log("-- Retiring 'user' role --");
$retireResult = zpms_retire_role('user', $dryRun, $log);
echo "\n";

$log("-- Seeding 'maintenance' role + any other missing permissions/roles/grants --");
zpms_seed_permissions_and_roles($dryRun, $log);

echo "\n=== " . ($dryRun ? 'Dry run complete' : 'Migration complete') . " ===\n";

if (!$retireResult['retired'] && $retireResult['blockedUsers']) {
    echo "\nACTION NEEDED: reassign " . implode(', ', $retireResult['blockedUsers'])
        . " away from the 'user' role (e.g. to 'secretary', via /admin/user_roles),\n"
        . "then re-run this script to actually remove it.\n";
}
if ($dryRun) {
    echo "\nNo changes were written. Re-run with --yes to apply them.\n";
}
