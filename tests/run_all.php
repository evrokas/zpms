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

// Fresh database + a login-capable test account, every run -- see
// TestSchema::reset()'s own docblock for why this matters.
TestSchema::reset();
TestFixtures::createTestUser();

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

    // Its own is_superuser client too -- the plain power-user $http above
    // is not granted users-manage.
    $adminCrudRunner = new TestRunner('Admin CRUD (users/roles/permissions)');
    zpms_functional_admin_crud($adminCrudRunner, $server->baseUrl());
    $ok = $adminCrudRunner->run() && $ok;
} finally {
    $server->stop();
}

echo $ok ? "ALL TESTS PASSED\n" : "TESTS FAILED -- do not deploy this change.\n";
exit($ok ? 0 : 1);
