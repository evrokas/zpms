#!/usr/bin/env php
<?php
/**
 * ZPMS's zops backup handler -- replaces bin/backup.sh. See
 * ../../zops/docs/HANDLERS.md.
 *
 * Elements shipped:
 *   - db     mysqldump of the ZPMS database (--single-transaction)
 *   - files  web/files/appointment_files/ -- patient appointment files +
 *            generated JPEG thumbnails (appointment_files.file_path/
 *            thumbnail_path, both relative to this same directory --
 *            core_get_dir_in_lib('appointment_files')), hardlinked live
 *   - config config/db.php (+ config/docarc_api.php, if this install has
 *            wired the DocArc patient-lookup integration)
 */
declare(strict_types=1);

require __DIR__ . '/../lib/zops/lib/handler/bootstrap.php';
require __DIR__ . '/../config/db.php';

use ZOps\Handler\ConfigBundle;
use ZOps\Handler\Ctx;
use ZOps\Handler\Dir;
use ZOps\Handler\Handler;
use ZOps\Handler\MysqlDump;

Handler::backup([
    'id' => 'zpms',
    'name' => 'ZPMS',
    'prepare' => function (Ctx $c): void {
        $appDir = dirname(__DIR__);
        $filesDir = $appDir . '/web/files/appointment_files';

        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stats = ['patients' => 0, 'appointments' => 0, 'files' => 0, 'missing_files' => 0];
        $stats['patients'] = (int) $pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn();
        $stats['appointments'] = (int) $pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn();

        $stmt = $pdo->query('SELECT file_path, thumbnail_path FROM appointment_files');
        foreach ($stmt as $row) {
            $stats['files']++;
            if (!is_file($filesDir . '/' . $row['file_path'])) {
                $stats['missing_files']++;
            }
            if ($row['thumbnail_path'] !== null && $row['thumbnail_path'] !== '' && !is_file($filesDir . '/' . $row['thumbnail_path'])) {
                $stats['missing_files']++;
            }
        }

        $c->add(MysqlDump::zip($c, 'db', ['host' => DB_HOST, 'user' => DB_USER, 'pass' => DB_PASS, 'db' => DB_NAME]));
        $c->add(Dir::link($c, 'files', $filesDir));

        $configPaths = [$appDir . '/config/db.php'];
        if (is_file($appDir . '/config/docarc_api.php')) {
            $configPaths[] = $appDir . '/config/docarc_api.php';
        }
        $c->add(ConfigBundle::zip($c, 'config', $configPaths));

        $c->setStats($stats);
    },
]);
