<?php
/*
 * The ONLY place test code is allowed to obtain database credentials.
 * Nothing under tests/ ever requires config/db.php (the real, live
 * database config) -- directly, indirectly, or by parsing its contents
 * for anything beyond the one sanity comparison below. See
 * tests/README.md's "Safety" section for the full reasoning.
 *
 * Every guard here is deliberately redundant with the others. A false
 * negative (refusing to run) costs a minute of debugging; a false
 * positive (a destructive test touching the wrong database) is
 * unacceptable on a system holding real patient data, so this fails
 * closed at every step rather than trusting any single check.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ZPMS_TEST_APPDIR', dirname(__DIR__));

function zpms_test_fatal(string $msg): never {
    fwrite(STDERR, "FATAL: $msg\n");
    exit(1);
}

$testConfig = ZPMS_TEST_APPDIR . '/config/db.test.php';

if (!is_file($testConfig)) {
    zpms_test_fatal(
        "config/db.test.php not found.\n" .
        "       Copy config/db.test.php.in to config/db.test.php and fill in a\n" .
        "       dedicated TEST-ONLY database (see tests/README.md)."
    );
}

require_once $testConfig;

foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'] as $const) {
    if (!defined($const)) {
        zpms_test_fatal("config/db.test.php did not define $const.");
    }
}

// Guard #1: the name itself must be unmistakably a test database. This is
// the cheapest, first-line check, and the one re-asserted immediately
// before the actual DROP DATABASE in TestSchema::reset() -- see there for
// why a single check at boot time isn't considered sufficient on its own.
if (stripos(DB_NAME, 'test') === false) {
    zpms_test_fatal(
        "config/db.test.php's DB_NAME ('" . DB_NAME . "') does not contain \"test\".\n" .
        "       Refusing to run -- this suite only ever runs against a database whose\n" .
        "       name makes it unmistakable, because it recreates that database from\n" .
        "       scratch on every run."
    );
}

// Guard #2: if a real config/db.php happens to exist alongside this test
// checkout (a real dev/prod server, as opposed to a CI runner with no
// production config at all), make sure the test config didn't somehow end
// up pointing at that same database. Deliberately reads this as plain text
// and regexes out DB_NAME -- never `require`s config/db.php, so this
// process can never actually obtain real credentials, even by accident.
$prodConfig = ZPMS_TEST_APPDIR . '/config/db.php';
if (is_file($prodConfig)) {
    $prodSrc = file_get_contents($prodConfig);
    if ($prodSrc !== false && preg_match("/define\\(\\s*'DB_NAME'\\s*,\\s*'([^']*)'/", $prodSrc, $m)) {
        if (strcasecmp($m[1], DB_NAME) === 0) {
            zpms_test_fatal(
                "config/db.test.php's DB_NAME is identical to config/db.php's ('{$m[1]}').\n" .
                "       Refusing to run -- this would mean tests running against the live database."
            );
        }
    }
}

// config/db.test.php (required above) already defines both of these
// itself (same guarded pattern as the real config/db.php.in) -- only
// define them here for the case a future db.test.php.in edit ever drops
// that, so this doesn't silently depend on it.
if (!defined('__APPDIR__')) define('__APPDIR__', ZPMS_TEST_APPDIR);
if (!defined('__FWDIR__')) define('__FWDIR__', ZPMS_TEST_APPDIR . '/web/core');

if (!is_dir(__FWDIR__)) {
    zpms_test_fatal(
        __FWDIR__ . " not found.\n" .
        "       Vendor the zeusfw framework there first, e.g.:\n" .
        "         ln -s /path/to/zeusfw/core web/core\n" .
        "       (same as a normal dev/deploy checkout -- web/core is gitignored and\n" .
        "       never committed to this repo either way)."
    );
}

require_once __DIR__ . '/lib/Assert.php';
require_once __DIR__ . '/lib/TestSchema.php';

// Regenerate the entity class PHP files (patientsClass, appointmentsClass,
// ...) from web/classes/yaml/*.yaml BEFORE requiring the framework
// bootstrap below -- that require is what pulls in
// web/classes/bootstrap_classes.php, which fatals immediately on a fresh
// checkout where these generated files don't exist yet. See
// TestSchema::regenerateClasses()'s own docblock for the rest of the
// reasoning (this is also a real consistency check in its own right).
TestSchema::regenerateClasses(__FWDIR__, ZPMS_TEST_APPDIR);

// web/user_classes.php (loaded by the framework bootstrap below) reads
// classes/bootstrap_classes.php via a path relative to the current
// working directory, not to itself -- true of every real request too
// (Apache's DocumentRoot is web/, and the dev router.php this suite's
// HTTP-level tests spin up chdir()s to web/ for the same reason).
// Matching that here so the same relative include resolves the same way
// whether a class gets loaded via a real HTTP request or directly by a
// test that talks to the entity classes without going through HTTP.
chdir(ZPMS_TEST_APPDIR . '/web');

// zeusfw's maker.php spill_class() has a real (separately-flagged, not
// fixed here -- see tests/README.md's "Known upstream quirks") code-gen
// bug: every generated entity class file starts with a few lines of
// stray whitespace before its opening `<?php` tag, which PHP echoes
// verbatim on every require. Harmless in the real app (every request
// path that matters is wrapped in its own output buffering that discards
// it -- confirmed by this suite's own file-download test actually
// passing), but it would otherwise splatter noise across every test run
// here, every time. Buffered and discarded once, right where it happens.
ob_start();
require_once __FWDIR__ . '/bootstrap.php';
ob_end_clean();

require_once __DIR__ . '/lib/HttpClient.php';
require_once __DIR__ . '/lib/ServerManager.php';

dbConnection::init(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Belt-and-suspenders #3, checked by every functional test file via
// TestSchema::assertSafeToMutate() before it writes anything -- confirms
// the connection actually landed on a database this suite itself
// prepared (see the _test_suite_marker table TestSchema::reset() creates)
// rather than some other, unrelated database that merely has "test" in
// its name.
