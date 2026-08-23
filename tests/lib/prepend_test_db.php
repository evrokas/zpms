<?php
/*
 * Loaded via `php -S ... -d auto_prepend_file=...` for every request this
 * suite's HTTP test server handles, BEFORE web/index.php runs. Defines
 * DB_HOST/DB_USER/DB_PASS/DB_NAME (and __APPDIR__/__FWDIR__) from
 * config/db.test.php so they're already locked in by the time index.php's
 * own `include_once(__DIR__ . "/../config/db.php")` runs.
 *
 * define() on an already-defined constant is a silent no-op (PHP emits a
 * warning, keeps the first value) -- so even on a real server where
 * config/db.php holds real production credentials, that file's own
 * DB_HOST/DB_USER/DB_PASS/DB_NAME defines simply lose to the ones set
 * here. This test server can never end up actually using whatever's in
 * config/db.php, regardless of its contents -- see tests/README.md's
 * "Safety" section.
 *
 * Deliberately minimal -- unlike tests/bootstrap.php, this file runs on
 * EVERY request the dev server handles, so it does not regenerate classes
 * or touch the schema. ServerManager only starts this server after the
 * test runner's own tests/bootstrap.php has already done that once (see
 * tests/run_all.php) -- by the time any request reaches here, the
 * generated entity classes and the test database are already in place.
 */

$appRoot = dirname(dirname(__DIR__));
$testConfig = $appRoot . '/config/db.test.php';

if (!is_file($testConfig)) {
    http_response_code(500);
    echo 'Test DB config missing -- see tests/README.md.';
    exit;
}

require_once $testConfig;
