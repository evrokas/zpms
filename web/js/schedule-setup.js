/* Settings -> Appointment schedule setup: the weekly-pattern and travel-time
 * dialogs, moved here from the schedule page. The rules are checked by the
 * server (POST /schedule/pattern, /schedule/travel -- web/schedule.php); this
 * only draws the forms from the JSON in #schedule-setup-data and posts them.
 * The on/off checkboxes for locations are a plain form, no script involved.
 * All wording comes from window.zpmsText (sched*). */
(function () {
    'use strict';

    var dataEl = document.getElementById('schedule-setup-data');
    var patternDlg = document.getElementById('sched-pattern');
    if (!dataEl || !patternDlg) return;

    var M = JSON.parse(dataEl.textContent);
    var T = window.zpmsText || {};
    var STEP = M.step;
    var locName = {};
    M.locations.forEach(function (l) { locName[l.code] = l.name; });

    function txt(key, vars) {
        var s = T[key] || key;
        Object.keys(vars || {}).forEach(function (k) { s = s.split('@' + k).join(String(vars[k])); });
        return s;
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function hm(min) { return pad(Math.floor(min / 60)) + ':' + pad(min % 60); }
    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined) e.textContent = text;
        return e;
    }
    function button(label, fn, cls) {
        var b = el('button', 'sched-btn' + (cls ? ' ' + cls : ''), label);
        b.type = 'button'; b.addEventListener('click', fn);
        return b;
    }
    function post(url, data) {
        var body = new URLSearchParams();
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        return fetch(url, { method: 'POST', headers: { 'X-CSRF-Token': csrf(), 'Accept': 'application/json' }, body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'server' }; }); })
            .catch(function () { return { ok: false, error: 'network' }; });
    }
    function addMsg(box, cls, text) { box.appendChild(el('p', 'sched-msg ' + cls, text)); }
    function fmtDate(d) { return d ? d.split('-').reverse().join('/') : '…'; }

    function problemText(p) {
        var v = {
            location: locName[p.location] || p.location || '', start: p.start || '', end: p.end || '', minutes: p.minutes || '',
            time: p.at || '', note: p.note || '', date: p.date || '',
        };
        if (p.code === 'gap') return txt(p.side === 'before' ? 'schedP_gap_before' : 'schedP_gap_after', { minutes: p.minutes });
        return txt('schedP_' + p.code, v);
    }
    function strandedText(s) {
        return fmtDate(s.date) + ' ' + s.start + '–' + s.end + ' ' + (locName[s.location] || s.location);
    }

    // ----------------------------------------------- weekly pattern dialog

    var patternRows = document.getElementById('sched-pattern-rows');
    var locSelect = document.getElementById('sched-pattern-loc-select');
    M.locations.forEach(function (l) {
        var o = el('option', null, l.name); o.value = l.code; locSelect.appendChild(o);
    });

    function timeSelect(value, allowEnd) {
        var s = el('select');
        for (var m = allowEnd ? STEP : 0; m <= (allowEnd ? 1440 : 1440 - STEP); m += STEP) {
            var o = el('option', null, hm(m)); o.value = hm(m); if (hm(m) === value) o.selected = true;
            s.appendChild(o);
        }
        return s;
    }

    function patternRow(r) {
        var tr = el('tr');
        var day = el('select');
        M.weekdayNames.forEach(function (n, i) { var o = el('option', null, n); o.value = i + 1; if (r.weekday === i + 1) o.selected = true; day.appendChild(o); });
        var kind = el('select');
        [['available', txt('schedPatAvailable')], ['hold', txt('schedPatHold')], ['surgery', txt('schedPatSurgery')]].forEach(function (k) {
            var o = el('option', null, k[1]); o.value = k[0]; if (r.kind === k[0]) o.selected = true; kind.appendChild(o);
        });
        var slot = el('input'); slot.type = 'number'; slot.min = STEP; slot.max = 240; slot.step = STEP; slot.value = r.slot_minutes;
        var reason = el('input', 'reason'); reason.type = 'text'; reason.maxLength = 255; reason.value = r.note || '';
        var rm = button('✕', function () { tr.remove(); });
        [day, timeSelect(r.start_time, false), timeSelect(r.end_time, true), kind, slot, reason, rm].forEach(function (c) {
            var td = el('td'); td.appendChild(c); tr.appendChild(td);
        });
        return tr;
    }

    function renderPatternHistory(pat) {
        var box = document.getElementById('sched-pattern-history'), body = document.getElementById('sched-pattern-history-body');
        body.innerHTML = '';
        var hist = pat.history || [];
        box.hidden = hist.length === 0;
        var kindLabel = { available: txt('schedPatAvailable'), hold: txt('schedPatHold'), surgery: txt('schedPatSurgery') };
        hist.forEach(function (v) {
            body.appendChild(el('p', 'sched-sub', txt('schedPatVersion', { from: fmtDate(v.from), to: fmtDate(v.to) })));
            var table = el('table', 'sched-table'), tb = el('tbody');
            v.rows.forEach(function (r) {
                var tr = el('tr');
                [M.weekdayNames[r.weekday - 1], r.start + '–' + r.end, kindLabel[r.kind] || r.kind, r.note].forEach(function (c) { tr.appendChild(el('td', null, c)); });
                tb.appendChild(tr);
            });
            table.appendChild(tb); body.appendChild(table);
        });
    }

    function loadPattern() {
        var pat = M.patterns[locSelect.value] || { rows: [], validFrom: '', history: [] };
        patternRows.innerHTML = '';
        pat.rows.forEach(function (r) { patternRows.appendChild(patternRow(r)); });
        // Empty = from today. The date of the version in force is only shown: a new
        // version can never start earlier than today (past weeks keep their pattern).
        document.getElementById('sched-valid-from').value = '';
        document.getElementById('sched-pattern-since').textContent = pat.validFrom ? txt('schedPatSince', { date: fmtDate(pat.validFrom) }) : '';
        document.getElementById('sched-pattern-msg').innerHTML = '';
        renderPatternHistory(pat);
    }

    var openPattern = document.getElementById('sched-open-pattern');
    if (openPattern) openPattern.addEventListener('click', function () { loadPattern(); patternDlg.showModal(); });
    locSelect.addEventListener('change', loadPattern);
    document.getElementById('sched-pattern-add').addEventListener('click', function () {
        patternRows.appendChild(patternRow({ weekday: 1, start_time: '10:00', end_time: '14:00', kind: 'available', slot_minutes: 30, note: '' }));
    });
    document.getElementById('sched-pattern-save').addEventListener('click', function () {
        var rows = [];
        patternRows.querySelectorAll('tr').forEach(function (tr) {
            var f = tr.querySelectorAll('select, input');
            rows.push({ weekday: +f[0].value, start_time: f[1].value, end_time: f[2].value, kind: f[3].value, slot_minutes: +f[4].value, note: f[5].value });
        });
        var msg = document.getElementById('sched-pattern-msg');
        post(M.urls.pattern, { loc: locSelect.value, valid_from: document.getElementById('sched-valid-from').value.trim(), rows: JSON.stringify(rows) }).then(function (res) {
            if (res.ok) { window.location.reload(); return; }
            msg.innerHTML = '';
            var box = el('div', 'sched-msgs');
            if (res.error === 'invalid') {
                (res.errors || []).forEach(function (e) { addMsg(box, 'bad', txt('schedPatRow', { row: e.row + 1, reason: txt('schedPatErr_' + e.code) })); });
            } else if (res.error === 'past') {
                addMsg(box, 'bad', txt('schedPatPast'));
            } else if (res.error === 'blocked') {
                if ((res.stranded || []).length) addMsg(box, 'bad', txt('schedPatStranded') + ' ' + res.stranded.map(strandedText).join('; '));
                (res.conflicts || []).forEach(function (p) { addMsg(box, 'bad', (p.date ? fmtDate(p.date) + ': ' : '') + problemText(p)); });
            } else {
                addMsg(box, 'bad', res.error === 'network' ? txt('schedNetwork') : txt('schedFailed'));
            }
            msg.appendChild(box);
        });
    });

    // ------------------------------------------------- travel-time dialog

    var travelDlg = document.getElementById('sched-travel');
    var travelTable = document.getElementById('sched-travel-table');

    var openTravel = document.getElementById('sched-open-travel');
    if (openTravel) openTravel.addEventListener('click', function () {
        travelTable.innerHTML = '';
        var head = el('tr'); head.appendChild(el('th', null, txt('schedFromTo')));
        M.locations.forEach(function (l) { head.appendChild(el('th', null, l.name)); });
        var thead = el('thead'); thead.appendChild(head); travelTable.appendChild(thead);
        var body = el('tbody');
        var minutes = {};
        M.travel.forEach(function (t) { minutes[t.from + '>' + t.to] = t.minutes; });
        M.locations.forEach(function (from) {
            var tr = el('tr'); tr.appendChild(el('th', null, from.name));
            M.locations.forEach(function (to) {
                var td = el('td');
                if (from.code === to.code) td.textContent = '—';
                else {
                    var inp = el('input'); inp.type = 'number'; inp.min = 0; inp.max = 600; inp.step = 5;
                    inp.value = minutes[from.code + '>' + to.code]; inp.dataset.from = from.code; inp.dataset.to = to.code;
                    inp.addEventListener('input', function () {
                        if (!document.getElementById('sched-travel-sym').checked) return;
                        var opp = travelTable.querySelector('input[data-from="' + to.code + '"][data-to="' + from.code + '"]');
                        if (opp) opp.value = inp.value;
                    });
                    td.appendChild(inp);
                }
                tr.appendChild(td);
            });
            body.appendChild(tr);
        });
        travelTable.appendChild(body);
        document.getElementById('sched-travel-msg').innerHTML = '';
        travelDlg.showModal();
    });
    document.getElementById('sched-travel-save').addEventListener('click', function () {
        var rows = [];
        travelTable.querySelectorAll('input').forEach(function (i) { rows.push({ from: i.dataset.from, to: i.dataset.to, minutes: i.value }); });
        var msg = document.getElementById('sched-travel-msg');
        post(M.urls.travel, { rows: JSON.stringify(rows), symmetric: document.getElementById('sched-travel-sym').checked ? 1 : '' }).then(function (res) {
            if (res.ok) { window.location.reload(); return; }
            msg.innerHTML = '';
            var box = el('div', 'sched-msgs'); addMsg(box, 'bad', res.error === 'invalid' ? txt('schedTravelInvalid') : (res.error === 'network' ? txt('schedNetwork') : txt('schedFailed')));
            msg.appendChild(box);
        });
    });

    document.querySelectorAll('.sched-dialog [data-close]').forEach(function (b) {
        b.addEventListener('click', function () { b.closest('dialog').close(); });
    });
})();
