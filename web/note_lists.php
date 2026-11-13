<?php
/**
 * Settings -> «Λίστες σημειώσεων» (docs/CLINICAL_TAGS_PLAN.md, phase 5): the
 * fixed suggestion lists of the clinical tags (note_list_entries). Per tag:
 * add, edit, reorder and deactivate entries (never delete -- a deleted entry
 * would come back with the next `note-lists:seed`), their next words, and for
 * prescriptions the strengths; and "promote" a frequent free-text entry from
 * the notes into the list.
 *
 * Gated like the rest of Settings (settings-manage). The frequent free-text
 * entries are values taken from patients' notes (aggregated, no patient
 * attached), so they are shown only to an account that may read those notes --
 * the same rule as the suggestions in the note boxes (zpms_note_suggestions()).
 * Audit rows name the tag, the action and the entry id, never the text.
 */

/** Tags that have a list (levels use the built-in grid). */
function zpms_note_list_tags(): array {
    $out = [];
    foreach (zpms_note_tags_spec()['tags'] as $key => $tag) {
        if ($tag['value'] !== 'levels') $out[$key] = $tag;
    }
    return $out;
}

/** "a, b , c" -> ['a', 'b', 'c'] (empty parts dropped, each at most 64 characters). */
function zpms_note_list_split(?string $s): array {
    $out = [];
    foreach (explode(',', (string)$s) as $part) {
        $part = trim(preg_replace('/\s+/u', ' ', $part));
        if ($part !== '') $out[] = mb_substr($part, 0, 64);
    }
    return array_values(array_unique($out));
}

/** The entry fields from a posted form, checked. Returns [fields, error message or null]. */
function zpms_note_list_fields_from_post(string $tag, array $post): array {
    $text = trim(preg_replace('/\s+/u', ' ', (string)($post['text'] ?? '')));
    if ($text === '') return [null, t('The entry text is required.')];
    if (mb_strlen($text) > 255) return [null, t('The entry text is too long.')];
    $aliases = zpms_note_list_split($post['aliases'] ?? '');
    $next = zpms_note_list_split($post['next_words'] ?? '');
    $strengths = $tag === 'rx' ? zpms_note_list_split($post['strengths'] ?? '') : [];
    return [[
        'text' => $text,
        'aliases' => $aliases ? mb_substr(implode(',', $aliases), 0, 255) : null,
        'next_words' => $next ? json_encode($next, JSON_UNESCAPED_UNICODE) : null,
        'rx_strengths' => $strengths ? json_encode($strengths, JSON_UNESCAPED_UNICODE) : null,
    ], null];
}

/** Renumbers a tag's entries 10, 20, 30 ... in their current order. */
function zpms_note_list_renumber(PDO $db, string $tag): array {
    $st = $db->prepare('SELECT id FROM note_list_entries WHERE tag_key = ? ORDER BY sort, id');
    $st->execute([$tag]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $up = $db->prepare('UPDATE note_list_entries SET sort = ? WHERE id = ?');
    foreach ($ids as $i => $id) $up->execute([($i + 1) * 10, $id]);
    return $ids;
}

/** GET /settings/note-lists[?tag=dx] */
function note_lists_page($params) {
    global $kernel, $Request;
    if (($errmsg = rbacClass::require(ZPMS_PERM_SETTINGS_MANAGE))) return $errmsg;

    $db = dbConnection::getConnection();
    $tags = zpms_note_list_tags();
    $in = isset($Request) ? $Request->getParams() : $_GET;
    $tag = is_string($in['tag'] ?? null) && isset($tags[$in['tag']]) ? $in['tag'] : array_key_first($tags);

    $ready = zpms_note_table_exists($db, 'note_list_entries');
    $entries = [];
    if ($ready) {
        $st = $db->prepare('SELECT * FROM note_list_entries WHERE tag_key = ? ORDER BY sort, id');
        $st->execute([$tag]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $nw = json_decode((string)$r['next_words'], true);
            $sg = json_decode((string)$r['rx_strengths'], true);
            $entries[] = [
                'id' => (int)$r['id'],
                'text' => (string)$r['text'],
                'aliases' => implode(', ', array_filter(explode(',', (string)$r['aliases']), 'strlen')),
                'next_words' => is_array($nw) ? implode(', ', $nw) : '',
                'strengths' => is_array($sg) ? implode(', ', $sg) : '',
                'active' => (int)$r['active'] === 1,
            ];
        }
    }

    // Frequent free-text entries (used twice or more, not in the list yet).
    $readsPatient = rbacClass::isPermitted(ZPMS_PERM_PATIENTS_VIEW_LIST);
    $readsAppointments = rbacClass::isPermitted(ZPMS_PERM_APPOINTMENT_VIEW) || rbacClass::isPermitted(ZPMS_PERM_APPOINTMENT_EDIT);
    $frequent = [];
    if ($ready && ($readsPatient || $readsAppointments)) {
        $known = [];
        foreach ($entries as $e) $known[zpms_note_normalize_text($e['text'])] = true;
        $sugg = zpms_note_suggestions($db, $readsPatient, $readsAppointments, 60);
        foreach ($sugg[$tag]['previous'] ?? [] as $p) {
            if ($p['count'] < 2 || isset($known[zpms_note_normalize_text($p['text'])])) continue;
            $frequent[] = $p;
        }
    }

    return Renderer::render('note_lists.zetem', [
        'tags' => $tags,
        'tag' => $tag,
        'tag_def' => $tags[$tag],
        'entries' => $entries,
        'frequent' => array_slice($frequent, 0, 20),
        'can_read_notes' => $readsPatient || $readsAppointments,
        'ready' => $ready,
        'action' => rel_url('/settings/note-lists'),
    ]);
}

/** POST /settings/note-lists -- action = add | update | move | promote. */
function note_lists_save($params) {
    global $kernel;
    if (($errmsg = rbacClass::require(ZPMS_PERM_SETTINGS_MANAGE))) return $errmsg;

    $tags = zpms_note_list_tags();
    $tag = is_string($_POST['tag'] ?? null) && isset($tags[$_POST['tag']]) ? $_POST['tag'] : null;
    $back = rel_url('/settings/note-lists') . ($tag ? '?tag=' . rawurlencode($tag) : '');

    if (!csrfClass::verifyRequest()) {
        $kernel->addStatus('error', t('Invalid security token (CSRF). Please try again.'));
        header('location: ' . $back);
        exit();
    }
    $db = dbConnection::getConnection();
    if ($tag === null || !zpms_note_table_exists($db, 'note_list_entries')) {
        $kernel->addStatus('error', t('The list was not found.'));
        header('location: ' . rel_url('/settings/note-lists'));
        exit();
    }

    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $row = null;
    if (in_array($action, ['update', 'move'], true)) {
        $st = $db->prepare('SELECT * FROM note_list_entries WHERE id = ? AND tag_key = ?');
        $st->execute([$id, $tag]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            $kernel->addStatus('error', t('The entry was not found.'));
            header('location: ' . $back);
            exit();
        }
    }

    switch ($action) {
        case 'add':
        case 'promote':
            [$fields, $err] = zpms_note_list_fields_from_post($tag, $_POST);
            if ($err) { $kernel->addStatus('error', $err); break; }
            $st = $db->prepare('SELECT COUNT(*) FROM note_list_entries WHERE tag_key = ? AND text = ?');
            $st->execute([$tag, $fields['text']]);
            if ((int)$st->fetchColumn() > 0) { $kernel->addStatus('warning', t('This entry is already in the list.')); break; }
            $max = $db->prepare('SELECT COALESCE(MAX(sort), 0) FROM note_list_entries WHERE tag_key = ?');
            $max->execute([$tag]);
            $db->prepare('INSERT INTO note_list_entries (tag_key, text, aliases, next_words, rx_strengths, rx_defaults, sort, active, cdate, cuser)
                          VALUES (?, ?, ?, ?, ?, NULL, ?, 1, ?, ?)')
               ->execute([$tag, $fields['text'], $fields['aliases'], $fields['next_words'], $fields['rx_strengths'],
                          (int)$max->fetchColumn() + 10, getDBtime(), $kernel->getUserName()]);
            $newId = (int)$db->lastInsertId();
            zpms_audit('note_list_changed', ['detail' => "tag=$tag action=$action id=$newId"]);
            $kernel->addStatus('notice', t('The entry was added to the list.'));
            break;

        case 'update':
            [$fields, $err] = zpms_note_list_fields_from_post($tag, $_POST);
            if ($err) { $kernel->addStatus('error', $err); break; }
            $active = isset($_POST['active']) ? 1 : 0;
            $db->prepare('UPDATE note_list_entries SET text = ?, aliases = ?, next_words = ?, rx_strengths = ?, active = ? WHERE id = ?')
               ->execute([$fields['text'], $fields['aliases'], $fields['next_words'], $fields['rx_strengths'], $active, $id]);
            zpms_audit('note_list_changed', ['detail' => "tag=$tag action=update id=$id active=$active"]);
            $kernel->addStatus('notice', t('The entry was saved.'));
            break;

        case 'move':
            $ids = zpms_note_list_renumber($db, $tag);
            $pos = array_search($id, $ids, true);
            $to = ($_POST['dir'] ?? '') === 'up' ? $pos - 1 : $pos + 1;
            if ($pos !== false && $to >= 0 && $to < count($ids)) {
                $up = $db->prepare('UPDATE note_list_entries SET sort = ? WHERE id = ?');
                $up->execute([($to + 1) * 10, $ids[$pos]]);
                $up->execute([($pos + 1) * 10, $ids[$to]]);
                zpms_audit('note_list_changed', ['detail' => "tag=$tag action=move id=$id"]);
            }
            break;

        default:
            $kernel->addStatus('error', t('Unknown action.'));
    }

    header('location: ' . $back . '#note-list');
    exit();
}
