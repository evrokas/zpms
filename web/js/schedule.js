/* Appointment schedule (/schedule): draws one location's week from the JSON in
 * #schedule-data, lets staff drag on it to choose a time, asks the server
 * what that choice would do (POST /schedule/check -- the rules live in
 * web/schedule.php, never here) and saves it. Also drives the weekly-pattern
 * and travel-time dialogs. All wording comes from window.zpmsText (sched*). */
(function () {
    'use strict';

    var dataEl = document.getElementById('schedule-data');
    var gridEl = document.getElementById('sched-grid');
    if (!dataEl || !gridEl) return;

    var M = JSON.parse(dataEl.textContent);
    var T = window.zpmsText || {};
    var panelEl = document.getElementById('sched-panel');
    var STEP = M.step;
    var ROWS = (M.dayEnd - M.dayStart) / STEP;
    var DAYS = M.days.length;
    var tool = 'book';
    var sel = null;          // {d, r0, r1}
    var drag = null;         // {d, anchor}
    var note = '';
    var allLocations = false;
    var useOverride = false;
    var checkSeq = 0;
    var result = null;       // last /schedule/check answer for the current selection
    var message = null;      // {cls, text} shown after a failed save

    var locName = {};
    M.locations.forEach(function (l) { locName[l.code] = l.name; });

    function txt(key, vars) {
        var s = T[key] || key;
        Object.keys(vars || {}).forEach(function (k) { s = s.split('@' + k).join(String(vars[k])); });
        return s;
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function hm(min) { return pad(Math.floor(min / 60)) + ':' + pad(min % 60); }
    function rowMin(r) { return M.dayStart + r * STEP; }
    function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined) e.textContent = text;
        return e;
    }

    function post(url, data) {
        var body = new URLSearchParams();
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        return fetch(url, { method: 'POST', headers: { 'X-CSRF-Token': csrf(), 'Accept': 'application/json' }, body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'server' }; }); })
            .catch(function () { return { ok: false, error: 'network' }; });
    }

    // ---------------------------------------------------------- the grid

    var cells = [];   // cells[d][r] = {block, idx} or null
    function buildCells() {
        cells = M.days.map(function (day) {
            var col = new Array(ROWS).fill(null);
            day.blocks.forEach(function (b) {
                for (var m = b.start, i = 0; m < b.end; m += STEP, i++) {
                    var r = (m - M.dayStart) / STEP;
                    if (r >= 0 && r < ROWS) col[r] = { block: b, idx: i };
                }
            });
            return col;
        });
    }

    function blockLabel(c) {
        var b = c.block;
        if (c.idx === 1 && b.note && (b.type === 'resv' || b.type === 'hold' || b.type === 'surgery')) return b.note;
        if (c.idx !== 0) return '';
        switch (b.type) {
            case 'avail': return txt('schedAvailable');
            case 'hold': return txt('schedReserved');
            case 'surgery': return txt('schedSurgery');
            case 'shadow': return b.ref === 'surgery' ? txt('schedSurgeryAt', { location: locName[b.label] || b.label }) : txt('schedBookingAt', { location: locName[b.label] || b.label });
            case 'resv':
                var info = M.refs[b.ref] || {};
                var who = info.patient ? txt('schedPatient', { id: info.patient }) : (info.pending ? txt('schedPendingLabel') : txt('schedBooked'));
                return (b.label === 'surgery' ? txt('schedSurgery') + ' · ' : '') + who;
        }
        return '';
    }

    function cellClass(c) {
        if (!c) return '';
        if (c.block.type === 'resv') return c.block.label === 'surgery' ? 'resv opsurg' : 'resv';
        return c.block.type === 'avail' ? 'avail' : c.block.type;
    }

    function isPast(d) { return M.days[d].date < M.today; }

    // Where bookings were cancelled this week (only when "show cancelled" is on).
    function releasedAt(d, m) {
        var list = (M.cancelled || {})[M.days[d].date] || [];
        for (var i = 0; i < list.length; i++) if (m >= list[i].start && m < list[i].end) return list[i];
        return null;
    }

    function allowed(d, r) {
        if (isPast(d)) return false;
        var c = cells[d][r];
        if (tool === 'book') return !!c && c.block.type === 'avail';
        if (!c) return tool !== 'clear';
        return c.block.type === 'avail' || c.block.type === 'hold' || c.block.type === 'surgery';
    }

    function renderGrid() {
        var h = '<div class="h"></div>';
        M.days.forEach(function (day) {
            h += '<div class="h' + (day.date === M.today ? ' today' : '') + '">' + day.weekday + '<small>' + day.short + '</small></div>';
        });
        var frag = [];
        for (var r = 0; r < ROWS; r++) {
            var m = rowMin(r);
            frag.push('<div class="t' + (m % 60 === 0 ? ' hr' : '') + '">' + (m % 30 === 0 ? hm(m) : '') + '</div>');
            for (var d = 0; d < DAYS; d++) {
                var c = cells[d][r];
                var cls = 's ' + cellClass(c);
                var label = c ? blockLabel(c) : '';
                var title = c ? [blockLabel({ block: c.block, idx: 0 }), c.block.note].filter(Boolean).join(' · ') : '';
                if (isPast(d)) cls += ' past';
                if (m % 60 === 0) cls += ' hr0';
                var rel = releasedAt(d, m);
                if (rel && (!c || c.block.type === 'avail')) { cls += ' released'; title = (title ? title + ' · ' : '') + txt('schedCancelledHere'); }
                if (sel && sel.d === d && r >= sel.r0 && r <= sel.r1) {
                    cls = 's sel kind-' + tool + (m % 60 === 0 ? ' hr0' : '') + (r === sel.r0 ? ' first' : '') + (r === sel.r1 ? ' last' : '');
                    label = r === sel.r0 ? hm(rowMin(sel.r0)) + '–' + hm(rowMin(sel.r1) + STEP) : '';
                }
                frag.push('<div class="' + cls + '" data-d="' + d + '" data-r="' + r + '"' + (title ? ' title="' + title.replace(/"/g, '&quot;') + '"' : '') + '>' + escapeHtml(label) + '</div>');
            }
        }
        gridEl.innerHTML = h + frag.join('');
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"]/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]; });
    }

    function cellAt(x, y) {
        var e = document.elementFromPoint(x, y);
        while (e && e !== gridEl && !(e.dataset && e.dataset.r !== undefined)) e = e.parentNode;
        return e && e.dataset && e.dataset.r !== undefined ? { d: +e.dataset.d, r: +e.dataset.r } : null;
    }

    function extend(anchor, target, d) {
        var dir = target >= anchor ? 1 : -1, end = anchor;
        for (var r = anchor; dir > 0 ? r <= target : r >= target; r += dir) {
            if (!allowed(d, r)) break;
            end = r;
        }
        return { r0: Math.min(anchor, end), r1: Math.max(anchor, end) };
    }

    gridEl.addEventListener('pointerdown', function (ev) {
        var c = cellAt(ev.clientX, ev.clientY);
        if (!c) return;
        if (sel && sel.d === c.d && (c.r === sel.r0 || c.r === sel.r1)) {
            drag = { d: c.d, anchor: c.r === sel.r0 ? sel.r1 : sel.r0 };
        } else {
            if (!allowed(c.d, c.r)) { flash(c); return; }
            drag = { d: c.d, anchor: c.r };
            sel = { d: c.d, r0: c.r, r1: c.r };
            note = ''; useOverride = false; message = null;
        }
        try { gridEl.setPointerCapture(ev.pointerId); } catch (e) { /* not supported */ }
        gridEl.classList.add('dragging');
        ev.preventDefault();
        renderGrid(); renderPanel(true);
    });
    gridEl.addEventListener('pointermove', function (ev) {
        if (!drag) return;
        var c = cellAt(ev.clientX, ev.clientY);
        if (!c || c.d !== drag.d) return;
        var e = extend(drag.anchor, c.r, drag.d);
        if (sel.r0 !== e.r0 || sel.r1 !== e.r1) {
            sel = { d: drag.d, r0: e.r0, r1: e.r1 };
            renderGrid(); renderPanel(true);
        }
    });
    function endDrag() {
        if (!drag) return;
        drag = null;
        gridEl.classList.remove('dragging');
        runCheck();
    }
    gridEl.addEventListener('pointerup', endDrag);
    gridEl.addEventListener('pointercancel', endDrag);

    function flash(c) {
        var e = gridEl.querySelector('[data-d="' + c.d + '"][data-r="' + c.r + '"]');
        if (e) { e.style.outline = '2px solid #e49aa3'; e.style.outlineOffset = '-2px'; setTimeout(function () { e.style.outline = ''; }, 350); }
        message = { cls: 'bad', text: txt('schedNothingHere') };
        renderPanel(false);
    }

    // ------------------------------------------------------- the panel

    function selTimes() { return { start: hm(rowMin(sel.r0)), end: hm(rowMin(sel.r1) + STEP) }; }

    function runCheck() {
        if (!sel) return;
        var seq = ++checkSeq, t = selTimes();
        result = null;
        renderPanel(false);
        post(M.urls.check, { loc: M.location, date: M.days[sel.d].date, start: t.start, end: t.end, mode: tool }).then(function (res) {
            if (seq !== checkSeq) return;
            result = res;
            renderPanel(false);
        });
    }

    function problemText(p) {
        var v = {
            location: locName[p.location] || p.location || '', start: p.start || '', end: p.end || '', minutes: p.minutes || '',
            time: p.at || '', note: p.note || '', date: p.date || '',
        };
        if (p.code === 'gap') return txt(p.side === 'before' ? 'schedP_gap_before' : 'schedP_gap_after', { minutes: p.minutes });
        return txt('schedP_' + p.code, v);
    }

    function addMsg(box, cls, text) { box.appendChild(el('p', 'sched-msg ' + cls, text)); }

    function renderPanel(summaryOnly) {
        panelEl.innerHTML = '';
        if (!sel) {
            panelEl.appendChild(el('p', 'hint', tool === 'book' ? txt('schedHintBook') : txt('schedHintEdit')));
            if (message) { var box0 = el('div', 'sched-msgs'); addMsg(box0, message.cls, message.text); panelEl.appendChild(box0); }
            return;
        }
        var t = selTimes(), len = (sel.r1 - sel.r0 + 1) * STEP;
        var big = el('div', 'big', M.days[sel.d].weekday + ' ' + M.days[sel.d].short + ' · ' + t.start + '–' + t.end);
        big.appendChild(el('small', null, txt('schedSummary', { minutes: len, location: locName[M.location] || M.location, kind: txt('schedTool_' + tool) })));
        panelEl.appendChild(big);
        if (summaryOnly) return;

        var needNote = tool === 'surgery' || tool === 'hold';
        if (tool !== 'clear') {
            var lab = el('label', 'sched-field', needNote ? txt('schedReasonRequired') : txt('schedNoteOptional'));
            var inp = el('input'); inp.type = 'text'; inp.maxLength = 255; inp.value = note;
            inp.addEventListener('input', function () { note = inp.value; updateButtons(); });
            if (tool === 'book') inp.placeholder = txt('schedNotePlaceholder');
            lab.appendChild(inp); panelEl.appendChild(lab);
        }
        if (tool === 'hold') {
            var lab2 = el('label', 'sched-check'), cb = el('input'); cb.type = 'checkbox'; cb.checked = allLocations;
            cb.addEventListener('change', function () { allLocations = cb.checked; });
            lab2.appendChild(cb); lab2.appendChild(document.createTextNode(' ' + txt('schedAllLocations')));
            panelEl.appendChild(lab2);
        }

        var box = el('div', 'sched-msgs');
        var hard = 0, shadow = 0;
        if (message) addMsg(box, message.cls, message.text);
        if (result && result.ok) {
            result.problems.forEach(function (p) {
                if (p.level === 'hard') hard++;
                if (p.level === 'shadow') shadow++;
                addMsg(box, p.level === 'warn' ? 'warn' : 'bad', problemText(p));
            });
            if (!hard && !shadow && !result.problems.length && tool === 'book') addMsg(box, 'ok', txt('schedNoGaps'));
        } else if (result) {
            addMsg(box, 'bad', txt('schedFailed'));
        }
        panelEl.appendChild(box);

        if (shadow && !hard) {
            if (result.can_override) {
                var lab3 = el('label', 'sched-check'), cb3 = el('input'); cb3.type = 'checkbox'; cb3.checked = useOverride;
                cb3.addEventListener('change', function () { useOverride = cb3.checked; updateButtons(); });
                lab3.appendChild(cb3); lab3.appendChild(document.createTextNode(' ' + txt('schedOverride')));
                panelEl.appendChild(lab3);
            } else {
                addMsg(box, 'bad', txt('schedOnlyDoctor'));
            }
        }
        if (result && result.suggestions && result.suggestions.length) {
            panelEl.appendChild(el('p', 'hint', txt('schedSuggestions')));
            var sg = el('div', 'sched-suggest');
            result.suggestions.forEach(function (s) {
                var b = el('button', null, '★ ' + s.start + '–' + s.end + ' · ' + txt('schedSugg_' + s.reason));
                b.type = 'button';
                b.addEventListener('click', function () {
                    sel = { d: sel.d, r0: (toMin(s.start) - M.dayStart) / STEP, r1: (toMin(s.end) - M.dayStart) / STEP - 1 };
                    renderGrid(); runCheck();
                });
                sg.appendChild(b);
            });
            panelEl.appendChild(sg);
        }

        var acts = el('div', 'sched-acts');
        acts.appendChild(button(txt('schedEarlier'), function () { nudge(-1, 0); }));
        acts.appendChild(button(txt('schedLater'), function () { nudge(0, 1); }));
        acts.appendChild(el('span', 'sched-grow'));
        acts.appendChild(button(txt('cancel'), function () { sel = null; result = null; message = null; renderGrid(); renderPanel(false); }));
        var go = button(tool === 'book' ? txt('schedBook', t) : txt('schedSave', t), save, 'pri');
        go.id = 'sched-go';
        acts.appendChild(go);
        panelEl.appendChild(acts);
        panelEl._state = { needNote: needNote, hard: hard, shadow: shadow };
        updateButtons();
    }

    function toMin(s) { var p = s.split(':'); return +p[0] * 60 + +p[1]; }

    function button(label, fn, cls) {
        var b = el('button', 'sched-btn' + (cls ? ' ' + cls : ''), label);
        b.type = 'button'; b.addEventListener('click', fn);
        return b;
    }

    function updateButtons() {
        var go = document.getElementById('sched-go');
        var st = panelEl._state;
        if (!go || !st) return;
        var ok = !!result && result.ok && !st.hard && (!st.shadow || (result.can_override && useOverride)) && (!st.needNote || note.trim() !== '');
        go.disabled = !ok;
    }

    function nudge(dStart, dEnd) {
        var r0 = sel.r0 + dStart, r1 = sel.r1 + dEnd;
        if (r0 < 0 || r1 >= ROWS) return;
        var ok = true;
        for (var r = r0; r <= r1; r++) if (!allowed(sel.d, r) && (r < sel.r0 || r > sel.r1)) ok = false;
        if (!ok) { message = { cls: 'bad', text: txt('schedNothingHere') }; renderPanel(false); return; }
        message = null;
        sel = { d: sel.d, r0: r0, r1: r1 };
        renderGrid(); runCheck();
    }

    function save() {
        var t = selTimes(), date = M.days[sel.d].date;
        if (tool === 'book') {
            var q = new URLSearchParams({ loc: M.location, date: date, start: t.start, end: t.end, note: note.trim() });
            if (useOverride) q.set('shadow_override', '1');
            window.location.href = M.newAppointmentUrl + '?' + q.toString();
            return;
        }
        var go = document.getElementById('sched-go');
        if (go) go.disabled = true;
        post(M.urls.override, { loc: M.location, date: date, start: t.start, end: t.end, kind: tool, note: note.trim(), all_locations: allLocations ? 1 : '' }).then(function (res) {
            if (res.ok) { window.location.reload(); return; }
            message = { cls: 'bad', text: failText(res) };
            renderPanel(false);
        });
    }

    function failText(res) {
        if (res.error === 'stranded' && res.stranded) return txt('schedStranded') + ' ' + res.stranded.map(strandedText).join('; ');
        if (res.error === 'conflict' && res.problems) return res.problems.map(problemText).join(' ');
        if (res.error === 'note_required') return txt('schedReasonRequired');
        if (res.error === 'past') return txt('schedPast');
        if (res.error === 'network') return txt('schedNetwork');
        return txt('schedFailed');
    }
    function strandedText(s) {
        return s.date.split('-').reverse().join('/') + ' ' + s.start + '–' + s.end + ' ' + (locName[s.location] || s.location);
    }

    // tools
    var toolButtons = document.querySelectorAll('#sched-tools [data-tool]');
    toolButtons.forEach(function (b) {
        b.addEventListener('click', function () {
            tool = b.dataset.tool;
            toolButtons.forEach(function (x) { x.classList.toggle('on', x === b); });
            sel = null; result = null; message = null; note = ''; useOverride = false;
            renderGrid(); renderPanel(false);
        });
    });

    // ----------------------------------------------- weekly pattern dialog

    var patternDlg = document.getElementById('sched-pattern');
    var patternRows = document.getElementById('sched-pattern-rows');

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

    document.getElementById('sched-open-pattern').addEventListener('click', function () {
        patternRows.innerHTML = '';
        M.pattern.rows.forEach(function (r) { patternRows.appendChild(patternRow(r)); });
        // Empty = from today. The date of the version in force is only shown: a new
        // version can never start earlier than today (past weeks keep their pattern).
        document.getElementById('sched-valid-from').value = '';
        document.getElementById('sched-pattern-since').textContent = M.pattern.validFrom ? txt('schedPatSince', { date: M.pattern.validFrom.split('-').reverse().join('/') }) : '';
        document.getElementById('sched-pattern-msg').innerHTML = '';
        renderPatternHistory();
        patternDlg.showModal();
    });
    function renderPatternHistory() {
        var box = document.getElementById('sched-pattern-history'), body = document.getElementById('sched-pattern-history-body');
        body.innerHTML = '';
        var hist = M.pattern.history || [];
        box.hidden = hist.length === 0;
        var kindLabel = { available: txt('schedPatAvailable'), hold: txt('schedPatHold'), surgery: txt('schedPatSurgery') };
        hist.forEach(function (v) {
            var fmt = function (d) { return d ? d.split('-').reverse().join('/') : '…'; };
            body.appendChild(el('p', 'sched-sub', txt('schedPatVersion', { from: fmt(v.from), to: fmt(v.to) })));
            var table = el('table', 'sched-table'), tb = el('tbody');
            v.rows.forEach(function (r) {
                var tr = el('tr');
                [M.weekdayNames[r.weekday - 1], r.start + '–' + r.end, kindLabel[r.kind] || r.kind, r.note].forEach(function (c) { tr.appendChild(el('td', null, c)); });
                tb.appendChild(tr);
            });
            table.appendChild(tb); body.appendChild(table);
        });
    }
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
        post(M.urls.pattern, { loc: M.location, valid_from: document.getElementById('sched-valid-from').value.trim(), rows: JSON.stringify(rows) }).then(function (res) {
            if (res.ok) { window.location.reload(); return; }
            msg.innerHTML = '';
            var box = el('div', 'sched-msgs');
            if (res.error === 'invalid') {
                (res.errors || []).forEach(function (e) { addMsg(box, 'bad', txt('schedPatRow', { row: e.row + 1, reason: txt('schedPatErr_' + e.code) })); });
            } else if (res.error === 'past') {
                addMsg(box, 'bad', txt('schedPatPast'));
            } else if (res.error === 'blocked') {
                if ((res.stranded || []).length) addMsg(box, 'bad', txt('schedPatStranded') + ' ' + res.stranded.map(strandedText).join('; '));
                (res.conflicts || []).forEach(function (p) { addMsg(box, 'bad', (p.date ? p.date.split('-').reverse().join('/') + ': ' : '') + problemText(p)); });
            } else {
                addMsg(box, 'bad', res.error === 'network' ? txt('schedNetwork') : txt('schedFailed'));
            }
            msg.appendChild(box);
        });
    });

    // ------------------------------------------------- travel-time dialog

    var travelDlg = document.getElementById('sched-travel');
    var travelTable = document.getElementById('sched-travel-table');

    document.getElementById('sched-open-travel').addEventListener('click', function () {
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

    buildCells();
    renderGrid();
    renderPanel(false);
    // Start the view at the first thing of the week rather than at 07:00.
    var first = ROWS;
    for (var d0 = 0; d0 < DAYS; d0++) for (var r0 = 0; r0 < ROWS; r0++) if (cells[d0][r0] && cells[d0][r0].block.type !== 'shadow') { first = Math.min(first, r0); break; }
    if (first < ROWS) gridEl.parentNode.scrollTop = Math.max(0, (first - 2) * 18);
})();
