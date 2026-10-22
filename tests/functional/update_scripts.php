<?php
/* The update-from-git tooling: bin/update_from_git.sh (against throwaway git
 * repositories) and the options of the framework's update.sh (against a fake
 * application). Both are plain shell test scripts under tests/shell/ that print
 * "ALL OK" on success; they need only bash, git and python3. */

function zpms_update_scripts_tests(TestRunner $runner): void {
    foreach (['update_from_git.sh' => 'update_from_git.sh (branches, backup, refusals)',
              'update_sh_flags.sh' => 'update.sh options (--yes, --non-interactive, --dry-run)',
              'git_update_wrapper.sh' => 'zpms-git-update wrapper (allowed commands, branch validation)'] as $file => $label) {
        $runner->add($label, function () use ($file) {
            $out = [];
            exec('bash ' . escapeshellarg(ZPMS_TEST_APPDIR . '/tests/shell/' . $file) . ' 2>&1', $out, $rc);
            $text = implode("\n", $out);
            assert_true($rc === 0 && strpos($text, 'ALL OK') !== false, "tests/shell/$file failed:\n" . $text);
        });
    }
}
