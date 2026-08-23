<?php
/*
 * Dev router for PHP's built-in server (`php -S`), used only by this test
 * suite's HTTP-level functional tests -- replicates web/.htaccess's own
 * rewrite rule, which mod_rewrite normally handles and `php -S` doesn't
 * read:
 *
 *   RewriteCond %{REQUEST_FILENAME} !-d
 *   RewriteCond %{REQUEST_FILENAME} !-f
 *   RewriteRule ^(.*)$ index.php?$1 [QSA,L,PT]
 *
 * i.e. any request that isn't an existing real file/directory gets
 * forwarded to index.php with the request path (no leading slash) as the
 * literal query string -- exactly what RequestClass::__construct() reads
 * back out via $_SERVER['QUERY_STRING'].
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . '/../../web' . $uri;

if ($uri !== '/' && is_file($file)) {
    return false; // let the built-in server serve the static file directly
}

chdir(__DIR__ . '/../../web');
$_SERVER['QUERY_STRING'] = ltrim($uri, '/');

// Kernel::rel_url() derives its path prefix from PHP_SELF's directory --
// on a real deployment (Apache rewriting everything to index.php) that's
// always "/index.php", giving a "/" root. php -S's router script itself
// is what PHP_SELF/SCRIPT_NAME normally point to instead, which would
// wrongly root every generated URL under /tests/lib/ -- override both so
// this dev server resolves URLs exactly like a real deployment at the
// webroot.
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';

require __DIR__ . '/../../web/index.php';
