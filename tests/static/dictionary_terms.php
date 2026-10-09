<?php
/* Interface text goes through the dictionary: a template says
 * t('English token') and config/dictionary.gr.php (loaded by
 * `php bin/import_dictionary.php`) supplies the Greek. Two ways that can
 * quietly go wrong, both caught here without a database:
 *   1. a t('...') term that has no entry in config/dictionary.gr.php, so a
 *      Greek page shows English until someone notices;
 *   2. Greek typed straight into a template (outside comments and
 *      <script> blocks), which no language switch can ever translate.
 * Only the files listed below are held to this; the login page's own terms
 * are translated in the dictionary table directly, and the older list/form
 * templates have not been converted. */

function zpms_static_dictionary_terms(TestRunner $runner): void {
    $root = ZPMS_TEST_APPDIR;
    $dictionary = require $root . '/config/dictionary.gr.php';

    $templates = [
        'web/templates/content/edit_appointment.zetem',
        'web/templates/content/edit_patient.zetem',
        'web/templates/content/homepage.zetem',
        'web/templates/content/new_consultation.zetem',
        'web/templates/content/patients_list.zetem',
        'web/templates/content/pending_appointment_convert.zetem',
        'web/templates/content/pending_appointment_edit.zetem',
        'web/templates/content/pending_appointments_list.zetem',
        'web/templates/content/settings.zetem',
        'web/templates/content/view_appointment.zetem',
        'web/templates/blocks/user_profile.zetem',
        'web/templates/apps/genqr/genqr.zetem',
    ];
    $phpFiles = [
        'web/index.php',
        'web/appointment_history.php',
        'web/zpms_mailer.php',
    ];

    foreach (array_merge($templates, $phpFiles) as $rel) {
        $runner->add("every t() term has a Greek entry: $rel", function () use ($root, $rel, $dictionary) {
            $source = file_get_contents($root . '/' . $rel);
            assert_true($source !== false, "$rel could not be read");
            // Documentation in comments may mention t('...') as an example.
            $source = preg_replace('/\{#.*?#\}|<!--.*?-->/s', '', $source);

            // A string literal handed straight to t(): t('...') or t("..."),
            // with \' / \" escapes inside. (A t() call with a variable
            // argument is not a fixed term and is not matched.)
            preg_match_all('/\bt\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/', $source, $m, PREG_SET_ORDER);
            $missing = [];
            foreach ($m as $hit) {
                $term = stripcslashes($hit[1] !== '' ? $hit[1] : ($hit[2] ?? ''));
                if ($term !== '' && !array_key_exists($term, $dictionary)) {
                    $missing[$term] = true;
                }
            }
            assert_equal([], array_keys($missing), "$rel uses t() terms missing from config/dictionary.gr.php");
        });
    }

    foreach ($templates as $rel) {
        $runner->add("no Greek typed into the template: $rel", function () use ($root, $rel) {
            $source = file_get_contents($root . '/' . $rel);
            assert_true($source !== false, "$rel could not be read");

            $source = preg_replace('/\{#.*?#\}|<!--.*?-->|<script\b.*?<\/script>|<style\b.*?<\/style>/s', '', $source);
            $found = [];
            foreach (explode("\n", $source) as $line) {
                if (preg_match('/[\x{0370}-\x{03FF}\x{1F00}-\x{1FFF}]/u', $line)) {
                    $found[] = trim($line);
                }
            }
            assert_equal([], $found, "$rel has Greek text that should be a t() term");
        });
    }
}
