#!/usr/bin/env php
<?php
/*
 * One-time data-safety step for the appointments.aoperationnotes ->
 * (merged into) appointments.anote change -- see web/classes/yaml/
 * appointments.yaml's own comment for the full story: a separate
 * "Λεπτομέρειες Επέμβασης" textarea for operation-type appointments was
 * removed in favor of a single shared anote field used by both plain
 * appointments and operations. That column is being dropped from the
 * schema, but it may still hold real clinical documentation on an
 * existing install -- this script copies anything in it into anote
 * BEFORE that happens, so nothing is silently lost.
 *
 * Deliberately raw PDO, not appointmentsClass -- once the schema is
 * regenerated from the updated yaml (spill:class:all), the class no
 * longer has get/setaoperationnotes() at all, so this can't depend on
 * it. Reads the column directly by name instead, which still works as
 * long as it's still physically present in the table (this script's
 * whole reason to exist is to run BEFORE it's dropped).
 *
 * Idempotent and safe to re-run: a row is only touched if its
 * aoperationnotes value isn't already a substring of its current anote
 * (i.e. skips anything this script -- or a previous run of it -- already
 * merged in), so running it twice in a row is a no-op the second time.
 *
 * Usage:
 *   php bin/migrate_operation_notes.php --dry-run     # show what WOULD change, write nothing
 *   php bin/migrate_operation_notes.php --yes         # actually merge
 *
 * Run --dry-run first, always -- it prints every row that would be
 * touched and exactly what would be appended to anote, without writing
 * anything.
 *
 * Targets config/db.php (the real database) by default, same as the live
 * app itself -- set ZPMS_DB_CONFIG to point at a different config file
 * (e.g. config/db.test.php) to run against a test database instead.
 *
 * IMPORTANT -- deployment ordering: run this with --yes BEFORE
 * regenerating web/classes/appointments.php / web/classes/sql/
 * appointments.sql from the updated yaml (which drops aoperationnotes
 * from the generated class), and well before actually executing an
 * ALTER TABLE ... DROP aoperationnotes against the live database. This
 * script only ever reads that column and writes anote -- it never drops
 * anything itself; the exact DROP statement is printed at the end once
 * every row has been merged, for you to run by hand once you're satisfied
 * nothing was missed (this framework has no migration runner, so that
 * last step -- like every other schema change in this app -- is manual).
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
      php bin/migrate_operation_notes.php --dry-run     # show what WOULD change, write nothing
      php bin/migrate_operation_notes.php --yes         # actually merge

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

$columnCheck = $pdo->query("SHOW COLUMNS FROM appointments LIKE 'aoperationnotes'")->fetchAll();
if (!$columnCheck) {
    echo "appointments.aoperationnotes doesn't exist in this database -- nothing to migrate\n";
    echo "(already dropped, or this schema never had it).\n";
    exit(0);
}

echo $dryRun ? "=== DRY RUN -- no changes will be written ===\n\n" : "=== Migrating ===\n\n";

$rows = $pdo->query(
    "SELECT id, anote, aoperationnotes FROM appointments
     WHERE aoperationnotes IS NOT NULL AND TRIM(aoperationnotes) != ''"
)->fetchAll(PDO::FETCH_ASSOC);

$update = $pdo->prepare("UPDATE appointments SET anote = :anote WHERE id = :id");

$touched = 0;
$skipped = 0;

foreach ($rows as $row) {
    $opNotes = trim($row['aoperationnotes']);
    $existingNote = (string)($row['anote'] ?? '');

    if ($opNotes !== '' && strpos($existingNote, $opNotes) !== false) {
        // Already merged in (this row was touched by a previous run of
        // this same script) -- skip so a re-run stays a no-op.
        $skipped++;
        continue;
    }

    $mergedNote = trim($existingNote) === ''
        ? $opNotes
        : $existingNote . "\n\n--- Λεπτομέρειες Επέμβασης ---\n" . $opNotes;

    echo "Appointment #{$row['id']}: would append operation notes to anote\n";
    if ($dryRun) {
        echo "  aoperationnotes: " . $opNotes . "\n\n";
    } else {
        $update->execute(['anote' => $mergedNote, 'id' => $row['id']]);
    }

    $touched++;
}

echo "\n=== " . ($dryRun ? 'Dry run complete' : 'Migration complete')
    . " -- {$touched} row(s) " . ($dryRun ? 'would be' : 'were') . " updated, {$skipped} already merged ===\n";

if ($dryRun) {
    echo "\nNo changes were written. Re-run with --yes to apply them.\n";
} else {
    echo "\nOnce you've confirmed the migrated anote content looks right (spot-check a\n";
    echo "few of the appointment IDs listed above), the aoperationnotes column itself\n";
    echo "can be dropped by hand -- this script never does that automatically:\n\n";
    echo "  ALTER TABLE appointments DROP aoperationnotes;\n\n";
    echo "Only run that once web/classes/appointments.php has also been regenerated\n";
    echo "from the updated yaml (spill:class:all -- see tests/lib/TestSchema.php's\n";
    echo "regenerateClasses() for the exact commands), so the deployed app code has\n";
    echo "already stopped reading/writing that column before it's physically dropped.\n";
}
