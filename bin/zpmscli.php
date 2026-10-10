#!/usr/bin/env php
<?php
/*
 * zpmscli -- housekeeping for the ZPMS database and its attachment files,
 * in the style of zeusfw's maker.php: `area:action` commands, --flag=value
 * options that may sit anywhere on the line.
 *
 *   php bin/zpmscli.php help
 *
 * Anything that changes data runs as a DRY RUN -- it prints what it would do
 * and writes nothing -- unless --yes is given. Destructive commands also need
 * to be told what to act on (ids, --user, --older-than); there is no
 * accidental "everything".
 *
 * Targets config/db.php like the web app; set ZPMS_DB_CONFIG to use another
 * database config (e.g. config/db.test.php). Acts on the ZPMS database and the
 * files under web/files/appointment_files only -- never on zeusfw.
 *
 * The work is done by bin/lib/zpms_cli.php (shared with the test suite).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

define('__APPDIR__', dirname(__DIR__));
require_once __DIR__ . '/lib/zpms_cli.php';

const ZPMSCLI_COMMANDS = [
    'users:list'              => '                                list the user names that can own records',
    'patients:list'           => '[--q=] [--user=] [--deleted] [--limit=50]   find patient ids',
    'appointments:list'       => '<patient id|guid>               list one patient\'s appointments',
    'patients:reassign'       => '--to=<user> [--from=<user>] [--patient=<id,...>] [--only=patients,appointments,files,history,operations] [--force] [--yes]',
    'patients:delete'         => '<id|guid ...> [--yes]           soft delete (same as the web interface; recoverable)',
    'patients:purge'          => '<id|guid ...> [--yes]           hard delete: appointments, attachments, files on disk, history',
    'appointments:delete'     => '<id|guid ...> [--yes]           soft delete appointments',
    'appointments:purge'      => '<id|guid ...> [--yes]           hard delete appointments, attachments and files',
    'cleanup:deleted'         => '--older-than=<days> | --all [--yes]   purge everything already soft-deleted',
    'cleanup:orphans'         => '[--yes]                         remove attachment/history rows whose appointment is gone; report stray files',
    'cache:clean'             => '[--yes]                         delete web/cache/*.php (compiled templates)',
    'help'                    => '',
];

function zpmscli_say(string $line = ''): void {
    echo $line . "\n";
}

function zpmscli_fail(string $message, int $code = 1): void {
    fwrite(STDERR, $message . "\n");
    exit($code);
}

function zpmscli_usage(): void {
    zpmscli_say('Usage: php bin/zpmscli.php [options] <command> [arguments]');
    zpmscli_say();
    zpmscli_say('Nothing is written unless --yes is given; without it every command is a dry run.');
    zpmscli_say();
    foreach (ZPMSCLI_COMMANDS as $name => $help) {
        if ($help !== '') zpmscli_say(sprintf('  %-20s %s', $name, $help));
    }
    zpmscli_say();
    zpmscli_say('Reassigning, deleting and purging change records for good (purge cannot be undone):');
    zpmscli_say('take a database backup first. Set ZPMS_DB_CONFIG to use another database config.');
}

function zpmscli_connect(): PDO {
    $configPath = getenv('ZPMS_DB_CONFIG') ?: (__APPDIR__ . '/config/db.php');
    if (!is_file($configPath)) {
        zpmscli_fail("Database config not found: $configPath\n(copy config/db.php.in to config/db.php first, or set ZPMS_DB_CONFIG)");
    }
    require_once $configPath;
    try {
        return new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        zpmscli_fail('Could not connect to the database: ' . $e->getMessage());
    }
}

function zpmscli_print_report(array $report): void {
    foreach ($report as $key => $value) {
        zpmscli_say(sprintf('  %-30s %s', str_replace('_', ' ', $key), $key === 'bytes' || str_ends_with($key, '_bytes') ? zpmscli_size((int)$value) : $value));
    }
}

function zpmscli_size(int $bytes): string {
    foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
        if ($bytes < 1024 || $unit === 'GB') return $unit === 'B' ? "$bytes B" : sprintf('%.1f %s', $bytes, $unit);
        $bytes /= 1024;
    }
    return (string)$bytes;
}

function zpmscli_print_patients(array $patients, int $max = 20): void {
    foreach (array_slice($patients, 0, $max) as $p) {
        zpmscli_say(sprintf('  #%-6d %-32s created by %-14s%s', $p['id'], mb_strimwidth($p['pname'], 0, 32, '...'), $p['cuser'] ?? '-',
            $p['deleted'] !== null ? '  [deleted ' . $p['deleted'] . ']' : ''));
    }
    if (count($patients) > $max) zpmscli_say('  ... and ' . (count($patients) - $max) . ' more');
}

[$command, $args, $options] = zpmscli_parse_argv($argv);
$apply = isset($options['yes']);

if ($command === null || $command === 'help' || isset($options['help'])) {
    zpmscli_usage();
    exit($command === null ? 1 : 0);
}
if (!array_key_exists($command, ZPMSCLI_COMMANDS)) {
    fwrite(STDERR, "Unknown command: $command\n\n");
    zpmscli_usage();
    exit(1);
}

date_default_timezone_set(zpmscli_timezone(__APPDIR__));
$now = date('Y-m-d H:i:s');
$filesDir = zpmscli_files_dir(__APPDIR__);
$logFile = dirname($filesDir) . '/logs/zpmscli.log';

// ---- commands that need no database --------------------------------------
if ($command === 'cache:clean') {
    zpmscli_say($apply ? '=== Cleaning web/cache ===' : '=== DRY RUN -- nothing will be written ===');
    $report = zpmscli_clean_cache(__APPDIR__ . '/web/cache', $apply);
    zpmscli_print_report($report);
    if ($apply) zpmscli_log_run($logFile, $command, $report);
    else zpmscli_say('Add --yes to delete them.');
    exit(0);
}

$db = zpmscli_connect();

try {
    switch ($command) {
        case 'users:list':
            foreach ($db->query('SELECT uname, name, email, active FROM users ORDER BY uname')->fetchAll(PDO::FETCH_ASSOC) as $u) {
                zpmscli_say(sprintf('%-20s %-30s %s%s', $u['uname'], $u['name'] ?? '', $u['email'] ?? '', $u['active'] ? '' : '  [inactive]'));
            }
            exit(0);

        case 'patients:list':
            $rows = zpmscli_list_patients($db, is_string($options['q'] ?? null) ? $options['q'] : null,
                is_string($options['user'] ?? null) ? $options['user'] : null, isset($options['deleted']), (int)($options['limit'] ?? 50));
            zpmscli_print_patients($rows, PHP_INT_MAX);
            zpmscli_say(count($rows) . ' patient(s).');
            exit(0);

        case 'appointments:list':
            $patients = zpmscli_find_patients($db, $args);
            if (!$args || !$patients) zpmscli_fail('Usage: appointments:list <patient id|guid>');
            foreach ($patients as $p) {
                zpmscli_say("Patient #{$p['id']} {$p['pname']}");
                foreach (zpmscli_appointments_of($db, [$p['guid']]) as $a) {
                    zpmscli_say(sprintf('  #%-6d %s  %-12s created by %-14s%s', $a['id'], $a['adate'], $a['atype'], $a['cuser'] ?? '-',
                        $a['deleted'] !== null ? '  [deleted ' . $a['deleted'] . ']' : ''));
                }
            }
            exit(0);

        case 'patients:reassign':
            $to = is_string($options['to'] ?? null) ? $options['to'] : null;
            $from = is_string($options['from'] ?? null) ? $options['from'] : null;
            if ($to === null) zpmscli_fail('Usage: patients:reassign --to=<user> [--from=<user>] [--patient=<id,...>] [--only=...] [--yes]');
            $selectors = array_merge(zpmscli_split_list($options['patient'] ?? null), $args);
            zpmscli_say($apply ? '=== Reassigning records ===' : '=== DRY RUN -- nothing will be written ===');
            $report = zpmscli_reassign($db, $from, $to, $selectors, zpmscli_split_list($options['only'] ?? null), $apply, isset($options['force']));
            zpmscli_say("Rows that " . ($apply ? 'now show' : 'would show') . " '$to' as their creator:");
            zpmscli_print_report($report);
            break;

        case 'patients:delete':
        case 'patients:purge':
            if (!$args) zpmscli_fail("Usage: $command <id|guid ...> [--yes]");
            $patients = zpmscli_find_patients($db, $args);
            if (!$patients) zpmscli_fail('No such patient.');
            zpmscli_say($apply ? '=== ' . ($command === 'patients:purge' ? 'Purging' : 'Deleting') . ' patients ===' : '=== DRY RUN -- nothing will be written ===');
            zpmscli_print_patients($patients);
            if ($command === 'patients:delete') {
                $report = zpmscli_soft_delete_patients($db, $patients, $now, $apply);
            } else {
                $report = zpmscli_purge_patients($db, $patients, $filesDir, $apply);
                zpmscli_say('Purging cannot be undone -- have you backed up the database and web/files?');
            }
            zpmscli_print_report($report);
            break;

        case 'appointments:delete':
        case 'appointments:purge':
            if (!$args) zpmscli_fail("Usage: $command <id|guid ...> [--yes]");
            $appointments = zpmscli_find_appointments($db, $args);
            if (!$appointments) zpmscli_fail('No such appointment.');
            zpmscli_say($apply ? '=== ' . ($command === 'appointments:purge' ? 'Purging' : 'Deleting') . ' appointments ===' : '=== DRY RUN -- nothing will be written ===');
            if ($command === 'appointments:delete') {
                $report = zpmscli_soft_delete_appointments($db, $appointments, $now, $apply);
            } else {
                $report = zpmscli_purge_appointments($db, $appointments, $filesDir, $apply);
                zpmscli_say('Purging cannot be undone -- have you backed up the database and web/files?');
            }
            zpmscli_print_report($report);
            break;

        case 'cleanup:deleted':
            $older = $options['older-than'] ?? null;
            if (!isset($options['all']) && !(is_string($older) && ctype_digit($older))) {
                zpmscli_fail('Usage: cleanup:deleted --older-than=<days> | --all [--yes]   (--all purges every soft-deleted record, however recent)');
            }
            zpmscli_say($apply ? '=== Purging deleted records ===' : '=== DRY RUN -- nothing will be written ===');
            $report = zpmscli_cleanup_deleted($db, isset($options['all']) ? null : (int)$older, $now, $filesDir, $apply);
            zpmscli_say('Purging cannot be undone -- have you backed up the database and web/files?');
            zpmscli_print_report($report);
            break;

        case 'cleanup:orphans':
            zpmscli_say($apply ? '=== Removing orphaned rows ===' : '=== DRY RUN -- nothing will be written ===');
            $report = zpmscli_cleanup_orphans($db, $filesDir, $apply);
            zpmscli_print_report($report);
            zpmscli_say('Appointments without a patient and files no row points at are only reported -- look at them by hand.');
            break;
    }
} catch (InvalidArgumentException $e) {
    zpmscli_fail($e->getMessage());
} catch (Throwable $e) {
    zpmscli_fail('Failed, nothing was changed by the step that failed: ' . $e->getMessage());
}

if ($apply) {
    zpmscli_log_run($logFile, $command, $report);
    zpmscli_say('Done.');
} else {
    zpmscli_say('Dry run only. Add --yes to apply.');
}
