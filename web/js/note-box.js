/*
 * Clinical tags -- the note boxes (docs/CLINICAL_TAGS_PLAN.md, sections 6-9).
 * Each tagged note is a .zt-box holding the server-rendered reading look
 * (.zt-read) and the plain <textarea> that is actually saved (autosave via
 * web/js/loader.js, unchanged).
 *
 *  - Reading look (phase 2): whenever the box does not hold the caret, and
 *    always for an account that cannot edit. Tag words hidden, values in ink.
 *  - Writing look (phase 2): the textarea text is transparent; a "mirror" div
 *    with the same metrics sits behind it and draws grey tag words, a wash on
 *    the caret's sentence, pale underlines elsewhere. Nothing in it takes space.
 *  - Editing aids (phase 3): the @ tag menu; ghost completion (Tab = next word,
 *    → at the end of the text = the rest); ↓ = the full list (or the exam
 *    checklist, or the level grid); prescription blanks (Tab / Shift+Tab); the
 *    next-word strip (Alt+1..9 or a click); a dashed frame on the value under
 *    the caret and a field bar (list, change field, remove tag); Backspace right
 *    after a tag word removes it; on a phone, a word bar above the keyboard.
 *    Keys are taken over only while one of these is showing.
 *
 * Needs web/js/note-tags.js and the #zt-spec JSON from zpms_note_tags_assets().
 * Without either, the boxes stay plain textareas. Suggestions come from
 * GET /notes/suggestions (note_tags_suggestions(), web/note_tags.php), loaded
 * once per page on the first focus. All text reaches the page through
 * textContent, never innerHTML.
 */
(function () {
    'use strict';

    var T = window.ZpmsNoteTags;
    var specEl = document.getElementById('zt-spec');
    if (!T || !specEl) return;
    var spec;
    try { spec = JSON.parse(specEl.textContent); } catch (e) { return; }
    if (!spec || !spec.tags) return;

    var TAG_KEYS = Object.keys(spec.tags);
    var WORD_RE = /^[\p{L}\p{N}_]$/u;
    var POP_ID = 0;
    var COARSE = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
    var TEXT_EN = {
        ztList: 'List', ztChangeField: 'Change field', ztRemoveTag: 'Remove tag', ztTakeAll: 'All of it',
        ztNextBlank: 'Next', ztWrite: 'Write', ztFromList: 'list', ztCurrent: 'current', ztTimes: '@n times',
        ztTotal: 'Total: @n tablets', ztCheckLevel: 'Check the level'
    };
    function S(key, n) {
        var t = (window.zpmsText && window.zpmsText[key]) || TEXT_EN[key] || key;
        return n === undefined ? t : t.replace('@n', String(n));
    }

    function isWord(c) { return c != null && WORD_RE.test(c); }
    function cpLen(s) { return Array.from(s).length; }
    /** UTF-16 index <-> character (code point) offset. */
    function cpOffset(text, index) { return Array.from(text.slice(0, index)).length; }
    function utf16Index(text, cp) { return Array.from(text).slice(0, cp).join('').length; }
    function fold(s) { return T.fold(String(s || '')); }

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        return e;
    }
    function keepFocus(e) { e.preventDefault(); }

    /** Sentence ends (section 2): '.' + whitespace/end, '·', ';', Greek question mark, a line break. */
    function sentenceRange(chars, caret) {
        function isEnd(k) {
            var c = chars[k];
            if (c === '\n' || c === '\r' || c === ';' || c === '·' || c === '·' || c === ';') return true;
            return c === '.' && (k + 1 >= chars.length || /\s/.test(chars[k + 1]));
        }
        var s = caret, e = caret;
        while (s > 0 && !isEnd(s - 1)) s--;
        while (e < chars.length && !isEnd(e)) e++;
        return [s, e];
    }

    // ---- suggestions (one request per page) ----

    var suggestions = null, suggestionsLoading = null;
    function loadSuggestions() {
        if (suggestions || suggestionsLoading || !spec.suggestUrl) return suggestionsLoading;
        suggestionsLoading = fetch(spec.suggestUrl, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.text() : ''; })
            // Parsed from the start of the object, so anything printed before it
            // (PHP notices on a development server) cannot break the lists.
            .then(function (t) { var i = t.indexOf('{"tags"'); return i >= 0 ? JSON.parse(t.slice(i)) : { tags: {} }; })
            .catch(function () { return { tags: {} }; })
            .then(function (d) { suggestions = (d && d.tags) || {}; return suggestions; });
        return suggestionsLoading;
    }
    function listOf(tag) { return (suggestions && suggestions[tag] && suggestions[tag].list) || []; }
    function previousOf(tag) { return (suggestions && suggestions[tag] && suggestions[tag].previous) || []; }

    /** Fixed list first, then previous entries not already in the list: [{text, fromList, count, entry}]. */
    function candidates(tag) {
        var seen = {}, out = [];
        listOf(tag).forEach(function (e) { seen[fold(e.text)] = true; out.push({ text: e.text, fromList: true, count: 0, entry: e }); });
        previousOf(tag).forEach(function (p) {
            var f = fold(p.text);
            if (seen[f]) { out.forEach(function (c) { if (fold(c.text) === f) c.count = p.count; }); return; }
            seen[f] = true;
            out.push({ text: p.text, fromList: false, count: p.count, entry: null });
        });
        return out;
    }

    /** The best completion of a typed prefix: a list entry (or its Greek spelling) first, then the most used previous entry. */
    function bestMatch(tag, prefix) {
        var fp = fold(prefix).replace(/\s+/g, ' ');
        if (!fp.trim()) return null;
        var all = candidates(tag), i, c;
        for (i = 0; i < all.length; i++) {
            c = all[i];
            if (fold(c.text).indexOf(fp) === 0 && cpLen(c.text) > cpLen(prefix)) return { text: c.text, alias: false, cand: c };
            if (c.entry && c.entry.aliases) {
                for (var a = 0; a < c.entry.aliases.length; a++) {
                    if (fold(c.entry.aliases[a]).indexOf(fp) === 0) return { text: c.text, alias: true, cand: c };
                }
            }
        }
        return null;
    }

    /** The words of `rest` up to and including the next word (leading spaces kept). */
    function nextWordOf(rest) {
        var m = /^\s*\S+/.exec(rest);
        return m ? m[0] : rest;
    }

    // ---- levels grid ----

    var LEVEL_ROWS = (function () {
        var rows = [], i;
        var c = []; for (i = 2; i <= 7; i++) c.push(['C', i, i === 7 ? 'T' : 'C', i === 7 ? 1 : i + 1]);
        var t = []; for (i = 1; i <= 12; i++) t.push(['T', i, i === 12 ? 'L' : 'T', i === 12 ? 1 : i + 1]);
        var l = []; for (i = 1; i <= 5; i++) l.push(['L', i, i === 5 ? 'S' : 'L', i === 5 ? 1 : i + 1]);
        rows.push(c, t, l);
        return rows;
    })();
    var GREEK_REGION = { C: 'Α', T: 'Θ', L: 'Ο', S: 'Ι' };
    function levelKey(cell) { return cell[0] + cell[1] + '-' + cell[2] + cell[3]; }
    function levelText(cell, notation) {
        var r = notation === 'en' ? function (x) { return x; } : function (x) { return GREEK_REGION[x]; };
        return r(cell[0]) + cell[1] + '-' + r(cell[2]) + cell[3];
    }
    function storedNotation() { try { return localStorage.getItem('zt-level-notation') || 'el'; } catch (e) { return 'el'; } }
    function storeNotation(n) { try { localStorage.setItem('zt-level-notation', n); } catch (e) { /* private mode */ } }

    // ---- prescription blanks ----

    var BLANK_ORDER = ['strength', 'per_day', 'days', 'note'];
    var BLANK_OPTIONS = {
        per_day: ['1x1', '1x2', '1x3', '½x2', 'επί πόνου'],
        days: ['για 7 ημέρες', 'για 14 ημέρες', 'για 30 ημέρες', 'για 60 ημέρες', 'συνεχώς'],
        note: ['μετά το φαγητό', 'πριν το φαγητό', 'πριν τον ύπνο', 'με σταδιακή αύξηση']
    };
    function drugsFor() {
        return listOf('rx').map(function (e) {
            var unit = 'mg', m = /[\d.,]+\s*([^\d\s.,]+)\s*$/.exec(e.strengths && e.strengths[0] || '');
            if (m) unit = m[1].toLowerCase();
            return { text: e.text, aliases: e.aliases || [], unit: unit, entry: e };
        });
    }

    // ---- the reading look (same markup as zpms_note_reading_html()) ----

    function renderReading(read, text) {
        read.textContent = '';
        T.runs(text, spec).forEach(function (r) {
            if (r.kind === 'tag') return;
            var span;
            if (r.kind === 'value') {
                var tag = spec.tags[r.row.tag];
                span = el('span', 'zt-v zt-c-' + tag.colour, r.text);
                span.dataset.tag = r.row.tag;
                span.title = tag.label;
            } else {
                span = el('span', null, r.text);
            }
            span.dataset.o = String(r.offset);
            read.appendChild(span);
        });
    }

    /** Character offset in the note of a point in the reading look, or null. */
    function offsetFromPoint(read, x, y) {
        var node = null, off = 0;
        if (document.caretPositionFromPoint) {
            var p = document.caretPositionFromPoint(x, y);
            if (p) { node = p.offsetNode; off = p.offset; }
        } else if (document.caretRangeFromPoint) {
            var r = document.caretRangeFromPoint(x, y);
            if (r) { node = r.startContainer; off = r.startOffset; }
        }
        if (!node || !read.contains(node)) return null;
        var span = node.nodeType === 3 ? node.parentNode : node;
        if (!span || !span.dataset || span.dataset.o === undefined) return null;
        var within = node.nodeType === 3 ? cpOffset(node.data, off) : 0;
        return parseInt(span.dataset.o, 10) + within;
    }

    // ---- one box ----

    function NoteBox(box) {
        this.box = box;
        this.ta = box.querySelector('textarea');
        this.read = box.querySelector('.zt-read');
        if (!this.ta || !this.read) return;
        this.mirror = el('div', 'zt-mirror');
        this.mirror.setAttribute('aria-hidden', 'true');
        this.box.insertBefore(this.mirror, this.ta);
        this.pop = null;            // the open popup: {kind, el, items, index, ...}
        this.dismissedAt = -1;      // an '@' whose tag menu was closed with Esc
        this.blankDismissed = -1;   // a prescription whose blanks were left with Esc
        this.ctx = null;            // the tagged field under the caret (T.context())
        this.ghost = null;          // {text, from, to} -- the completion shown after the caret
        this.strip = null;          // {words[]} -- the next-word strip
        this.init();
    }

    NoteBox.prototype.editable = function () {
        return !this.ta.matches(':disabled') && !this.ta.readOnly;
    };

    NoteBox.prototype.init = function () {
        var self = this, ta = this.ta, read = this.read;
        this.copyLook();
        this.box.classList.add('zt-on', 'zt-reading');
        if (this.editable()) {
            read.tabIndex = 0;
            read.setAttribute('role', 'textbox');
            read.setAttribute('aria-multiline', 'true');
            if (ta.labels && ta.labels[0]) read.setAttribute('aria-label', ta.labels[0].textContent.trim());
        } else {
            this.box.classList.add('zt-readonly');
        }

        read.addEventListener('mousedown', function (e) {
            if (!self.editable() || e.button !== 0) return;
            e.preventDefault();
            var o = offsetFromPoint(read, e.clientX, e.clientY);
            self.enterWriting(o === null ? cpLen(ta.value) : o);
        });
        read.addEventListener('focus', function () {
            if (self.editable()) self.enterWriting(cpLen(ta.value));
        });

        ta.addEventListener('input', function () { self.update(true); });
        ta.addEventListener('scroll', function () { self.mirror.scrollTop = ta.scrollTop; self.mirror.scrollLeft = ta.scrollLeft; self.place(); });
        ta.addEventListener('keydown', function (e) { self.onKey(e); });
        ta.addEventListener('blur', function () { self.leaveWriting(); });
        ta.addEventListener('focus', function () { loadSuggestions() && suggestionsLoading.then(function () { self.update(false); }); });
        ta.addEventListener('click', function () {
            self.update(false);
            // A click on the grey tag word: change the field (section 8.5).
            if (self.ctx && self.ctx.inTag && !self.pop) self.openTagMenu('change');
        });
        ta.addEventListener('keyup', function (e) {
            if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown'].indexOf(e.key) !== -1) self.update(false);
        });
        document.addEventListener('selectionchange', function () {
            if (document.activeElement === ta) self.update(false);
        });
        if (window.ResizeObserver) {
            new ResizeObserver(function () { if (self.writing()) { self.layout(); self.place(); } }).observe(ta);
        }
        window.addEventListener('resize', function () { if (self.writing()) { self.layout(); self.place(); } });
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', function () { self.placePhoneBar(); });
            window.visualViewport.addEventListener('scroll', function () { self.placePhoneBar(); });
        }
    };

    /** The reading div looks like the textarea it stands in for, wherever the note is shown. */
    NoteBox.prototype.copyLook = function () {
        var cs = getComputedStyle(this.ta), r = this.read.style;
        ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'letterSpacing', 'wordSpacing', 'tabSize',
         'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
         'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
         'borderTopStyle', 'borderRightStyle', 'borderBottomStyle', 'borderLeftStyle',
         'borderTopColor', 'borderRightColor', 'borderBottomColor', 'borderLeftColor',
         'borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomLeftRadius', 'borderBottomRightRadius',
         'backgroundColor', 'minHeight'].forEach(function (p) { if (cs[p]) r[p] = cs[p]; });
        var lh = parseFloat(cs.lineHeight);
        if (!(parseFloat(cs.minHeight) > 0) && lh > 0) r.minHeight = (5 * lh + (parseFloat(cs.paddingTop) || 0) + (parseFloat(cs.paddingBottom) || 0)) + 'px';
    };

    NoteBox.prototype.writing = function () { return this.box.classList.contains('zt-writing'); };

    NoteBox.prototype.enterWriting = function (cp) {
        this.box.classList.remove('zt-reading');
        this.box.classList.add('zt-writing');
        var i = utf16Index(this.ta.value, cp);
        this.ta.focus({ preventScroll: true });
        this.ta.setSelectionRange(i, i);
        this.update(false);
    };

    NoteBox.prototype.leaveWriting = function () {
        this.closePop();
        this.dismissedAt = -1;
        this.blankDismissed = -1;
        this.ctx = null;
        this.setGhost(null);
        this.setStrip(null);
        this.setFieldBar(null);
        this.hidePhoneBar();
        renderReading(this.read, this.ta.value);
        this.box.classList.remove('zt-writing');
        this.box.classList.add('zt-reading');
    };

    /** Copies everything that decides where the letters fall from the textarea onto the mirror. */
    NoteBox.prototype.layout = function () {
        var ta = this.ta, m = this.mirror, cs = getComputedStyle(ta);
        ['fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'fontVariant', 'fontStretch', 'fontFeatureSettings', 'fontKerning',
         'lineHeight', 'letterSpacing', 'wordSpacing', 'textIndent', 'textTransform', 'tabSize', 'direction',
         'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
         'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
         'borderTopStyle', 'borderRightStyle', 'borderBottomStyle', 'borderLeftStyle', 'boxSizing'].forEach(function (p) { m.style[p] = cs[p]; });
        var bl = parseFloat(cs.borderLeftWidth) || 0, br = parseFloat(cs.borderRightWidth) || 0;
        var gutter = Math.max(0, ta.offsetWidth - ta.clientWidth - bl - br);
        m.style.paddingRight = 'calc(' + cs.paddingRight + ' + ' + gutter + 'px)';
        // Exact (sub-pixel) sizes: a width rounded by a fraction of a pixel
        // can wrap a line differently from the textarea.
        var tr = ta.getBoundingClientRect(), br2 = this.box.getBoundingClientRect();
        m.style.top = (tr.top - br2.top - this.box.clientTop) + 'px';
        m.style.left = (tr.left - br2.left - this.box.clientLeft) + 'px';
        m.style.width = tr.width + 'px';
        m.style.height = tr.height + 'px';
        m.scrollTop = ta.scrollTop;
    };

    NoteBox.prototype.caretCp = function () { return cpOffset(this.ta.value, this.ta.selectionStart); };

    /** Redraws the mirror and every aid. typed = the call comes from an input event. */
    NoteBox.prototype.update = function (typed) {
        if (!this.writing()) return;
        var ta = this.ta, text = ta.value, chars = Array.from(text);
        var caret = this.caretCp();
        var collapsed = ta.selectionStart === ta.selectionEnd;
        var ctx = T.context(text, caret, spec);
        this.ctx = ctx;
        var sentence = sentenceRange(chars, caret);
        var m = this.mirror;
        m.textContent = '';
        var marker = null;

        T.runs(text, spec).forEach(function (r) {
            var cls = null;
            if (r.kind === 'tag') {
                cls = 'zt-tag';
            } else if (r.kind === 'value') {
                var tag = spec.tags[r.row.tag];
                var end = r.offset + cpLen(r.text);
                var here = r.offset < sentence[1] && end > sentence[0];
                var framed = ctx && !ctx.inTag && r.offset >= ctx.start && end <= ctx.end && ctx.end > ctx.start;
                cls = 'zt-v zt-c-' + tag.colour + (here ? ' zt-wash' : ' zt-under') + (r.row.valid ? '' : ' zt-bad') + (framed ? ' zt-frame' : '');
            }
            var start = r.offset, len = cpLen(r.text);
            if (marker === null && caret >= start && caret < start + len) {
                var cut = Array.from(r.text);
                var a = cut.slice(0, caret - start).join(''), b = cut.slice(caret - start).join('');
                if (a) m.appendChild(cls ? el('span', cls, a) : document.createTextNode(a));
                marker = el('span', 'zt-caret');
                m.appendChild(marker);
                m.appendChild(cls ? el('span', cls, b) : document.createTextNode(b));
            } else {
                m.appendChild(cls ? el('span', cls, r.text) : document.createTextNode(r.text));
            }
        });
        if (marker === null) { marker = el('span', 'zt-caret'); m.appendChild(marker); }
        if (text === '' || /[\n\r]$/.test(text)) m.appendChild(document.createTextNode('​'));
        this.layout();
        this.caretMarker = marker;

        // The tag menu (typing @), else the aids of the field under the caret.
        var prefix = collapsed ? this.tagPrefix(text, ta.selectionStart) : null;
        var changing = this.pop && this.pop.kind === 'tag' && this.pop.mode === 'change';
        if (changing) {
            // keep the change-field menu while the caret stays on that tag word
        } else if (prefix && prefix.at !== this.dismissedAt && (typed || (this.pop && this.pop.kind === 'tag'))) {
            this.openTagMenu('insert', prefix);
        } else if (this.pop && this.pop.kind === 'tag' && this.pop.mode === 'insert') {
            this.closePop();
        }
        if (this.pop && this.pop.kind === 'tag' && this.pop.mode === 'change' && !(ctx && ctx.pos === this.pop.ctx.pos)) this.closePop();
        if (this.pop && this.pop.kind !== 'tag' && !(ctx && ctx.pos === this.pop.ctx.pos)) this.closePop();
        if (this.pop && this.pop.kind === 'list' && typed) this.refilterList();

        this.updateBlanks(ctx, collapsed);
        this.updateGhost(ctx, caret, text, collapsed);
        this.updateStrip(ctx, caret, collapsed);
        this.setFieldBar(ctx);
        this.place();
    };

    // ---- positioning ----

    /** Where the caret is, relative to the box: {left, top, bottom}. */
    NoteBox.prototype.caretBox = function () {
        if (!this.caretMarker) return null;
        var b = this.box.getBoundingClientRect(), mk = this.caretMarker.getBoundingClientRect();
        var lh = parseFloat(getComputedStyle(this.ta).lineHeight) || 20;
        return { left: mk.left - b.left, top: mk.top - b.top, bottom: mk.top - b.top + lh, viewTop: mk.top, lh: lh };
    };

    NoteBox.prototype.place = function () {
        var c = this.caretBox();
        if (!c) return;
        if (this.ghostEl) {
            this.ghostEl.style.left = c.left + 'px';
            this.ghostEl.style.top = c.top + 'px';
            this.ghostEl.style.lineHeight = c.lh + 'px';
            var tcs = getComputedStyle(this.ta);
            this.ghostEl.style.fontFamily = tcs.fontFamily;
            this.ghostEl.style.fontSize = tcs.fontSize;
            this.ghostEl.style.letterSpacing = tcs.letterSpacing;
            var maxW = this.box.clientWidth - c.left - 8;
            this.ghostEl.style.maxWidth = Math.max(0, maxW) + 'px';
        }
        if (this.stripEl) {
            this.stripEl.style.left = Math.max(0, Math.min(c.left, this.box.clientWidth - this.stripEl.offsetWidth)) + 'px';
            this.stripEl.style.top = (c.bottom + 2) + 'px';
        }
        if (this.pop) {
            var p = this.pop.el;
            var left = Math.max(0, Math.min(c.left, this.box.clientWidth - p.offsetWidth));
            var top = c.bottom + (this.stripEl ? this.stripEl.offsetHeight + 4 : 0);
            if (c.viewTop + c.lh + p.offsetHeight > window.innerHeight && c.viewTop - p.offsetHeight > 0) top = c.top - p.offsetHeight - 2;
            p.style.left = left + 'px';
            p.style.top = top + 'px';
        }
        this.placePhoneBar();
    };

    // ---- editing the text ----

    /** Replaces characters [from, to) with str; the caret goes to `caret` (characters), or selects [selFrom, selTo). */
    NoteBox.prototype.replace = function (from, to, str, caret, selTo) {
        var ta = this.ta, v = ta.value;
        var a = utf16Index(v, from), b = utf16Index(v, to);
        ta.setRangeText(str, a, b, 'end');
        var nv = ta.value;
        var c1 = caret === undefined ? from + cpLen(str) : caret;
        var c2 = selTo === undefined ? c1 : selTo;
        ta.setSelectionRange(utf16Index(nv, c1), utf16Index(nv, c2));
        ta.dispatchEvent(new Event('input', { bubbles: true }));   // autosave + redraw
    };

    // ---- the @ tag menu (insert a tag, or change the field of one) ----

    /** The '@' + letters right before the caret, if they could start a tag: {at, partial} (UTF-16 at). */
    NoteBox.prototype.tagPrefix = function (text, caretIdx) {
        var before = Array.from(text.slice(0, caretIdx)), k = before.length;
        while (k > 0 && /^[\p{L}]$/u.test(before[k - 1])) k--;
        if (k === 0 || before[k - 1] !== '@' || isWord(before[k - 2])) return null;
        if (before.length - k > 20) return null;
        var partial = fold(before.slice(k).join(''));
        var keys = TAG_KEYS.filter(function (key) {
            return spec.tags[key].names.some(function (n) { return fold(n).indexOf(partial) === 0; });
        });
        if (!keys.length) return null;
        return { at: before.slice(0, k - 1).join('').length, atCp: k - 1, partial: partial, keys: keys };
    };

    NoteBox.prototype.openTagMenu = function (mode, prefix) {
        var self = this, keys = mode === 'insert' ? prefix.keys : TAG_KEYS;
        var items = keys.map(function (key) {
            var tag = spec.tags[key];
            return { key: key, label: tag.label, hint: '@' + tag.names[0], colour: tag.colour };
        });
        var prev = this.pop && this.pop.kind === 'tag' ? this.pop.items[this.pop.index] : null;
        this.openPop('tag', items, function (item) { self.acceptTag(item.key); });
        this.pop.mode = mode;
        this.pop.prefix = prefix;
        this.pop.ctx = mode === 'change' ? this.ctx : { pos: this.ctx ? this.ctx.pos : -2 };
        if (prev) {
            var i = keys.indexOf(prev.key);
            if (i >= 0) { this.pop.index = i; this.highlight(); }
        }
    };

    NoteBox.prototype.acceptTag = function (key) {
        var name = '@' + spec.tags[key].names[0];
        var pop = this.pop;
        this.closePop();
        if (pop.mode === 'change') {
            // Only the tag word changes; the value stays (section 8.5).
            var c = pop.ctx, caret = this.caretCp();
            var delta = cpLen(name) - (c.nameEnd - c.pos);
            this.replace(c.pos, c.nameEnd, name, caret >= c.nameEnd ? caret + delta : c.pos + cpLen(name));
            return;
        }
        var text = this.ta.value, end = this.caretCp();
        var after = Array.from(text)[end];
        var insert = name + (after === undefined || !/\s/.test(after) ? ' ' : '');
        this.replace(pop.prefix.atCp, end, insert, pop.prefix.atCp + cpLen(insert) + (after !== undefined && /\s/.test(after) ? 1 : 0));
    };

    // ---- popups: one at a time ----

    NoteBox.prototype.openPop = function (kind, items, onAccept) {
        var self = this;
        if (!this.pop || this.pop.kind !== kind) {
            this.closePop();
            var ul = el('ul', 'zt-menu zt-pop-' + kind);
            ul.id = 'zt-pop-' + (++POP_ID);
            ul.setAttribute('role', 'listbox');
            ul.addEventListener('mousedown', keepFocus);
            this.box.appendChild(ul);
            this.ta.setAttribute('aria-controls', ul.id);
            this.ta.setAttribute('aria-expanded', 'true');
            this.pop = { kind: kind, el: ul, items: [], index: 0, ctx: this.ctx, ticked: {} };
        }
        var pop = this.pop;
        pop.items = items;
        pop.onAccept = onAccept;
        pop.index = Math.min(pop.index, Math.max(0, items.length - 1));
        pop.el.textContent = '';
        items.forEach(function (item, i) {
            var li = el('li', 'zt-menu-item' + (item.colour ? ' zt-c-' + item.colour : '') + (item.fromList ? ' is-list' : ''));
            li.id = pop.el.id + '-' + i;
            li.setAttribute('role', 'option');
            if (kind === 'checklist') li.appendChild(el('span', 'zt-check'));
            else li.appendChild(el('span', 'zt-menu-dot'));
            li.appendChild(el('span', 'zt-menu-label', item.label));
            if (item.hint) li.appendChild(el('span', 'zt-menu-name', item.hint));
            li.addEventListener('click', function () {
                pop.index = i;
                if (kind === 'checklist') { self.toggleTick(); } else { self.highlight(); onAccept(item); }
            });
            pop.el.appendChild(li);
        });
        if (kind === 'checklist') {
            var ok = el('li', 'zt-menu-ok', S('ztWrite'));
            ok.addEventListener('click', function () { self.acceptChecklist(); });
            pop.el.appendChild(ok);
        }
        this.highlight();
        this.place();
    };

    NoteBox.prototype.highlight = function () {
        var pop = this.pop, ta = this.ta;
        if (!pop) return;
        Array.prototype.forEach.call(pop.el.querySelectorAll('.zt-menu-item'), function (li, i) {
            var on = i === pop.index;
            li.classList.toggle('is-active', on);
            li.classList.toggle('is-ticked', !!pop.ticked[i]);
            li.setAttribute('aria-selected', on ? 'true' : 'false');
            if (on) {
                ta.setAttribute('aria-activedescendant', li.id);
                if (li.scrollIntoView) li.scrollIntoView({ block: 'nearest' });
            }
        });
    };

    NoteBox.prototype.closePop = function () {
        if (!this.pop) return;
        this.pop.el.remove();
        this.pop = null;
        this.ta.removeAttribute('aria-controls');
        this.ta.removeAttribute('aria-activedescendant');
        this.ta.setAttribute('aria-expanded', 'false');
    };

    // ---- ↓: the list, the exam checklist, the level grid ----

    NoteBox.prototype.openPicker = function () {
        var c = this.ctx;
        if (!c || c.inTag) return false;
        var picker = spec.tags[c.tag].picker;
        if (picker === 'grid' || spec.tags[c.tag].value === 'levels') { this.openGrid(); return true; }
        if (!suggestions) {
            var self = this;
            if (loadSuggestions()) suggestionsLoading.then(function () { if (self.writing()) { self.update(false); self.openPicker(); } });
            return true;
        }
        if (picker === 'checklist') { this.openChecklist(); return true; }
        this.openList(true);
        return true;
    };

    /** One choice (section 6/8): the current value first, then the fixed list, then previous entries. */
    NoteBox.prototype.openList = function (all) {
        var self = this, c = this.ctx;
        var items = this.listItems(all);
        if (!items.length) return;
        this.openPop('list', items, function (item) { self.acceptListItem(item); });
        this.pop.all = all;
    };

    NoteBox.prototype.listItems = function (all) {
        var c = this.ctx, value = c.value, out = [];
        var fp = fold(value);
        var cands = candidates(c.tag);
        var filtered = all ? cands : cands.filter(function (x) { return fold(x.text).indexOf(fp) === 0 || (x.entry && (x.entry.aliases || []).some(function (a) { return fold(a).indexOf(fp) === 0; })); });
        if (!filtered.length) filtered = cands;
        if (value && all) out.push({ label: value, hint: S('ztCurrent'), text: value, current: true });
        filtered.forEach(function (x) {
            if (value && fold(x.text) === fp && all) return;
            out.push({ label: x.text, hint: x.fromList ? S('ztFromList') : S('ztTimes', x.count), text: x.text, fromList: x.fromList, entry: x.entry });
        });
        return out;
    };

    NoteBox.prototype.refilterList = function () {
        if (!this.ctx) return;
        var items = this.listItems(false);
        if (!items.length) { this.closePop(); return; }
        var self = this;
        this.openPop('list', items, function (item) { self.acceptListItem(item); });
    };

    /** Enter replaces the whole value inside the frame (section 8.2). A drug writes the prescription with its blanks. */
    NoteBox.prototype.acceptListItem = function (item) {
        var c = this.ctx;
        this.closePop();
        if (spec.tags[c.tag].value === 'prescription' && item.entry) {
            this.writePrescription(c, item.entry);
            return;
        }
        this.replace(c.start, c.end, item.text);
    };

    NoteBox.prototype.openChecklist = function () {
        var self = this, c = this.ctx, items = [];
        candidates(c.tag).forEach(function (x) {
            items.push({ label: x.text, hint: x.fromList ? '' : S('ztTimes', x.count), text: x.text, fromList: x.fromList });
        });
        if (!items.length) return;
        this.openPop('checklist', items, function () { self.acceptChecklist(); });
        this.pop.ticked = {};
        var fv = fold(c.value);
        items.forEach(function (it, i) { if (fv && fold(it.text) === fv) self.pop.ticked[i] = true; });
        this.highlight();
    };

    NoteBox.prototype.toggleTick = function () {
        var pop = this.pop;
        pop.ticked[pop.index] = !pop.ticked[pop.index];
        this.highlight();
    };

    /** Each pick written with its own tag, since a comma ends a text value (section 6). */
    NoteBox.prototype.acceptChecklist = function () {
        var pop = this.pop, c = this.ctx;
        var picked = pop.items.filter(function (it, i) { return pop.ticked[i]; }).map(function (it) { return it.text; });
        if (!picked.length) picked = [pop.items[pop.index].text];
        var tagWord = '@' + spec.tags[c.tag].names[0];
        this.closePop();
        this.replace(c.start, c.end, picked.join(', ' + tagWord + ' '));
    };

    /** The level grid: regions as rows, disc levels as cells; several at once under one tag. */
    NoteBox.prototype.openGrid = function () {
        var self = this, c = this.ctx;
        this.closePop();
        var rows = T.parse(this.ta.value, spec, []).filter(function (r) { return r.tag === 'lvl' && r.pos === c.pos; });
        var row = rows[0] || null;
        var notation = storedNotation();
        if (row && /[A-Za-z]/.test(row.value)) notation = 'en'; else if (row && row.normalized) notation = 'el';
        if (row && row.normalized) storeNotation(notation);
        var selected = {};
        if (row && row.normalized) row.normalized.split(', ').forEach(function (k) { selected[k] = true; });

        var wrap = el('div', 'zt-menu zt-grid');
        wrap.addEventListener('mousedown', keepFocus);
        wrap.id = 'zt-pop-' + (++POP_ID);
        var cells = [];
        LEVEL_ROWS.forEach(function (r, ri) {
            var line = el('div', 'zt-grid-row');
            line.appendChild(el('span', 'zt-grid-region', notation === 'en' ? r[0][0] : GREEK_REGION[r[0][0]]));
            cells[ri] = [];
            r.forEach(function (cell, ci) {
                var b = el('button', 'zt-grid-cell', levelText(cell, notation));
                b.type = 'button';
                b.tabIndex = -1;
                b.addEventListener('click', function () { self.pop.cur = [ri, ci]; self.gridToggle(); });
                line.appendChild(b);
                cells[ri][ci] = b;
            });
            wrap.appendChild(line);
        });
        var ok = el('button', 'zt-grid-ok', S('ztWrite'));
        ok.type = 'button';
        ok.tabIndex = -1;
        ok.addEventListener('click', function () { self.acceptGrid(); });
        wrap.appendChild(ok);
        this.box.appendChild(wrap);
        var cur = [2, 3];
        LEVEL_ROWS.some(function (r, ri) { return r.some(function (cell, ci) { if (selected[levelKey(cell)]) { cur = [ri, ci]; return true; } return false; }); });
        this.pop = { kind: 'grid', el: wrap, items: [], index: 0, ctx: c, cells: cells, cur: cur, selected: selected, notation: notation, row: row };
        this.ta.setAttribute('aria-expanded', 'true');
        this.gridPaint();
        this.place();
    };

    NoteBox.prototype.gridPaint = function () {
        var pop = this.pop;
        LEVEL_ROWS.forEach(function (r, ri) {
            r.forEach(function (cell, ci) {
                var b = pop.cells[ri][ci];
                b.classList.toggle('is-ticked', !!pop.selected[levelKey(cell)]);
                b.classList.toggle('is-active', pop.cur[0] === ri && pop.cur[1] === ci);
            });
        });
    };

    NoteBox.prototype.gridMove = function (dr, dc) {
        var pop = this.pop, ri = pop.cur[0], ci = pop.cur[1];
        if (dr) { ri = Math.max(0, Math.min(LEVEL_ROWS.length - 1, ri + dr)); ci = Math.min(ci, LEVEL_ROWS[ri].length - 1); }
        if (dc) ci = Math.max(0, Math.min(LEVEL_ROWS[ri].length - 1, ci + dc));
        pop.cur = [ri, ci];
        this.gridPaint();
    };

    NoteBox.prototype.gridToggle = function () {
        var pop = this.pop, k = levelKey(LEVEL_ROWS[pop.cur[0]][pop.cur[1]]);
        if (pop.selected[k]) delete pop.selected[k]; else pop.selected[k] = true;
        this.gridPaint();
    };

    NoteBox.prototype.acceptGrid = function () {
        var pop = this.pop, c = pop.ctx, picked = [];
        LEVEL_ROWS.forEach(function (r) { r.forEach(function (cell) { if (pop.selected[levelKey(cell)]) picked.push(levelText(cell, pop.notation)); }); });
        if (!picked.length) picked.push(levelText(LEVEL_ROWS[pop.cur[0]][pop.cur[1]], pop.notation));
        var end = pop.row ? pop.row.end : c.end;
        this.closePop();
        this.replace(c.start, end, picked.join(' και '));
    };

    // ---- prescription blanks (section 5) ----

    /** Writes «Lyrica 75mg 1x2 για 30 ημέρες» for a picked drug and selects its first blank. */
    NoteBox.prototype.writePrescription = function (c, entry) {
        var d = entry.defaults || {};
        var parts = [entry.text, d.strength || (entry.strengths && entry.strengths[0]) || '', d.per_day || '1x2', d.days || 'για 30 ημέρες'];
        var phrase = parts.filter(function (p) { return p; }).join(' ');
        this.blankDismissed = -1;
        this.replace(c.start, c.end, phrase);
        this.selectBlank(0);
    };

    /** The blanks of the prescription under the caret: [{name, from, to}] in note characters, or null. */
    NoteBox.prototype.blanks = function (c) {
        if (!c || c.inTag || spec.tags[c.tag].value !== 'prescription' || !c.value) return null;
        var drugs = drugsFor();
        var full = T.parseRxFull(c.value, drugs);
        var known = drugs.some(function (d) { return d.text === full.rx.drug; });
        if (!known || !full.spans.drug) return null;
        var out = [], end = cpLen(c.value), chars = Array.from(this.ta.value);
        BLANK_ORDER.forEach(function (name) {
            var s = full.spans[name];
            if (s && name === 'note' && s[0] === s[1]) {
                // ", " typed, nothing after it yet: the blank sits after the space.
                var at = c.start + full.comma + 1;
                while (chars[at] === ' ') at++;
                out.push({ name: 'note', from: at, to: at });
            } else if (s) out.push({ name: name, from: c.start + s[0], to: c.start + s[1] });
            else if (name === 'note') out.push({ name: 'note', from: c.start + end, to: c.start + end, empty: true });
        });
        return { list: out, drug: drugs.filter(function (d) { return d.text === full.rx.drug; })[0], rx: full.rx };
    };

    NoteBox.prototype.selectBlank = function (i) {
        var b = this.blanks(T.context(this.ta.value, this.caretCp(), spec));
        if (!b) return;
        i = Math.max(0, Math.min(b.list.length - 1, i));
        var bl = b.list[i];
        if (bl.name === 'note' && bl.empty) {
            // The note blank appears only when visited; left empty, it goes away again.
            this.replace(bl.from, bl.to, ', ', bl.from + 2);
            return;
        }
        var v = this.ta.value;
        this.ta.setSelectionRange(utf16Index(v, bl.from), utf16Index(v, bl.to));
        this.update(false);
    };

    NoteBox.prototype.updateBlanks = function (c, collapsed) {
        var b = this.blanks(c);
        this.blankState = null;
        if (!b || (c && c.pos === this.blankDismissed)) { if (this.pop && this.pop.kind === 'blank') this.closePop(); return; }
        var caret = this.caretCp(), selEnd = cpOffset(this.ta.value, this.ta.selectionEnd), idx = -1;
        // A blank not written yet (no note) is never "under" the caret.
        b.list.forEach(function (bl, i) { if (idx === -1 && !bl.empty && caret >= bl.from && selEnd <= bl.to && caret <= bl.to) idx = i; });
        this.blankState = { blanks: b, index: idx };
        // Its list shows only while the blank is selected (as Tab leaves it) or the
        // caret is inside it -- not at its end, where Enter must stay a line break.
        var cur = idx >= 0 ? b.list[idx] : null;
        var showing = cur && ((caret === cur.from && selEnd === cur.to) || (caret > cur.from && caret < cur.to && caret === selEnd));
        if (!showing || (this.pop && this.pop.kind !== 'blank')) { if (this.pop && this.pop.kind === 'blank') this.closePop(); return; }
        var bl = b.list[idx], self = this;
        var opts = bl.name === 'strength' ? (b.drug.entry.strengths || []) : BLANK_OPTIONS[bl.name];
        var items = opts.map(function (o) { return { label: o, text: o }; });
        if (!items.length) { if (this.pop) this.closePop(); return; }
        var keepIndex = this.pop && this.pop.kind === 'blank' && this.pop.blank === bl.name ? this.pop.index : 0;
        this.openPop('blank', items, function (item) { self.acceptBlank(item); });
        this.pop.blank = bl.name;
        this.pop.index = keepIndex;
        this.highlight();
    };

    NoteBox.prototype.acceptBlank = function (item) {
        var st = this.blankState;
        if (!st || st.index < 0) return;
        var bl = st.blanks.list[st.index], i = st.index;
        this.closePop();
        this.replace(bl.from, bl.to, item.text);
        if (i + 1 < st.blanks.list.length) this.selectBlank(i + 1);
    };

    /** Tab / Shift+Tab between blanks. Leaving the note blank empty removes its ", ". */
    NoteBox.prototype.moveBlank = function (dir) {
        var st = this.blankState;
        if (!st || st.index < 0) return false;
        var bl = st.blanks.list[st.index];
        if (bl.name === 'note' && bl.from === bl.to) {
            var chars = Array.from(this.ta.value);
            if (chars.slice(bl.from - 2, bl.from).join('') === ', ') {
                this.replace(bl.from - 2, bl.from, '', bl.from - 2);
                if (dir > 0) { this.closePop(); return true; }
            }
        }
        var next = st.index + dir;
        if (next < 0 || next >= st.blanks.list.length) { this.closePop(); this.blankDismissed = this.ctx ? this.ctx.pos : -1; return true; }
        this.selectBlank(next);
        return true;
    };

    // ---- ghost completion ----

    NoteBox.prototype.updateGhost = function (c, caret, text, collapsed) {
        var g = null;
        if (c && !c.inTag && collapsed && !this.pop && caret === c.end && c.value && suggestions && spec.tags[c.tag].value !== 'levels') {
            var rest = text.slice(this.ta.selectionStart).split(/[\n\r]/)[0];
            if (!rest.trim()) {
                var m = bestMatch(c.tag, c.value);
                if (m) {
                    var remainder = m.alias ? m.text : Array.from(m.text).slice(cpLen(c.value)).join('');
                    if (remainder) g = { remainder: remainder, alias: m.alias, full: m.text, start: c.start, end: c.end };
                }
            }
        }
        this.setGhost(g);
    };

    NoteBox.prototype.setGhost = function (g) {
        this.ghost = g;
        if (!g) {
            if (this.ghostEl) { this.ghostEl.remove(); this.ghostEl = null; }
            return;
        }
        var self = this;
        if (!this.ghostEl) {
            this.ghostEl = el('span', 'zt-ghost');
            this.ghostEl.addEventListener('mousedown', keepFocus);
            this.ghostEl.addEventListener('click', function () { self.takeGhost(true); });
            this.box.appendChild(this.ghostEl);
        }
        this.ghostEl.textContent = g.alias ? ' → ' + g.full : g.remainder;
        this.ghostEl.classList.toggle('is-alias', g.alias);
    };

    /** Tab takes the next word (all = the rest). A Greek spelling of a drug is replaced by its name. */
    NoteBox.prototype.takeGhost = function (all, upto) {
        var g = this.ghost;
        if (!g) return;
        var c = this.ctx;
        if (g.alias) {
            var item = candidates(c.tag).filter(function (x) { return x.text === g.full; })[0];
            if (spec.tags[c.tag].value === 'prescription' && item && item.entry) { this.writePrescription(c, item.entry); return; }
            this.replace(g.start, g.end, g.full);
            return;
        }
        var add = all ? g.remainder : (upto !== undefined ? upto : nextWordOf(g.remainder));
        this.replace(g.end, g.end, add);
        if (all || cpLen(add) === cpLen(g.remainder)) {
            var after = candidates(c.tag).filter(function (x) { return x.text === g.full; })[0];
            if (spec.tags[c.tag].value === 'prescription' && after && after.entry) {
                var cc = T.context(this.ta.value, this.caretCp(), spec);
                if (cc) this.writePrescription(cc, after.entry);
            }
        }
    };

    // ---- next-word strip ----

    NoteBox.prototype.updateStrip = function (c, caret, collapsed) {
        var words = null;
        if (c && !c.inTag && collapsed && !this.pop && caret === c.end && c.value && spec.tags[c.tag].next_words && suggestions) {
            var fv = fold(c.value);
            var entry = listOf(c.tag).filter(function (e) {
                var fe = fold(e.text);
                return fv === fe || fv.indexOf(fe + ' ') === 0;
            }).sort(function (a, b) { return b.text.length - a.text.length; })[0];
            if (entry) {
                var fe = fold(entry.text), seen = {}, list = [];
                (entry.next_words || []).forEach(function (w) { if (!seen[fold(w)]) { seen[fold(w)] = true; list.push(w); } });
                // Words previously written after this entry.
                previousOf(c.tag).forEach(function (p) {
                    var fp = fold(p.text);
                    if (fp.indexOf(fe + ' ') !== 0) return;
                    var w = Array.from(p.text).slice(cpLen(entry.text)).join('').trim().split(/\s+/)[0];
                    if (w && !seen[fold(w)]) { seen[fold(w)] = true; list.push(w); }
                });
                words = list.filter(function (w) { return (' ' + fv + ' ').indexOf(' ' + fold(w) + ' ') === -1; }).slice(0, 9);
                if (!words.length) words = null;
            }
        }
        this.setStrip(words);
    };

    NoteBox.prototype.setStrip = function (words) {
        this.strip = words;
        if (!words) { if (this.stripEl) { this.stripEl.remove(); this.stripEl = null; } return; }
        var self = this;
        if (!this.stripEl) {
            this.stripEl = el('div', 'zt-strip');
            this.stripEl.addEventListener('mousedown', keepFocus);
            this.box.appendChild(this.stripEl);
        }
        this.stripEl.textContent = '';
        words.forEach(function (w, i) {
            var b = el('button', 'zt-strip-word');
            b.type = 'button';
            b.tabIndex = -1;
            if (!COARSE) b.appendChild(el('kbd', null, String(i + 1)));
            b.appendChild(document.createTextNode(w));
            b.addEventListener('click', function () { self.insertWord(w); });
            self.stripEl.appendChild(b);
        });
    };

    NoteBox.prototype.insertWord = function (w) {
        var c = this.ctx;
        if (!c) return;
        var before = Array.from(this.ta.value)[c.end - 1];
        this.replace(c.end, c.end, (before && /\s/.test(before) ? '' : ' ') + w);
    };

    // ---- the field bar and the value frame (section 8) ----

    NoteBox.prototype.setFieldBar = function (c) {
        var self = this;
        if (!c || !this.writing()) {
            if (this.barEl) { this.barEl.remove(); this.barEl = null; this.barKey = null; }
            return;
        }
        var tag = spec.tags[c.tag];
        var info = '';
        if (tag.value === 'prescription' && c.value) {
            var rx = T.parseRx(c.value, drugsFor());
            if (rx.total !== null) info = S('ztTotal', String(rx.total).replace('.', ','));
        }
        if (tag.value === 'levels' && c.value) {
            var row = T.parse(this.ta.value, spec, []).filter(function (r) { return r.pos === c.pos; })[0];
            if (row && !row.valid) info = S('ztCheckLevel');
        }
        var key = c.tag + '|' + info + '|' + c.pos;
        if (this.barEl && this.barKey === key) return;
        if (!this.barEl) {
            this.barEl = el('div', 'zt-fieldbar');
            this.barEl.addEventListener('mousedown', keepFocus);
            this.box.appendChild(this.barEl);
        }
        this.barKey = key;
        var bar = this.barEl;
        bar.textContent = '';
        bar.className = 'zt-fieldbar zt-c-' + tag.colour;
        bar.appendChild(el('span', 'zt-menu-dot'));
        bar.appendChild(el('span', 'zt-fieldbar-label', tag.label));
        if (info) bar.appendChild(el('span', 'zt-fieldbar-info' + (tag.value === 'levels' ? ' is-warning' : ''), info));
        function btn(label, fn) {
            var b = el('button', 'zt-fieldbar-btn', label);
            b.type = 'button';
            b.tabIndex = -1;
            b.addEventListener('click', fn);
            bar.appendChild(b);
        }
        btn(S('ztList') + ' ↓', function () { self.update(false); self.openPicker(); });
        btn(S('ztChangeField'), function () { self.update(false); if (self.ctx) self.openTagMenu('change'); });
        btn(S('ztRemoveTag'), function () { self.removeTag(); });
    };

    /** Removes the tag word (and the spaces after it); the words stay (section 8.6). */
    NoteBox.prototype.removeTag = function () {
        var c = this.ctx;
        if (!c) return;
        var caret = this.caretCp(), len = c.start - c.pos;
        this.closePop();
        this.replace(c.pos, c.start, '', caret >= c.start ? caret - len : c.pos);
    };

    // ---- the phone bar (no Tab or arrows on a touch keyboard) ----

    NoteBox.prototype.updatePhoneBar = function () {
        if (!COARSE || !this.writing()) { this.hidePhoneBar(); return; }
        var self = this, items = [];
        if (this.ghost && !this.ghost.alias) {
            var words = this.ghost.remainder.match(/\s*\S+/g) || [];
            var acc = '';
            words.forEach(function (w) { acc += w; var upto = acc; items.push({ label: w.trim(), fn: function () { self.takeGhost(false, upto); } }); });
            items.push({ label: S('ztTakeAll'), cls: 'is-all', fn: function () { self.takeGhost(true); } });
        } else if (this.ghost) {
            items.push({ label: this.ghost.full, fn: function () { self.takeGhost(true); } });
        }
        if (this.blankState && this.blankState.index >= 0) {
            items.push({ label: S('ztNextBlank'), cls: 'is-next', fn: function () { self.moveBlank(1); } });
        }
        if (!items.length) { this.hidePhoneBar(); return; }
        if (!this.phoneBar) {
            this.phoneBar = el('div', 'zt-phonebar');
            this.phoneBar.addEventListener('mousedown', keepFocus);
            document.body.appendChild(this.phoneBar);
        }
        this.phoneBar.textContent = '';
        items.forEach(function (it) {
            var b = el('button', 'zt-phonebar-btn' + (it.cls ? ' ' + it.cls : ''), it.label);
            b.type = 'button';
            b.tabIndex = -1;
            b.addEventListener('click', it.fn);
            self.phoneBar.appendChild(b);
        });
        this.placePhoneBar();
    };

    NoteBox.prototype.placePhoneBar = function () {
        if (!this.phoneBar) return;
        var vv = window.visualViewport;
        var bottom = vv ? Math.max(0, window.innerHeight - vv.height - vv.offsetTop) : 0;
        this.phoneBar.style.bottom = bottom + 'px';
    };

    NoteBox.prototype.hidePhoneBar = function () {
        if (this.phoneBar) { this.phoneBar.remove(); this.phoneBar = null; }
    };

    // ---- keys: taken over only while an aid is showing (section 6) ----

    NoteBox.prototype.onKey = function (e) {
        if (e.isComposing) return;
        var pop = this.pop, plain = !e.altKey && !e.ctrlKey && !e.metaKey;
        if (pop) {
            if (pop.kind === 'grid') return this.gridKey(e, plain);
            var n = pop.items.length;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                pop.index = (pop.index + (e.key === 'ArrowDown' ? 1 : n - 1)) % n;
                this.highlight();
                e.preventDefault();
                return;
            }
            if (e.key === 'Escape') {
                if (pop.kind === 'tag' && pop.mode === 'insert') this.dismissedAt = pop.prefix.at;
                if (pop.kind === 'blank') this.blankDismissed = this.ctx ? this.ctx.pos : -1;
                this.closePop();
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            if (pop.kind === 'checklist') {
                if (e.key === ' ' && plain) { this.toggleTick(); e.preventDefault(); return; }
                if ((e.key === 'Enter' || e.key === 'Tab') && plain && !e.shiftKey) { this.acceptChecklist(); e.preventDefault(); return; }
                if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') { this.closePop(); return; }
                return;
            }
            if (pop.kind === 'blank') {
                if (e.key === 'Enter' && plain && !e.shiftKey) { this.acceptBlank(pop.items[pop.index]); e.preventDefault(); return; }
                if (e.key === 'Tab' && plain) { if (this.moveBlank(e.shiftKey ? -1 : 1)) e.preventDefault(); return; }
                return;
            }
            if (pop.kind === 'list') {
                if (e.key === 'Enter' && plain && !e.shiftKey) { pop.onAccept(pop.items[pop.index]); e.preventDefault(); return; }
                if (e.key === 'Tab' && plain && !e.shiftKey) {
                    // Tab takes the next word of the highlighted entry.
                    var it = pop.items[pop.index], c = this.ctx;
                    var fv = fold(c.value), ft = fold(it.text);
                    if (ft.indexOf(fv) === 0 && cpLen(it.text) > cpLen(c.value)) {
                        var rest = Array.from(it.text).slice(cpLen(c.value)).join('');
                        this.replace(c.end, c.end, nextWordOf(rest));
                    } else {
                        pop.onAccept(it);
                    }
                    e.preventDefault();
                    return;
                }
                if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') { this.closePop(); return; }
                return;
            }
            if (pop.kind === 'tag') {
                if ((e.key === 'Enter' || e.key === 'Tab') && plain && !e.shiftKey) { pop.onAccept(pop.items[pop.index]); e.preventDefault(); }
                return;
            }
            return;
        }

        // Prescription blanks: Tab / Shift+Tab move between them while the caret is in one.
        if (e.key === 'Tab' && plain && this.blankState && this.blankState.index >= 0 && this.ctx && this.ctx.pos !== this.blankDismissed) {
            if (this.moveBlank(e.shiftKey ? -1 : 1)) { e.preventDefault(); return; }
        }
        if (this.ghost && plain && !e.shiftKey) {
            if (e.key === 'Tab') { this.takeGhost(false); e.preventDefault(); return; }
            if (e.key === 'ArrowRight' && this.ta.selectionStart === this.ta.value.length) { this.takeGhost(true); e.preventDefault(); return; }
        }
        if (this.strip && e.altKey && !e.ctrlKey && !e.metaKey && /^Digit[1-9]$/.test(e.code)) {
            var w = this.strip[parseInt(e.code.slice(5), 10) - 1];
            if (w) { this.insertWord(w); e.preventDefault(); return; }
        }
        if (e.key === 'ArrowDown' && plain && !e.shiftKey && this.ctx && !this.ctx.inTag && this.ta.selectionStart === this.ta.selectionEnd) {
            if (this.openPicker()) { e.preventDefault(); return; }
        }
        // One Backspace right after a tag word removes the whole tag word (section 8.6).
        if (e.key === 'Backspace' && plain && this.ta.selectionStart === this.ta.selectionEnd && this.ctx) {
            var caret = this.caretCp();
            if (caret === this.ctx.nameEnd) {
                this.replace(this.ctx.pos, this.ctx.nameEnd, '', this.ctx.pos);
                e.preventDefault();
            }
        }
    };

    NoteBox.prototype.gridKey = function (e, plain) {
        var k = e.key;
        if (k === 'ArrowDown') { this.gridMove(1, 0); e.preventDefault(); }
        else if (k === 'ArrowUp') { this.gridMove(-1, 0); e.preventDefault(); }
        else if (k === 'ArrowLeft') { this.gridMove(0, -1); e.preventDefault(); }
        else if (k === 'ArrowRight') { this.gridMove(0, 1); e.preventDefault(); }
        else if (k === ' ' && plain) { this.gridToggle(); e.preventDefault(); }
        else if ((k === 'Enter' || k === 'Tab') && plain && !e.shiftKey) { this.acceptGrid(); e.preventDefault(); }
        else if (k === 'Escape') { this.closePop(); e.preventDefault(); e.stopPropagation(); }
        else if (k.length === 1 || k === 'Backspace') { this.closePop(); }
    };

    // The phone bar follows every redraw.
    var baseUpdate = NoteBox.prototype.update;
    NoteBox.prototype.update = function (typed) {
        baseUpdate.call(this, typed);
        if (this.writing()) this.updatePhoneBar();
    };

    // ---- start ----

    function start() {
        document.querySelectorAll('.zt-box').forEach(function (box) {
            if (!box.ztNoteBox) box.ztNoteBox = new NoteBox(box);
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();

    window.ZpmsNoteBox = {
        /** The note without its tag words (previews such as the first line of the patient note). */
        strip: function (text) { return T.strip(String(text || ''), spec); },
        refresh: start
    };
})();
