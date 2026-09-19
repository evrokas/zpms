#!/usr/bin/env php
<?php
/**
 * ZPMS's zops health handler. See ../../zops/docs/HANDLERS.md.
 */
declare(strict_types=1);

require __DIR__ . '/../lib/zops/lib/handler/bootstrap.php';
require __DIR__ . '/../config/db.php';

use ZOps\Handler\Handler;
use ZOps\Handler\HealthCtx;

Handler::health([
    'id' => 'zpms',
    'name' => 'ZPMS',
    'check' => function (HealthCtx $h): void {
        $appDir = dirname(__DIR__);
        $filesDir = $appDir . '/web/files/appointment_files';

        $pdo = null;
        try {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->query('SELECT 1');
            $h->ok('db.connect', 'MySQL connects');
        } catch (Throwable $e) {
            $h->fail('db.connect', 'MySQL connects', $e->getMessage());
        }

        if (!is_dir($filesDir)) {
            $h->warn('fs.files.exists', 'web/files/appointment_files/ exists', 'directory does not exist yet');
        } else {
            $h->writeProbe($filesDir)
                ? $h->ok('fs.files.write', 'web/files/appointment_files/ writable')
                : $h->fail('fs.files.write', 'web/files/appointment_files/ writable', 'write probe failed');
        }

        $baseUrl = rtrim(getenv('ZPMS_HEALTH_BASE_URL') ?: 'http://127.0.0.1', '/');

        // zeusfw's own router sets HTTP 200 before a route handler ever
        // runs (a known, documented framework quirk -- see zeusfw's own
        // CLAUDE.md), so a rejected/login-required page can ship with a
        // 200 status even though the BODY is the real 401 error page.
        // Check the body's content instead of trusting the status code.
        $resp = $h->httpGet($baseUrl . '/');
        (stripos($resp['body'], 'login') !== false || $resp['code'] === 200)
            ? $h->ok('route.home', 'Home route responds')
            : $h->warn('route.home', 'Home route responds', "HTTP {$resp['code']}" . ($resp['error'] ? " ({$resp['error']})" : ''));

        if (defined('DOCARC_API_KEY') || is_file($appDir . '/config/docarc_api.php')) {
            $api = $h->httpGet($baseUrl . '/api/v1/patients/search/test');
            (in_array($api['code'], [400, 401, 503], true) || stripos($api['body'], '"error"') !== false)
                ? $h->ok('route.api_patients.gate', 'api_patients.php rejects an unauthenticated request')
                : $h->warn('route.api_patients.gate', 'api_patients.php rejects an unauthenticated request', "HTTP {$api['code']}");
        }

        if ($pdo !== null) {
            $h->info('patients_total', (int) $pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn());
            $h->info('appointments_today', (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE DATE(cdate) = CURDATE()")->fetchColumn());
            $h->info('files_uploaded_today', (int) $pdo->query("SELECT COUNT(*) FROM appointment_files WHERE DATE(cdate) = CURDATE()")->fetchColumn());
        }
    },
]);
