<?php
/**
 * Appointment scheduling engine: which times can be booked at which location,
 * and whether a given booking is allowed.
 *
 * Model (see the four schedule_* / slot_* / location_travel_times tables in
 * web/classes/yaml):
 *   - every location has a weekly pattern (schedule_templates) of 'available'
 *     intervals, 'surgery' intervals and 'hold' intervals (reserved, with a
 *     reason), all on a 15-minute grid;
 *   - a single date can be changed on top of that (schedule_overrides), which
 *     always wins over the pattern for the interval it covers;
 *   - 'phone' (phone / online appointments: Zoom, Webex, Viber, WhatsApp, ...)
 *     is a location like any other -- a locations row fed from
 *     web/content/locations/phone.yml -- with its own calendar and 0 minutes
 *     of travel to everywhere;
 *   - bookings (slot_reservations) never overlap, at ANY location, and two
 *     bookings at different locations keep the travel time between them;
 *   - surgery at one location shades every other location's calendar, widened
 *     by the travel time, so the doctor cannot be booked while operating or
 *     travelling. Booking inside such a shadow is refused unless the doctor
 *     (ZPMS_PERM_SCHEDULE_OVERRIDE) deliberately overrides it -- nobody
 *     else can, and no location is exempt.
 *
 * The rule functions are pure: they take already-loaded rows (the "context")
 * and return a list of problems, so they can be tested without a database.
 * Only the zpms_sched_load_* / _reserve / _release functions touch the
 * database. Times are minutes since midnight in the rule functions and
 * 'HH:MM' strings in the tables; dates are 'Y-m-d' and, like every
 * calendar date in this app, carry no time zone.
 *
 * Problem levels:
 *   hard   -- never allowed (overlap, outside availability, travel conflict);
 *   shadow -- inside a surgery shadow: allowed only as a doctor's override;
 *   warn   -- allowed, but worth telling the user (leaves a gap).
 */

const ZPMS_SCHED_STEP = 15;
const ZPMS_SCHED_DEFAULT_TRAVEL_MINUTES = 30;
/** locations.machinename of the phone / online location. */
const ZPMS_SCHED_ONLINE = 'phone';
const ZPMS_SCHED_KINDS = ['available', 'hold', 'surgery'];
/** Later kinds win where intervals of one source overlap. */
const ZPMS_SCHED_KIND_PRIORITY = ['available' => 0, 'hold' => 1, 'surgery' => 2];

// ---------------------------------------------------------------- times

/** 'HH:MM' -> minutes since midnight, or null when it is not a valid time. */
function zpms_sched_time_to_min(?string $time): ?int {
    if ($time === null || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
        return null;
    }
    return (int)$m[1] * 60 + (int)$m[2];
}

/** Minutes since midnight -> 'HH:MM' (1440 prints as '24:00'). */
function zpms_sched_min_to_time(int $minutes): string {
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

function zpms_sched_valid_interval(int $start, int $end): bool {
    return $start >= 0 && $end <= 1440 && $start < $end
        && $start % ZPMS_SCHED_STEP === 0 && $end % ZPMS_SCHED_STEP === 0;
}

function zpms_sched_valid_date(?string $date): bool {
    if ($date === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

/** ISO weekday of a Y-m-d date: 1 = Monday ... 7 = Sunday. */
function zpms_sched_weekday(string $date): int {
    return (int)DateTime::createFromFormat('Y-m-d', $date)->format('N');
}

function zpms_sched_row_start(array $row): int {
    return (int)zpms_sched_time_to_min($row['start_time'] ?? null);
}

function zpms_sched_row_end(array $row): int {
    return (int)zpms_sched_time_to_min($row['end_time'] ?? null);
}

// -------------------------------------------------------------- travel

/**
 * Travel minutes between two locations. The same location needs none; a
 * stored row wins (looked up in either direction); otherwise 0 for anything
 * involving the phone location and ZPMS_SCHED_DEFAULT_TRAVEL_MINUTES for the rest.
 *
 * @param array $travel [from][to] => minutes
 */
function zpms_sched_travel_minutes(array $travel, string $from, string $to): int {
    if ($from === $to) {
        return 0;
    }
    if (isset($travel[$from][$to])) {
        return (int)$travel[$from][$to];
    }
    if (isset($travel[$to][$from])) {
        return (int)$travel[$to][$from];
    }
    return ($from === ZPMS_SCHED_ONLINE || $to === ZPMS_SCHED_ONLINE) ? 0 : ZPMS_SCHED_DEFAULT_TRAVEL_MINUTES;
}

/** Rounds a travel margin out to the 15-minute grid. */
function zpms_sched_round_margin(int $minutes): int {
    return (int)(ceil($minutes / ZPMS_SCHED_STEP) * ZPMS_SCHED_STEP);
}

// -------------------------------------------------------------- layout

/**
 * One day of one location's calendar as 96 fifteen-minute cells, each null
 * (nothing planned) or ['kind' => available|hold|surgery, 'note' => string,
 * 'slot' => int, 'source' => 'template'|'override'].
 *
 * Pattern rows apply on their weekday from their valid_from date on; overrides
 * for exactly this date are laid on top and win over the pattern whatever
 * their kind. Within one source, surgery beats hold beats available.
 *
 * @param array[] $templates schedule_templates rows
 * @param array[] $overrides schedule_overrides rows
 * @return array<int, ?array>
 */
function zpms_sched_day_cells(array $templates, array $overrides, string $location, string $date): array {
    $cells = array_fill(0, 96, null);
    $weekday = zpms_sched_weekday($date);

    $apply = function (array $rows, string $source) use (&$cells) {
        usort($rows, fn($a, $b) => ZPMS_SCHED_KIND_PRIORITY[$a['kind']] <=> ZPMS_SCHED_KIND_PRIORITY[$b['kind']]);
        foreach ($rows as $row) {
            $start = zpms_sched_row_start($row);
            $end = zpms_sched_row_end($row);
            for ($m = $start; $m < $end; $m += ZPMS_SCHED_STEP) {
                $cells[intdiv($m, ZPMS_SCHED_STEP)] = [
                    'kind' => $row['kind'],
                    'note' => (string)($row['note'] ?? ''),
                    'slot' => (int)($row['slot_minutes'] ?? 30),
                    'source' => $source,
                ];
            }
        }
    };

    $apply(array_filter($templates, function ($t) use ($location, $weekday, $date) {
        return $t['location'] === $location && (int)$t['weekday'] === $weekday
            && (($t['valid_from'] ?? null) === null || $t['valid_from'] === '' || $t['valid_from'] <= $date);
    }), 'template');

    $apply(array_filter($overrides, fn($o) => $o['location'] === $location && $o['odate'] === $date), 'override');

    return $cells;
}

/**
 * Merges a day's cells into intervals [start, end, kind, note, slot],
 * starting a new interval whenever the kind or the note changes.
 *
 * @return array[] each ['start' => int, 'end' => int, 'kind' => string, 'note' => string, 'slot' => int]
 */
function zpms_sched_cells_to_intervals(array $cells): array {
    $out = [];
    foreach ($cells as $i => $cell) {
        if ($cell === null) {
            continue;
        }
        $start = $i * ZPMS_SCHED_STEP;
        $last = count($out) - 1;
        if ($last >= 0 && $out[$last]['end'] === $start && $out[$last]['kind'] === $cell['kind']
            && $out[$last]['note'] === $cell['note'] && $out[$last]['slot'] === $cell['slot']) {
            $out[$last]['end'] = $start + ZPMS_SCHED_STEP;
        } else {
            $out[] = ['start' => $start, 'end' => $start + ZPMS_SCHED_STEP, 'kind' => $cell['kind'], 'note' => $cell['note'], 'slot' => $cell['slot']];
        }
    }
    return $out;
}

/** Surgery intervals of a location's day (from the pattern and overrides). */
function zpms_sched_surgery_intervals(array $ctx, string $location, string $date): array {
    $cells = zpms_sched_day_cells($ctx['templates'], $ctx['overrides'], $location, $date);
    return array_values(array_filter(zpms_sched_cells_to_intervals($cells), fn($i) => $i['kind'] === 'surgery'));
}

// --------------------------------------------------------------- rules

function zpms_sched_problem(string $level, string $code, array $detail = []): array {
    return ['level' => $level, 'code' => $code] + $detail;
}

function zpms_sched_overlaps(int $aStart, int $aEnd, int $bStart, int $bEnd): bool {
    return $aStart < $bEnd && $bStart < $aEnd;
}

/**
 * Everything wrong with booking [$start,$end) minutes on $date at $location.
 *
 * $ctx: ['templates' => [], 'overrides' => [], 'reservations' => [] (all
 * locations, this date), 'travel' => [from][to] => minutes, 'locations' => []].
 * $kind is 'appointment' or 'surgery'. $ignoreGuid leaves one reservation out
 * (moving an existing booking must not collide with itself).
 *
 * @return array[] problems, each ['level' => hard|shadow|warn, 'code' => ..., ...]
 */
function zpms_sched_check(array $ctx, string $location, string $date, int $start, int $end, string $kind = 'appointment', ?string $ignoreGuid = null): array {
    $problems = [];

    if (!zpms_sched_valid_interval($start, $end)) {
        return [zpms_sched_problem('hard', 'invalid_interval')];
    }
    if (!zpms_sched_valid_date($date)) {
        return [zpms_sched_problem('hard', 'invalid_date')];
    }

    // 1. The interval must lie inside an 'available' part of this location's day.
    //    A surgery booking may also sit in this location's own surgery block.
    $cells = zpms_sched_day_cells($ctx['templates'], $ctx['overrides'], $location, $date);
    for ($m = $start; $m < $end; $m += ZPMS_SCHED_STEP) {
        $cell = $cells[intdiv($m, ZPMS_SCHED_STEP)];
        $ok = $cell !== null && ($cell['kind'] === 'available' || ($kind === 'surgery' && $cell['kind'] === 'surgery'));
        if (!$ok) {
            $problems[] = zpms_sched_problem('hard', $cell === null ? 'not_available' : ($cell['kind'] === 'hold' ? 'held' : 'surgery_here'), [
                'at' => zpms_sched_min_to_time($m),
                'note' => $cell['note'] ?? '',
            ]);
            break;
        }
    }

    // 2. Other bookings: overlap anywhere is refused; at another location the
    //    travel time must fit between the two. A surgery booking elsewhere is
    //    handled as a shadow below instead of a hard travel conflict.
    foreach ($ctx['reservations'] as $r) {
        if ($ignoreGuid !== null && $r['guid'] === $ignoreGuid) {
            continue;
        }
        $rs = zpms_sched_row_start($r);
        $re = zpms_sched_row_end($r);
        if (zpms_sched_overlaps($start, $end, $rs, $re)) {
            $isSurgeryElsewhere = $r['location'] !== $location && ($r['kind'] ?? '') === 'surgery';
            if (!$isSurgeryElsewhere || $kind === 'surgery') {
                $problems[] = zpms_sched_problem('hard', 'overlap', ['location' => $r['location'], 'start' => $r['start_time'], 'end' => $r['end_time']]);
            }
            continue;
        }
        if ($r['location'] === $location || (($r['kind'] ?? '') === 'surgery' && $kind !== 'surgery')) {
            continue;
        }
        $gap = zpms_sched_round_margin(zpms_sched_travel_minutes($ctx['travel'], $location, $r['location']));
        if (zpms_sched_overlaps($start - $gap, $end + $gap, $rs, $re)) {
            $problems[] = zpms_sched_problem('hard', 'travel_conflict', [
                'location' => $r['location'], 'start' => $r['start_time'], 'end' => $r['end_time'], 'minutes' => $gap,
            ]);
        }
    }

    // 3. Surgery shadow: the doctor is operating (or travelling to/from it)
    //    at another location. Applies to appointments; a surgery booking
    //    colliding with another surgery is a hard overlap/travel conflict.
    foreach ($ctx['locations'] as $other) {
        if ($other === $location) {
            continue;
        }
        $gap = zpms_sched_round_margin(zpms_sched_travel_minutes($ctx['travel'], $location, $other));
        $blocks = [];
        foreach (zpms_sched_surgery_intervals($ctx, $other, $date) as $i) {
            $blocks[] = [$i['start'], $i['end'], $i['note']];
        }
        foreach ($ctx['reservations'] as $r) {
            if ($r['location'] === $other && ($r['kind'] ?? '') === 'surgery' && ($ignoreGuid === null || $r['guid'] !== $ignoreGuid)) {
                $blocks[] = [zpms_sched_row_start($r), zpms_sched_row_end($r), (string)($r['note'] ?? '')];
            }
        }
        foreach ($blocks as [$bs, $be, $bnote]) {
            if (zpms_sched_overlaps($start - $gap, $end + $gap, $bs, $be)) {
                $problems[] = zpms_sched_problem($kind === 'surgery' ? 'hard' : 'shadow', 'surgery_shadow', [
                    'location' => $other, 'start' => zpms_sched_min_to_time($bs), 'end' => zpms_sched_min_to_time($be),
                    'minutes' => $gap, 'note' => $bnote,
                ]);
            }
        }
    }

    // 4. Not a problem, but worth saying: free time left stranded between this
    //    booking and a neighbouring booking or hold.
    if ($kind === 'appointment' && !array_filter($problems, fn($p) => $p['level'] === 'hard')) {
        foreach (zpms_sched_holes($ctx, $location, $date, $start, $end, $ignoreGuid) as $hole) {
            $problems[] = zpms_sched_problem('warn', 'gap', $hole);
        }
    }

    return $problems;
}

/** Only the hard problems, or only the shadow ones, etc. */
function zpms_sched_problems_of_level(array $problems, string $level): array {
    return array_values(array_filter($problems, fn($p) => $p['level'] === $level));
}

/**
 * Free minutes left between [$start,$end) and a booking/hold on the same day
 * and location, with nothing else able to fill them in practice: a run of
 * free 'available' cells that ends at a booking or a hold (not at the edge of
 * the available block, which is a normal place for free time to be).
 *
 * @return array[] each ['side' => 'before'|'after', 'minutes' => int]
 */
function zpms_sched_holes(array $ctx, string $location, string $date, int $start, int $end, ?string $ignoreGuid = null): array {
    $cells = zpms_sched_day_cells($ctx['templates'], $ctx['overrides'], $location, $date);
    $taken = array_fill(0, 96, false);
    foreach ($ctx['reservations'] as $r) {
        if ($r['location'] !== $location || ($ignoreGuid !== null && $r['guid'] === $ignoreGuid)) {
            continue;
        }
        for ($m = zpms_sched_row_start($r); $m < zpms_sched_row_end($r); $m += ZPMS_SCHED_STEP) {
            $taken[intdiv($m, ZPMS_SCHED_STEP)] = true;
        }
    }
    $free = fn(int $i) => $i >= 0 && $i < 96 && $cells[$i] !== null && $cells[$i]['kind'] === 'available' && !$taken[$i];
    $blocker = fn(int $i) => $i >= 0 && $i < 96 && ($taken[$i] || ($cells[$i] !== null && $cells[$i]['kind'] === 'hold'));

    $out = [];
    $i = intdiv($start, ZPMS_SCHED_STEP) - 1;
    $n = 0;
    while ($free($i)) { $n++; $i--; }
    if ($n > 0 && $blocker($i)) {
        $out[] = ['side' => 'before', 'minutes' => $n * ZPMS_SCHED_STEP];
    }
    $i = intdiv($end, ZPMS_SCHED_STEP);
    $n = 0;
    while ($free($i)) { $n++; $i++; }
    if ($n > 0 && $blocker($i)) {
        $out[] = ['side' => 'after', 'minutes' => $n * ZPMS_SCHED_STEP];
    }
    return $out;
}

/**
 * Start times (minutes) for a booking of $length minutes that are allowed,
 * leave no stranded gap, and sit right against something -- a booking, a hold
 * or the edge of the available time -- so appointments stay consecutive.
 * Best first: directly after the day's earliest booking, then directly before
 * a booking, then the start of an available block.
 *
 * @return array[] each ['start' => int, 'reason' => 'after'|'before'|'start']
 */
function zpms_sched_suggest(array $ctx, string $location, string $date, int $length, int $limit = 6): array {
    $cells = zpms_sched_day_cells($ctx['templates'], $ctx['overrides'], $location, $date);
    $mine = array_values(array_filter($ctx['reservations'], fn($r) => $r['location'] === $location));
    $out = [];
    for ($start = 0; $start + $length <= 1440; $start += ZPMS_SCHED_STEP) {
        $end = $start + $length;
        $problems = zpms_sched_check($ctx, $location, $date, $start, $end);
        if (zpms_sched_problems_of_level($problems, 'hard') || zpms_sched_problems_of_level($problems, 'shadow')
            || zpms_sched_problems_of_level($problems, 'warn')) {
            continue;
        }
        $reason = null;
        foreach ($mine as $r) {
            if (zpms_sched_row_end($r) === $start) { $reason = 'after'; break; }
            if (zpms_sched_row_start($r) === $end) { $reason = 'before'; }
        }
        if ($reason === null) {
            $prev = $start > 0 ? $cells[intdiv($start, ZPMS_SCHED_STEP) - 1] : null;
            if ($prev === null || $prev['kind'] !== 'available') {
                $reason = 'start';
            }
        }
        if ($reason !== null) {
            $out[] = ['start' => $start, 'reason' => $reason];
        }
    }
    $rank = ['after' => 0, 'before' => 1, 'start' => 2];
    usort($out, fn($a, $b) => [$rank[$a['reason']], $a['start']] <=> [$rank[$b['reason']], $b['start']]);
    return array_slice($out, 0, $limit);
}

/**
 * Whether turning [$start,$end) of a location's day into surgery or a hold is
 * allowed: no booking may sit in it, and for surgery no booking at another
 * location may fall within the travel margin around it (move those first).
 *
 * @return array[] hard problems, empty when it is allowed
 */
function zpms_sched_check_block(array $ctx, string $location, string $date, int $start, int $end, string $kind): array {
    if (!zpms_sched_valid_interval($start, $end) || !zpms_sched_valid_date($date)) {
        return [zpms_sched_problem('hard', 'invalid_interval')];
    }
    $problems = [];
    foreach ($ctx['reservations'] as $r) {
        $rs = zpms_sched_row_start($r);
        $re = zpms_sched_row_end($r);
        if ($r['location'] === $location) {
            if (zpms_sched_overlaps($start, $end, $rs, $re)) {
                $problems[] = zpms_sched_problem('hard', 'has_reservation', ['location' => $location, 'start' => $r['start_time'], 'end' => $r['end_time'], 'guid' => $r['guid']]);
            }
        } elseif ($kind === 'surgery') {
            $gap = zpms_sched_round_margin(zpms_sched_travel_minutes($ctx['travel'], $location, $r['location']));
            if (zpms_sched_overlaps($start - $gap, $end + $gap, $rs, $re)) {
                $problems[] = zpms_sched_problem('hard', 'has_reservation', ['location' => $r['location'], 'start' => $r['start_time'], 'end' => $r['end_time'], 'guid' => $r['guid']]);
            }
        }
    }
    if ($kind === 'surgery') {
        foreach ($ctx['locations'] as $other) {
            if ($other === $location) {
                continue;
            }
            $gap = zpms_sched_round_margin(zpms_sched_travel_minutes($ctx['travel'], $location, $other));
            foreach (zpms_sched_surgery_intervals($ctx, $other, $date) as $i) {
                if (zpms_sched_overlaps($start - $gap, $end + $gap, $i['start'], $i['end'])) {
                    $problems[] = zpms_sched_problem('hard', 'surgery_conflict', ['location' => $other, 'start' => zpms_sched_min_to_time($i['start']), 'end' => zpms_sched_min_to_time($i['end'])]);
                }
            }
        }
    }
    return $problems;
}

// ------------------------------------------------------------ database

/**
 * Every location code the schedule knows: the locations.machinename values
 * (the phone location included, once web/content/locations/phone.yml has been
 * fed with feed:load). The phone code is appended when that has not been done
 * yet, so the schedule never silently loses the location.
 */
function zpms_sched_locations(PDO $db): array {
    $codes = $db->query("SELECT DISTINCT machinename FROM locations WHERE machinename IS NOT NULL AND machinename <> '' ORDER BY machinename")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array(ZPMS_SCHED_ONLINE, $codes, true)) {
        $codes[] = ZPMS_SCHED_ONLINE;
    }
    return $codes;
}

/** The travel table as [from][to] => minutes. */
function zpms_sched_load_travel(PDO $db): array {
    $travel = [];
    foreach ($db->query('SELECT from_location, to_location, minutes FROM location_travel_times') as $row) {
        $travel[$row['from_location']][$row['to_location']] = (int)$row['minutes'];
    }
    return $travel;
}

/** Everything the rule functions need for one date. */
function zpms_sched_load_context(PDO $db, string $date): array {
    $st = $db->prepare('SELECT * FROM schedule_overrides WHERE odate = ?');
    $st->execute([$date]);
    $overrides = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $db->prepare('SELECT * FROM slot_reservations WHERE rdate = ?');
    $st->execute([$date]);
    $reservations = $st->fetchAll(PDO::FETCH_ASSOC);

    return [
        'templates' => $db->query('SELECT * FROM schedule_templates')->fetchAll(PDO::FETCH_ASSOC),
        'overrides' => $overrides,
        'reservations' => $reservations,
        'travel' => zpms_sched_load_travel($db),
        'locations' => zpms_sched_locations($db),
    ];
}

/**
 * Books a slot. Runs in a transaction that first locks the day's bookings
 * (SELECT ... FOR UPDATE) so two people booking at the same moment cannot
 * both succeed, then re-checks every rule against the locked data.
 *
 * $allowShadowOverride must only be true for a user holding
 * ZPMS_PERM_SCHEDULE_OVERRIDE AND who asked to override; a shadow problem is
 * then accepted and recorded on the row (shadow_override = 1). Hard problems
 * are never accepted.
 *
 * @return array ['ok' => bool, 'guid' => ?string, 'problems' => array[]]
 */
function zpms_sched_reserve(PDO $db, string $location, string $date, int $start, int $end, string $kind, ?string $refGuid, string $note, string $cuser, bool $allowShadowOverride = false): array {
    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT guid FROM slot_reservations WHERE rdate = ? FOR UPDATE');
        $st->execute([$date]);
        $ctx = zpms_sched_load_context($db, $date);
        $problems = zpms_sched_check($ctx, $location, $date, $start, $end, $kind, $refGuid !== null ? zpms_sched_ref_reservation($ctx, $refGuid) : null);

        $hard = zpms_sched_problems_of_level($problems, 'hard');
        $shadow = zpms_sched_problems_of_level($problems, 'shadow');
        if ($hard || ($shadow && !$allowShadowOverride)) {
            if ($own) { $db->rollBack(); }
            return ['ok' => false, 'guid' => null, 'problems' => $problems];
        }

        $guid = guid();
        $db->prepare('INSERT INTO slot_reservations (guid, cdate, cuser, location, rdate, start_time, end_time, kind, ref_guid, note, shadow_override) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$guid, getDBtime(), $cuser, $location, $date, zpms_sched_min_to_time($start), zpms_sched_min_to_time($end), $kind, $refGuid, $note !== '' ? $note : null, $shadow ? 1 : 0]);
        if ($own) { $db->commit(); }
        return ['ok' => true, 'guid' => $guid, 'problems' => $problems];
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) { $db->rollBack(); }
        throw $e;
    }
}

/** The guid of the existing reservation that belongs to $refGuid on this context's date, if any (so a re-booking ignores itself). */
function zpms_sched_ref_reservation(array $ctx, string $refGuid): ?string {
    foreach ($ctx['reservations'] as $r) {
        if (($r['ref_guid'] ?? null) === $refGuid) {
            return $r['guid'];
        }
    }
    return null;
}

/** Frees a booking (the slot becomes bookable again). Returns whether a row was removed. */
function zpms_sched_release(PDO $db, string $guid): bool {
    $st = $db->prepare('DELETE FROM slot_reservations WHERE guid = ?');
    $st->execute([$guid]);
    return $st->rowCount() > 0;
}

/**
 * Future bookings (today and later) that a changed pattern would strand:
 * the ones no longer inside an available interval of their location. Used
 * before saving a pattern or an override -- the change is refused while any
 * are listed ("move those appointments first"), which is also how "apply to
 * future bookings only" is kept honest: past bookings are never looked at.
 *
 * @param array $templates the schedule_templates rows as they WOULD be after the change
 * @return array[] reservation rows
 */
function zpms_sched_stranded_reservations(PDO $db, array $templates, string $today): array {
    $st = $db->prepare('SELECT * FROM slot_reservations WHERE rdate >= ? ORDER BY rdate, start_time');
    $st->execute([$today]);
    $stranded = [];
    $overridesByDate = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $date = $r['rdate'];
        if (!isset($overridesByDate[$date])) {
            $q = $db->prepare('SELECT * FROM schedule_overrides WHERE odate = ?');
            $q->execute([$date]);
            $overridesByDate[$date] = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        $cells = zpms_sched_day_cells($templates, $overridesByDate[$date], $r['location'], $date);
        for ($m = zpms_sched_row_start($r); $m < zpms_sched_row_end($r); $m += ZPMS_SCHED_STEP) {
            $cell = $cells[intdiv($m, ZPMS_SCHED_STEP)];
            $okKind = $r['kind'] === 'surgery' ? ['available', 'surgery'] : ['available'];
            if ($cell === null || !in_array($cell['kind'], $okKind, true)) {
                $stranded[] = $r;
                break;
            }
        }
    }
    return $stranded;
}
