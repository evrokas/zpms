<?php
/* web/schedule.php: the rule functions on hand-built data (no database), then
 * zpms_sched_reserve()/release() and the stranded-booking check against the
 * test database. Sample data used throughout (Monday 2026-12-14; Tuesday
 * 2026-12-15):
 *   Peristeri (p)    Mon 10:00-14:00 available
 *   Metropolitan (m) Tue 08:00-13:00 surgery; Mon 16:00-19:00 available
 *   Phone (phone)  Mon 14:00-16:00 available
 * Travel: m<->p 30, m<->a 45, p<->a 50; phone 0 to everywhere. */

require_once __DIR__ . '/../../web/schedule.php';

function zpms_sched_t(string $loc, int $wd, string $s, string $e, string $kind, string $note = '', int $slot = 30): array {
    return ['location' => $loc, 'weekday' => $wd, 'start_time' => $s, 'end_time' => $e, 'kind' => $kind, 'note' => $note, 'slot_minutes' => $slot, 'valid_from' => null];
}

function zpms_sched_r(string $guid, string $loc, string $date, string $s, string $e, string $kind = 'appointment', string $note = ''): array {
    return ['guid' => $guid, 'location' => $loc, 'rdate' => $date, 'start_time' => $s, 'end_time' => $e, 'kind' => $kind, 'ref_guid' => null, 'note' => $note];
}

function zpms_sched_ctx(array $reservations = [], array $overrides = []): array {
    return [
        'templates' => [
            zpms_sched_t('p', 1, '10:00', '14:00', 'available'),
            zpms_sched_t('m', 2, '08:00', '13:00', 'surgery', 'Επέμβαση'),
            zpms_sched_t('m', 1, '16:00', '19:00', 'available'),
            zpms_sched_t('phone', 1, '14:00', '16:00', 'available'),
            zpms_sched_t('p', 2, '15:00', '19:00', 'available'),
        ],
        'overrides' => $overrides,
        'reservations' => $reservations,
        'travel' => ['m' => ['p' => 30, 'a' => 45], 'p' => ['a' => 50]],
        'locations' => ['a', 'm', 'phone', 'p'],
    ];
}

function zpms_sched_codes(array $problems, ?string $level = null): array {
    $codes = [];
    foreach ($problems as $p) {
        if ($level === null || $p['level'] === $level) {
            $codes[] = $p['code'];
        }
    }
    sort($codes);
    return $codes;
}

function zpms_sched_db(): PDO {
    return dbConnection::getConnection();
}

function zpms_schedule_functional_tests(TestRunner $runner): void {
    $MON = '2026-12-14';
    $TUE = '2026-12-15';
    $m = fn(string $t) => zpms_sched_time_to_min($t);

    $runner->add('times: parsing, formatting and the 15-minute grid', function () {
        assert_equal(600, zpms_sched_time_to_min('10:00'));
        assert_equal(null, zpms_sched_time_to_min('24:00'));
        assert_equal(null, zpms_sched_time_to_min('9:00'));
        assert_equal('09:45', zpms_sched_min_to_time(585));
        assert_true(zpms_sched_valid_interval(600, 645));
        assert_false(zpms_sched_valid_interval(600, 640), 'a 10:00-10:40 interval is off the 15-minute grid');
        assert_false(zpms_sched_valid_interval(645, 600));
        assert_false(zpms_sched_valid_date('2026-02-30'));
        assert_equal(1, zpms_sched_weekday('2026-12-14'));
    });

    $runner->add('layout: pattern applies on its weekday, valid_from and an override wins', function () use ($MON, $TUE) {
        $ctx = zpms_sched_ctx();
        $cells = zpms_sched_day_cells($ctx['templates'], [], 'p', $MON);
        assert_equal('available', $cells[40]['kind'], '10:00 should be available');
        assert_equal(null, $cells[39], '09:45 should be empty');
        assert_equal(null, zpms_sched_day_cells($ctx['templates'], [], 'p', '2026-12-16')[40], 'Wednesday has no pattern');

        $future = [zpms_sched_t('p', 1, '10:00', '14:00', 'available')];
        $future[0]['valid_from'] = '2026-12-21';
        assert_equal(null, zpms_sched_day_cells($future, [], 'p', $MON)[40], 'pattern must not apply before valid_from');
        assert_equal('available', zpms_sched_day_cells($future, [], 'p', '2026-12-21')[40]['kind']);

        $hold = [['location' => 'p', 'odate' => $MON, 'start_time' => '12:00', 'end_time' => '13:00', 'kind' => 'hold', 'note' => 'Σύσκεψη']];
        $cells = zpms_sched_day_cells($ctx['templates'], $hold, 'p', $MON);
        assert_equal('hold', $cells[48]['kind']);
        assert_equal('Σύσκεψη', $cells[48]['note']);
        assert_equal('available', $cells[47]['kind']);

        $open = [['location' => 'p', 'odate' => $TUE, 'start_time' => '09:00', 'end_time' => '10:00', 'kind' => 'available', 'note' => '']];
        assert_equal('available', zpms_sched_day_cells($ctx['templates'], $open, 'p', $TUE)[36]['kind'], 'an override can open a time the pattern does not');

        $ints = zpms_sched_cells_to_intervals(zpms_sched_day_cells($ctx['templates'], $hold, 'p', $MON));
        assert_equal(3, count($ints), 'available / hold / available');
        assert_equal('hold', $ints[1]['kind']);
    });

    $runner->add('travel: same place 0, stored either way, online 0, default 30', function () {
        $t = ['m' => ['p' => 30]];
        assert_equal(0, zpms_sched_travel_minutes($t, 'm', 'm'));
        assert_equal(30, zpms_sched_travel_minutes($t, 'm', 'p'));
        assert_equal(30, zpms_sched_travel_minutes($t, 'p', 'm'));
        assert_equal(0, zpms_sched_travel_minutes($t, 'p', 'phone'));
        assert_equal(ZPMS_SCHED_DEFAULT_TRAVEL_MINUTES, zpms_sched_travel_minutes($t, 'p', 'a'));
        assert_equal(60, zpms_sched_round_margin(50), '50 minutes round out to 60 on the 15-minute grid');
    });

    $runner->add('booking inside availability is allowed; outside it, in a hold or in surgery is not', function () use ($MON, $TUE, $m) {
        $ctx = zpms_sched_ctx();
        assert_equal([], zpms_sched_problems_of_level(zpms_sched_check($ctx, 'p', $MON, $m('10:00'), $m('10:30')), 'hard'));
        assert_equal(['not_available'], zpms_sched_codes(zpms_sched_check($ctx, 'p', $MON, $m('09:30'), $m('10:00'), 'appointment'), 'hard'));
        assert_equal(['not_available'], zpms_sched_codes(zpms_sched_check($ctx, 'p', $MON, $m('13:45'), $m('14:15')), 'hard'), 'running past the end of the available time');
        assert_equal(['invalid_interval'], zpms_sched_codes(zpms_sched_check($ctx, 'p', $MON, $m('10:00'), $m('10:40'))));
        $held = zpms_sched_ctx([], [['location' => 'p', 'odate' => $MON, 'start_time' => '12:00', 'end_time' => '13:00', 'kind' => 'hold', 'note' => 'Σύσκεψη']]);
        $p = zpms_sched_check($held, 'p', $MON, $m('12:00'), $m('12:30'));
        assert_equal(['held'], zpms_sched_codes($p, 'hard'));
        assert_equal('Σύσκεψη', $p[0]['note'], 'the reason of the hold is reported');
        assert_equal(['surgery_here'], zpms_sched_codes(zpms_sched_check($ctx, 'm', $TUE, $m('09:00'), $m('09:30')), 'hard'));
    });

    $runner->add('bookings never overlap, at any location', function () use ($MON, $m) {
        $ctx = zpms_sched_ctx([zpms_sched_r('r1', 'p', $MON, '11:00', '11:30')]);
        assert_equal(['overlap'], zpms_sched_codes(zpms_sched_check($ctx, 'p', $MON, $m('11:15'), $m('11:45')), 'hard'));
        assert_equal(['overlap'], zpms_sched_codes(zpms_sched_check($ctx, 'p', $MON, $m('10:45'), $m('11:15')), 'hard'));
        assert_equal([], zpms_sched_codes(zpms_sched_check($ctx, 'p', $MON, $m('11:30'), $m('12:00')), 'hard'), 'back to back at the same location is fine');
        // Online overlapping an in-person booking is still an overlap (one doctor).
        $ctx2 = zpms_sched_ctx([zpms_sched_r('r1', 'p', $MON, '14:00', '14:30')]);
        assert_equal(['overlap'], zpms_sched_codes(zpms_sched_check($ctx2, 'phone', $MON, $m('14:00'), $m('14:30')), 'hard'));
        // Moving a booking must not collide with itself.
        assert_equal([], zpms_sched_codes(zpms_sched_check($ctx, 'p', $MON, $m('11:00'), $m('11:30'), 'appointment', 'r1'), 'hard'));
    });

    $runner->add('different locations keep the travel time between bookings', function () use ($MON, $m) {
        // Peristeri 13:30-14:00 against a Metropolitan booking (travel 30'): 14:15 is too close, 14:30 is exactly enough.
        $tight = zpms_sched_ctx([zpms_sched_r('r1', 'm', $MON, '14:15', '14:45')]);
        $tight['templates'][] = zpms_sched_t('m', 1, '14:15', '15:00', 'available');
        $p = zpms_sched_check($tight, 'p', $MON, $m('13:30'), $m('14:00'));
        assert_equal(['travel_conflict'], zpms_sched_codes($p, 'hard'));
        assert_equal(30, $p[0]['minutes']);
        $ok = zpms_sched_ctx([zpms_sched_r('r1', 'm', $MON, '14:30', '15:00')]);
        assert_equal([], zpms_sched_codes(zpms_sched_check($ok, 'p', $MON, $m('13:30'), $m('14:00')), 'hard'), 'a gap of exactly the travel time is allowed');
        // The margin works on either side: a Metropolitan booking BEFORE the Peristeri slot.
        $before = zpms_sched_ctx([zpms_sched_r('r1', 'm', $MON, '09:00', '09:45')]);
        assert_equal(['travel_conflict'], zpms_sched_codes(zpms_sched_check($before, 'p', $MON, $m('10:00'), $m('10:30')), 'hard'));
        // Online: 0 minutes of travel, so right after an in-person booking is fine ...
        $online = zpms_sched_ctx([zpms_sched_r('r1', 'p', $MON, '13:30', '14:00')]);
        assert_equal([], zpms_sched_codes(zpms_sched_check($online, 'phone', $MON, $m('14:00'), $m('14:30')), 'hard'), 'online needs no travel time');
        // ... and 2 hours between Peristeri and Metropolitan is plenty.
        $far = zpms_sched_ctx([zpms_sched_r('r1', 'm', $MON, '16:00', '16:30')]);
        assert_equal([], zpms_sched_codes(zpms_sched_check($far, 'p', $MON, $m('13:30'), $m('14:00')), 'hard'));
        // Same location: no gap needed.
        $same = zpms_sched_ctx([zpms_sched_r('r1', 'p', $MON, '13:30', '14:00')]);
        assert_equal([], zpms_sched_codes(zpms_sched_check($same, 'p', $MON, $m('13:00'), $m('13:30')), 'hard'));
    });

    $runner->add('surgery shades the other locations, widened by the travel time', function () use ($TUE, $m) {
        $ctx = zpms_sched_ctx();   // Metropolitan surgery Tue 08:00-13:00; Peristeri available Tue 15:00-19:00; travel 30
        $p = zpms_sched_check($ctx, 'p', $TUE, $m('15:00'), $m('15:30'));
        assert_equal([], zpms_sched_codes($p, 'shadow'), '15:00 is 2h after surgery ends');
        // Open Peristeri 12:00-16:00 on Tuesday by override and test the margin.
        $ov = [['location' => 'p', 'odate' => $TUE, 'start_time' => '12:00', 'end_time' => '16:00', 'kind' => 'available', 'note' => '']];
        $ctx = zpms_sched_ctx([], $ov);
        assert_equal(['surgery_shadow'], zpms_sched_codes(zpms_sched_check($ctx, 'p', $TUE, $m('12:30'), $m('13:00')), 'shadow'), 'during the surgery');
        assert_equal(['surgery_shadow'], zpms_sched_codes(zpms_sched_check($ctx, 'p', $TUE, $m('13:00'), $m('13:30')), 'shadow'), 'inside the 30 minutes of travel after it');
        assert_equal([], zpms_sched_codes(zpms_sched_check($ctx, 'p', $TUE, $m('13:30'), $m('14:00')), 'shadow'), 'travel time over');
        // Online has no travel but is shaded during the surgery itself.
        $ovOnline = [['location' => 'phone', 'odate' => $TUE, 'start_time' => '12:00', 'end_time' => '15:00', 'kind' => 'available', 'note' => '']];
        $ctx = zpms_sched_ctx([], $ovOnline);
        assert_equal(['surgery_shadow'], zpms_sched_codes(zpms_sched_check($ctx, 'phone', $TUE, $m('12:45'), $m('13:15')), 'shadow'));
        assert_equal([], zpms_sched_codes(zpms_sched_check($ctx, 'phone', $TUE, $m('13:00'), $m('13:30')), 'shadow'), 'online right after surgery: 0 minutes of travel');
        // The reason of the surgery travels with the problem.
        $ctx = zpms_sched_ctx([], $ov);
        $problem = zpms_sched_check($ctx, 'p', $TUE, $m('12:30'), $m('13:00'))[0];
        assert_equal('Επέμβαση', $problem['note']);
        assert_equal('m', $problem['location']);
        // A surgery BOOKING shades other locations too, and nobody is exempt.
        $booked = zpms_sched_ctx([zpms_sched_r('s1', 'm', '2026-12-14', '17:00', '18:00', 'surgery', 'Επέμβαση ασθενή')]);
        $booked['templates'][] = zpms_sched_t('a', 1, '16:00', '19:00', 'available');
        foreach (['a', 'p', 'phone'] as $loc) {
            $booked['templates'][] = zpms_sched_t($loc, 1, '16:00', '19:00', 'available');
            assert_equal(['surgery_shadow'], zpms_sched_codes(zpms_sched_check($booked, $loc, '2026-12-14', $m('17:00'), $m('17:30')), 'shadow'), "$loc is not exempt from the shadow");
        }
    });

    $runner->add('gaps: stranded free time warns, edges and suggestions do not', function () use ($MON, $m) {
        $ctx = zpms_sched_ctx([zpms_sched_r('r1', 'p', $MON, '12:00', '12:30')]);
        // 10:00-10:30 leaves 10:30-12:00 free, ending at the 12:00 booking: a hole.
        assert_equal(['gap'], zpms_sched_codes(zpms_sched_check($ctx, 'p', $MON, $m('10:00'), $m('10:30')), 'warn'));
        $holes = zpms_sched_holes($ctx, 'p', $MON, $m('10:00'), $m('10:30'));
        assert_equal([['side' => 'after', 'minutes' => 90]], $holes);
        assert_equal([], zpms_sched_holes($ctx, 'p', $MON, $m('11:30'), $m('12:00')), 'directly before the booking');
        assert_equal([], zpms_sched_holes($ctx, 'p', $MON, $m('12:30'), $m('13:00')), 'directly after it');
        assert_equal([['side' => 'before', 'minutes' => 15]], zpms_sched_holes($ctx, 'p', $MON, $m('12:45'), $m('13:15')));
        // The warn level is attached by zpms_sched_check().
        assert_equal(['gap'], zpms_sched_codes(zpms_sched_check($ctx, 'p', $MON, $m('13:00'), $m('13:30')), 'warn'));
    });

    $runner->add('suggestions: consecutive slots first, never a hole, never a shadow', function () use ($MON, $m) {
        $ctx = zpms_sched_ctx([zpms_sched_r('r1', 'p', $MON, '12:00', '12:30')]);
        $s = zpms_sched_suggest($ctx, 'p', $MON, 30);
        assert_true(count($s) >= 2, 'expected suggestions');
        assert_equal(['start' => $m('12:30'), 'reason' => 'after'], $s[0], 'directly after the booking is the best slot');
        $starts = array_column($s, 'start');
        assert_true(in_array($m('11:30'), $starts, true), 'directly before the booking is suggested too');
        assert_false(in_array($m('10:30'), $starts, true), '10:30 would leave a hole before the 12:00 booking');
        $empty = zpms_sched_suggest(zpms_sched_ctx(), 'p', $MON, 30);
        assert_equal($m('10:00'), $empty[0]['start'], 'on an empty day, the start of the block');
        assert_equal('start', $empty[0]['reason']);
    });

    $runner->add('creating surgery or a hold: refused while bookings are in the way', function () use ($MON, $TUE, $m) {
        $ctx = zpms_sched_ctx([zpms_sched_r('r1', 'p', $MON, '11:00', '11:30'), zpms_sched_r('r2', 'm', $MON, '16:00', '16:30')]);
        assert_equal(['has_reservation'], zpms_sched_codes(zpms_sched_check_block($ctx, 'p', $MON, $m('10:45'), $m('11:15'), 'hold')));
        assert_equal([], zpms_sched_check_block($ctx, 'p', $MON, $m('12:00'), $m('13:00'), 'hold'), 'a hold only cares about its own location');
        // Surgery at p ending 15:30 leaves exactly the 30' of travel before the 16:00 Metropolitan booking: allowed; ending 15:45 does not.
        assert_equal([], zpms_sched_check_block($ctx, 'p', $MON, $m('13:00'), $m('15:30'), 'surgery'));
        $codes = zpms_sched_codes(zpms_sched_check_block($ctx, 'p', $MON, $m('13:00'), $m('15:45'), 'surgery'));
        assert_equal(['has_reservation'], $codes, 'a booking elsewhere within travel time of the surgery');
        // Two surgeries at different locations cannot touch within the travel time.
        $s = zpms_sched_ctx();
        assert_equal(['surgery_conflict'], zpms_sched_codes(zpms_sched_check_block($s, 'p', $TUE, $m('13:15'), $m('14:00'), 'surgery')), '30 minutes of travel after the Metropolitan surgery (until 13:30)');
        assert_equal([], zpms_sched_check_block($s, 'p', $TUE, $m('13:30'), $m('14:00'), 'surgery'));
    });

    // ------------------------------------------------ database-backed

    $runner->add('history: a saved-again slot is the same row; moving keeps the old one released; released rows block nothing', function () use ($MON, $m) {
        $db = zpms_sched_db();
        foreach (['slot_reservations', 'schedule_templates', 'schedule_overrides', 'location_travel_times'] as $t) { $db->exec("DELETE FROM $t"); }
        $db->prepare('INSERT INTO schedule_templates (guid, cdate, cuser, location, weekday, start_time, end_time, kind, slot_minutes) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([guid(), getDBtime(), 't', 'hp', 1, '10:00', '14:00', 'available', 30]);
        $ref = guid();
        $a = zpms_sched_reserve($db, 'hp', $MON, $m('10:00'), $m('10:30'), 'appointment', $ref, 'α', 'u1');
        assert_true($a['ok']);
        $again = zpms_sched_reserve($db, 'hp', $MON, $m('10:00'), $m('10:30'), 'appointment', $ref, 'β', 'u1');
        assert_equal($a['guid'], $again['guid'], 're-saving without moving keeps the slot');
        assert_equal(1, (int)$db->query('SELECT COUNT(*) FROM slot_reservations')->fetchColumn(), 'no new row');
        assert_equal('β', zpms_sched_slot_for_ref($db, $ref)['note'], 'only the note changed');

        $moved = zpms_sched_reserve($db, 'hp', $MON, $m('12:00'), $m('12:30'), 'appointment', $ref, 'β', 'u2');
        assert_true($moved['ok']);
        assert_equal(2, (int)$db->query('SELECT COUNT(*) FROM slot_reservations')->fetchColumn(), 'the old slot stays as history');
        $old = $db->query("SELECT * FROM slot_reservations WHERE guid = " . $db->quote($a['guid']))->fetch(PDO::FETCH_ASSOC);
        assert_equal(['moved', 'u2', '10:00'], [$old['release_reason'], $old['released_by'], $old['start_time']]);
        assert_true($old['released_at'] !== null);

        // The old time is free again for somebody else, and the rules never see the released row.
        $other = zpms_sched_reserve($db, 'hp', $MON, $m('10:00'), $m('10:30'), 'appointment', guid(), '', 'u3');
        assert_true($other['ok'], 'a released slot blocks nothing');
        assert_equal(2, count(zpms_sched_load_context($db, $MON)['reservations']), 'the context holds live bookings only');

        // Releasing by reference records reason and person; releasing twice changes nothing.
        zpms_sched_release_ref($db, $ref, 'cancelled', 'u4');
        zpms_sched_release_ref($db, $ref, 'deleted', 'u5');
        $rel = $db->query("SELECT release_reason, released_by FROM slot_reservations WHERE guid = " . $db->quote($moved['guid']))->fetch(PDO::FETCH_ASSOC);
        assert_equal(['cancelled', 'u4'], [$rel['release_reason'], $rel['released_by']], 'the first release stands');
        assert_true(zpms_sched_slot_for_ref($db, $ref) === null);
        $db->exec('DELETE FROM slot_reservations'); $db->exec('DELETE FROM schedule_templates');
    });

    $runner->add('pattern history: a new version closes the old one; past dates keep the old pattern; the past is refused', function () {
        $db = zpms_sched_db();
        foreach (['slot_reservations', 'schedule_templates'] as $t) { $db->exec("DELETE FROM $t"); }
        $today = date('Y-m-d');
        $weekday = zpms_sched_weekday($today);
        $old = [['weekday' => $weekday, 'start' => 600, 'end' => 720, 'kind' => 'available', 'slot_minutes' => 30, 'note' => '']];
        $new = [['weekday' => $weekday, 'start' => 840, 'end' => 960, 'kind' => 'available', 'slot_minutes' => 30, 'note' => '']];
        $past = date('Y-m-d', strtotime('-7 days'));
        // The first pattern, back-dated the only way possible: written directly (an install that had one before).
        $db->prepare('INSERT INTO schedule_templates (guid, cdate, cuser, location, weekday, start_time, end_time, kind, slot_minutes, valid_from) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([guid(), getDBtime(), 't', 'ph', $weekday, '10:00', '12:00', 'available', 30, date('Y-m-d', strtotime('-60 days'))]);

        $r = zpms_sched_pattern_replace($db, 'ph', $new, $past, 'u', $today);
        assert_false($r['ok']);
        assert_equal('past', $r['error'], 'history is not rewritten');

        $r = zpms_sched_pattern_replace($db, 'ph', $new, null, 'u', $today);
        assert_true($r['ok']);
        $rows = $db->query("SELECT * FROM schedule_templates WHERE location = 'ph' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        assert_equal(2, count($rows), 'old version kept, new one added');
        assert_equal(date('Y-m-d', strtotime('yesterday')), $rows[0]['valid_to']);
        assert_equal([$today, null], [$rows[1]['valid_from'], $rows[1]['valid_to']]);

        $cells = fn(string $date) => zpms_sched_cells_to_intervals(zpms_sched_day_cells($rows, [], 'ph', $date));
        $lastWeek = date('Y-m-d', strtotime("-7 days"));
        assert_equal([600, 720], [$cells($lastWeek)[0]['start'], $cells($lastWeek)[0]['end']], 'last week still shows 10:00-12:00');
        assert_equal([840, 960], [$cells($today)[0]['start'], $cells($today)[0]['end']], 'from today, the new pattern');
        assert_equal(1, count($cells($today)), 'and only the new one');

        // A version scheduled for the future, then replaced by a nearer one: the unstarted one is dropped, not kept as history.
        $future = date('Y-m-d', strtotime('+14 days'));
        zpms_sched_pattern_replace($db, 'ph', $old, $future, 'u', $today);
        $nearer = date('Y-m-d', strtotime('+7 days'));
        zpms_sched_pattern_replace($db, 'ph', $new, $nearer, 'u', $today);
        $all = $db->query("SELECT valid_from, valid_to FROM schedule_templates WHERE location = 'ph' ORDER BY valid_from")->fetchAll(PDO::FETCH_ASSOC);
        assert_equal(3, count($all), 'the first-ever row, the version closed before the nearer one, the nearer one');
        assert_true(!in_array($future, array_column($all, 'valid_from'), true), 'the version that never started is gone');

        $hist = zpms_sched_pattern_history($db, 'ph');
        assert_true(count($hist) >= 2, 'history lists the ended versions');
        assert_true($hist[0]['to'] >= $hist[1]['to'], 'newest first');
        $db->exec('DELETE FROM schedule_templates');
    });

    $runner->add('reserve: books, refuses overlap and shadow, doctor override only, release frees the slot', function () use ($MON, $TUE, $m) {
        $db = zpms_sched_db();
        $db->exec('DELETE FROM slot_reservations');
        $db->exec('DELETE FROM schedule_templates');
        $db->exec('DELETE FROM schedule_overrides');
        $db->exec('DELETE FROM location_travel_times');
        $db->exec("DELETE FROM locations WHERE machinename IN ('sched_p','sched_m')");
        foreach (['sched_p', 'sched_m'] as $code) {
            $db->prepare('INSERT INTO locations (guid, lang, cdate, cuser, name, machinename, address) VALUES (?,?,?,?,?,?,?)')
                ->execute([guid(), 'gr', getDBtime(), 'test', $code, $code, '']);
        }
        $ins = $db->prepare('INSERT INTO schedule_templates (guid, cdate, cuser, location, weekday, start_time, end_time, kind, slot_minutes, note) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([guid(), getDBtime(), 'test', 'sched_p', 1, '10:00', '14:00', 'available', 30, '']);
        $ins->execute([guid(), getDBtime(), 'test', 'sched_m', 2, '08:00', '13:00', 'surgery', 30, 'Επέμβαση']);
        $ins->execute([guid(), getDBtime(), 'test', 'sched_p', 2, '09:00', '16:00', 'available', 30, '']);
        $db->prepare('INSERT INTO location_travel_times (guid, cdate, cuser, from_location, to_location, minutes) VALUES (?,?,?,?,?,?)')
            ->execute([guid(), getDBtime(), 'test', 'sched_m', 'sched_p', 30]);

        $a = zpms_sched_reserve($db, 'sched_p', $MON, $m('10:00'), $m('10:30'), 'appointment', null, 'Πρώτη επίσκεψη', 'tester');
        assert_true($a['ok'], 'first booking should succeed: ' . json_encode($a['problems']));
        $row = $db->query("SELECT * FROM slot_reservations WHERE guid = " . $db->quote($a['guid']))->fetch(PDO::FETCH_ASSOC);
        assert_equal('10:00', $row['start_time']);
        assert_equal('Πρώτη επίσκεψη', $row['note'], 'the note is stored with the slot');
        assert_equal(0, (int)$row['shadow_override']);

        $b = zpms_sched_reserve($db, 'sched_p', $MON, $m('10:15'), $m('10:45'), 'appointment', null, '', 'tester');
        assert_false($b['ok'], 'an overlapping booking must be refused');
        assert_equal(['overlap'], zpms_sched_codes($b['problems'], 'hard'));
        assert_equal(1, (int)$db->query('SELECT COUNT(*) FROM slot_reservations')->fetchColumn(), 'a refused booking writes nothing');

        // Tuesday 12:30 at sched_p is inside the Metropolitan surgery (08:00-13:00) shadow.
        $c = zpms_sched_reserve($db, 'sched_p', $TUE, $m('12:30'), $m('13:00'), 'appointment', null, '', 'tester', false);
        assert_false($c['ok'], 'inside a surgery shadow: refused for anyone without the override');
        assert_equal(['surgery_shadow'], zpms_sched_codes($c['problems'], 'shadow'));
        $d = zpms_sched_reserve($db, 'sched_p', $TUE, $m('12:30'), $m('13:00'), 'appointment', null, 'το ξέρω', 'doctor', true);
        assert_true($d['ok'], 'the doctor can override the shadow');
        $row = $db->query("SELECT shadow_override FROM slot_reservations WHERE guid = " . $db->quote($d['guid']))->fetch(PDO::FETCH_ASSOC);
        assert_equal(1, (int)$row['shadow_override'], 'an overridden booking is marked');

        // The override never accepts a hard problem.
        $e = zpms_sched_reserve($db, 'sched_p', $TUE, $m('12:45'), $m('13:15'), 'appointment', null, '', 'doctor', true);
        assert_false($e['ok'], 'overlap stays refused even for the doctor');
        $f = zpms_sched_reserve($db, 'sched_p', $MON, $m('18:00'), $m('18:30'), 'appointment', null, '', 'doctor', true);
        assert_false($f['ok'], 'outside availability stays refused even for the doctor');

        assert_true(zpms_sched_release($db, $a['guid']));
        $g = zpms_sched_reserve($db, 'sched_p', $MON, $m('10:15'), $m('10:45'), 'appointment', null, '', 'tester');
        assert_true($g['ok'], 'a released slot can be booked again');
    });

    $runner->add('changing the pattern: bookings that would be stranded are listed, past ones ignored', function () use ($MON) {
        $db = zpms_sched_db();
        $future = date('Y-m-d', strtotime('next monday +7 days'));
        $past = date('Y-m-d', strtotime('last monday -7 days'));
        $db->exec('DELETE FROM slot_reservations');
        foreach ([$future, $past] as $d) {
            $db->prepare('INSERT INTO slot_reservations (guid, cdate, cuser, location, rdate, start_time, end_time, kind) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([guid(), getDBtime(), 'test', 'sched_p', $d, '10:00', '10:30', 'appointment']);
        }
        $current = $db->query('SELECT * FROM schedule_templates')->fetchAll(PDO::FETCH_ASSOC);
        assert_equal(0, count(zpms_sched_stranded_reservations($db, $current, date('Y-m-d'))), 'the current pattern strands nothing');
        $without = array_values(array_filter($current, fn($t) => !($t['location'] === 'sched_p' && (int)$t['weekday'] === 1)));
        $stranded = zpms_sched_stranded_reservations($db, $without, date('Y-m-d'));
        assert_equal(1, count($stranded), 'only the future booking is stranded');
        assert_equal($future, $stranded[0]['rdate']);
    });

    $runner->add('cleanup: test rows removed', function () {
        $db = zpms_sched_db();
        foreach (['slot_reservations', 'schedule_templates', 'schedule_overrides', 'location_travel_times'] as $t) {
            $db->exec("DELETE FROM $t");
        }
        $db->exec("DELETE FROM locations WHERE machinename IN ('sched_p','sched_m')");
        assert_equal(0, (int)$db->query('SELECT COUNT(*) FROM slot_reservations')->fetchColumn());
    });
}


// ------------------------------------------------------------------ HTTP

/** The CSRF token the page puts in <meta name="csrf-token">. */
function zpms_sched_meta_token(string $html): string {
    assert_true((bool)preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m), 'the page has no csrf-token meta tag');
    return html_entity_decode($m[1], ENT_QUOTES);
}

/** POST to a schedule endpoint the way the script does; returns [status, decoded JSON]. */
function zpms_sched_api(TestHttpClient $http, string $token, string $path, array $fields): array {
    $res = $http->postWithHeaders($path, $fields, ['X-CSRF-Token' => $token]);
    // The test server prints a harmless "config/db.php missing" warning ahead of
    // every response (the tests use their own database); skip to the JSON.
    $start = strpos($res['body'], '{"');
    $json = $start === false ? null : json_decode(substr($res['body'], $start), true);
    assert_true(is_array($json), "$path did not answer JSON (HTTP {$res['status']}): " . substr(strip_tags($res['body']), 0, 300));
    return [$res['status'], $json];
}

function zpms_sched_audit_rows(int $afterId): array {
    return zpms_sched_db()->query('SELECT * FROM audit_log WHERE id > ' . $afterId . " AND event_type LIKE 'schedule_%' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}

function zpms_schedule_http_tests(TestRunner $runner, string $baseUrl): void {
    $monday = date('Y-m-d', strtotime('monday next week +7 days'));
    $tuesday = date('Y-m-d', strtotime($monday . ' +1 day'));
    $m = fn(string $t) => zpms_sched_time_to_min($t);

    $runner->add('set up two locations, a pattern, a travel time and the secretary account', function () {
        $db = zpms_sched_db();
        foreach (['slot_reservations', 'schedule_templates', 'schedule_overrides', 'location_travel_times'] as $t) {
            $db->exec("DELETE FROM $t");
        }
        $db->exec("DELETE FROM locations WHERE machinename IN ('sched_p','sched_m','phone')");
        foreach ([['sched_p', 'Περιστέρι'], ['sched_m', 'Metropolitan'], ['phone', 'Τηλεφωνικά']] as [$code, $name]) {
            $db->prepare('INSERT INTO locations (guid, lang, cdate, cuser, name, machinename, address) VALUES (?,?,?,?,?,?,?)')
                ->execute([guid(), 'gr', getDBtime(), 'test', $name, $code, '']);
        }
        $ins = $db->prepare('INSERT INTO schedule_templates (guid, cdate, cuser, location, weekday, start_time, end_time, kind, slot_minutes, note) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([guid(), getDBtime(), 'test', 'sched_p', 1, '10:00', '14:00', 'available', 30, '']);
        $ins->execute([guid(), getDBtime(), 'test', 'sched_m', 2, '08:00', '13:00', 'surgery', 30, 'Επέμβαση']);
        $ins->execute([guid(), getDBtime(), 'test', 'sched_p', 2, '09:00', '16:00', 'available', 30, '']);
        $db->prepare('INSERT INTO location_travel_times (guid, cdate, cuser, from_location, to_location, minutes) VALUES (?,?,?,?,?,?)')
            ->execute([guid(), getDBtime(), 'test', 'sched_m', 'sched_p', 30]);
        if (!(int)$db->query("SELECT COUNT(*) FROM users WHERE uname = '" . TestFixtures::SECRETARY_USERNAME . "'")->fetchColumn()) {
            TestFixtures::createSecretaryUser();
        }
    });

    $runner->add('/schedule: refused when logged out; doctor and secretary can open it and see the week', function () use ($baseUrl, $monday) {
        $anon = new TestHttpClient($baseUrl);
        $res = $anon->get('/schedule');
        assert_not_contains('id="schedule-data"', $res['body'], 'a logged-out request must not get the schedule');

        foreach (['doctor' => fn($h) => TestFixtures::loginAsTestUser($h), 'secretary' => fn($h) => TestFixtures::loginAsSecretary($h)] as $who => $login) {
            $http = new TestHttpClient($baseUrl);
            $login($http);
            $page = $http->get('/schedule?loc=sched_p&date=' . $monday);
            assert_equal(200, $page['status'], "$who: GET /schedule");
            assert_contains('id="schedule-data"', $page['body'], "$who: the page carries its data");
            assert_contains('sched_p', $page['body']);
            assert_contains('Περιστέρι', $page['body'], "$who: the location tab is shown by name");
            assert_contains('Πρόγραμμα Ραντεβού', $page['body'], "$who: Greek title from the dictionary");
            $data = zpms_sched_page_model($page['body']);
            assert_equal('sched_p', $data['location']);
            assert_equal($monday, $data['days'][0]['date']);
            $blocks = $data['days'][0]['blocks'];
            assert_equal('avail', $blocks[0]['type'], "$who: Monday 10:00-14:00 is available");
            assert_equal(600, $blocks[0]['start']);
            assert_equal(840, $blocks[0]['end']);
            assert_equal($who === 'doctor', $data['canOverride'], "$who: only the doctor may override the shadow");
        }
    });

    $runner->add('the endpoints refuse a request without the CSRF token and a logged-out one', function () use ($baseUrl, $monday) {
        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);
        $res = $http->post('/schedule/check', ['loc' => 'sched_p', 'date' => $monday, 'start' => '10:00', 'end' => '10:30', 'mode' => 'book']);
        assert_equal(403, $res['status'], 'no token');
        $res = (new TestHttpClient($baseUrl))->post('/schedule/check', ['loc' => 'sched_p']);
        assert_true(in_array($res['status'], [302, 401, 403], true), 'logged out, got ' . $res['status']);
    });

    $runner->add('check: free time is fine, a hold and a surgery shadow are reported; only the doctor may override', function () use ($baseUrl, $monday, $tuesday) {
        $doctor = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($doctor);
        $token = zpms_sched_meta_token($doctor->get('/schedule')['body']);

        [$st, $r] = zpms_sched_api($doctor, $token, '/schedule/check', ['loc' => 'sched_p', 'date' => $monday, 'start' => '10:00', 'end' => '10:30', 'mode' => 'book']);
        assert_equal(200, $st);
        assert_equal([], zpms_sched_codes($r['problems'], 'hard'));
        assert_true($r['can_override'], 'doctor');

        [, $r] = zpms_sched_api($doctor, $token, '/schedule/check', ['loc' => 'sched_p', 'date' => $monday, 'start' => '09:00', 'end' => '09:30', 'mode' => 'book']);
        assert_equal(['not_available'], zpms_sched_codes($r['problems'], 'hard'));

        // Tuesday 12:30 at sched_p is inside the Metropolitan surgery shade.
        [, $r] = zpms_sched_api($doctor, $token, '/schedule/check', ['loc' => 'sched_p', 'date' => $tuesday, 'start' => '12:30', 'end' => '13:00', 'mode' => 'book']);
        assert_equal(['surgery_shadow'], zpms_sched_codes($r['problems'], 'shadow'));

        [, $r] = zpms_sched_api($doctor, $token, '/schedule/check', ['loc' => 'sched_p', 'date' => $monday, 'start' => '11:00', 'end' => '11:20', 'mode' => 'book']);
        assert_equal(['invalid_interval'], zpms_sched_codes($r['problems']), 'off the 15-minute grid');

        $secretary = new TestHttpClient($baseUrl);
        TestFixtures::loginAsSecretary($secretary);
        $stoken = zpms_sched_meta_token($secretary->get('/schedule')['body']);
        [, $r] = zpms_sched_api($secretary, $stoken, '/schedule/check', ['loc' => 'sched_p', 'date' => $tuesday, 'start' => '12:30', 'end' => '13:00', 'mode' => 'book']);
        assert_false($r['can_override'], 'the secretary cannot override the shadow');
    });

    $runner->add('a date can be reserved with a reason, shows on the calendar, and goes back to the pattern', function () use ($baseUrl, $monday) {
        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);
        $token = zpms_sched_meta_token($http->get('/schedule')['body']);
        $audit = (int)zpms_sched_db()->query('SELECT COALESCE(MAX(id),0) FROM audit_log')->fetchColumn();
        $base = ['loc' => 'sched_p', 'date' => $monday, 'start' => '12:00', 'end' => '13:00'];

        [$st, $r] = zpms_sched_api($http, $token, '/schedule/override', $base + ['kind' => 'hold', 'note' => '']);
        assert_equal(422, $st, 'a reserved time needs a reason');
        assert_equal('note_required', $r['error']);

        [$st] = zpms_sched_api($http, $token, '/schedule/override', $base + ['kind' => 'hold', 'note' => 'Σύσκεψη διοίκησης ΑΒΓ']);
        assert_equal(200, $st);
        $data = zpms_sched_page_model($http->get('/schedule?loc=sched_p&date=' . $monday)['body']);
        $types = array_map(fn($b) => $b['type'] . ':' . $b['start'], $data['days'][0]['blocks']);
        assert_equal(['avail:600', 'hold:720', 'avail:780'], $types, 'the hold splits the available time');
        assert_equal('Σύσκεψη διοίκησης ΑΒΓ', $data['days'][0]['blocks'][1]['note'], 'the reason is shown on the calendar');

        [, $r] = zpms_sched_api($http, $token, '/schedule/check', ['loc' => 'sched_p', 'date' => $monday, 'start' => '12:00', 'end' => '12:30', 'mode' => 'book']);
        assert_equal(['held'], zpms_sched_codes($r['problems'], 'hard'));

        [$st] = zpms_sched_api($http, $token, '/schedule/override', $base + ['kind' => 'clear']);
        assert_equal(200, $st);
        $data = zpms_sched_page_model($http->get('/schedule?loc=sched_p&date=' . $monday)['body']);
        assert_equal(1, count($data['days'][0]['blocks']), 'back to the plain pattern');

        $rows = zpms_sched_audit_rows($audit);
        assert_equal(['schedule_override_set', 'schedule_override_cleared'], array_column($rows, 'event_type'));
        foreach ($rows as $row) {
            foreach ($row as $value) {
                assert_true($value === null || !str_contains((string)$value, 'ΑΒΓ'), 'the reason must never reach the audit log');
            }
        }

        [$st, $r] = zpms_sched_api($http, $token, '/schedule/override', ['loc' => 'sched_p', 'date' => '2020-01-06', 'start' => '10:00', 'end' => '11:00', 'kind' => 'hold', 'note' => 'x']);
        assert_equal(422, $st, 'past dates cannot be changed');
    });

    $runner->add('surgery on a date shades the other location, with the reason; a booking in the way refuses it', function () use ($baseUrl, $monday, $m) {
        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);
        $token = zpms_sched_meta_token($http->get('/schedule')['body']);

        [$st] = zpms_sched_api($http, $token, '/schedule/override', ['loc' => 'sched_m', 'date' => $monday, 'start' => '16:00', 'end' => '17:00', 'kind' => 'surgery', 'note' => 'Επέμβαση Χ']);
        assert_equal(200, $st);
        // Metropolitan 16:00-17:00 + 30' travel shades Peristeri 15:30-17:30; Peristeri is open until 14:00, so open it by override and look.
        zpms_sched_api($http, $token, '/schedule/override', ['loc' => 'sched_p', 'date' => $monday, 'start' => '14:00', 'end' => '18:00', 'kind' => 'available', 'note' => '']);
        $blocks = zpms_sched_page_model($http->get('/schedule?loc=sched_p&date=' . $monday)['body'])['days'][0]['blocks'];
        $shadow = array_values(array_filter($blocks, fn($b) => $b['type'] === 'shadow'));
        assert_equal(1, count($shadow));
        assert_equal(930, $shadow[0]['start'], 'surgery starts 16:00, minus 30 minutes of travel');
        assert_equal(1050, $shadow[0]['end']);
        assert_equal('sched_m', $shadow[0]['label']);
        assert_equal('Επέμβαση Χ', $shadow[0]['note'], 'the surgery reason travels to the shaded calendar');

        // Put a booking at Peristeri 13:30-14:00 and try surgery at Metropolitan 14:15 (needs 30' of travel): refused.
        $r = zpms_sched_reserve(zpms_sched_db(), 'sched_p', $monday, $m('13:30'), $m('14:00'), 'appointment', null, 'x', 'test');
        assert_true($r['ok'], 'setup booking: ' . json_encode($r['problems']));
        [$st, $res] = zpms_sched_api($http, $token, '/schedule/override', ['loc' => 'sched_m', 'date' => $monday, 'start' => '14:15', 'end' => '15:00', 'kind' => 'surgery', 'note' => 'Y']);
        assert_equal(409, $st);
        assert_equal(['has_reservation'], zpms_sched_codes($res['problems']));
        // Clearing the Peristeri override that opened 14:00-18:00 is fine; clearing 13:30-14:00 (not an override) changes nothing and is fine too,
        // but turning the booked time into a hold is refused (the booking would be stranded or in the way).
        [$st, $res] = zpms_sched_api($http, $token, '/schedule/override', ['loc' => 'sched_p', 'date' => $monday, 'start' => '13:30', 'end' => '14:00', 'kind' => 'hold', 'note' => 'Z']);
        assert_equal(409, $st);
        zpms_sched_db()->exec('DELETE FROM slot_reservations');
        zpms_sched_db()->exec('DELETE FROM schedule_overrides');
    });

    $runner->add('weekly pattern: validated, saved, and refused while it would strand a future booking', function () use ($baseUrl, $monday, $m) {
        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);
        $token = zpms_sched_meta_token($http->get('/schedule')['body']);
        $audit = (int)zpms_sched_db()->query('SELECT COALESCE(MAX(id),0) FROM audit_log')->fetchColumn();
        $good = [['weekday' => 3, 'start_time' => '16:00', 'end_time' => '19:00', 'kind' => 'available', 'slot_minutes' => 30, 'note' => '']];

        [$st, $r] = zpms_sched_api($http, $token, '/schedule/pattern', ['loc' => 'sched_m', 'valid_from' => '', 'rows' => json_encode([['weekday' => 3, 'start_time' => '16:10', 'end_time' => '19:00', 'kind' => 'available', 'slot_minutes' => 30, 'note' => '']])]);
        assert_equal(422, $st);
        assert_equal('times', $r['errors'][0]['code']);
        [$st, $r] = zpms_sched_api($http, $token, '/schedule/pattern', ['loc' => 'sched_m', 'valid_from' => '', 'rows' => json_encode([['weekday' => 3, 'start_time' => '16:00', 'end_time' => '19:00', 'kind' => 'surgery', 'slot_minutes' => 30, 'note' => '']])]);
        assert_equal('note', $r['errors'][0]['code'], 'surgery needs a reason');
        $two = [$good[0], ['weekday' => 3, 'start_time' => '18:00', 'end_time' => '20:00', 'kind' => 'available', 'slot_minutes' => 30, 'note' => '']];
        [, $r] = zpms_sched_api($http, $token, '/schedule/pattern', ['loc' => 'sched_m', 'valid_from' => '', 'rows' => json_encode($two)]);
        assert_equal('overlap', $r['errors'][0]['code']);

        [$st] = zpms_sched_api($http, $token, '/schedule/pattern', ['loc' => 'sched_m', 'valid_from' => '', 'rows' => json_encode($good)]);
        assert_equal(200, $st);
        $rows = zpms_sched_db()->query("SELECT * FROM schedule_templates WHERE location = 'sched_m' AND valid_to IS NULL")->fetchAll(PDO::FETCH_ASSOC);
        assert_equal(1, count($rows), 'one version is in force');
        assert_equal('16:00', $rows[0]['start_time']);
        assert_equal(date('Y-m-d'), $rows[0]['valid_from'], 'from today');
        $closed = zpms_sched_db()->query("SELECT * FROM schedule_templates WHERE location = 'sched_m' AND valid_to IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        assert_equal(1, count($closed), 'the old surgery row is kept as history');
        assert_equal(date('Y-m-d', strtotime('yesterday')), $closed[0]['valid_to'], 'closed the day before');
        [$st, $past] = zpms_sched_api($http, $token, '/schedule/pattern', ['loc' => 'sched_m', 'valid_from' => date('Y-m-d', strtotime('yesterday')), 'rows' => json_encode($good)]);
        assert_equal(422, $st);
        assert_equal('past', $past['error'], 'a new version cannot start in the past');

        // A future booking at Peristeri on Monday 10:00; replacing Peristeri's pattern without Monday must be refused.
        $b = zpms_sched_reserve(zpms_sched_db(), 'sched_p', $monday, $m('10:00'), $m('10:30'), 'appointment', null, '', 'test');
        assert_true($b['ok']);
        [$st, $r] = zpms_sched_api($http, $token, '/schedule/pattern', ['loc' => 'sched_p', 'valid_from' => '', 'rows' => json_encode([['weekday' => 2, 'start_time' => '09:00', 'end_time' => '16:00', 'kind' => 'available', 'slot_minutes' => 30, 'note' => ''], ['weekday' => 3, 'start_time' => '09:00', 'end_time' => '10:00', 'kind' => 'available', 'slot_minutes' => 30, 'note' => '']])]);
        assert_equal(409, $st);
        assert_equal('blocked', $r['error']);
        assert_equal(1, count($r['stranded']));
        assert_equal($monday, $r['stranded'][0]['date']);
        assert_true(!isset($r['stranded'][0]['note']) && !isset($r['stranded'][0]['ref_guid']), 'the list says where and when, never who');
        $count = (int)zpms_sched_db()->query("SELECT COUNT(*) FROM schedule_templates WHERE location = 'sched_p' AND weekday = 1")->fetchColumn();
        assert_equal(1, $count, 'a refused pattern writes nothing');
        zpms_sched_release(zpms_sched_db(), $b['guid']);

        $events = array_column(zpms_sched_audit_rows($audit), 'event_type');
        assert_equal(['schedule_pattern_changed'], $events, 'one audit row, for the saved pattern only');
    });

    $runner->add('travel times: saved in both directions, validated, audited', function () use ($baseUrl) {
        $http = new TestHttpClient($baseUrl);
        TestFixtures::loginAsTestUser($http);
        $token = zpms_sched_meta_token($http->get('/schedule')['body']);
        $audit = (int)zpms_sched_db()->query('SELECT COALESCE(MAX(id),0) FROM audit_log')->fetchColumn();

        [$st, $r] = zpms_sched_api($http, $token, '/schedule/travel', ['symmetric' => '1', 'rows' => json_encode([['from' => 'sched_p', 'to' => 'sched_m', 'minutes' => 'abc']])]);
        assert_equal(422, $st);
        [$st] = zpms_sched_api($http, $token, '/schedule/travel', ['symmetric' => '1', 'rows' => json_encode([['from' => 'sched_p', 'to' => 'nowhere', 'minutes' => 10]])]);
        assert_equal(422, $st, 'an unknown location');

        [$st] = zpms_sched_api($http, $token, '/schedule/travel', ['symmetric' => '1', 'rows' => json_encode([['from' => 'sched_p', 'to' => 'sched_m', 'minutes' => 45]])]);
        assert_equal(200, $st);
        $t = zpms_sched_load_travel(zpms_sched_db());
        assert_equal(45, $t['sched_p']['sched_m']);
        assert_equal(45, $t['sched_m']['sched_p'], 'the opposite direction was set too');
        assert_equal(['schedule_travel_changed'], array_column(zpms_sched_audit_rows($audit), 'event_type'));
    });

    $runner->add('cleanup: schedule test rows removed', function () {
        $db = zpms_sched_db();
        foreach (['slot_reservations', 'schedule_templates', 'schedule_overrides', 'location_travel_times'] as $t) {
            $db->exec("DELETE FROM $t");
        }
        $db->exec("DELETE FROM locations WHERE machinename IN ('sched_p','sched_m','phone')");
        assert_equal(0, (int)$db->query('SELECT COUNT(*) FROM schedule_templates')->fetchColumn());
    });
}

/** The model the page hands to schedule.js. */
function zpms_sched_page_model(string $html): array {
    assert_true((bool)preg_match('#<script type="application/json" id="schedule-data">(.*?)</script>#s', $html, $m), 'schedule-data script not found');
    $data = json_decode($m[1], true);
    assert_true(is_array($data), 'schedule-data is not valid JSON');
    return $data;
}
