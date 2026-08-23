#!/usr/bin/env php
<?php
/*
 * Seeds the modernized permissions/roles/role_permissions tables (see
 * web/classes/yaml/{permissions,roles,role_permissions,user_roles}.yaml and
 * web/rbac.php's own docblock for the full design/rationale) and transfers
 * every existing user's role assignment out of the legacy users.roles
 * column (a single CHAR(36) field holding a raw, space-separated string
 * like "power-user") into the new user_roles join table.
 *
 * The actual seeding/migration logic lives in web/rbac_seed.php (shared
 * with tests/lib/TestFixtures.php, so tests exercise the exact same code
 * path a real deploy uses) -- this script is a thin CLI wrapper around it.
 *
 * Idempotent and safe to re-run: seeding skips any permission/role that
 * already exists by name, and user_rolesClassEx::assignRole() skips any
 * (user, role) pair already assigned. Running this twice in a row is a
 * no-op the second time.
 *
 * Usage:
 *   php bin/migrate_roles.php --dry-run     # show what WOULD change, write nothing
 *   php bin/migrate_roles.php --yes         # actually seed + migrate
 *
 * Run --dry-run first, always -- it prints every user's legacy roles
 * value and exactly which new roles it maps to (and flags anything it
 * *doesn't* recognize, so nothing is silently dropped) without touching
 * the database. Requires --yes to actually write, so it can never be run
 * destructively by accident (e.g. a bare `php bin/migrate_roles.php` with
 * no flags does nothing but print the usage above and exit).
 *
 * Targets config/db.php (the real database) by default, same as the live
 * app itself -- set ZPMS_DB_CONFIG to point at a different config file
 * (e.g. config/db.test.php) to migrate a test database instead.
 *
 * Schema prerequisite: the permissions/roles/role_permissions/user_roles
 * tables must already exist (run the same spill:class:all / update:
 * bootstrap / spill:sql:all steps your normal deploy process uses --
 * see tests/lib/TestSchema.php's regenerateClasses() for the exact
 * commands -- then load the generated web/classes/sql/{permissions,roles,
 * role_permissions,user_roles}.sql files into the live database by hand;
 * this framework has no migration runner, so that step is manual, same
 * as every other schema change in this app).
 *
 * IMPORTANT -- deployment ordering: once this app's code is deployed with
 * web/rbac.php in place, zpms_require_permission() checks the user_roles
 * table directly for every permission check (it never falls back to the
 * legacy users.roles column for that -- only login's *session* role list,
 * used solely for route-level access:, falls back). Any account that
 * hasn't been through this script yet will fail every permission check
 * after that point (patient view/edit/delete, appointment edit, backups,
 * settings) even though it can still log in. Run this script with --yes
 * as part of the SAME deploy that ships web/rbac.php, not some time after.
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
      php bin/migrate_roles.php --dry-run     # show what WOULD change, write nothing
      php bin/migrate_roles.php --yes         # actually seed + migrate

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

// Same reasoning as tests/bootstrap.php: web/user_classes.php (loaded by
// the framework bootstrap below) reads classes/bootstrap_classes.php via
// a path relative to the current working directory, not to itself.
chdir(__APPDIR__ . '/web');

// zeusfw's maker.php spill_class() has a known code-gen quirk: every
// generated entity class file starts with a few lines of stray whitespace
// before its opening <?php tag, which PHP echoes verbatim on every
// require (harmless in the real app -- every HTTP response path is
// wrapped in its own output buffering that discards it -- but it would
// otherwise splatter noise across this script's own output). Same
// workaround tests/bootstrap.php uses.
ob_start();
require_once __FWDIR__ . '/bootstrap.php';
require_once __APPDIR__ . '/web/rbac.php';
require_once __APPDIR__ . '/web/rbac_seed.php';
ob_end_clean();

// Normally done inside Kernel's constructor (core/kernel/Kernel.php) from
// these same DB_* constants -- this script never instantiates a Kernel
// (no routing/rendering needed for a data-only migration), so it has to
// do this step itself.
dbConnection::init(DB_HOST, DB_USER, DB_PASS, DB_NAME);

$log = function (string $msg): void {
    echo $msg . "\n";
};

$log($dryRun ? '=== DRY RUN -- no changes will be written ===' : '=== Migrating ===');
echo "\n";

$seeded = zpms_seed_permissions_and_roles($dryRun, $log);
echo "\n";
$result = zpms_migrate_users_roles($dryRun, $seeded['roleIdsByName'], $log);

echo "\n=== " . ($dryRun ? 'Dry run complete' : 'Migration complete')
    . " -- {$result['usersProcessed']} user(s) processed ===\n";

if ($result['unrecognizedTokens']) {
    echo "\nWARNING: the following users had a legacy roles token that didn't match any\n";
    echo "known role name, and were NOT migrated for that token. Check these by hand\n";
    echo "(e.g. via a role-assignment UI once one exists, or user_rolesClassEx::assignRole()):\n";
    foreach ($result['unrecognizedTokens'] as $uname => $tokens) {
        echo "  $uname: " . implode(', ', $tokens) . "\n";
    }
}
if ($dryRun) {
    echo "\nNo changes were written. Re-run with --yes to apply them.\n";
}
