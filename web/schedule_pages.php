<?php
/**
 * The appointment schedule pages: /schedule (the per-location calendar) and
 * the JSON endpoints its script calls to check and save changes. The rules
 * themselves live in web/schedule.php; this file only turns requests into
 * calls to it and results into JSON / a rendered page.
 *
 * Everything needs ZPMS_PERM_SCHEDULE_MANAGE (doctor and secretary). The
 * endpoints are POST + CSRF (the X-CSRF-Token header the script sends) and
 * always answer JSON; the page itself is a plain GET.
 *
 * Audit events carry only codes, dates, times and the kind of change -- never
 * the free-text note, which can name a patient (see web/audit.php).
 */

require_once __DIR__ . '/schedule.php';

/** Location code => display name, in the current language; the phone location last. */
function zpms_sched_location_names(PDO $db): array {
    global $kernel;
    $lang = isset($kernel) ? $kernel->getCurrentLanguage() : 'gr';
    $names = [];
    $st = $db->prepare('SELECT machinename, name FROM locations WHERE lang = ? ORDER BY name');
    $st->execute([$lang]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $names[$row['machinename']] = $row['name'];
    }
    $out = [];
    foreach (zpms_sched_locations($db) as $code) {
        $out[$code] = $names[$code] ?? $code;
    }
    uksort($out, fn($a, $b) => [$a === ZPMS_SCHED_ONLINE, $out[$a]] <=> [$b === ZPMS_SCHED_ONLINE, $out[$b]]);
    return $out;
}

function zpms_sched_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

/** Permission + CSRF for a JSON endpoint; ends the request with a JSON error otherwise. */
function zpms_sched_api_guard(): void {
    if (rbacClass::require(ZPMS_PERM_SCHEDULE_MANAGE)) {
        zpms_sched_json(['ok' => false, 'error' => 'forbidden'], 403);
    }
    if (!csrfClass::verifyRequest()) {
        zpms_sched_json(['ok' => false, 'error' => 'csrf'], 403);
    }
}

function zpms_sched_post_time(string $field): ?int {
    $v = (string)($_POST[$field] ?? '');
    if ($v === '24:00') {
        return 1440;
    }
    return zpms_sched_time_to_min($v);
}

/** Problems trimmed to what the script needs (no row guids, nothing free-text except the schedule's own reasons). */
function zpms_sched_problem_list(array $problems): array {
    return array_map(function ($p) {
        unset($p['guid']);
        return $p;
    }, $problems);
}

/** A stranded-bookings list as the script shows it: where and when, never who. */
function zpms_sched_stranded_list(array $rows): array {
    return array_map(fn($r) => ['location' => $r['location'], 'date' => $r['rdate'], 'start' => $r['start_time'], 'end' => $r['end_time']], $rows);
}

/** Which patient (id) or pending appointment each booking's ref_guid points at. */
function zpms_sched_resolve_refs(PDO $db, array $weekModel): array {
    $refs = [];
    foreach ($weekModel as $day) {
        foreach ($day['blocks'] as $b) {
            if ($b['type'] === 'resv' && $b['ref'] !== '') {
                $refs[$b['ref']] = true;
            }
        }
    }
    $out = [];
    foreach (array_keys($refs) as $guid) {
        $st = $db->prepare('SELECT p.id FROM appointments a JOIN patients p ON p.guid = a.pguid WHERE a.guid = ? LIMIT 1');
        $st->execute([$guid]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            $out[$guid] = ['patient' => (int)$id];
            continue;
        }
        $st = $db->prepare('SELECT id FROM pending_appointments WHERE guid = ? LIMIT 1');
        $st->execute([$guid]);
        $out[$guid] = $st->fetchColumn() !== false ? ['pending' => true] : [];
    }
    return $out;
}

/** /schedule?loc=<code>&date=<Y-m-d> -- one location's week. */
function schedule_view($params) {
    global $Request, $kernel;
    if (($errmsg = rbacClass::require(ZPMS_PERM_SCHEDULE_MANAGE))) return $errmsg;

    $get = isset($Request) ? $Request->getParams() : $_GET;
    $db = dbConnection::getConnection();

    if (!$db->query("SHOW TABLES LIKE 'slot_reservations'")->fetchColumn()) {
        return Renderer::render('schedule.zetem', ['missing_table' => true, 'model_json' => '{}']);
    }

    $names = zpms_sched_location_names($db);
    $loc = (string)($get['loc'] ?? '');
    if (!isset($names[$loc])) {
        $loc = array_key_first($names);
    }
    $date = (string)($get['date'] ?? '');
    $today = date('Y-m-d');
    $monday = zpms_sched_monday(zpms_sched_valid_date($date) ? $date : $today);

    $days = 6;
    $week = zpms_sched_week_model($db, $loc, $monday, $days);
    $shift = fn(int $d) => (new DateTime($monday))->modify(($d >= 0 ? '+' : '') . $d . ' days')->format('Y-m-d');
    $weekdayNames = [t('Mon'), t('Tue'), t('Wed'), t('Thu'), t('Fri'), t('Sat'), t('Sun')];
    foreach ($week as $i => &$day) {
        $day['weekday'] = $weekdayNames[$i];
        $day['short'] = date('d/m', strtotime($day['date']));
    }
    unset($day);

    $patternRows = [];
    $st = $db->prepare('SELECT weekday, start_time, end_time, kind, slot_minutes, note, valid_from FROM schedule_templates WHERE location = ? ORDER BY weekday, start_time');
    $st->execute([$loc]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $patternRows[] = ['weekday' => (int)$r['weekday'], 'start_time' => $r['start_time'], 'end_time' => $r['end_time'],
            'kind' => $r['kind'], 'slot_minutes' => (int)$r['slot_minutes'], 'note' => (string)$r['note']];
    }
    $validFrom = $db->prepare('SELECT MAX(valid_from) FROM schedule_templates WHERE location = ?');
    $validFrom->execute([$loc]);

    $travel = zpms_sched_load_travel($db);
    $travelRows = [];
    foreach (array_keys($names) as $from) {
        foreach (array_keys($names) as $to) {
            if ($from !== $to) {
                $travelRows[] = ['from' => $from, 'to' => $to, 'minutes' => zpms_sched_travel_minutes($travel, $from, $to), 'set' => isset($travel[$from][$to])];
            }
        }
    }

    $model = [
        'location' => $loc,
        'phoneCode' => ZPMS_SCHED_ONLINE,
        'locations' => array_map(fn($code, $name) => ['code' => $code, 'name' => $name], array_keys($names), array_values($names)),
        'monday' => $monday,
        'today' => $today,
        'days' => $week,
        'refs' => zpms_sched_resolve_refs($db, $week),
        'dayStart' => 7 * 60,
        'dayEnd' => 21 * 60,
        'step' => ZPMS_SCHED_STEP,
        'weekdayNames' => $weekdayNames,
        'canOverride' => rbacClass::isPermitted(ZPMS_PERM_SCHEDULE_OVERRIDE),
        'pattern' => ['rows' => $patternRows, 'validFrom' => (string)($validFrom->fetchColumn() ?: '')],
        'travel' => $travelRows,
        'newAppointmentUrl' => rel_url('/consultation/new'),
        'urls' => [
            'check' => rel_url('/schedule/check'),
            'override' => rel_url('/schedule/override'),
            'pattern' => rel_url('/schedule/pattern'),
            'travel' => rel_url('/schedule/travel'),
        ],
    ];

    $loc_tabs = [];
    foreach ($names as $code => $name) {
        $loc_tabs[] = ['name' => $name, 'url' => rel_url('/schedule?loc=' . rawurlencode($code) . '&date=' . $monday), 'active' => $code === $loc];
    }

    return Renderer::render('schedule.zetem', [
        'missing_table' => false,
        'loc_tabs' => $loc_tabs,
        'prev_url' => rel_url('/schedule?loc=' . rawurlencode($loc) . '&date=' . $shift(-7)),
        'next_url' => rel_url('/schedule?loc=' . rawurlencode($loc) . '&date=' . $shift(7)),
        'today_url' => rel_url('/schedule?loc=' . rawurlencode($loc)),
        'range_label' => date('d/m/Y', strtotime($monday)) . ' – ' . date('d/m/Y', strtotime($shift($days - 1))),
        'location_name' => $names[$loc],
        'model_json' => json_encode($model, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE),
    ]);
}

/**
 * POST /schedule/check: what would happen if this interval were used for
 * $mode (book | available | surgery | hold | clear). Writes nothing.
 * Answers {ok, problems[], suggestions[], can_override, note_required}.
 */
function schedule_check($params) {
    zpms_sched_api_guard();
    $db = dbConnection::getConnection();

    $loc = (string)($_POST['loc'] ?? '');
    $date = (string)($_POST['date'] ?? '');
    $mode = (string)($_POST['mode'] ?? '');
    $start = zpms_sched_post_time('start');
    $end = zpms_sched_post_time('end');
    if (!in_array($loc, zpms_sched_locations($db), true) || !zpms_sched_valid_date($date) || $start === null || $end === null
        || !in_array($mode, ['book', 'available', 'surgery', 'hold', 'clear'], true)) {
        zpms_sched_json(['ok' => false, 'error' => 'bad request'], 400);
    }

    $ctx = zpms_sched_load_context($db, $date);
    $problems = [];
    $suggestions = [];
    if ($mode === 'book') {
        $problems = zpms_sched_check($ctx, $loc, $date, $start, $end);
        if (zpms_sched_problems_of_level($problems, 'warn')) {
            foreach (zpms_sched_suggest($ctx, $loc, $date, $end - $start, 3) as $s) {
                $suggestions[] = ['start' => zpms_sched_min_to_time($s['start']), 'end' => zpms_sched_min_to_time($s['start'] + $end - $start), 'reason' => $s['reason']];
            }
        }
    } elseif ($mode === 'surgery' || $mode === 'hold') {
        $problems = zpms_sched_check_block($ctx, $loc, $date, $start, $end, $mode);
    } elseif (!zpms_sched_valid_interval($start, $end)) {
        $problems = [zpms_sched_problem('hard', 'invalid_interval')];
    }
    zpms_sched_json([
        'ok' => true,
        'problems' => zpms_sched_problem_list($problems),
        'suggestions' => $suggestions,
        'can_override' => rbacClass::isPermitted(ZPMS_PERM_SCHEDULE_OVERRIDE),
        'note_required' => $mode === 'surgery' || $mode === 'hold',
    ]);
}

/**
 * POST /schedule/override: change one date of a location (kind = available |
 * surgery | hold) or put it back to the pattern (kind = clear). A hold may
 * apply to every location (all_locations=1). Surgery and hold need a reason.
 */
function schedule_override_save($params) {
    global $kernel;
    zpms_sched_api_guard();
    $db = dbConnection::getConnection();

    $loc = (string)($_POST['loc'] ?? '');
    $date = (string)($_POST['date'] ?? '');
    $kind = (string)($_POST['kind'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    $start = zpms_sched_post_time('start');
    $end = zpms_sched_post_time('end');
    $locations = zpms_sched_locations($db);
    if (!in_array($loc, $locations, true) || !zpms_sched_valid_date($date) || $start === null || $end === null
        || !zpms_sched_valid_interval($start, $end) || !in_array($kind, ['available', 'surgery', 'hold', 'clear'], true)) {
        zpms_sched_json(['ok' => false, 'error' => 'bad request'], 400);
    }
    if (mb_strlen($note) > 255) {
        zpms_sched_json(['ok' => false, 'error' => 'note_long'], 422);
    }
    if (($kind === 'surgery' || $kind === 'hold') && $note === '') {
        zpms_sched_json(['ok' => false, 'error' => 'note_required'], 422);
    }
    if ($date < date('Y-m-d')) {
        zpms_sched_json(['ok' => false, 'error' => 'past'], 422);
    }

    $targets = ($kind === 'hold' && !empty($_POST['all_locations'])) ? $locations : [$loc];
    $dbKind = $kind === 'clear' ? null : $kind;
    $user = $kernel->getUserName();

    $db->beginTransaction();
    try {
        foreach ($targets as $target) {
            if ($kind === 'surgery' || $kind === 'hold') {
                $problems = zpms_sched_check_block(zpms_sched_load_context($db, $date), $target, $date, $start, $end, $kind);
                if ($problems) {
                    $db->rollBack();
                    zpms_sched_json(['ok' => false, 'error' => 'conflict', 'problems' => zpms_sched_problem_list($problems)], 409);
                }
            }
            $res = zpms_sched_override_apply($db, $target, $date, $start, $end, $dbKind, $note, $user);
            if (!$res['ok']) {
                $db->rollBack();
                zpms_sched_json(['ok' => false, 'error' => 'stranded', 'stranded' => zpms_sched_stranded_list($res['stranded'])], 409);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        error_log('schedule_override_save failed: ' . $e->getMessage());
        zpms_sched_json(['ok' => false, 'error' => 'server'], 500);
    }

    zpms_audit($kind === 'clear' ? 'schedule_override_cleared' : 'schedule_override_set', [
        'detail' => 'loc=' . ($targets === $locations && count($targets) > 1 ? 'all' : $loc) . ' date=' . $date . ' ' . zpms_sched_min_to_time($start) . '-' . zpms_sched_min_to_time($end) . ' kind=' . $kind,
    ]);
    zpms_sched_json(['ok' => true]);
}

/** POST /schedule/pattern: replace one location's weekly pattern (rows = JSON). */
function schedule_pattern_save($params) {
    global $kernel;
    zpms_sched_api_guard();
    $db = dbConnection::getConnection();

    $loc = (string)($_POST['loc'] ?? '');
    $validFrom = trim((string)($_POST['valid_from'] ?? ''));
    $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
    if (!in_array($loc, zpms_sched_locations($db), true) || !is_array($rows) || ($validFrom !== '' && !zpms_sched_valid_date($validFrom))) {
        zpms_sched_json(['ok' => false, 'error' => 'bad request'], 400);
    }
    [$clean, $errors] = zpms_sched_validate_pattern_rows($rows);
    if ($errors) {
        zpms_sched_json(['ok' => false, 'error' => 'invalid', 'errors' => $errors], 422);
    }
    $res = zpms_sched_pattern_replace($db, $loc, $clean, $validFrom !== '' ? $validFrom : null, $kernel->getUserName(), date('Y-m-d'));
    if (!$res['ok']) {
        zpms_sched_json(['ok' => false, 'error' => 'blocked', 'stranded' => zpms_sched_stranded_list($res['stranded']),
            'conflicts' => zpms_sched_problem_list($res['conflicts'])], 409);
    }
    zpms_audit('schedule_pattern_changed', ['detail' => 'loc=' . $loc . ' rows=' . count($clean)]);
    zpms_sched_json(['ok' => true]);
}

/** POST /schedule/travel: replace the travel-time table (rows = JSON, symmetric = 1). */
function schedule_travel_save($params) {
    global $kernel;
    zpms_sched_api_guard();
    $db = dbConnection::getConnection();

    $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
    if (!is_array($rows)) {
        zpms_sched_json(['ok' => false, 'error' => 'bad request'], 400);
    }
    [$ok, $errors] = zpms_sched_travel_replace($db, $rows, !empty($_POST['symmetric']), $kernel->getUserName());
    if (!$ok) {
        zpms_sched_json(['ok' => false, 'error' => 'invalid', 'errors' => $errors], 422);
    }
    zpms_audit('schedule_travel_changed', ['detail' => 'rows=' . count($rows)]);
    zpms_sched_json(['ok' => true]);
}
