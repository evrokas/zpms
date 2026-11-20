/* "Create patient record" from a pending booking
   (/consultation/pending/{id}/convert, pending_appointment_convert.zetem).

   Lists the patients on file who may be the person of the booking (same
   AMKA, name or phone, or a rare name word -- from
   patientsClassEx::findConversionCandidates()), re-checks them as the
   name/AMKA/phone fields change, and asks before saving:
     - an existing patient is chosen: "add the appointment to this record?"
     - none chosen but candidates exist: "is it one of these, or a new patient?"
     - no candidates: saves straight away as a new patient (confirm-new=1).
   The server refuses a new record while candidates exist without
   confirm-new=1, so nothing is ever attached or duplicated by guesswork.
   Values go into the page with textContent only. Texts: window.zpmsText. */
(function () {
    'use strict';

    var page = document.getElementById('pc-page');
    var form = document.getElementById('pc-form');
    if (!page || !form) return;

    var T = window.zpmsText || {};
    var list = document.getElementById('pc-list');
    var section = document.getElementById('pc-candidates');
    var existingInput = document.getElementById('existing-patient-id');
    var confirmNew = document.getElementById('confirm-new');
    var submitBtn = document.getElementById('pc-submit');
    var selectedBox = document.getElementById('pc-selected');
    var selectedText = document.getElementById('pc-selected-text');
    var unselectBtn = document.getElementById('pc-unselect');
    var dialog = document.getElementById('pc-dialog');
    var fields = {
        name: document.getElementById('convert-patient-name'),
        phone: document.getElementById('convert-patient-telephone'),
        amka: document.getElementById('convert-patient-amka'),
        email: document.getElementById('convert-patient-email')
    };
    var booking = {
        name: fields.name.value, phone: fields.phone.value,
        amka: fields.amka.value, email: fields.email.value
    };
    var candidates = [];
    try { candidates = JSON.parse(page.dataset.candidates || '[]') || []; } catch (e) { candidates = []; }
    var timer = null;
    var controller = null;

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text !== undefined && text !== null) e.textContent = text;
        return e;
    }
    function icon(name) {
        var i = el('i', 'bx ' + name);
        i.setAttribute('aria-hidden', 'true');
        return i;
    }
    function fmt(s, vars) {
        return String(s || '').replace(/@(\w+)/g, function (m, k) { return (k in vars) ? vars[k] : m; });
    }
    function byId(id) {
        id = parseInt(id, 10);
        for (var i = 0; i < candidates.length; i++) if (candidates[i].id === id) return candidates[i];
        return null;
    }

    /* ---------- one patient card (list and dialog) ---------- */

    function card(c, onUse, selected) {
        var li = el('li', 'pc-card' + (selected ? ' is-selected' : ''));
        li.dataset.id = c.id;
        var who = el('div', 'pc-who');
        var name = el('a', 'pc-name', c.name);
        name.href = c.url;
        name.target = '_blank';
        name.rel = 'noopener';
        who.appendChild(name);

        var sub = el('div', 'pc-sub');
        if (c.amka) sub.appendChild(el('span', null, (T.amka || 'AMKA') + ' ' + c.amka));
        if (c.phone) sub.appendChild(el('span', null, c.phone));
        if (c.dob) sub.appendChild(el('span', null, c.dob + (c.age_label ? ' (' + c.age_label + ')' : '')));
        who.appendChild(sub);

        var sub2 = el('div', 'pc-sub');
        if (c.last) {
            var s = el('span');
            s.appendChild(icon('bx-calendar'));
            s.appendChild(document.createTextNode(' ' + T.pcLast + ': ' + c.last.date
                + (c.last.place ? ' · ' + c.last.place : '') + ' (' + c.appointments + ')'));
            sub2.appendChild(s);
        } else {
            sub2.appendChild(el('span', null, T.pcNoAppointments));
        }
        who.appendChild(sub2);

        var reasons = el('div', 'pc-reasons');
        Object.keys(c.reason_labels || {}).forEach(function (k) {
            reasons.appendChild(el('span', 'pc-reason is-' + k.replace(/[^a-z_]/g, ''), c.reason_labels[k]));
        });
        who.appendChild(reasons);
        li.appendChild(who);

        var use = el('button', 'pc-use', T.pcUseThis);
        use.type = 'button';
        use.addEventListener('click', function () { onUse(c); });
        li.appendChild(use);
        return li;
    }

    function renderList() {
        list.replaceChildren();
        candidates.forEach(function (c) {
            list.appendChild(card(c, function (cc) { select(cc.id); }, String(c.id) === existingInput.value));
        });
        section.hidden = candidates.length === 0;
    }

    /* ---------- choosing an existing patient ---------- */

    function setReadonly(on) {
        Object.keys(fields).forEach(function (k) { fields[k].readOnly = on; });
    }

    function select(id) {
        var c = byId(id);
        if (!c) return;
        existingInput.value = String(c.id);
        /* The record's own details are kept; show them, read-only. */
        fields.name.value = c.name;
        fields.phone.value = c.phone || '';
        fields.amka.value = c.amka || '';
        fields.email.value = c.email || '';
        setReadonly(true);
        selectedText.replaceChildren(document.createTextNode(T.pcLinked + ' '), el('b', null, c.name));
        selectedBox.hidden = false;
        submitBtn.value = T.pcSubmitExisting;
        renderList();
    }

    function unselect() {
        existingInput.value = '';
        Object.keys(fields).forEach(function (k) { fields[k].value = booking[k]; });
        setReadonly(false);
        selectedBox.hidden = true;
        submitBtn.value = T.pcSubmitNew;
        renderList();
    }

    unselectBtn.addEventListener('click', unselect);

    /* ---------- live re-check as the details change ---------- */

    function fetchCandidates() {
        if (controller) controller.abort();
        controller = new AbortController();
        var body = new URLSearchParams();
        var token = form.querySelector('input[name="csrf_token"]');
        if (token) body.set('csrf_token', token.value);
        body.set('patient-name', fields.name.value);
        body.set('patient-amka', fields.amka.value);
        body.set('patient-telephone', fields.phone.value);
        return fetch(page.dataset.candidatesUrl, {
            method: 'POST', body: body, credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }, signal: controller.signal
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }).then(function (data) {
            candidates = data.candidates || [];
            if (existingInput.value === '') renderList();
            return candidates;
        });
    }

    ['name', 'phone', 'amka'].forEach(function (k) {
        fields[k].addEventListener('input', function () {
            if (existingInput.value !== '') return;
            clearTimeout(timer);
            timer = setTimeout(function () { fetchCandidates().catch(function () {}); }, 400);
        });
    });

    /* ---------- confirmation dialog ---------- */

    var dTitle = document.getElementById('pc-dialog-title');
    var dBody = document.getElementById('pc-dialog-body');
    var dButtons = document.getElementById('pc-dialog-buttons');

    function button(text, cls, onClick) {
        var b = el('button', cls, text);
        b.type = 'button';
        b.addEventListener('click', onClick);
        return b;
    }

    function bookingLine() {
        var p = el('p', 'pc-dialog-booking');
        p.appendChild(icon('bx-calendar-check'));
        p.appendChild(document.createTextNode(' ' + page.dataset.bookingWhen
            + (page.dataset.bookingPlace ? ' · ' + page.dataset.bookingPlace : '')
            + ' — ' + T.pcBookingName + ': '));
        p.appendChild(el('b', null, page.dataset.bookingName));
        return p;
    }

    function openDialog(title, bodyNodes, buttons) {
        dTitle.textContent = title;
        dBody.replaceChildren.apply(dBody, bodyNodes);
        dButtons.replaceChildren.apply(dButtons, buttons);
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
            var first = dButtons.querySelector('.is-primary') || dButtons.querySelector('button');
            if (first) first.focus();
        }
    }
    function closeDialog() { if (dialog.open) dialog.close(); }

    function save() {
        closeDialog();
        form.dataset.confirmed = '1';
        submitBtn.disabled = true;
        form.submit();
    }

    function askExisting(c) {
        var ul = el('ul', 'pc-list');
        ul.appendChild(card(c, save, true));
        ul.querySelector('.pc-use').remove();
        if (typeof dialog.showModal !== 'function') {
            if (window.confirm(T.pcAddTitle + '\n\n' + c.name)) save();
            return;
        }
        openDialog(T.pcAddTitle, [
            bookingLine(),
            el('p', null, T.pcAddText),
            ul
        ], [
            button(T.pcBack, 'pc-btn', closeDialog),
            button(T.pcAddYes, 'pc-btn is-primary', save)
        ]);
    }

    function askWhich(cands) {
        if (typeof dialog.showModal !== 'function') {
            /* No <dialog>: the list is on the page; only confirm "new". */
            if (window.confirm(T.pcWhichTitle + '\n\n' + T.pcNew + '?')) { confirmNew.value = '1'; save(); }
            return;
        }
        var ul = el('ul', 'pc-list');
        cands.forEach(function (c) {
            ul.appendChild(card(c, function (cc) {
                select(cc.id);
                save();
            }, false));
        });
        openDialog(T.pcWhichTitle, [
            bookingLine(),
            el('p', null, T.pcWhichText),
            ul
        ], [
            button(T.pcBack, 'pc-btn', closeDialog),
            button(T.pcNew, 'pc-btn is-new', function () { confirmNew.value = '1'; save(); })
        ]);
    }

    form.addEventListener('submit', function (e) {
        if (form.dataset.confirmed === '1') return;
        e.preventDefault();
        if (!form.reportValidity()) return;

        var chosen = byId(existingInput.value);
        if (existingInput.value !== '' && chosen) {
            askExisting(chosen);
            return;
        }
        submitBtn.disabled = true;
        fetchCandidates().then(function (cands) {
            submitBtn.disabled = false;
            if (cands.length > 0) askWhich(cands);
            else { confirmNew.value = '1'; save(); }
        }).catch(function () {
            /* Check failed: let the server decide (it refuses a new record
               while candidates exist without confirm-new). */
            submitBtn.disabled = false;
            save();
        });
    });

    /* ---------- start ---------- */

    renderList();
    if (existingInput.value !== '' && byId(existingInput.value)) select(existingInput.value);
    else submitBtn.value = T.pcSubmitNew;
})();
