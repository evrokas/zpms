<?php
/*
 * Reads the plain-text BUILD_NUMBER file at the project root -- a
 * tracked, plain integer bumped by githooks/pre-commit on every commit
 * (see bin/install_git_hooks.sh for the one-time setup that wires that
 * hook up, and README.md's "Build number" section for the full design).
 * Same $rootdir-from-SCRIPT_FILENAME trick githashModule already uses
 * right next to this one in the footer, rather than shelling out to git
 * or introducing a second way to locate the project root.
 *
 * Missing file (a fresh clone before its first hook-driven commit, or an
 * install that never ran bin/install_git_hooks.sh) degrades to simply
 * not rendering anything -- same "optional, never a fatal error"
 * convention as every other footer/status block in this app family.
 */
class buildnumberModule extends moduleClass {
    function render($params = array()) {
        $rootdir = explode('index.php', $_SERVER['SCRIPT_FILENAME'])[0] . "../";

        $buildFile = $rootdir . "BUILD_NUMBER";
        $build = file_exists($buildFile) ? trim(file_get_contents($buildFile)) : null;

        return $this->renderTemplate([
            'build' => $build,
        ]);
    }
}

function register_buildnumber_module() {
    global $kernel;

    $kernel->registerModule( new buildnumberModule(__DIR__, 'buildnumber', 'buildnumber.zetem'));
}
