#!/usr/bin/env php
<?php
/*
 * Schema changes for "Appointment email notifications" -- see README.md
 * for the full design. Two pieces, both applied here since they always
 * ship together:
 *
 *  1. pending_appointments.assigned_user_id (nullable int) -- an existing,
 *     already-populated table on any real deployment, so this needs a real
 *     ALTER TABLE, not a fresh CREATE (contrast the table's own original
 *     rollout, which needed no migration at all -- see README's "Schema"
 *     section under Pending Appointments).
 *  2. mail_settings -- a brand-new singleton table (SMTP config, same
 *     shape as apyweb's own mail_settings), seeded with its one row
 *     (id=1) so the settings-save handler always has a row to UPDATE.
 *
 * Idempotent and safe to re-run: each step checks whether it's already
 * done (information_schema for the column, SHOW TABLES for mail_settings)
 * before touching anything.
 *
 * Usage:
 *   php bin/migrate_appointment_email.php --dry-run     # show what WOULD change, write nothing
 *   php bin/migrate_appointment_email.php --yes         # actually apply
 *
 * Targets config/db.php (the real database) by default, same as the live
 * app itself -- set ZPMS_DB_CONFIG to point at a different config file
 * (e.g. config/db.test.php) to migrate a test database instead.
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
      php bin/migrate_appointment_email.php --dry-run     # show what WOULD change, write nothing
      php bin/migrate_appointment_email.php --yes         # actually apply

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

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

echo $dryRun ? "=== DRY RUN -- no changes will be written ===\n\n" : "=== Migrating ===\n\n";

// -- 1. pending_appointments.assigned_user_id -----------------------------
$columnCheck = $pdo->query("SHOW COLUMNS FROM pending_appointments LIKE 'assigned_user_id'")->fetchAll();
if ($columnCheck) {
    echo "pending_appointments.assigned_user_id already exists -- skipping.\n";
} else {
    $sql = "ALTER TABLE pending_appointments ADD COLUMN assigned_user_id INT(11) DEFAULT NULL AFTER location";
    echo ($dryRun ? "Would run: " : "Running: ") . "$sql\n";
    if (!$dryRun) {
        $pdo->exec($sql);
    }
}

// -- 2. mail_settings -------------------------------------------------------
$tableCheck = $pdo->query("SHOW TABLES LIKE 'mail_settings'")->fetchAll();
if ($tableCheck) {
    echo "mail_settings table already exists -- skipping create.\n";
} else {
    $sql = "CREATE TABLE `mail_settings` (
  `id` INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
  `smtp_host` varchar(255) DEFAULT NULL,
  `smtp_port` int(11) DEFAULT NULL,
  `smtp_encryption` varchar(16) DEFAULT NULL,
  `smtp_username` varchar(255) DEFAULT NULL,
  `smtp_password` varchar(255) DEFAULT NULL,
  `from_email` varchar(255) DEFAULT NULL,
  `from_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    echo ($dryRun ? "Would run: " : "Running: ") . "CREATE TABLE mail_settings (...)\n";
    if (!$dryRun) {
        $pdo->exec($sql);
    }
}

$rowCheck = $tableCheck ? $pdo->query("SELECT id FROM mail_settings WHERE id = 1")->fetchAll() : [];
if ($tableCheck && $rowCheck) {
    echo "mail_settings row #1 already exists -- skipping seed.\n";
} else {
    echo ($dryRun ? "Would run: " : "Running: ") . "INSERT INTO mail_settings (id) VALUES (1)\n";
    if (!$dryRun) {
        $pdo->exec("INSERT INTO mail_settings (id) VALUES (1)");
    }
}

echo "\n=== " . ($dryRun ? 'Dry run complete' : 'Migration complete') . " ===\n";

if ($dryRun) {
    echo "\nNo changes were written. Re-run with --yes to apply them.\n";
} else {
    echo "\nDone -- SMTP settings can now be configured under Settings -> Email,\n";
    echo "and the booking screen (/consultation/new) will show a Doctor field.\n";
}
