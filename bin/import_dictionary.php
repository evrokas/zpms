#!/usr/bin/env php
<?php
/*
 * Loads the Greek (and any other) translations in config/dictionary.<lang>.php
 * into the `dictionary` table, which is what t('...') in the templates reads.
 *
 * Without this, a term used by t() for the first time is added to the table
 * with the English text in every language column, so the Greek interface
 * shows English until someone translates it. Run this after deploying a
 * release that added terms; it only touches terms nobody has translated yet,
 * so a translation edited in the dictionary table is never overwritten and
 * it is safe to run every time.
 *
 * Usage:
 *   php bin/import_dictionary.php --dry-run   # say what would change, write nothing
 *   php bin/import_dictionary.php             # import
 *
 * Targets config/db.php by default; set ZPMS_DB_CONFIG to use another file
 * (e.g. config/db.test.php).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);

define('__APPDIR__', dirname(__DIR__));
require_once __DIR__ . '/lib/dictionary_import.php';

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

$files = glob(__APPDIR__ . '/config/dictionary.*.php') ?: [];
if (!$files) {
    echo "No config/dictionary.<lang>.php files found -- nothing to import.\n";
    exit(0);
}

echo $dryRun ? "=== DRY RUN -- nothing will be written ===\n" : "=== Importing dictionary terms ===\n";
foreach ($files as $file) {
    if (!preg_match('/dictionary\.([a-z]{2,8})\.php$/', $file, $m)) continue;
    $lang = $m[1];
    $terms = require $file;
    try {
        $r = zpms_import_dictionary_terms($pdo, $lang, is_array($terms) ? $terms : [], $dryRun);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, basename($file) . ': ' . $e->getMessage() . "\n");
        continue;
    }
    printf("%s: %d added, %d translated, %d left as they are (already translated)\n",
        $lang, $r['added'], $r['translated'], $r['kept']);
}
