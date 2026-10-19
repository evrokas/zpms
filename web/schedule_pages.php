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
    // Appointments that are not held on the schedule (made on a patient record,
    // or booked before the schedule existed) are shown too, view-only.
    $seen = zpms_sched_unscheduled_blocks($db, $loc, $monday, $days);
    foreach ($week as $i => &$day) {
        $day['weekday'] = $weekdayNames[$i];
        $day['short'] = date('d/m', strtotime($day['date']));
        $day['blocks'] = array_merge($day['blocks'], $seen[$day['date']] ?? []);
    }
    unset($day);

    $patternRows = [];
    // The version in force (or next in line): rows not closed yet.
    $st = $db->prepare('SELECT weekday, start_time, end_time, kind, slot_minutes, note, valid_from FROM schedule_templates WHERE location = ? AND valid_to IS NULL ORDER BY weekday, start_time');
    $st->execute([$loc]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $patternRows[] = ['weekday' => (int)$r['weekday'], 'start_time' => $r['start_time'], 'end_time' => $r['end_time'],
            'kind' => $r['kind'], 'slot_minutes' => (int)$r['slot_minutes'], 'note' => (string)$r['note']];
    }
    $validFrom = $db->prepare('SELECT MAX(valid_from) FROM schedule_templates WHERE location = ? AND valid_to IS NULL');
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

    // Optional: show where bookings were cancelled this week (history), as faint dashed outlines.
    $showCancelled = !empty($get['cancelled']);
    $cancelled = [];
    if ($showCancelled && $db->query("SHOW COLUMNS FROM slot_reservations LIKE 'released_at'")->fetchColumn()) {
        $weekEnd = $shift($days - 1);
        $cq = $db->prepare("SELECT rdate, start_time, end_time, release_reason FROM slot_reservations WHERE location = ? AND rdate BETWEEN ? AND ? AND released_at IS NOT NULL AND (release_reason IS NULL OR release_reason NOT IN ('moved', 'edited'))");
        $cq->execute([$loc, $monday, $weekEnd]);
        foreach ($cq->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cancelled[$c['rdate']][] = ['start' => zpms_sched_row_start($c), 'end' => zpms_sched_row_end($c), 'reason' => (string)$c['release_reason']];
        }
    }

    $model = [
        'cancelled' => (object)$cancelled,
        'location' => $loc,
        'phoneCode' => ZPMS_SCHED_ONLINE,
        'locations' => array_map(fn($code, $name) => ['code' => $code, 'name' => $name], array_keys($names), array_values($names)),
        'monday' => $monday,
        'today' => $today,
        'days' => $week,
        'refs' => zpms_sched_resolve_refs($db, $week),
        'dayStart' => 7 * 60,
        'dayEnd' => 22 * 60,
        'step' => ZPMS_SCHED_STEP,
        'weekdayNames' => $weekdayNames,
        'canOverride' => rbacClass::isPermitted(ZPMS_PERM_SCHEDULE_OVERRIDE),
        'pattern' => ['rows' => $patternRows, 'validFrom' => (string)($validFrom->fetchColumn() ?: ''), 'history' => zpms_sched_pattern_history($db, $loc)],
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
        'cancelled_toggle_url' => rel_url('/schedule?loc=' . rawurlencode($loc) . '&date=' . $monday . ($showCancelled ? '' : '&cancelled=1')),
        'show_cancelled' => $showCancelled,
        'cancelled_list_url' => rel_url('/schedule/cancelled?loc=' . rawurlencode($loc)),
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
        // `ref`: the appointment being moved, whose own slot must not count as
        // a collision; `kind`: surgery bookings may also sit in surgery time.
        $ref = (string)($_POST['ref'] ?? '');
        $bookKind = ($_POST['kind'] ?? '') === 'surgery' ? 'surgery' : 'appointment';
        $problems = zpms_sched_check($ctx, $loc, $date, $start, $end, $bookKind, $ref !== '' ? zpms_sched_ref_reservation($ctx, $ref) : null);
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

    // Event ids of this date's surgeries/reservations before the change, so
    // Calendar can be brought in line after it (web/schedule_calendar.php).
    $eventsBefore = function_exists('zpms_gcal_override_event_ids') ? zpms_gcal_override_event_ids($db, $date) : [];

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

    if (function_exists('zpms_gcal_reconcile_overrides')) {
        zpms_gcal_reconcile_overrides($db, $date, $eventsBefore);
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
    if (!$res['ok'] && ($res['error'] ?? '') === 'past') {
        zpms_sched_json(['ok' => false, 'error' => 'past'], 422);
    }
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


// ------------------------------------------------ booking in the forms

/** Remote (phone / online) services an appointment can use: code => label. */
function zpms_remote_platforms(): array {
    return [
        'zoom' => 'Zoom', 'webex' => 'Webex', 'teams' => 'Microsoft Teams', 'meet' => 'Google Meet',
        // t() needs the running app; without it (a script, a test) the English names stand.
        'viber' => 'Viber', 'whatsapp' => 'WhatsApp',
        'phone' => isset($GLOBALS['kernel']) ? t('Phone call') : 'Phone call',
        'other' => isset($GLOBALS['kernel']) ? t('Other') : 'Other',
    ];
}

/** What the contact field of a platform holds: 'link' or 'number'. */
function zpms_remote_contact_kind(string $platform): string {
    return in_array($platform, ['zoom', 'webex', 'teams', 'meet'], true) ? 'link' : 'number';
}

/** "Zoom" for a remote appointment, '' for one in person. */
function zpms_remote_label(?string $platform): string {
    $all = zpms_remote_platforms();
    return ($platform !== null && $platform !== '' && isset($all[$platform])) ? $all[$platform] : '';
}

/** A schedule problem as one sentence (the same wording schedule.js shows). */
function zpms_sched_problem_message(array $p, array $names): string {
    $text = zpms_js_text();
    if ($p['code'] === 'gap') {
        $key = $p['side'] === 'before' ? 'schedP_gap_before' : 'schedP_gap_after';
        return strtr($text[$key], ['@minutes' => (string)$p['minutes']]);
    }
    $template = $text['schedP_' . $p['code']] ?? $text['schedFailed'];
    return trim(strtr($template, [
        '@location' => $names[$p['location'] ?? ''] ?? ($p['location'] ?? ''), '@start' => $p['start'] ?? '', '@end' => $p['end'] ?? '',
        '@minutes' => (string)($p['minutes'] ?? ''), '@time' => $p['at'] ?? '', '@note' => $p['note'] ?? '', '@date' => $p['date'] ?? '',
    ]));
}

/**
 * The booking block of a form: location, date, a one-day calendar to drag on,
 * and (for the phone / online location) the platform and contact fields.
 * Everything is posted as sched-* / remote-* fields and read back with
 * zpms_booking_from_post(). $o: loc, date, start, end (HH:MM), ref (guid of
 * the appointment being moved), kind ('appointment' | 'surgery'), remote
 * (platform, contact, code), note (shown only as the calendar's own text).
 */
function zpms_booking_picker(array $o): string {
    $db = dbConnection::getConnection();
    $names = zpms_sched_location_names($db);
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $remote = $o['remote'] ?? [];
    $date = (string)($o['date'] ?? '');
    $attrs = [
        'data-booking-picker' => '1',
        'data-day-url' => rel_url('/schedule/day'),
        'data-check-url' => rel_url('/schedule/check'),
        'data-phone' => ZPMS_SCHED_ONLINE,
        'data-ref' => (string)($o['ref'] ?? ''),
        'data-kind' => ($o['kind'] ?? 'appointment') === 'surgery' ? 'surgery' : 'appointment',
        'data-start' => (string)($o['start'] ?? ''),
        'data-end' => (string)($o['end'] ?? ''),
        'data-can-override' => rbacClass::isPermitted(ZPMS_PERM_SCHEDULE_OVERRIDE) ? '1' : '0',
        'data-names' => json_encode($names, JSON_UNESCAPED_UNICODE),
    ];
    $attrHtml = '';
    foreach ($attrs as $k => $v) {
        $attrHtml .= ' ' . $k . '="' . $h($v) . '"';
    }

    $locOptions = '<option value="">' . $h(t('-- Select a location --')) . '</option>';
    foreach ($names as $code => $name) {
        $locOptions .= '<option value="' . $h($code) . '"' . ($code === ($o['loc'] ?? '') ? ' selected' : '') . '>' . $h($name) . '</option>';
    }
    $platformOptions = '';
    foreach (zpms_remote_platforms() as $code => $label) {
        $platformOptions .= '<option value="' . $h($code) . '"' . ($code === ($remote['platform'] ?? 'zoom') ? ' selected' : '') . '>' . $h($label) . '</option>';
    }

    return '<div class="booking-picker"' . $attrHtml . '>'
        . '<div class="input-div"><label for="sched-loc">' . $h(t('Location')) . '</label><select name="sched-loc" id="sched-loc" required>' . $locOptions . '</select></div>'
        . '<div class="input-div"><label for="sched-date">' . $h(t('Date')) . '</label>'
        . '<input type="text" name="sched-date" id="sched-date" value="' . $h($date) . '" autocomplete="off" required></div>'
        . '<div class="booking-remote" data-remote hidden>'
        . '<div class="input-div"><label for="remote-platform">' . $h(t('Platform')) . '</label><select name="remote-platform" id="remote-platform">' . $platformOptions . '</select></div>'
        . '<div class="input-div"><label for="remote-contact" data-remote-contact-label>' . $h(t('Meeting link or number')) . '</label>'
        . '<input type="text" name="remote-contact" id="remote-contact" maxlength="255" value="' . $h($remote['contact'] ?? '') . '"></div>'
        . '<div class="input-div"><label for="remote-code">' . $h(t('Meeting code (optional)')) . '</label>'
        . '<input type="text" name="remote-code" id="remote-code" maxlength="64" value="' . $h($remote['code'] ?? '') . '"></div>'
        . '</div>'
        . '<div class="input-div"><label>' . $h(t('Time: drag on the calendar')) . '</label>'
        . '<div class="sched-cal booking-cal"><div class="sched-grid dayone" data-booking-grid></div></div>'
        . '<div class="sched-panel booking-panel" data-booking-panel></div></div>'
        . '<input type="hidden" name="sched-start" value="' . $h($o['start'] ?? '') . '" data-sched-start>'
        . '<input type="hidden" name="sched-end" value="' . $h($o['end'] ?? '') . '" data-sched-end>'
        . '<input type="hidden" name="sched-override" value="0" data-sched-override>'
        . '</div>';
}

/**
 * Reads the booking block of a submitted form and books the slot.
 *
 * Returns ['mode' => 'none'] when the form carries no sched-* time at all (an
 * older client or a script: callers then keep their previous behaviour and
 * book nothing); ['mode' => 'error', 'message' => ...] when the booking is
 * not allowed (nothing is written, say why); or ['mode' => 'ok', 'datetime',
 * 'duration', 'code', 'name', 'remote' => [platform, contact, code]].
 *
 * $refGuid is the appointment or pending appointment the slot belongs to;
 * booking again for the same guid moves its slot. Booking inside a surgery
 * shade is only accepted for a holder of ZPMS_PERM_SCHEDULE_OVERRIDE who
 * ticked the override.
 */
function zpms_booking_from_post(string $refGuid, string $kind, string $note): array {
    global $kernel;
    if (!isset($_POST['sched-start']) || $_POST['sched-start'] === '') {
        return ['mode' => 'none'];
    }
    $db = dbConnection::getConnection();
    $names = zpms_sched_location_names($db);
    $text = zpms_js_text();
    $error = fn(string $msg) => ['mode' => 'error', 'message' => $msg];

    $code = (string)($_POST['sched-loc'] ?? '');
    $date = (string)($_POST['sched-date'] ?? '');
    $start = zpms_sched_time_to_min((string)($_POST['sched-start'] ?? ''));
    $end = ($_POST['sched-end'] ?? '') === '24:00' ? 1440 : zpms_sched_time_to_min((string)($_POST['sched-end'] ?? ''));
    if (!isset($names[$code])) {
        return $error(t('Select a location.'));
    }
    if (!zpms_sched_valid_date($date) || $start === null || $end === null || !zpms_sched_valid_interval($start, $end)) {
        return $error($text['schedP_invalid_interval']);
    }

    $remote = ['platform' => null, 'contact' => null, 'code' => null];
    if ($code === ZPMS_SCHED_ONLINE) {
        $platform = (string)($_POST['remote-platform'] ?? '');
        $contact = trim((string)($_POST['remote-contact'] ?? ''));
        if (!isset(zpms_remote_platforms()[$platform])) {
            return $error(t('Select the platform of the remote appointment.'));
        }
        if ($contact === '') {
            return $error(zpms_remote_contact_kind($platform) === 'link' ? t('Enter the meeting link.') : t('Enter the number to call or message the patient on.'));
        }
        $remote = ['platform' => $platform, 'contact' => mb_substr($contact, 0, 255), 'code' => mb_substr(trim((string)($_POST['remote-code'] ?? '')), 0, 64) ?: null];
    }

    $override = !empty($_POST['sched-override']) && rbacClass::isPermitted(ZPMS_PERM_SCHEDULE_OVERRIDE);
    $res = zpms_sched_reserve($db, $code, $date, $start, $end, $kind, $refGuid, mb_substr(trim($note), 0, 255), $kernel->getUserName(), $override);
    if (!$res['ok']) {
        $hard = zpms_sched_problems_of_level($res['problems'], 'hard');
        $shadow = zpms_sched_problems_of_level($res['problems'], 'shadow');
        $first = $hard[0] ?? $shadow[0] ?? null;
        $msg = $first ? zpms_sched_problem_message($first, $names) : $text['schedFailed'];
        if (!$hard && $shadow && !rbacClass::isPermitted(ZPMS_PERM_SCHEDULE_OVERRIDE)) {
            $msg .= ' ' . $text['schedOnlyDoctor'];
        } elseif (!$hard && $shadow) {
            $msg .= ' ' . t('Tick the override to book it anyway.');
        }
        return $error($msg);
    }
    zpms_audit('slot_reserved', ['detail' => 'loc=' . $code . ' date=' . $date . ' ' . zpms_sched_min_to_time($start) . '-' . zpms_sched_min_to_time($end) . ' kind=' . $kind . ($res['problems'] && zpms_sched_problems_of_level($res['problems'], 'shadow') ? ' shadow_override=1' : '')]);
    return ['mode' => 'ok', 'datetime' => $date . ' ' . zpms_sched_min_to_time($start) . ':00', 'duration' => $end - $start,
        'code' => $code, 'name' => $names[$code], 'remote' => $remote];
}

/** Applies a booking result's remote fields to a pending appointment or appointment object. */
function zpms_booking_apply_remote($record, array $remote): void {
    $record->setremote_platform($remote['platform']);
    $record->setremote_contact($remote['contact']);
    $record->setremote_code($remote['code']);
}

/**
 * POST /schedule/day: one location's day for the booking picker -- the blocks
 * of /schedule, except that the slot belonging to `ref` (the appointment
 * being moved) is shown as free for it ('own').
 */
function schedule_day($params) {
    zpms_sched_api_guard();
    $db = dbConnection::getConnection();
    $loc = (string)($_POST['loc'] ?? '');
    $date = (string)($_POST['date'] ?? '');
    $ref = (string)($_POST['ref'] ?? '');
    if (!in_array($loc, zpms_sched_locations($db), true) || !zpms_sched_valid_date($date)) {
        zpms_sched_json(['ok' => false, 'error' => 'bad request'], 400);
    }
    $ctx = zpms_sched_context_for_date(zpms_sched_load_context_range($db, $date, $date), $date);
    $blocks = zpms_sched_day_blocks($ctx, $loc, $date);
    $refs = zpms_sched_resolve_refs($db, [['blocks' => $blocks]]);
    foreach ($blocks as &$b) {
        if ($b['type'] === 'resv') {
            $b['own'] = $ref !== '' && $b['ref'] === $ref;
            $b['info'] = $refs[$b['ref']] ?? [];
        }
    }
    unset($b);

    // Times that keep the day consecutive (right after or before a booking, or
    // at the start of the available time) for a booking of the default length;
    // the appointment being moved does not count against itself.
    $suggestions = [];
    if ($date >= date('Y-m-d')) {
        $own = $ref !== '' ? zpms_sched_ref_reservation($ctx, $ref) : null;
        $ctx['reservations'] = array_values(array_filter($ctx['reservations'], fn($r) => $own === null || $r['guid'] !== $own));
        $len = max(15, min(240, (int)($_POST['len'] ?? 30)));
        $len -= $len % 15;
        foreach (zpms_sched_suggest($ctx, $loc, $date, $len, 4) as $s) {
            $suggestions[] = ['start' => zpms_sched_min_to_time($s['start']), 'end' => zpms_sched_min_to_time($s['start'] + $len), 'reason' => $s['reason']];
        }
    }
    zpms_sched_json(['ok' => true, 'blocks' => $blocks, 'past' => $date < date('Y-m-d'), 'suggestions' => $suggestions]);
}


/** The location code of a stored place name (appointments.aplace / pending_appointments.location), or ''. */
function zpms_sched_code_for_name(PDO $db, string $name): string {
    if ($name === '') {
        return '';
    }
    $st = $db->prepare('SELECT machinename FROM locations WHERE name = ? LIMIT 1');
    $st->execute([$name]);
    return (string)($st->fetchColumn() ?: '');
}

/**
 * The booking block pre-filled from an existing appointment or pending
 * appointment: the slot it holds if it has one, otherwise its stored date,
 * time and place name (30 minutes when no length was ever recorded).
 */
function zpms_booking_picker_for_record(string $refGuid, ?string $datetime, int $duration, string $placeName, string $kind, array $remote): string {
    $db = dbConnection::getConnection();
    $slot = zpms_sched_slot_for_ref($db, $refGuid);
    if ($slot) {
        $o = ['loc' => $slot['location'], 'date' => $slot['rdate'], 'start' => $slot['start_time'], 'end' => $slot['end_time']];
    } else {
        $ts = $datetime ? strtotime($datetime) : false;
        $start = $ts ? date('H:i', $ts) : '';
        $o = [
            'loc' => zpms_sched_code_for_name($db, $placeName),
            'date' => $ts ? date('Y-m-d', $ts) : '',
            'start' => $start,
            'end' => $ts ? zpms_sched_min_to_time(min(1440, zpms_sched_time_to_min($start) + ($duration ?: ZPMS_CONSULTATION_DEFAULT_DURATION_MINUTES))) : '',
        ];
    }
    return zpms_booking_picker($o + ['ref' => $refGuid, 'kind' => $kind, 'remote' => $remote]);
}

/** Frees the slot of an appointment or pending appointment (cancelled, deleted) and audits it if there was one. */
function zpms_sched_release_slot(string $refGuid, string $detail = '', string $reason = 'cancelled'): void {
    global $kernel;
    $db = dbConnection::getConnection();
    $slot = zpms_sched_slot_for_ref($db, $refGuid);
    if (!$slot) {
        return;
    }
    zpms_sched_release_ref($db, $refGuid, $reason, isset($kernel) ? (string)$kernel->getUserName() : '');
    zpms_audit('slot_released', ['detail' => 'loc=' . $slot['location'] . ' date=' . $slot['rdate'] . ' ' . $slot['start_time'] . '-' . $slot['end_time'] . ($detail !== '' ? ' ' . $detail : '')]);
}


// ------------------------------------------------ cancelled bookings

/** Why a booking was released: key => label (English tokens, translated by t()). */
const ZPMS_SCHED_RELEASE_REASONS = [
    'cancelled' => 'Cancelled',
    'deleted' => 'Appointment deleted',
    'calendar_cancelled' => 'Cancelled in Google Calendar',
    'calendar_conflict' => 'Moved in Google Calendar to a time that is not allowed',
    'moved' => 'Moved to another time',
    'edited' => 'Changed on the patient record',
];

/**
 * /schedule/cancelled -- bookings that were released (cancelled, deleted, moved
 * away), newest first, kept as history. "Moved" and "edited" rows only on
 * request: they are not cancellations, the appointment still exists. Staff page:
 * shows who the booking was for (name, linked while the record exists), unlike
 * the audit log. Needs ZPMS_PERM_SCHEDULE_MANAGE.
 */
function schedule_cancelled($params) {
    global $Request;
    if (($errmsg = rbacClass::require(ZPMS_PERM_SCHEDULE_MANAGE))) return $errmsg;
    $get = isset($Request) ? $Request->getParams() : $_GET;
    $db = dbConnection::getConnection();
    $names = zpms_sched_location_names($db);

    $filters = [
        'loc' => isset($names[$get['loc'] ?? '']) ? (string)$get['loc'] : '',
        'date_from' => zpms_sched_valid_date($get['date_from'] ?? null) ? (string)$get['date_from'] : '',
        'date_to' => zpms_sched_valid_date($get['date_to'] ?? null) ? (string)$get['date_to'] : '',
        'reason' => isset(ZPMS_SCHED_RELEASE_REASONS[$get['reason'] ?? '']) ? (string)$get['reason'] : '',
        'moved' => !empty($get['moved']) ? '1' : '',
    ];
    if (!$db->query("SHOW COLUMNS FROM slot_reservations LIKE 'released_at'")->fetchColumn()) {
        return Renderer::render('cancelled_bookings.zetem', ['missing_table' => true, 'rows' => [], 'filters' => $filters, 'locations' => $names,
            'reasons' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'prev_url' => null, 'next_url' => null, 'filtered' => false]);
    }

    $where = ['released_at IS NOT NULL'];
    $bind = [];
    if ($filters['reason'] !== '') {
        $where[] = 'release_reason = ?';
        $bind[] = $filters['reason'];
    } elseif ($filters['moved'] === '') {
        $where[] = "(release_reason IS NULL OR release_reason NOT IN ('moved', 'edited'))";
    }
    if ($filters['loc'] !== '') { $where[] = 'location = ?'; $bind[] = $filters['loc']; }
    if ($filters['date_from'] !== '') { $where[] = 'rdate >= ?'; $bind[] = $filters['date_from']; }
    if ($filters['date_to'] !== '') { $where[] = 'rdate <= ?'; $bind[] = $filters['date_to']; }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $perPage = 50;
    $page = max(1, (int)($get['page'] ?? 1));
    $count = $db->prepare("SELECT COUNT(*) FROM slot_reservations $whereSql");
    $count->execute($bind);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $st = $db->prepare("SELECT * FROM slot_reservations $whereSql ORDER BY released_at DESC, id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
    $st->execute($bind);

    $reasons = [];
    foreach (ZPMS_SCHED_RELEASE_REASONS as $key => $label) { $reasons[$key] = t($label); }
    $byAppointment = $db->prepare('SELECT p.id AS pid, p.pname, p.deleted FROM appointments a JOIN patients p ON p.guid = a.pguid WHERE a.guid = ? LIMIT 1');
    $byPending = $db->prepare('SELECT patient_name FROM pending_appointments WHERE guid = ? LIMIT 1');

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $who = '';
        $url = null;
        if ($r['ref_guid']) {
            $byAppointment->execute([$r['ref_guid']]);
            if ($a = $byAppointment->fetch(PDO::FETCH_ASSOC)) {
                $who = (string)$a['pname'];
                $url = $a['deleted'] === null ? rel_url('/patient/' . $a['pid'] . '/edit') : null;
            } else {
                $byPending->execute([$r['ref_guid']]);
                $who = (string)($byPending->fetchColumn() ?: '');
            }
        }
        $rows[] = [
            'released_at' => date('d/m/Y H:i', strtotime($r['released_at'])),
            'released_by' => (string)$r['released_by'],
            'reason' => $reasons[$r['release_reason']] ?? (string)$r['release_reason'],
            'reason_key' => (string)$r['release_reason'],
            'when' => date('d/m/Y', strtotime($r['rdate'])) . ' ' . $r['start_time'] . '–' . $r['end_time'],
            'location' => $names[$r['location']] ?? $r['location'],
            'kind' => $r['kind'] === 'surgery' ? t('Operation') : t('Appointment'),
            'who' => $who,
            'url' => $url,
            'note' => (string)$r['note'],
        ];
    }

    $query = fn(array $extra) => '/schedule/cancelled?' . http_build_query(array_filter($extra + $filters, fn($v) => $v !== ''));
    return Renderer::render('cancelled_bookings.zetem', [
        'missing_table' => false, 'rows' => $rows, 'filters' => $filters, 'locations' => $names, 'reasons' => $reasons,
        'total' => $total, 'page' => $page, 'pages' => $pages,
        'prev_url' => $page > 1 ? rel_url($query(['page' => (string)($page - 1)])) : null,
        'next_url' => $page < $pages ? rel_url($query(['page' => (string)($page + 1)])) : null,
        'filtered' => (bool)array_filter($filters),
    ]);
}


/**
 * Appointments and pending appointments at $location in the week that hold no
 * slot (so the rules never see them) as view-only blocks for the calendar:
 * ['start','end','type' => 'seen','label' => 'appointment'|'surgery'|'pending',
 * 'note','info' => ['patient' => id] | ['pending' => true]], keyed by date. They
 * need a time of day: an appointment stored with a date only (00:00) is not drawn.
 */
function zpms_sched_unscheduled_blocks(PDO $db, string $location, string $monday, int $days): array {
    $names = $db->prepare('SELECT DISTINCT name FROM locations WHERE machinename = ?');
    $names->execute([$location]);
    $names = $names->fetchAll(PDO::FETCH_COLUMN);
    if (!$names) {
        return [];
    }
    $from = $monday . ' 00:00:00';
    $to = (new DateTime($monday))->modify("+$days days")->format('Y-m-d') . ' 00:00:00';
    $in = implode(',', array_fill(0, count($names), '?'));
    $out = [];
    $add = function (string $datetime, ?int $minutes, string $label, string $note, array $info) use (&$out) {
        $ts = strtotime($datetime);
        $start = (int)date('H', $ts) * 60 + (int)date('i', $ts);
        if ($start === 0) {
            return;
        }
        $start -= $start % ZPMS_SCHED_STEP;
        $end = min(1440, $start + ($minutes ?: ZPMS_CONSULTATION_DEFAULT_DURATION_MINUTES));
        $out[date('Y-m-d', $ts)][] = ['start' => $start, 'end' => $end, 'type' => 'seen', 'label' => $label, 'note' => mb_substr($note, 0, 255), 'ref' => '', 'guid' => '', 'info' => $info];
    };

    $st = $db->prepare("SELECT a.adate, a.duration_minutes, a.atype, a.anote, p.id AS pid
                          FROM appointments a JOIN patients p ON p.guid = a.pguid
                         WHERE a.deleted IS NULL AND p.deleted IS NULL AND a.adate >= ? AND a.adate < ? AND a.aplace IN ($in)
                           AND NOT EXISTS (SELECT 1 FROM slot_reservations s WHERE s.ref_guid = a.guid AND s.released_at IS NULL)");
    $st->execute(array_merge([$from, $to], $names));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $add($r['adate'], (int)$r['duration_minutes'], $r['atype'] === 'operation' ? 'surgery' : 'appointment', (string)$r['anote'], ['patient' => (int)$r['pid']]);
    }
    $st = $db->prepare("SELECT pa.appointment_datetime, pa.duration_minutes, pa.notes
                          FROM pending_appointments pa
                         WHERE pa.converted_at IS NULL AND pa.cancelled_at IS NULL AND pa.appointment_datetime >= ? AND pa.appointment_datetime < ? AND pa.location IN ($in)
                           AND NOT EXISTS (SELECT 1 FROM slot_reservations s WHERE s.ref_guid = pa.guid AND s.released_at IS NULL)");
    $st->execute(array_merge([$from, $to], $names));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $add($r['appointment_datetime'], (int)$r['duration_minutes'], 'pending', (string)$r['notes'], ['pending' => true]);
    }
    return $out;
}
