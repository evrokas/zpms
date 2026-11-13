<?php
/*
 * Converting the existing notes to the clinical-tag notation
 * (docs/CLINICAL_TAGS_CONVERSION_PLAN.md):
 *
 *   zpmscli notes:export              every live note into one text file with markers
 *   (the file is tagged by hand, saved under another name, and reviewed)
 *   zpmscli notes:import <file>       writes the tagged notes back (dry run unless --yes)
 *   zpmscli notes:import --revert=<backup.json>   puts the originals back
 *
 * The import only accepts "@<tag name> " inserted into the text: every other
 * character of the note must be unchanged (--allow-edits relaxes this). The
 * insertions are applied to the note as it is in the database, so the stored
 * text keeps its exact characters and line endings even when an editor
 * changed them in the file. Patient data: the files stay under var/ (not in
 * git) and are written readable by their owner only.
 */

require_once dirname(__DIR__, 2) . '/web/note_tags.php';

const ZPMS_NOTE_EXPORT_VERSION = 1;

/** Short fingerprint of a note as stored (the import refuses a note whose text changed since the export). */
function zpms_note_hash(string $text): string {
    return substr(hash('sha256', $text), 0, 16);
}

/**
 * Every live, non-empty note: patient notes and appointment notes of patients
 * and appointments that are not soft-deleted. Ordered by patient, the patient
 * note first, then the appointments by date.
 * @return list<array{source:string,id:int,patient_id:int,date:?string,type:?string,text:string}>
 */
function zpms_notes_for_export(PDO $db): array {
    $out = [];
    $patients = $db->query("SELECT id, guid, pnote FROM patients WHERE deleted IS NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $apSt = $db->prepare("SELECT id, adate, atype, anote FROM appointments WHERE pguid = ? AND deleted IS NULL ORDER BY adate, id");
    foreach ($patients as $p) {
        if (trim((string)$p['pnote']) !== '') {
            $out[] = ['source' => 'patient', 'id' => (int)$p['id'], 'patient_id' => (int)$p['id'], 'date' => null, 'type' => null, 'text' => (string)$p['pnote']];
        }
        $apSt->execute([$p['guid']]);
        foreach ($apSt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if (trim((string)$a['anote']) === '') continue;
            $out[] = ['source' => 'appointment', 'id' => (int)$a['id'], 'patient_id' => (int)$p['id'],
                'date' => substr((string)$a['adate'], 0, 10), 'type' => $a['atype'] === 'operation' ? 'operation' : 'appointment', 'text' => (string)$a['anote']];
        }
    }
    return $out;
}

/** The export file. Line breaks are written as \n; the header records the note's own (eol=crlf|lf). */
function zpms_notes_export_text(array $notes, string $now): string {
    $s = "# ZPMS notes export v" . ZPMS_NOTE_EXPORT_VERSION . ", $now, " . count($notes) . " notes.\n"
       . "# PATIENT DATA: keep this file under var/note-conversion/, never commit, mail or upload it.\n"
       . "# Tagging: insert \"@tag \" (e.g. \"@δγ \") before a value. Change no other character,\n"
       . "# and leave the === lines exactly as they are. Lines starting with # are ignored.\n\n";
    foreach ($notes as $n) {
        $text = $n['text'];
        $eol = str_contains($text, "\r\n") ? 'crlf' : 'lf';
        $body = str_replace("\r\n", "\n", $text);
        if (preg_match('/^=== /m', $body)) throw new RuntimeException("{$n['source']} {$n['id']}: a line of the note starts with \"=== \"");
        $meta = ["{$n['source']} {$n['id']}", "patient {$n['patient_id']}"];
        if ($n['date'] !== null) $meta[] = $n['date'];
        if ($n['type'] === 'operation') $meta[] = 'operation';
        $meta[] = "eol=$eol";
        $meta[] = 'sha=' . zpms_note_hash($text);
        $s .= '=== NOTE ' . implode(' | ', $meta) . " ===\n" . $body . "\n=== END {$n['source']} {$n['id']} ===\n\n";
    }
    return $s;
}

/**
 * Reads an export file (the original or the tagged copy).
 * @return list<array{source:string,id:int,patient_id:int,eol:string,sha:string,text:string,line:int}>
 *         text with the note's own line breaks restored
 */
function zpms_notes_parse_file(string $content): array {
    $content = str_replace("\r\n", "\n", $content);
    if (str_starts_with($content, "\xEF\xBB\xBF")) $content = substr($content, 3);
    $lines = explode("\n", $content);
    $notes = [];
    $seen = [];
    for ($i = 0, $n = count($lines); $i < $n; $i++) {
        $line = rtrim($lines[$i], " \t");
        if ($line === '' || str_starts_with($line, '#')) continue;   // blank lines and comments between notes
        if (!str_starts_with($line, '=== ')) {
            throw new InvalidArgumentException('line ' . ($i + 1) . ': text outside a note (moved out of its note?): ' . mb_strimwidth($line, 0, 80, '...'));
        }
        if (!preg_match('/^=== NOTE (appointment|patient) (\d+) \| patient (\d+)(?: \| [^|]*)*? \| eol=(crlf|lf) \| sha=([0-9a-f]{16}) ===$/', $line, $m)) {
            throw new InvalidArgumentException('line ' . ($i + 1) . ': not a NOTE header: ' . mb_strimwidth($line, 0, 80, '...'));
        }
        [, $source, $id, $patientId, $eol, $sha] = $m;
        $end = "=== END $source $id ===";
        $body = [];
        $start = $i + 1;
        for ($i++; $i < $n && rtrim($lines[$i], " \t") !== $end; $i++) {
            if (str_starts_with($lines[$i], '=== ')) throw new InvalidArgumentException('line ' . ($i + 1) . ": \"$end\" missing before this line");
            $body[] = $lines[$i];
        }
        if ($i >= $n) throw new InvalidArgumentException("line $start: \"$end\" missing");
        if (isset($seen["$source $id"])) throw new InvalidArgumentException("line $start: $source $id appears twice");
        $seen["$source $id"] = true;
        $text = implode("\n", $body);
        $notes[] = ['source' => $source, 'id' => (int)$id, 'patient_id' => (int)$patientId, 'eol' => $eol, 'sha' => $sha,
            'text' => $eol === 'crlf' ? str_replace("\n", "\r\n", $text) : $text, 'line' => $start];
    }
    if (!$notes) throw new InvalidArgumentException('no notes in the file (not a notes:export file?)');
    return $notes;
}

/**
 * Lines up the tagged text against the original: equal characters, or a tag
 * "@<name> " inserted into the tagged text. Spaces/tabs at a line end of the
 * original may be missing from the tagged text (editors strip them).
 *
 * @return array{0:?list<array{0:int,1:string}>,1:?string} [insertions (original char offset, "@name "), error]
 */
function zpms_note_tag_insertions(string $original, string $tagged, array $spec): array {
    // Names folded (case and accents), as the parser reads them: «@ΔΓ», «@φαρμακα» are tags too.
    $names = [];
    foreach ($spec['tags'] as $tag) foreach ($tag['names'] as $name) $names[zpms_note_fold($name)] = mb_strlen($name);
    uksort($names, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));   // longest first

    $o = zpms_note_chars($original);
    $t = zpms_note_chars($tagged);
    $no = count($o);
    $nt = count($t);
    // Length of the "@name " tag word starting at $k in $chars, or 0.
    $tagAt = function (array $chars, int $k) use ($names): int {
        if (($chars[$k] ?? '') !== '@') return 0;
        $rest = zpms_note_fold(implode('', array_slice($chars, $k + 1, 40)));
        foreach ($names as $name => $_) {
            $len = mb_strlen($name);
            if (mb_substr($rest, 0, $len + 1) === $name . ' ') return $len + 2;
        }
        return 0;
    };
    $i = $j = 0;
    $ins = [];
    while ($i < $no || $j < $nt) {
        // An inserted tag word, kept as the tagger wrote it. Checked before equal characters,
        // so a tag put in front of an «@» already in the note is not mistaken for it.
        $len = $j < $nt ? $tagAt($t, $j) : 0;
        if ($len > 0) {
            $word = implode('', array_slice($t, $j, $len));
            $same = $i + $len <= $no && implode('', array_slice($o, $i, $len)) === $word;
            if (!$same) { $ins[] = [$i, $word]; $j += $len; continue; }
        }
        if ($i < $no && $j < $nt && $o[$i] === $t[$j]) { $i++; $j++; continue; }
        // trailing spaces of a line of the original, dropped by an editor
        if ($i < $no && ($o[$i] === ' ' || $o[$i] === "\t")) {
            $k = $i;
            while ($k < $no && ($o[$k] === ' ' || $o[$k] === "\t")) $k++;
            if ($k === $no || $o[$k] === "\r" || $o[$k] === "\n") { $i = $k; continue; }
        }
        $lineNo = 1 + substr_count(implode('', array_slice($o, 0, min($i, $no))), "\n");
        $ctxO = str_replace(["\r", "\n"], ['', '⏎'], implode('', array_slice($o, max(0, $i - 15), 30)));
        $ctxT = str_replace(["\r", "\n"], ['', '⏎'], implode('', array_slice($t, max(0, $j - 15), 30)));
        return [null, "text changed at line $lineNo of the note (only \"@tag \" may be added): original «{$ctxO}» / tagged «{$ctxT}»"];
    }
    return [$ins, null];
}

/** The original with the insertions applied, plus notes about tags that will not read as tags. */
function zpms_note_apply_insertions(string $original, array $ins, array $spec, array $drugs): array {
    $o = zpms_note_chars($original);
    $out = '';
    $starts = [];   // char offsets of inserted "@" in the result
    $k = 0;
    $len = 0;
    foreach ($ins as [$at, $word]) {
        $chunk = implode('', array_slice($o, $k, $at - $k));
        $out .= $chunk;
        $len += $at - $k;
        $starts[] = [$len, $word, $at];
        $out .= $word;
        $len += mb_strlen($word);
        $k = $at;
    }
    $out .= implode('', array_slice($o, $k));

    $warnings = [];
    $rowsAt = [];
    foreach (zpms_note_parse($out, $spec, $drugs) as $r) $rowsAt[$r['pos']] = $r;
    foreach ($starts as [$pos, $word, $at]) {
        $line = 1 + substr_count(implode('', array_slice($o, 0, $at)), "\n");
        $before = $at > 0 ? $o[$at - 1] : null;
        if ($before !== null && zpms_note_is_word($before)) {
            $warnings[] = "line $line: " . trim($word) . ' is glued to the word before it, so it is not a tag';
        } elseif (!isset($rowsAt[$pos])) {
            $warnings[] = "line $line: " . trim($word) . ' has no value after it';
        } elseif (!$rowsAt[$pos]['valid']) {
            $warnings[] = "line $line: " . trim($word) . ' «' . $rowsAt[$pos]['value'] . '» is not a valid level';
        }
    }
    return [$out, $warnings];
}

/** The note as it is now, with what note_fields_sync needs. Null when gone or soft-deleted. */
function zpms_note_current(PDO $db, string $source, int $id): ?array {
    if ($source === 'appointment') {
        $st = $db->prepare('SELECT a.anote AS text, a.deleted AS adel, p.id AS patient_id, p.guid AS patient_guid, p.deleted AS pdel
            FROM appointments a JOIN patients p ON p.guid = a.pguid WHERE a.id = ?');
    } else {
        $st = $db->prepare('SELECT p.pnote AS text, NULL AS adel, p.id AS patient_id, p.guid AS patient_guid, p.deleted AS pdel FROM patients p WHERE p.id = ?');
    }
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['adel'] !== null || $r['pdel'] !== null) return null;
    return ['text' => (string)$r['text'], 'patient_id' => (int)$r['patient_id'], 'patient_guid' => (string)$r['patient_guid']];
}

/**
 * Checks a tagged file against the database and, with $apply, writes it.
 * Notes with a problem are skipped (listed in 'problems'); the rest are
 * written in one transaction, after their originals are saved to $backupFile.
 *
 * @return array{report:array<string,int>, problems:list<string>, warnings:list<string>, changed:list<array>}
 */
function zpms_notes_import(PDO $db, array $notes, bool $allowEdits, string $now, bool $apply, ?string $backupFile): array {
    $spec = zpms_note_tags_spec();
    $drugs = zpms_note_rx_drugs($db);
    $report = ['notes_in_file' => count($notes), 'to_write' => 0, 'unchanged' => 0, 'skipped' => 0, 'tags_added' => 0, 'values' => 0];
    $problems = $warnings = $changed = [];

    foreach ($notes as $n) {
        $label = "{$n['source']} {$n['id']} (file line {$n['line']})";
        $cur = zpms_note_current($db, $n['source'], $n['id']);
        if ($cur === null) { $problems[] = "$label: not found or deleted"; $report['skipped']++; continue; }
        if ($cur['patient_id'] !== $n['patient_id']) { $problems[] = "$label: belongs to another patient now"; $report['skipped']++; continue; }
        if (zpms_note_hash($cur['text']) !== $n['sha']) { $problems[] = "$label: the note was edited after the export -- export again"; $report['skipped']++; continue; }

        [$ins, $err] = zpms_note_tag_insertions($cur['text'], $n['text'], $spec);
        if ($err !== null) {
            if (!$allowEdits) { $problems[] = "$label: $err"; $report['skipped']++; continue; }
            $new = $n['text'];
            $noteWarnings = ["edited by hand (--allow-edits): $err"];
            $added = 0;
        } else {
            [$new, $noteWarnings] = zpms_note_apply_insertions($cur['text'], $ins, $spec, $drugs);
            $added = count($ins);
        }
        if ($new === $cur['text']) { $report['unchanged']++; continue; }
        foreach ($noteWarnings as $w) $warnings[] = "$label: $w";
        $report['to_write']++;
        $report['tags_added'] += $added;
        $report['values'] += count(zpms_note_parse($new, $spec, $drugs));
        $changed[] = ['source' => $n['source'], 'id' => $n['id'], 'patient_id' => $cur['patient_id'], 'patient_guid' => $cur['patient_guid'],
            'before' => $cur['text'], 'after' => $new, 'tags_added' => $added];
    }

    if ($apply && $changed) {
        zpms_notes_write_backup($backupFile, $changed, $now);
        zpms_notes_write($db, array_map(fn($c) => $c + ['expect' => $c['before'], 'text' => $c['after']], $changed), $now, $spec, $drugs);
    }
    return ['report' => $report, 'problems' => $problems, 'warnings' => $warnings, 'changed' => $changed];
}

/** Puts the originals from a backup back, for every note still as the import left it. */
function zpms_notes_revert(PDO $db, string $backupFile, string $now, bool $apply): array {
    $data = json_decode((string)@file_get_contents($backupFile), true);
    if (!is_array($data) || !isset($data['notes'])) throw new InvalidArgumentException("Not a notes:import backup: $backupFile");
    $report = ['notes_in_backup' => count($data['notes']), 'to_restore' => 0, 'skipped' => 0];
    $problems = $todo = [];
    foreach ($data['notes'] as $c) {
        if (!is_array($c) || !in_array($c['source'] ?? null, ['appointment', 'patient'], true) || !is_string($c['before'] ?? null) || !is_string($c['after'] ?? null)) {
            throw new InvalidArgumentException("Not a notes:import backup (bad entry): $backupFile");
        }
        $cur = zpms_note_current($db, $c['source'], (int)$c['id']);
        if ($cur === null) { $problems[] = "{$c['source']} {$c['id']}: not found or deleted"; $report['skipped']++; continue; }
        if ($cur['text'] === $c['before']) { $problems[] = "{$c['source']} {$c['id']}: already restored"; $report['skipped']++; continue; }
        if ($cur['text'] !== $c['after']) { $problems[] = "{$c['source']} {$c['id']}: edited after the import -- left as it is"; $report['skipped']++; continue; }
        $report['to_restore']++;
        $todo[] = ['source' => $c['source'], 'id' => (int)$c['id'], 'patient_id' => $cur['patient_id'], 'patient_guid' => $cur['patient_guid'],
            'expect' => $c['after'], 'text' => $c['before']];
    }
    if ($apply && $todo) zpms_notes_write($db, $todo, $now, zpms_note_tags_spec(), zpms_note_rx_drugs($db));
    return ['report' => $report, 'problems' => $problems, 'changed' => $todo];
}

/** Writes notes and their note_fields rows in one transaction; a note changed meanwhile aborts everything. */
function zpms_notes_write(PDO $db, array $notes, string $now, array $spec, array $drugs): void {
    $withFields = zpms_note_table_exists($db, 'note_fields');
    $db->beginTransaction();
    try {
        foreach ($notes as $c) {
            $st = $c['source'] === 'appointment'
                ? $db->prepare('UPDATE appointments SET anote = ? WHERE id = ? AND anote = ? COLLATE utf8mb4_bin')
                : $db->prepare('UPDATE patients SET pnote = ? WHERE id = ? AND pnote = ? COLLATE utf8mb4_bin');
            $st->execute([$c['text'], $c['id'], $c['expect']]);
            if ($st->rowCount() !== 1) throw new RuntimeException("{$c['source']} {$c['id']} changed while writing -- nothing was written");
            if ($withFields) {
                zpms_note_fields_sync($db, $c['source'], $c['source'] === 'appointment' ? $c['id'] : null,
                    $c['patient_id'], $c['patient_guid'], $c['text'], null, $now, $spec, $drugs);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function zpms_notes_write_backup(?string $file, array $changed, string $now): void {
    if ($file === null) throw new InvalidArgumentException('no backup file given');
    zpms_notes_private_dir(dirname($file));
    if (file_exists($file)) throw new RuntimeException("The backup $file exists already -- nothing was imported, run it again");
    $json = json_encode(['written' => $now, 'notes' => array_map(fn($c) => [
        'source' => $c['source'], 'id' => $c['id'], 'before' => $c['before'], 'after' => $c['after']], $changed)],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (@file_put_contents($file, $json) === false) throw new RuntimeException("Could not write the backup $file -- nothing was imported");
    @chmod($file, 0600);
}

/** A folder for files holding patient data: created owner-only. */
function zpms_notes_private_dir(string $dir): void {
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) throw new RuntimeException("Could not create $dir");
}
