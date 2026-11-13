/*
 * Clinical tags -- the note boxes (docs/CLINICAL_TAGS_PLAN.md, sections 7-9,
 * phase 2). Each tagged note is a .zt-box holding the server-rendered reading
 * look (.zt-read) and the plain <textarea> that is actually saved (autosave via
 * web/js/loader.js, unchanged).
 *
 *  - Reading look: whenever the box does not hold the caret, and always for an
 *    account that cannot edit (the textarea is disabled). Tag words hidden,
 *    values in their ink colour.
 *  - Writing look: a click/tap or Tab into the box shows the textarea, caret on
 *    the clicked word. The textarea text is transparent; a "mirror" div with the
 *    same font, size, padding and wrapping sits exactly behind it and draws the
 *    coloured copy: grey tag words, a pale wash on the values of the sentence
 *    holding the caret, a pale underline on the others. Nothing in the mirror
 *    takes extra space, so letters never move.
 *  - Typing @ opens the tag menu (arrows, Enter/Tab, Esc; taps on a phone).
 *
 * Needs web/js/note-tags.js (the parser) and the #zt-spec JSON that
 * zpms_note_tags_assets() prints. Without either, the boxes stay plain
 * textareas. All text reaches the page through textContent, never innerHTML.
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
    var MENU_ID = 0;

    function isWord(c) { return c != null && WORD_RE.test(c); }

    /** UTF-16 index <-> character (code point) offset. */
    function cpOffset(text, index) { return Array.from(text.slice(0, index)).length; }
    function utf16Index(text, cp) { return Array.from(text).slice(0, cp).join('').length; }

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        return e;
    }

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
        this.menu = null;
        this.menuItems = [];
        this.menuIndex = 0;
        this.menuAt = -1;          // UTF-16 index of the '@' the menu belongs to
        this.dismissedAt = -1;     // an '@' whose menu was closed with Esc
        this.pointerDown = false;
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
            if (ta.getAttribute('aria-label') || ta.name) read.setAttribute('aria-label', ta.getAttribute('aria-label') || (ta.labels && ta.labels[0] ? ta.labels[0].textContent.trim() : ''));
        } else {
            this.box.classList.add('zt-readonly');
        }

        // A click/tap: caret on the clicked word. mousedown is stopped so the
        // reading div does not take the focus first (that is the keyboard path).
        read.addEventListener('mousedown', function (e) {
            if (!self.editable() || e.button !== 0) return;
            if (e.target.closest && e.target.closest('a')) return;
            e.preventDefault();
            var o = offsetFromPoint(read, e.clientX, e.clientY);
            self.enterWriting(o === null ? Array.from(ta.value).length : o);
        });
        read.addEventListener('focus', function () {
            if (self.editable()) self.enterWriting(Array.from(ta.value).length);
        });

        ta.addEventListener('input', function () { self.update(true); });
        ta.addEventListener('scroll', function () { self.mirror.scrollTop = ta.scrollTop; self.mirror.scrollLeft = ta.scrollLeft; });
        ta.addEventListener('keydown', function (e) { self.onKey(e); });
        ta.addEventListener('blur', function () { self.leaveWriting(); });
        ta.addEventListener('click', function () { self.update(false); });
        ta.addEventListener('keyup', function (e) {
            if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown'].indexOf(e.key) !== -1) self.update(false);
        });
        document.addEventListener('selectionchange', function () {
            if (document.activeElement === ta) self.update(false);
        });
        if (window.ResizeObserver) {
            new ResizeObserver(function () { if (self.writing()) self.layout(); }).observe(ta);
        }
        window.addEventListener('resize', function () { if (self.writing()) self.layout(); });
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
        this.closeMenu();
        this.dismissedAt = -1;
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
        // A scrollbar inside the textarea (a browser without field-sizing)
        // narrows its text: narrow the mirror's text the same way.
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

    /** Redraws the mirror; with typed = true also checks the @ menu. */
    NoteBox.prototype.update = function (typed) {
        if (!this.writing()) return;
        var ta = this.ta, text = ta.value, chars = Array.from(text);
        var caretIdx = ta.selectionStart, caret = cpOffset(text, caretIdx);
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
                var end = r.offset + Array.from(r.text).length;
                var here = r.offset < sentence[1] && end > sentence[0];   // a value of the caret's sentence
                cls = 'zt-v zt-c-' + tag.colour + (here ? ' zt-wash' : ' zt-under') + (r.row.valid ? '' : ' zt-bad');
            }
            // The caret marker (for placing the menu) goes inside its run.
            var start = r.offset, len = Array.from(r.text).length;
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
        // A trailing line break needs something after it to take up its line.
        if (text === '' || /[\n\r]$/.test(text)) m.appendChild(document.createTextNode('​'));
        this.layout();
        this.caretMarker = marker;
        this.checkMenu(typed, text, caretIdx);
    };

    // ---- the @ tag menu ----

    /** The '@' + letters right before the caret, if they could start a tag: {at, partial} or null. */
    NoteBox.prototype.tagPrefix = function (text, caretIdx) {
        var before = Array.from(text.slice(0, caretIdx)), k = before.length;
        while (k > 0 && /^[\p{L}]$/u.test(before[k - 1])) k--;
        if (k === 0 || before[k - 1] !== '@' || isWord(before[k - 2])) return null;
        if (k - before.length < -20) return null;
        var at = before.slice(0, k - 1).join('').length;
        return { at: at, partial: T.fold(before.slice(k).join('')) };
    };

    NoteBox.prototype.checkMenu = function (typed, text, caretIdx) {
        if (this.ta.selectionStart !== this.ta.selectionEnd) { this.closeMenu(); return; }
        var p = this.tagPrefix(text, caretIdx);
        if (!p || p.at === this.dismissedAt) { this.closeMenu(); return; }
        if (!typed && !this.menu) return;     // only typing opens it; moving the caret may close it
        var matches = TAG_KEYS.filter(function (key) {
            return spec.tags[key].names.some(function (n) { return T.fold(String(n)).indexOf(p.partial) === 0; });
        });
        if (!matches.length) { this.closeMenu(); return; }
        this.openMenu(p.at, matches);
    };

    NoteBox.prototype.openMenu = function (at, keys) {
        var self = this;
        if (!this.menu) {
            this.menu = el('ul', 'zt-menu');
            this.menu.id = 'zt-menu-' + (++MENU_ID);
            this.menu.setAttribute('role', 'listbox');
            this.menu.addEventListener('mousedown', function (e) { e.preventDefault(); });   // keep the caret in the box
            this.box.appendChild(this.menu);
            this.ta.setAttribute('aria-controls', this.menu.id);
            this.ta.setAttribute('aria-expanded', 'true');
        }
        var prevKey = this.menuItems[this.menuIndex] && this.menuAt === at ? this.menuItems[this.menuIndex] : null;
        this.menuAt = at;
        this.menuItems = keys;
        this.menuIndex = Math.max(0, prevKey ? keys.indexOf(prevKey) : 0);
        this.menu.textContent = '';
        keys.forEach(function (key, i) {
            var tag = spec.tags[key];
            var li = el('li', 'zt-menu-item zt-c-' + tag.colour);
            li.id = self.menu.id + '-' + i;
            li.setAttribute('role', 'option');
            li.appendChild(el('span', 'zt-menu-dot'));
            li.appendChild(el('span', 'zt-menu-label', tag.label));
            li.appendChild(el('span', 'zt-menu-name', '@' + tag.names[0]));
            li.addEventListener('click', function () { self.acceptMenu(key); });
            self.menu.appendChild(li);
        });
        this.highlight();
        this.placeMenu();
    };

    NoteBox.prototype.highlight = function () {
        var self = this;
        Array.prototype.forEach.call(this.menu.children, function (li, i) {
            var on = i === self.menuIndex;
            li.classList.toggle('is-active', on);
            li.setAttribute('aria-selected', on ? 'true' : 'false');
            if (on) {
                self.ta.setAttribute('aria-activedescendant', li.id);
                if (li.scrollIntoView) li.scrollIntoView({ block: 'nearest' });
            }
        });
    };

    /** Under the caret, inside the box, flipped above it if it would leave the window. */
    NoteBox.prototype.placeMenu = function () {
        if (!this.menu || !this.caretMarker) return;
        var boxRect = this.box.getBoundingClientRect(), mk = this.caretMarker.getBoundingClientRect();
        var lh = parseFloat(getComputedStyle(this.ta).lineHeight) || 20;
        var left = Math.max(0, Math.min(mk.left - boxRect.left, this.box.clientWidth - this.menu.offsetWidth));
        var top = mk.top - boxRect.top + lh;
        if (mk.top + lh + this.menu.offsetHeight > window.innerHeight && mk.top - this.menu.offsetHeight > 0) {
            top = mk.top - boxRect.top - this.menu.offsetHeight - 2;
        }
        this.menu.style.left = left + 'px';
        this.menu.style.top = top + 'px';
    };

    NoteBox.prototype.closeMenu = function () {
        if (!this.menu) return;
        this.menu.remove();
        this.menu = null;
        this.menuItems = [];
        this.menuAt = -1;
        this.ta.removeAttribute('aria-controls');
        this.ta.removeAttribute('aria-activedescendant');
        this.ta.setAttribute('aria-expanded', 'false');
    };

    /** Replaces "@partial" before the caret with the tag's main name and a space. */
    NoteBox.prototype.acceptMenu = function (key) {
        var ta = this.ta, at = this.menuAt, end = ta.selectionStart;
        if (at < 0) return;
        var insert = '@' + spec.tags[key].names[0];
        var after = ta.value.charAt(end);
        if (after === '' || !/\s/.test(after)) insert += ' ';
        this.closeMenu();
        ta.setRangeText(insert, at, end, 'end');
        if (after !== '' && /\s/.test(after)) ta.setSelectionRange(at + insert.length + 1, at + insert.length + 1);
        ta.dispatchEvent(new Event('input', { bubbles: true }));   // autosave + redraw
    };

    /** Keys are taken over only while the menu is showing; otherwise every key does what it always does. */
    NoteBox.prototype.onKey = function (e) {
        if (!this.menu || e.isComposing) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            var n = this.menuItems.length;
            this.menuIndex = (this.menuIndex + (e.key === 'ArrowDown' ? 1 : n - 1)) % n;
            this.highlight();
            e.preventDefault();
        } else if ((e.key === 'Enter' || e.key === 'Tab') && !e.shiftKey && !e.altKey && !e.ctrlKey && !e.metaKey) {
            this.acceptMenu(this.menuItems[this.menuIndex]);
            e.preventDefault();
        } else if (e.key === 'Escape') {
            this.dismissedAt = this.menuAt;
            this.closeMenu();
            e.preventDefault();
            e.stopPropagation();
        }
    };

    // ---- start ----

    var boxes = [];
    function start() {
        document.querySelectorAll('.zt-box').forEach(function (box) {
            if (!box.ztNoteBox) { box.ztNoteBox = new NoteBox(box); boxes.push(box.ztNoteBox); }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();

    window.ZpmsNoteBox = {
        /** The note without its tag words (previews such as the first line of the patient note). */
        strip: function (text) { return T.strip(String(text || ''), spec); },
        refresh: start
    };
})();
