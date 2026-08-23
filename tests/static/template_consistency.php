<?php
/* Compiles every .zetem template this app owns through the real
 * ZETEMTemplate compiler (Renderer::cache() -- the same compile step
 * render() itself runs, just without then executing the result) and
 * `php -l`'s the compiled output. This is a much stronger check than
 * brace-counting: it exercises the actual {% %}/{{ }}/{{{ }}} compiler,
 * so a malformed PHP block inside a template, or a broken tag the
 * compiler mishandles, fails here as a real PHP syntax error -- without
 * needing per-template fixture data, since compiling never executes the
 * template. */

function zpms_static_template_consistency(TestRunner $runner): void {
    $root = ZPMS_TEST_APPDIR;
    $templateDir = $root . '/web/templates';

    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($templateDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $entry) {
        if ($entry->isFile() && str_ends_with($entry->getFilename(), '.zetem')) {
            $files[$entry->getPathname()] = $entry->getFilename();
        }
    }
    ksort($files);
    assert_true(count($files) > 0, 'no .zetem templates were found under web/templates -- check the path above');

    $cacheDir = sys_get_temp_dir() . '/zpms_test_template_cache_' . getmypid();
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0700, true);
    }
    Renderer::init([__FWDIR__ . '/templates/', $templateDir . '/'], false, $cacheDir . '/', false);

    foreach ($files as $path => $basename) {
        $rel = substr($path, strlen($root) + 1);
        $runner->add("compiles cleanly: $rel", function () use ($basename, $rel) {
            $cachedFile = Renderer::cache($basename, null);
            assert_true(is_file($cachedFile), "$rel did not produce a cached PHP file");

            $output = [];
            $exitCode = 0;
            exec('php -l ' . escapeshellarg($cachedFile) . ' 2>&1', $output, $exitCode);
            assert_equal(0, $exitCode, "$rel compiled to invalid PHP:\n" . implode("\n", $output));
        });
    }
}
