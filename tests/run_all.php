<?php
/*
 * Entry point for the ZPMS regression suite. Run via bin/run_tests.sh,
 * not directly -- see tests/README.md for setup and usage.
 *
 * Usage: php tests/run_all.php [--static-only|--functional-only]
 */

require_once __DIR__ . '/bootstrap.php';

$mode = 'all';
if (in_array('--static-only', $argv, true)) $mode = 'static';
if (in_array('--functional-only', $argv, true)) $mode = 'functional';

$ok = true;

if ($mode !== 'functional') {
    echo "=== Static consistency checks (PHP / JS / CSS / templates) ===\n\n";

    require_once __DIR__ . '/static/php_lint.php';
    require_once __DIR__ . '/static/js_lint.php';
    require_once __DIR__ . '/static/css_consistency.php';
    require_once __DIR__ . '/static/template_consistency.php';
    require_once __DIR__ . '/static/dictionary_terms.php';

    $phpRunner = new TestRunner('PHP syntax');
    zpms_static_php_lint($phpRunner);
    $ok = $phpRunner->run() && $ok;

    $jsRunner = new TestRunner('JS syntax');
    zpms_static_js_lint($jsRunner);
    $ok = $jsRunner->run() && $ok;

    $cssRunner = new TestRunner('CSS consistency');
    zpms_static_css_consistency($cssRunner);
    $ok = $cssRunner->run() && $ok;

    $templateRunner = new TestRunner('Template consistency');
    zpms_static_template_consistency($templateRunner);
    $ok = $templateRunner->run() && $ok;

    $dictionaryRunner = new TestRunner('Dictionary terms');
    zpms_static_dictionary_terms($dictionaryRunner);
    $ok = $dictionaryRunner->run() && $ok;
}

if ($mode === 'static') {
    exit($ok ? 0 : 1);
}

if (!$ok && $mode === 'all') {
    fwrite(STDERR, "\nStatic checks failed -- skipping functional tests (fix the above first).\n");
    exit(1);
}

echo "=== Functional regression tests ===\n\n";

require_once __DIR__ . '/lib/TestFixtures.php';
require_once __DIR__ . '/functional/patient_crud.php';
require_once __DIR__ . '/functional/appointment_crud.php';
require_once __DIR__ . '/functional/auth_csrf.php';
require_once __DIR__ . '/functional/file_upload.php';
require_once __DIR__ . '/functional/admin_crud.php';
require_once __DIR__ . '/functional/zpmscli.php';
require_once __DIR__ . '/functional/audit_log.php';
require_once __DIR__ . '/functional/schedule.php';
require_once __DIR__ . '/functional/booking.php';
require_once __DIR__ . '/functional/calendar_sync.php';
require_once __DIR__ . '/functional/update_scripts.php';
require_once __DIR__ . '/functional/system_update.php';
require_once __DIR__ . '/functional/config_files.php';
require_once __DIR__ . '/functional/session_policy.php';

// Fresh database + a login-capable test account, every run -- see
// TestSchema::reset()'s own docblock for why this matters.
TestSchema::reset();
TestFixtures::createTestUser();

// The stand-in for the sudo wrapper behind /system/update (see functional/system_update.php).
$suStub = zpms_su_stub();
putenv('ZPMS_UPDATE_TEST_WRAPPER=' . $suStub);
$GLOBALS['suStubForTests'] = $suStub;

$server = new TestServer();
$server->start();

try {
    $http = new TestHttpClient($server->baseUrl());
    TestFixtures::loginAsTestUser($http);

    $patientRunner = new TestRunner('Patient CRUD');
    zpms_functional_patient_crud($patientRunner, $http);
    $ok = $patientRunner->run() && $ok;

    $appointmentRunner = new TestRunner('Appointment CRUD');
    zpms_functional_appointment_crud($appointmentRunner, $http);
    $ok = $appointmentRunner->run() && $ok;

    $fileRunner = new TestRunner('File uploads');
    zpms_functional_file_upload($fileRunner, $http);
    $ok = $fileRunner->run() && $ok;

    // Its own clients (logged-out, wrong-password, tampered-token cases) --
    // deliberately not sharing the already-authenticated $http above.
    $authRunner = new TestRunner('Auth + CSRF');
    zpms_functional_auth_csrf($authRunner, $server->baseUrl());
    $ok = $authRunner->run() && $ok;

    // Fresh server for this suite, rather than the same long-lived one
    // the four suites above already ran dozens of requests through --
    // php -S's single-process dev server reliably became unresponsive
    // (every further request timing out, including a bare unauthenticated
    // GET /login) a couple of requests into this suite specifically when
    // it ran on the shared, already-busy server, but never when the exact
    // same suite ran against its own fresh one (confirmed by running it
    // standalone). Restarting between suites costs under a second and
    // sidesteps whatever that dev-server-only degradation is -- it isn't
    // specific to anything this suite's requests do differently from the
    // others, and a real deployment never runs under php -S anyway.
    $server->stop();
    $server = new TestServer();
    $server->start();

    // Its own is_superuser client too -- the plain doctor $http above
    // is not granted users-manage.
    $adminCrudRunner = new TestRunner('Admin CRUD (users/roles/permissions)');
    zpms_functional_admin_crud($adminCrudRunner, $server->baseUrl());
    $ok = $adminCrudRunner->run() && $ok;

    // zpmscli (bin/zpmscli.php) -- straight against the test database, no HTTP.
    $cliRunner = new TestRunner('zpmscli (housekeeping commands)');
    zpmscli_functional_tests($cliRunner);
    $ok = $cliRunner->run() && $ok;

    // The GDPR audit log (web/audit.php) -- its own fresh server, same
    // reason as the admin suite above.
    $server->stop();
    $server = new TestServer();
    $server->start();
    $auditRunner = new TestRunner('Audit log (GDPR)');
    zpms_functional_audit_log($auditRunner, $server->baseUrl());
    $ok = $auditRunner->run() && $ok;

    // The scheduling engine (web/schedule.php) -- rules on built data plus the
    // database-backed booking functions; no HTTP.
    $scheduleRunner = new TestRunner('Schedule engine (slots, travel, surgery shadow)');
    zpms_schedule_functional_tests($scheduleRunner);
    $ok = $scheduleRunner->run() && $ok;

    // The schedule page and its JSON endpoints, over HTTP (fresh server).
    $server->stop();
    $server = new TestServer();
    $server->start();
    $scheduleHttpRunner = new TestRunner('Schedule pages (calendar, pattern, travel, reasons)');
    zpms_schedule_http_tests($scheduleHttpRunner, $server->baseUrl());
    $ok = $scheduleHttpRunner->run() && $ok;

    // Booking through the forms (pending appointments, appointments, operations, rescheduling).
    $server->stop();
    $server = new TestServer();
    $server->start();
    $bookingRunner = new TestRunner('Booking through the forms');
    zpms_booking_functional_tests($bookingRunner, $server->baseUrl());
    $ok = $bookingRunner->run() && $ok;

    // What goes to Google Calendar from the schedule (stand-in for Google; no network).
    $calendarRunner = new TestRunner('Google Calendar sync of the schedule');
    zpms_calendar_sync_functional_tests($calendarRunner);
    $ok = $calendarRunner->run() && $ok;

    $cfgRunner = new TestRunner('Configuration files (config_files:)');
    zpms_config_files_tests($cfgRunner);
    $ok = $cfgRunner->run() && $ok;

    $sessRunner = new TestRunner('Session lifetime (idle logout, cap, remember me)');
    zpms_session_policy_tests($sessRunner, $server->baseUrl());
    $ok = $sessRunner->run() && $ok;

    $suRunner = new TestRunner('Update from git page');
    zpms_system_update_tests($suRunner, $server->baseUrl(), $suStub);
    $ok = $suRunner->run() && $ok;

    $updateRunner = new TestRunner('Update-from-git scripts');
    zpms_update_scripts_tests($updateRunner);
    $ok = $updateRunner->run() && $ok;
} finally {
    $server->stop();
}

echo $ok ? "ALL TESTS PASSED\n" : "TESTS FAILED -- do not deploy this change.\n";
exit($ok ? 0 : 1);
