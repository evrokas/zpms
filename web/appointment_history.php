<?php

/**
 * Records and formats aggregated appointment-edit history -- see
 * web/classes/yaml/appointment_history.yaml's own docblock for the full
 * design (5-minute sliding-window sessions, one row per appointment+user
 * pair per session) and appointmentHistoryClassEx (web/ClassesEx.php) for
 * the read-side queries. This file holds the one write path
 * (zpms_record_appointment_change(), called from appointment_edit_post()
 * in web/index.php right before $ap->update()) and the human-readable
 * field-label mapping the history view renders against.
 */

// How long a gap between two saves on the same appointment, by the same
// user, is still treated as "the same editing session" -- matches the
// feature's own name ("aggregate 5-min sessions"). A sliding window, not
// a fixed bucket from the session's start: each new save that lands
// inside this window extends the window from its own timestamp, so a
// user who keeps steadily editing (never idle for a full 5 minutes)
// stays in one session no matter how long the whole stretch runs.
const ZPMS_APPOINTMENT_HISTORY_SESSION_MINUTES = 5;

// The three fields appointment_edit_post() ever writes, and the label
// each renders under in the history view -- kept here, not duplicated at
// the one call site, so a future new editable field only needs updating
// in one place.
function zpms_appointment_history_field_labels(): array {
    return [
        'appointment-date' => 'Ημ/νία & Ώρα',
        'appointment-place' => 'Περιοχή',
        'appointment-notes' => 'Σημειώσεις',
    ];
}

/**
 * Folds one save's changed fields into this appointment/user's current
 * editing session -- extending the most recent session row if the last
 * save on this exact appointment by this exact user landed within
 * ZPMS_APPOINTMENT_HISTORY_SESSION_MINUTES of now, or starting a fresh
 * row otherwise. A no-op when $changedFields is empty (nothing to
 * record -- e.g. an autosave POST that re-submitted the same values with
 * nothing actually changed).
 *
 * $changedFields is a plain array of the posted field names that
 * actually differed from what was already stored (appointment_edit_post()
 * computes this by comparing old vs. new before calling this) -- never
 * "every field present in the POST", since loader.js's own autosave
 * always submits the whole form regardless of which single field the
 * user actually typed into.
 */
function zpms_record_appointment_change(int $appointmentId, string $cuser, array $changedFields): void {
    if (empty($changedFields)) {
        return;
    }

    $now = getDBtime();
    $existing = appointmentHistoryClassEx::getMostRecentSession($appointmentId, $cuser);

    if ($existing) {
        $gapSeconds = strtotime($now) - strtotime($existing->getlast_change_at());
        if ($gapSeconds >= 0 && $gapSeconds <= ZPMS_APPOINTMENT_HISTORY_SESSION_MINUTES * 60) {
            $already = array_filter(explode(',', $existing->getchanged_fields()));
            $merged = array_values(array_unique(array_merge($already, $changedFields)));

            $existing->setlast_change_at($now);
            $existing->setchanged_fields(implode(',', $merged));
            $existing->setchange_count($existing->getchange_count() + 1);
            $existing->update();
            return;
        }
    }

    $row = new appointmentHistoryClass([
        'guid' => guid(),
        'cdate' => $now,
        'cuser' => $cuser,
        'appointment_id' => $appointmentId,
        'last_change_at' => $now,
        'changed_fields' => implode(',', array_values(array_unique($changedFields))),
        'change_count' => 1,
    ]);
    $row->insert();
}

/**
 * One display-ready row per session: who, when (session start -> last
 * change, or just the one time if it was a single save), which fields
 * (resolved to their Greek labels, unrecognized/legacy field names left
 * as-is rather than silently dropped), and how many saves were folded in.
 */
function zpms_appointment_history_for_display(int $appointmentId): array {
    $labels = zpms_appointment_history_field_labels();
    $rows = appointmentHistoryClassEx::getHistoryForAppointment($appointmentId);

    $out = [];
    foreach ($rows as $row) {
        $fieldNames = array_filter(explode(',', $row->getchanged_fields()));
        $fieldLabels = array_map(function ($f) use ($labels) {
            return $labels[$f] ?? $f;
        }, $fieldNames);

        $start = $row->getcdate();
        $end = $row->getlast_change_at();

        $out[] = [
            'user' => $row->getcuser(),
            'started_at' => formatDateTime($start),
            'ended_at' => formatDateTime($end),
            // A single-save session has start == end -- the template
            // shows just one timestamp rather than "12:00 -- 12:00".
            'single_moment' => (strtotime($start) === strtotime($end)),
            'fields' => $fieldLabels,
            'change_count' => (int)$row->getchange_count(),
        ];
    }

    return $out;
}
