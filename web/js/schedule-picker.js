/* Booking picker (zpms_booking_picker() in web/schedule_pages.php): inside an
 * appointment form, choose location and date, then drag on that day's
 * calendar to pick the time. The choice goes into hidden sched-start /
 * sched-end fields; the rules are checked by the server (POST /schedule/check)
 * and again, authoritatively, when the form is saved. All wording comes from
 * window.zpmsText (sched*). */
(function () {
    'use strict';

    var root = document.querySelector('[data-booking-picker]');
    if (!root) return;

    var T = window.zpmsText || {};
    var STEP = 15, DAY_START = 7 * 60, DAY_END = 21 * 60, ROWS = (DAY_END - DAY_START) / STEP;
    var form = root.closest('form');
    var gridEl = root.querySelector('[data-booking-grid]');
    var panelEl = root.querySelector('[data-booking-panel]');
    var locEl = root.querySelector('[name="sched-loc"]');
    var dateEl = root.querySelector('[name="sched-date"]');
    var startEl = root.querySelector('[data-sched-start]');
    var endEl = root.querySelector('[data-sched-end]');
    var overrideEl = root.querySelector('[data-sched-override]');
    var remoteEl = root.querySelector('[data-remote]');
    var platformEl = root.querySelector('[name="remote-platform"]');
    var contactEl = root.querySelector('[name="remote-contact"]');
    var contactLabel = root.querySelector('[data-remote-contact-label]');
    var names = JSON.parse(root.dataset.names || '{}');
    var ref = root.dataset.ref || '';
    var kind = root.dataset.kind || 'appointment';
    var canOverride = root.dataset.canOverride === '1';
    var phone = root.dataset.phone;

    var cells = new Array(ROWS).fill(null);   // block covering each row
    var loaded = false;                        // is `cells` valid for the chosen loc/date
    var isPast = false;
    var sel = null;                            // {r0, r1}
    var drag = null;
    var result = null;
    var daySuggestions = [];                   // for a default-length booking, from /schedule/day
    var useOverride = false;
    var seq = 0;

    function txt(key, vars) {
        var s = T[key] || key;
        Object.keys(vars || {}).forEach(function (k) { s = s.split('@' + k).join(String(vars[k])); });
        return s;
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function hm(min) { return pad(Math.floor(min / 60)) + ':' + pad(min % 60); }
    function toMin(s) { var p = s.split(':'); return +p[0] * 60 + +p[1]; }
    function rowMin(r) { return DAY_START + r * STEP; }
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }
    function post(url, data) {
        var body = new URLSearchParams();
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        return fetch(url, { method: 'POST', headers: { 'X-CSRF-Token': csrf(), 'Accept': 'application/json' }, body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .catch(function () { return { ok: false, error: 'network' }; });
    }

    // ------------------------------------------------------------- remote

    function updateRemote() {
        var on = locEl.value === phone;
        remoteEl.hidden = !on;
        contactEl.required = on;
        if (contactLabel) {
            var link = ['zoom', 'webex', 'teams', 'meet'].indexOf(platformEl.value) !== -1;
            contactLabel.textContent = link ? txt('schedRemoteLink') : txt('schedRemoteNumber');
        }
    }

    // --------------------------------------------------------------- grid

    function allowed(r) {
        if (!loaded || isPast || r < 0 || r >= ROWS) return false;
        var b = cells[r];
        if (!b) return false;
        return b.type === 'avail' || (b.type === 'resv' && b.own) || (kind === 'surgery' && b.type === 'surgery');
    }

    function blockLabel(b, idx) {
        if (idx === 1 && b.note && (b.type === 'resv' || b.type === 'hold' || b.type === 'surgery')) return b.note;
        if (idx !== 0) return '';
        switch (b.type) {
            case 'avail': return txt('schedAvailable');
            case 'hold': return txt('schedReserved');
            case 'surgery': return txt('schedSurgery');
            case 'shadow': return b.ref === 'surgery' ? txt('schedSurgeryAt', { location: names[b.label] || b.label }) : txt('schedBookingAt', { location: names[b.label] || b.label });
            case 'resv':
                if (b.own) return txt('schedThisAppointment');
                var info = b.info || {};
                return info.patient ? txt('schedPatient', { id: info.patient }) : (info.pending ? txt('schedPendingLabel') : txt('schedBooked'));
        }
        return '';
    }

    function render() {
        var h = '<div class="h"></div><div class="h">' + esc(dateEl.value ? dateEl.value.split('-').reverse().join('/') : '') + '</div>';
        for (var r = 0; r < ROWS; r++) {
            var m = rowMin(r), b = cells[r], cls = 's', label = '';
            if (b) {
                cls += ' ' + (b.type === 'avail' ? 'avail' : (b.type === 'resv' && b.own ? 'avail own' : (b.type === 'resv' && b.label === 'surgery' ? 'resv opsurg' : b.type)));
                var idx = 0;
                while (r - 1 - idx >= 0 && cells[r - 1 - idx] === b) idx++;
                label = blockLabel(b, idx);
            }
            if (m % 60 === 0) cls += ' hr0';
            if (sel && r >= sel.r0 && r <= sel.r1) {
                cls = 's sel kind-' + (kind === 'surgery' ? 'surgery' : 'book') + (m % 60 === 0 ? ' hr0' : '') + (r === sel.r0 ? ' first' : '') + (r === sel.r1 ? ' last' : '');
                label = r === sel.r0 ? hm(rowMin(sel.r0)) + '–' + hm(rowMin(sel.r1) + STEP) : '';
            }
            h += '<div class="t' + (m % 60 === 0 ? ' hr' : '') + '">' + (m % 30 === 0 ? hm(m) : '') + '</div><div class="' + cls + '" data-r="' + r + '">' + esc(label) + '</div>';
        }
        gridEl.innerHTML = h;
    }

    function rowAt(x, y) {
        var e = document.elementFromPoint(x, y);
        while (e && e !== gridEl && !(e.dataset && e.dataset.r !== undefined)) e = e.parentNode;
        return e && e.dataset && e.dataset.r !== undefined ? +e.dataset.r : null;
    }

    function extend(anchor, target) {
        var dir = target >= anchor ? 1 : -1, end = anchor;
        for (var r = anchor; dir > 0 ? r <= target : r >= target; r += dir) { if (!allowed(r)) break; end = r; }
        return { r0: Math.min(anchor, end), r1: Math.max(anchor, end) };
    }

    gridEl.addEventListener('pointerdown', function (ev) {
        var r = rowAt(ev.clientX, ev.clientY);
        if (r === null) return;
        if (sel && (r === sel.r0 || r === sel.r1)) drag = { anchor: r === sel.r0 ? sel.r1 : sel.r0 };
        else {
            if (!allowed(r)) { panelMessage('bad', txt('schedNothingHere')); return; }
            drag = { anchor: r }; sel = { r0: r, r1: r }; useOverride = false;
        }
        try { gridEl.setPointerCapture(ev.pointerId); } catch (e) { /* unsupported */ }
        ev.preventDefault();
        setFields(); render(); renderPanel(true);
    });
    gridEl.addEventListener('pointermove', function (ev) {
        if (!drag) return;
        var r = rowAt(ev.clientX, ev.clientY);
        if (r === null) return;
        var e = extend(drag.anchor, r);
        if (sel.r0 !== e.r0 || sel.r1 !== e.r1) { sel = e; setFields(); render(); renderPanel(true); }
    });
    function endDrag() { if (!drag) return; drag = null; runCheck(); }
    gridEl.addEventListener('pointerup', endDrag);
    gridEl.addEventListener('pointercancel', endDrag);

    function setFields() {
        startEl.value = sel ? hm(rowMin(sel.r0)) : '';
        endEl.value = sel ? hm(rowMin(sel.r1) + STEP) : '';
        overrideEl.value = useOverride ? '1' : '0';
    }

    // -------------------------------------------------------------- panel

    function panelMessage(cls, text) {
        panelEl.innerHTML = '';
        var box = el('div', 'sched-msgs'); box.appendChild(el('p', 'sched-msg ' + cls, text)); panelEl.appendChild(box);
    }

    function problemText(p) {
        if (p.code === 'gap') return txt(p.side === 'before' ? 'schedP_gap_before' : 'schedP_gap_after', { minutes: p.minutes });
        return txt('schedP_' + p.code, { location: names[p.location] || p.location || '', start: p.start || '', end: p.end || '', minutes: p.minutes || '', time: p.at || '', note: p.note || '', date: p.date || '' });
    }

    function runCheck() {
        if (!sel) return;
        var my = ++seq;
        result = null; renderPanel(false);
        post(root.dataset.checkUrl, { loc: locEl.value, date: dateEl.value, start: startEl.value, end: endEl.value, mode: 'book', ref: ref, kind: kind }).then(function (res) {
            if (my !== seq) return;
            result = res; renderPanel(false);
        });
    }

    function blocking() {
        if (!sel) return txt('schedChooseTime');
        if (!result) return '';
        if (!result.ok) return txt('schedFailed');
        var hard = result.problems.filter(function (p) { return p.level === 'hard'; });
        if (hard.length) return problemText(hard[0]);
        var shadow = result.problems.filter(function (p) { return p.level === 'shadow'; });
        if (shadow.length && !(canOverride && useOverride)) return canOverride ? txt('schedOverrideNeeded') : txt('schedOnlyDoctor');
        return '';
    }

    function renderPanel(summaryOnly) {
        panelEl.innerHTML = '';
        if (!locEl.value || !dateEl.value) { panelEl.appendChild(el('p', 'hint', txt('schedPickHint'))); return; }
        if (loaded && !sel) {
            var any = cells.some(function (b) { return b && (b.type === 'avail' || (b.type === 'resv' && b.own)); });
            panelEl.appendChild(el('p', 'hint', any ? txt('schedHintBook') : txt('schedNoFree')));
            if (daySuggestions.length) {
                panelEl.appendChild(el('p', 'hint', txt('schedSuggestions')));
                var sg0 = el('div', 'sched-suggest');
                daySuggestions.forEach(function (s) {
                    var b0 = el('button', null, '★ ' + s.start + '–' + s.end + ' · ' + txt('schedSugg_' + s.reason)); b0.type = 'button';
                    b0.addEventListener('click', function () {
                        sel = { r0: (toMin(s.start) - DAY_START) / STEP, r1: (toMin(s.end) - DAY_START) / STEP - 1 };
                        useOverride = false; setFields(); render(); runCheck();
                    });
                    sg0.appendChild(b0);
                });
                panelEl.appendChild(sg0);
            }
            return;
        }
        if (!sel) return;
        var len = (sel.r1 - sel.r0 + 1) * STEP;
        var big = el('div', 'big', hm(rowMin(sel.r0)) + '–' + hm(rowMin(sel.r1) + STEP));
        big.appendChild(el('small', null, len + ' ' + txt('schedMinutes') + ' · ' + (names[locEl.value] || '')));
        panelEl.appendChild(big);
        if (summaryOnly || !result) return;

        var box = el('div', 'sched-msgs'), hard = 0, shadow = 0;
        if (result.ok) {
            result.problems.forEach(function (p) {
                if (p.level === 'hard') hard++;
                if (p.level === 'shadow') shadow++;
                box.appendChild(el('p', 'sched-msg ' + (p.level === 'warn' ? 'warn' : 'bad'), problemText(p)));
            });
            if (!result.problems.length) box.appendChild(el('p', 'sched-msg ok', txt('schedNoGaps')));
        } else box.appendChild(el('p', 'sched-msg bad', txt('schedFailed')));
        panelEl.appendChild(box);

        if (shadow && !hard && canOverride) {
            var lab = el('label', 'sched-check'), cb = el('input'); cb.type = 'checkbox'; cb.checked = useOverride;
            cb.addEventListener('change', function () { useOverride = cb.checked; setFields(); });
            lab.appendChild(cb); lab.appendChild(document.createTextNode(' ' + txt('schedOverride')));
            panelEl.appendChild(lab);
        } else if (shadow && !hard) box.appendChild(el('p', 'sched-msg bad', txt('schedOnlyDoctor')));

        if (result.suggestions && result.suggestions.length) {
            panelEl.appendChild(el('p', 'hint', txt('schedSuggestions')));
            var sg = el('div', 'sched-suggest');
            result.suggestions.forEach(function (s) {
                var b = el('button', null, '★ ' + s.start + '–' + s.end + ' · ' + txt('schedSugg_' + s.reason)); b.type = 'button';
                b.addEventListener('click', function () {
                    sel = { r0: (toMin(s.start) - DAY_START) / STEP, r1: (toMin(s.end) - DAY_START) / STEP - 1 };
                    useOverride = false; setFields(); render(); runCheck();
                });
                sg.appendChild(b);
            });
            panelEl.appendChild(sg);
        }
    }

    // --------------------------------------------------------------- load

    function load() {
        sel = null; result = null; useOverride = false; setFields();
        cells = new Array(ROWS).fill(null); loaded = false; daySuggestions = [];
        updateRemote();
        if (!locEl.value || !dateEl.value) { render(); renderPanel(false); return Promise.resolve(); }
        var my = ++seq;
        return post(root.dataset.dayUrl, { loc: locEl.value, date: dateEl.value, ref: ref }).then(function (res) {
            if (my !== seq || !res.ok) { if (my === seq) panelMessage('bad', txt('schedFailed')); return; }
            isPast = !!res.past;
            daySuggestions = res.suggestions || [];
            res.blocks.forEach(function (b) {
                for (var m = b.start; m < b.end; m += STEP) { var r = (m - DAY_START) / STEP; if (r >= 0 && r < ROWS) cells[r] = b; }
            });
            loaded = true; render(); renderPanel(false);
        });
    }

    locEl.addEventListener('change', load);
    platformEl.addEventListener('change', function () { updateRemote(); });
    dateEl.addEventListener('change', load);
    if (window.flatpickr) {
        window.flatpickr(dateEl, { dateFormat: 'Y-m-d', altInput: true, altFormat: 'd-m-Y', allowInput: true, minDate: 'today', onChange: function () { load(); } });
    }

    if (form) {
        form.addEventListener('submit', function (ev) {
            var submitter = ev.submitter;
            if (submitter && (submitter.name === 'cancel' || submitter.name === 'delete')) return;
            var why = blocking();
            if (!sel || why) {
                ev.preventDefault();
                panelMessage('bad', why || txt('schedChooseTime'));
                root.scrollIntoView({ block: 'center' });
            }
        });
    }

    // Start from the saved/prefilled time when there is one.
    updateRemote();
    load().then(function () {
        var s = root.dataset.start, e = root.dataset.end;
        if (loaded && s && e) {
            var r0 = (toMin(s) - DAY_START) / STEP, r1 = (toMin(e) - DAY_START) / STEP - 1;
            var ok = r0 >= 0 && r1 < ROWS;
            for (var r = r0; ok && r <= r1; r++) if (!allowed(r)) ok = false;
            if (ok) { sel = { r0: r0, r1: r1 }; setFields(); render(); runCheck(); }
        }
        var first = ROWS;
        for (var i = 0; i < ROWS; i++) if (cells[i] && cells[i].type !== 'shadow') { first = i; break; }
        var wrap = gridEl.parentNode;
        if (first < ROWS) wrap.scrollTop = Math.max(0, ((sel ? sel.r0 : first) - 2) * 18);
    });
})();
