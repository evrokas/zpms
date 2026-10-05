<?php
/*
 * Loads a language's term list into the `dictionary` table -- shared by
 * bin/import_dictionary.php and the test suite, so both exercise the same code.
 *
 * Terms are keyed by their English text (the token t() is called with, stored
 * in the `en` column). For each term:
 *   - not in the table yet          -> added, marked translated in $lang
 *   - in the table, $lang untranslated (<lang>_set = 0, which is what a term
 *     auto-added the first time a page used it looks like)  -> translated
 *   - in the table, $lang already translated (someone edited it) -> left alone
 *
 * $lang is a column name in this table (a language code from config), so it
 * is checked against the real columns before it goes anywhere near SQL.
 *
 * @return array{added:int, translated:int, kept:int}
 */
function zpms_import_dictionary_terms(PDO $db, string $lang, array $terms, bool $dryRun = false): array {
    $columns = $db->query('SHOW COLUMNS FROM dictionary')->fetchAll(PDO::FETCH_COLUMN);
    if (!preg_match('/^[a-z]{2,8}$/', $lang) || !in_array($lang, $columns, true) || !in_array($lang . '_set', $columns, true)) {
        throw new InvalidArgumentException("The dictionary table has no '$lang' language column.");
    }

    $find = $db->prepare('SELECT id, ' . $lang . '_set AS is_set FROM dictionary WHERE en = :en ORDER BY id LIMIT 1');
    $insert = $db->prepare("INSERT INTO dictionary (en, en_set, $lang, {$lang}_set) VALUES (:en, 1, :tr, 1)");
    $update = $db->prepare("UPDATE dictionary SET $lang = :tr, {$lang}_set = 1 WHERE id = :id");

    $result = ['added' => 0, 'translated' => 0, 'kept' => 0];
    foreach ($terms as $token => $translation) {
        $token = (string)$token;
        $translation = (string)$translation;
        if ($token === '' || $translation === '') continue;

        $find->execute([':en' => $token]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            if (!$dryRun) $insert->execute([':en' => $token, ':tr' => $translation]);
            $result['added']++;
        } elseif (!(int)$row['is_set']) {
            if (!$dryRun) $update->execute([':tr' => $translation, ':id' => $row['id']]);
            $result['translated']++;
        } else {
            $result['kept']++;
        }
    }
    return $result;
}
