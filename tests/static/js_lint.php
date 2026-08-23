<?php
/* `node --check` across every JS file this repo owns (vanilla JS, no
 * build step -- same convention as the other apps in this project
 * family). */

function zpms_static_js_lint(TestRunner $runner): void {
    $root = ZPMS_TEST_APPDIR;

    $patterns = [
        $root . '/web/js/*.js',
        $root . '/web/modules/*/js/*.js',
    ];

    $files = [];
    foreach ($patterns as $pattern) {
        foreach (glob($pattern) as $file) {
            $files[$file] = true;
        }
    }
    ksort($files);
    assert_true(count($files) > 0, 'no JS files were found to lint -- check the glob patterns above');

    foreach (array_keys($files) as $file) {
        $rel = substr($file, strlen($root) + 1);
        $runner->add("node --check $rel", function () use ($file, $rel) {
            $output = [];
            $exitCode = 0;
            exec('node --check ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
            assert_equal(0, $exitCode, "$rel:\n" . implode("\n", $output));
        });
    }
}
