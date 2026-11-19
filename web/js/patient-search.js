/* Search-as-you-type dropdown for the patients list (/patients).

   The input (#patients-q) belongs to the list's plain GET form, so Enter with
   nothing highlighted still runs the full search; this script only adds the
   dropdown: up to six matching patients with their AMKA, telephone and last
   appointment, the number of matches, and a link to all of them.

   Results come from POST /patients/searchajax/term (patients_list_search_ajax()
   in web/index.php), which uses the list's own query, so the count and the
   "view all" page always agree. Every value is put into the page with
   textContent, never as HTML. Keyboard: Up/Down move, Enter opens, Escape
   closes. Texts come from window.zpmsText (zpms_js_text()). */
(function () {
    'use strict';

    var input = document.getElementById('patients-q');
    var panel = document.getElementById('ps-panel');
    if (!input || !panel) return;

    var form = input.form;
    var clearBtn = document.getElementById('ps-clear');
    var url = panel.dataset.url;
    var T = window.zpmsText || {};
    var timer = null;
    var controller = null;
    var items = [];
    var active = -1;
    var lastTerm = null;

    /* ---------- helpers ---------- */

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

    /* Case- and accent-insensitive fold of one character ("ά" -> "α", "Σ" -> "σ"). */
    function foldChar(c) {
        var f = c.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
        return f === 'ς' ? 'σ' : f;
    }

    /* Append text to parent with every word of the search term marked. */
    function appendMarked(parent, text, term) {
        text = String(text || '');
        var words = term.split(/\s+/).map(function (w) {
            return Array.from(w).map(foldChar).join('');
        }).filter(function (w) { return w.length > 0; });

        var chars = Array.from(text);
        var folded = chars.map(foldChar);
        var marked = new Array(chars.length).fill(false);
        words.forEach(function (w) {
            var wc = Array.from(w);
            for (var i = 0; i + wc.length <= folded.length; i++) {
                /* Only at the start of a word, as the search itself matches. */
                if (i > 0 && /[\p{L}\p{N}]/u.test(chars[i - 1])) continue;
                var ok = true;
                for (var j = 0; j < wc.length; j++) {
                    if (folded[i + j] !== wc[j]) { ok = false; break; }
                }
                if (ok) for (var k = 0; k < wc.length; k++) marked[i + k] = true;
            }
        });

        var buf = '';
        var inMark = false;
        function flush() {
            if (buf === '') return;
            parent.appendChild(inMark ? el('mark', null, buf) : document.createTextNode(buf));
            buf = '';
        }
        chars.forEach(function (c, i) {
            if (marked[i] !== inMark) { flush(); inMark = marked[i]; }
            buf += c;
        });
        flush();
    }

    /* ---------- open / close / highlight ---------- */

    function open() {
        panel.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    function close() {
        panel.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        setActive(-1);
    }

    function setActive(n) {
        items.forEach(function (it, i) { it.classList.toggle('is-active', i === n); });
        active = n;
        if (n >= 0 && items[n]) {
            input.setAttribute('aria-activedescendant', items[n].id);
            items[n].scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function updateClear() {
        if (clearBtn) clearBtn.hidden = input.value === '';
    }

    /* ---------- rendering ---------- */

    function header(countText) {
        var h = el('div', 'ps-head');
        h.appendChild(icon('bx-search'));
        h.appendChild(el('span', 'ps-title', T.psResults));
        if (countText) h.appendChild(el('span', 'ps-count', countText));
        return h;
    }

    function renderMessage(text, busy) {
        panel.replaceChildren(header(''), el('div', 'ps-message' + (busy ? ' is-busy' : ''), text));
        items = [];
        active = -1;
        open();
    }

    function renderResults(data, term) {
        var total = data.total || 0;
        var list = data.list || [];
        if (total === 0) {
            renderMessage(T.psNone);
            return;
        }

        var countText = (total === 1) ? T.psOne : String(T.psMany).replace('@count', total);
        var ul = el('ul', 'ps-list');
        ul.setAttribute('role', 'listbox');
        ul.id = 'ps-listbox';
        input.setAttribute('aria-controls', 'ps-listbox');

        items = list.map(function (p, i) {
            var li = el('li');
            var a = el('a', 'ps-item');
            a.href = p.link;
            a.id = 'ps-opt-' + i;
            a.setAttribute('role', 'option');
            a.tabIndex = -1;

            var av = el('span', 'pt-avatar tone-' + (parseInt(p.tone, 10) || 0), p.initials);
            av.setAttribute('aria-hidden', 'true');
            a.appendChild(av);

            var who = el('span', 'ps-who');
            var name = el('span', 'ps-name');
            appendMarked(name, p.name, term);
            if (p.operated) {
                var op = icon('bx-plus-medical ps-operated');
                op.title = T.psOperation;
                name.appendChild(op);
            }
            who.appendChild(name);

            var sub = el('span', 'ps-sub');
            if (p.amka) {
                var am = el('span');
                am.appendChild(document.createTextNode((T.amka || 'AMKA') + ' '));
                appendMarked(am, p.amka, term);
                sub.appendChild(am);
            }
            if (p.tel) {
                var tel = el('span');
                appendMarked(tel, p.tel, term);
                sub.appendChild(tel);
            }
            who.appendChild(sub);
            a.appendChild(who);

            var last = el('span', 'ps-last');
            if (p.last) {
                last.appendChild(icon('bx-calendar'));
                var lw = el('span', 'ps-last-text');
                var d = el('span', 'ps-date', p.last.date);
                if (p.last.upcoming) d.appendChild(el('span', 'ps-upcoming', T.psUpcoming));
                lw.appendChild(d);
                var kind = p.last.is_operation ? T.psOperation : T.psAppointment;
                lw.appendChild(el('span', 'ps-place' + (p.last.is_operation ? ' is-operation' : ''),
                    p.last.place ? kind + ' – ' + p.last.place : kind));
                last.appendChild(lw);
            } else {
                last.appendChild(el('span', 'ps-none', T.psNoAppointments));
            }
            a.appendChild(last);
            a.appendChild(icon('bx-chevron-right ps-go'));

            a.addEventListener('mousemove', function () { if (active !== i) setActive(i); });
            li.appendChild(a);
            ul.appendChild(li);
            return a;
        });

        var foot = el('a', 'ps-all');
        foot.href = data.all_url;
        foot.appendChild(icon('bx-search'));
        foot.appendChild(document.createTextNode(T.psAll));

        panel.replaceChildren(header(countText), ul, foot);
        active = -1;
        open();
    }

    /* ---------- searching ---------- */

    function search() {
        var term = input.value.trim();
        if (term === '') {
            lastTerm = null;
            if (controller) controller.abort();
            close();
            return;
        }
        if (term === lastTerm && !panel.hidden) return;
        lastTerm = term;

        if (controller) controller.abort();
        controller = new AbortController();
        var body = new URLSearchParams();
        body.set('sterm', term);
        var area = form && form.elements.namedItem('area');
        if (area && area.value) body.set('area', area.value);

        if (panel.hidden) renderMessage(T.psSearching, true);
        panel.setAttribute('aria-busy', 'true');

        fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: controller.signal,
        })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (input.value.trim() !== term) return;
                renderResults(data, term);
            })
            .catch(function (e) {
                if (e.name === 'AbortError') return;
                lastTerm = null;
                renderMessage(T.psFailed);
            })
            .finally(function () { panel.removeAttribute('aria-busy'); });
    }

    /* ---------- events ---------- */

    input.addEventListener('input', function () {
        updateClear();
        clearTimeout(timer);
        timer = setTimeout(search, input.value.trim().length < 2 ? 0 : 180);
    });

    input.addEventListener('focus', function () {
        if (input.value.trim() !== '' && panel.childElementCount > 0) open();
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (panel.hidden) { if (input.value.trim() !== '') { lastTerm = null; search(); } return; }
            if (items.length === 0) return;
            e.preventDefault();
            var n = (e.key === 'ArrowDown')
                ? (active + 1) % items.length
                : (active <= 0 ? items.length - 1 : active - 1);
            setActive(n);
        } else if (e.key === 'Enter') {
            if (!panel.hidden && active >= 0 && items[active]) {
                e.preventDefault();
                window.location.href = items[active].href;
            }
            /* Otherwise the form submits: the full list for this search. */
        } else if (e.key === 'Escape') {
            if (!panel.hidden) { e.preventDefault(); close(); }
        }
    });

    document.addEventListener('mousedown', function (e) {
        if (panel.hidden) return;
        if (e.target === input || panel.contains(e.target) || (clearBtn && clearBtn.contains(e.target))) return;
        close();
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            var hadSearch = clearBtn.dataset.active === '1';
            input.value = '';
            updateClear();
            close();
            /* The page is showing a search: go back to the whole list. */
            if (hadSearch && form) { form.requestSubmit(); return; }
            input.focus();
        });
    }

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    updateClear();
})();
