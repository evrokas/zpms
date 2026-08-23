<?php
/* Brace-balance check across every stylesheet this repo owns. Cheap and
 * crude, but it catches exactly the class of mistake a hand-edit
 * introduces -- a truncated rule, a stray/missing closing brace -- the
 * same class of bug found and fixed by hand earlier in this app's own
 * CSS cleanup. Excludes the vendored boxicons library (bx/) -- not this
 * app's own code. */

function zpms_static_css_consistency(TestRunner $runner): void {
    $root = ZPMS_TEST_APPDIR;

    $files = [];
    foreach (glob($root . '/web/css/*.css') as $file) {
        $files[$file] = true;
    }
    foreach (glob($root . '/web/modules/*/css/*.css') as $file) {
        $files[$file] = true;
    }
    ksort($files);
    assert_true(count($files) > 0, 'no CSS files were found to check -- check the glob patterns above');

    foreach (array_keys($files) as $file) {
        $rel = substr($file, strlen($root) + 1);
        $runner->add("brace balance: $rel", function () use ($file, $rel) {
            $css = file_get_contents($file);
            assert_true($css !== false, "could not read $rel");

            $open = substr_count($css, '{');
            $close = substr_count($css, '}');
            assert_equal($open, $close, "$rel has $open '{' but $close '}' -- likely a truncated/broken rule");
        });
    }
}
