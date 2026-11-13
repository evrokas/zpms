/*
 * Clinical tags in the note boxes -- the parser, browser side
 * (docs/CLINICAL_TAGS_PLAN.md). The same rules as zpms_note_parse() in
 * web/note_tags.php, written step by step the same way; both run the test
 * cases in tests/note_tags/cases.json, so keep them in step.
 *
 * Phase 1 only provides the parser (window.ZpmsNoteTags.parse); the note box
 * uses it from phase 2 on. Also loadable from node (module.exports) for the
 * test suite.
 *
 * Positions are in characters (code points), like the PHP side, not UTF-16
 * units.
 */
(function (root) {
    'use strict';

    var FOLD = { 'ά': 'α', 'έ': 'ε', 'ή': 'η', 'ί': 'ι', 'ό': 'ο', 'ύ': 'υ', 'ώ': 'ω',
        'ϊ': 'ι', 'ϋ': 'υ', 'ΐ': 'ι', 'ΰ': 'υ', 'ς': 'σ' };
    var SPACE = '[ \\t\\n\\r\\f\\v\\u00A0\\u2028\\u2029]';
    var SPACE_RE = new RegExp('^' + SPACE + '$', 'u');
    var SPACES_RE = new RegExp(SPACE + '+', 'gu');
    var TRIM_RE = new RegExp('^' + SPACE + '+|' + SPACE + '+$', 'gu');
    var WORD_RE = /^[\p{L}\p{N}_]$/u;

    function foldChar(c) {
        var l = c.toLowerCase();
        if (Array.from(l).length !== 1) l = c;
        return FOLD[l] || l;
    }
    function fold(s) { return Array.from(s).map(foldChar).join(''); }
    function isWord(c) { return c != null && WORD_RE.test(c); }
    function isSpace(c) { return c != null && SPACE_RE.test(c); }
    function isInlineSpace(c) { return c === ' ' || c === '\t' || c === ' '; }
    function isNewline(c) { return c === '\n' || c === '\r' || c === ' ' || c === ' '; }
    function isSemicolon(c) { return c === ';' || c === '·' || c === '·' || c === ';'; }
    function isDigit(c) { return c != null && c.length === 1 && c >= '0' && c <= '9'; }
    function trim(s) { return s.replace(TRIM_RE, ''); }
    function decimalComma(chars, k) { return k > 0 && isDigit(chars[k - 1]) && isDigit(chars[k + 1]); }
    function normalizeText(s) { return trim(fold(s).replace(SPACES_RE, ' ')); }

    // A closing bracket or quote with no opener inside the value ends it;
    // openers left at the end of a value are dropped.
    var CLOSERS = { ')': '(', ']': '[', '»': '«' };
    var OPENERS = ['(', '[', '«'];

    var REGION = { 'α': 'C', 'a': 'C', 'c': 'C', 'θ': 'T', 't': 'T',
        'ο': 'L', 'o': 'L', 'l': 'L', 'ι': 'S', 'i': 'S', 's': 'S' };
    var REGION_MAX = { C: 7, T: 12, L: 5, S: 1 };
    var REGION_BASE = { C: 0, T: 7, L: 19, S: 24 };

    function Parser(text, spec, drugs) {
        this.c = Array.from(text || '');
        this.f = this.c.map(foldChar);
        this.n = this.c.length;
        this.tags = spec.tags;
        this.drugs = drugs || [];
        var names = [];
        Object.keys(this.tags).forEach(function (key) {
            this.tags[key].names.forEach(function (name) { names.push([Array.from(fold(String(name))), key]); });
        }, this);
        // Stable sort, longest first (same order as PHP's usort on equal lengths: definition order).
        names = names.map(function (x, i) { return [x, i]; })
            .sort(function (a, b) { return (b[0][0].length - a[0][0].length) || (a[1] - b[1]); })
            .map(function (x) { return x[0]; });
        this.names = names;
    }

    Parser.prototype.at = function (i) { return i >= 0 && i < this.n ? this.c[i] : null; };
    Parser.prototype.slice = function (s, e) { return this.c.slice(s, e).join(''); };

    Parser.prototype.tagAt = function (i) {
        if (this.at(i) !== '@' || isWord(this.at(i - 1))) return null;
        for (var x = 0; x < this.names.length; x++) {
            var chars = this.names[x][0], l = chars.length, ok = true;
            if (i + 1 + l > this.n) continue;
            for (var k = 0; k < l; k++) {
                if (this.f[i + 1 + k] !== chars[k]) { ok = false; break; }
            }
            if (ok && !isWord(this.at(i + 1 + l))) return [this.names[x][1], i + 1 + l];
        }
        return null;
    };

    Parser.prototype.valueEnd = function (j, commaAllowed) {
        var k = j, open = { '(': 0, '[': 0, '«': 0 };
        while (k < this.n) {
            var ch = this.c[k];
            if (isNewline(ch) || isSemicolon(ch)) break;
            if (Object.prototype.hasOwnProperty.call(open, ch)) open[ch]++;
            if (Object.prototype.hasOwnProperty.call(CLOSERS, ch)) {
                if (open[CLOSERS[ch]] === 0) break;   // closes a bracket opened before the tag
                open[CLOSERS[ch]]--;
            }
            if (ch === ',' && !commaAllowed && !decimalComma(this.c, k)) break;
            if (ch === '.' && (k + 1 >= this.n || isSpace(this.c[k + 1]))) break;
            if (ch === '@' && this.tagAt(k) !== null) break;
            k++;
        }
        var e = k;
        while (e > j && (isSpace(this.c[e - 1]) || OPENERS.indexOf(this.c[e - 1]) !== -1)) e--;
        return [e, k];
    };

    Parser.prototype.parse = function () {
        var out = [], i = 0;
        while (i < this.n) {
            var t = this.tagAt(i);
            if (t === null) { i++; continue; }
            var key = t[0], nameEnd = t[1], j = nameEnd;
            while (isInlineSpace(this.at(j))) j++;
            if (this.at(j) === ':') {
                j++;
                while (isInlineSpace(this.at(j))) j++;
            }
            var type = this.tags[key].value, next = nameEnd, e, seq;
            if (type === 'levels') {
                var limit = this.valueEnd(j, true)[0];
                seq = this.levelSequence(j, limit);
                if (seq !== null) {
                    out.push({ tag: key, value: this.slice(j, seq.end), normalized: seq.normalized, valid: seq.valid,
                        pos: i, start: j, end: seq.end, origin: null, rx: null });
                    next = seq.end;
                } else {
                    e = this.valueEnd(j, false)[0];
                    if (e > j) {
                        out.push({ tag: key, value: this.slice(j, e), normalized: null, valid: false,
                            pos: i, start: j, end: e, origin: null, rx: null });
                    }
                    next = Math.max(nameEnd, e);
                }
            } else {
                e = this.valueEnd(j, type === 'prescription')[0];
                if (e > j) {
                    var value = this.slice(j, e);
                    var row = { tag: key, value: value, normalized: normalizeText(value), valid: true,
                        pos: i, start: j, end: e, origin: null, rx: null };
                    if (type === 'prescription') row.rx = parseRx(value, this.drugs);
                    var outer = out.length;
                    out.push(row);
                    if (this.tags[key].inner_levels) {
                        var p = j;
                        while (p < e) {
                            seq = this.levelSequence(p, e);
                            if (seq === null) { p++; continue; }
                            out.push({ tag: 'lvl', value: this.slice(p, seq.end), normalized: seq.normalized, valid: seq.valid,
                                pos: p, start: p, end: seq.end, origin: outer, rx: null });
                            p = seq.end;
                        }
                    }
                }
                next = Math.max(nameEnd, e);
            }
            i = next;
        }
        return out;
    };

    /**
     * The tagged field the caret (character offset) is in, for the editing
     * aids: {tag, pos, nameEnd, start, end, stop, value} -- pos..nameEnd is the
     * @name, start..end the value (trailing spaces left out), stop where the
     * value would end if typing went on. inTag is true while the caret is on
     * the @name itself. null when the caret is in plain text.
     */
    Parser.prototype.contextAt = function (caret) {
        var i = 0;
        while (i < this.n) {
            var t = this.tagAt(i);
            if (t === null) { i++; continue; }
            var key = t[0], nameEnd = t[1], j = nameEnd;
            while (isInlineSpace(this.at(j))) j++;
            if (this.at(j) === ':') { j++; while (isInlineSpace(this.at(j))) j++; }
            var type = this.tags[key].value;
            var r = this.valueEnd(j, type !== 'text');
            var e = r[0], stop = r[1];
            if (type === 'levels') {
                var seq = this.levelSequence(j, e);
                if (seq === null) { var r2 = this.valueEnd(j, false); e = r2[0]; stop = r2[1]; }
            }
            if (caret > i && caret <= stop && caret >= nameEnd) {
                if (caret < j) caret = j;
                return { tag: key, pos: i, nameEnd: nameEnd, start: j, end: Math.max(j, e), stop: stop,
                    value: this.slice(j, Math.max(j, e)), inTag: false };
            }
            if (caret > i && caret < nameEnd) {
                return { tag: key, pos: i, nameEnd: nameEnd, start: j, end: Math.max(j, e), stop: stop,
                    value: this.slice(j, Math.max(j, e)), inTag: true };
            }
            i = Math.max(nameEnd, stop);
        }
        return null;
    };

    Parser.prototype.number = function (p, lim) {
        var k = p;
        while (k < lim && k - p < 2 && isDigit(this.c[k])) k++;
        if (k === p || (k < lim && isDigit(this.c[k]))) return null;
        return [parseInt(this.slice(p, k), 10), k];
    };

    Parser.prototype.levelToken = function (p, lim) {
        if (p >= lim || isWord(this.at(p - 1))) return null;
        var r1 = REGION[this.f[p]] || null;
        if (r1 === null) return null;
        var n1 = this.number(p + 1, lim);
        if (n1 === null) return null;
        var tok = { r1: r1, n1: n1[0], r2: null, n2: null, end: n1[1] };
        var q = n1[1];
        if (q < lim && ['-', '/', '–', '—'].indexOf(this.c[q]) !== -1) {
            var r = q + 1;
            var r2 = r < lim ? (REGION[this.f[r]] || null) : null;
            var n2 = this.number(r2 !== null ? r + 1 : r, lim);
            if (n2 !== null) tok = { r1: r1, n1: n1[0], r2: r2 || r1, n2: n2[0], end: n2[1] };
        }
        if (isWord(this.at(tok.end))) return null;
        return tok;
    };

    function levelValid(r, n) { return n >= 1 && n <= REGION_MAX[r]; }

    Parser.prototype.levelSequence = function (p, lim) {
        var tok = this.levelToken(p, lim);
        if (tok === null) return null;
        var parts = [], valid = true, end = p;
        while (tok !== null) {
            end = tok.end;
            if (tok.r2 === null) {
                parts.push(tok.r1 + tok.n1);
                valid = valid && levelValid(tok.r1, tok.n1);
            } else {
                parts.push(tok.r1 + tok.n1 + '-' + tok.r2 + tok.n2);
                var ok = levelValid(tok.r1, tok.n1) && levelValid(tok.r2, tok.n2)
                    && REGION_BASE[tok.r2] + tok.n2 > REGION_BASE[tok.r1] + tok.n1;
                valid = valid && ok;
            }
            var q = end;
            while (q < lim && isInlineSpace(this.c[q])) q++;
            if (q < lim && (this.c[q] === ',' || this.c[q] === '&')) {
                q++;
            } else if (q + 3 <= lim && this.f[q] === 'κ' && this.f[q + 1] === 'α' && this.f[q + 2] === 'ι'
                       && !isWord(this.at(q + 3))) {
                q += 3;
            } else {
                break;
            }
            while (q < lim && isInlineSpace(this.c[q])) q++;
            tok = this.levelToken(q, lim);
        }
        return { end: end, normalized: parts.join(', '), valid: valid };
    };

    function cpLen(s) { return Array.from(s).length; }

    function parseRx(value, drugs) { return parseRxFull(value, drugs).rx; }

    /**
     * parseRx() plus where each part sits in the value (character ranges), for
     * the prescription blanks: {rx, spans: {drug, strength, per_day, days, note}, comma}.
     * Browser only (not part of the shared PHP/JS contract).
     */
    function parseRxFull(value, drugs) {
        var spans = { drug: null, strength: null, per_day: null, days: null, note: null };
        var rx = { drug: null, strength: null, per_day_text: null, per_dose: null,
            per_day: null, days: null, note: null, total: null };
        var chars = Array.from(value), comma = -1, k, q;
        for (k = 0; k < chars.length; k++) {
            if (chars[k] === ',' && !decimalComma(chars, k)) { comma = k; break; }
        }
        var mainChars = comma === -1 ? chars : chars.slice(0, comma);
        var tail = comma === -1 ? '' : trim(chars.slice(comma + 1).join(''));
        var folded = fold(mainChars.join(''));
        var n = mainChars.length;
        var used = mainChars.map(function () { return false; });

        function find(source) {
            var re = new RegExp(source, 'gdu'), m;
            re.lastIndex = 0;
            while ((m = re.exec(folded)) !== null) {
                var s = cpLen(folded.slice(0, m.index)), e = s + cpLen(m[0]), free = true;
                for (var x = s; x < e; x++) if (used[x]) { free = false; break; }
                if (free && e > s) {
                    for (x = s; x < e; x++) used[x] = true;
                    var groups = {};
                    for (var gi = 1; gi < m.length; gi++) {
                        groups[gi] = m.indices[gi] ? [cpLen(folded.slice(0, m.indices[gi][0])), cpLen(m[gi])] : null;
                    }
                    return [s, e, groups];
                }
                re.lastIndex = m.index + Math.max(1, m[0].length);
                if (re.lastIndex > folded.length) break;
            }
            return null;
        }
        function cut(s, e) { return mainChars.slice(s, e).join(''); }
        function group(m, gi) { return m[2][gi] === null ? null : cut(m[2][gi][0], m[2][gi][0] + m[2][gi][1]); }
        function num(s) { return s === '½' ? 0.5 : (s === '¼' ? 0.25 : parseFloat(s.replace(',', '.'))); }
        var sp = SPACE, nb = '(?<![\\p{L}\\p{N}])', na = '(?![\\p{L}\\p{N}])', m;

        if ((m = find(nb + '([0-9]+(?:[.,][0-9]+)?|½|¼)' + sp + '*[xχ×]' + sp + '*([0-9]+)' + na))) {
            rx.per_day_text = cut(m[0], m[1]).replace(SPACES_RE, '');
            spans.per_day = [m[0], m[1]];
            rx.per_dose = num(group(m, 1));
            rx.per_day = parseInt(group(m, 2), 10);
        } else if ((m = find(nb + '([0-9]+)' + sp + '*φορ(?:εσ|α)' + na))) {
            rx.per_day_text = cut(m[0], m[1]);
            spans.per_day = [m[0], m[1]];
            rx.per_dose = 1;
            rx.per_day = parseInt(group(m, 1), 10);
        } else if ((m = find(nb + 'επι' + sp + '+πονου' + na))) {
            rx.per_day_text = cut(m[0], m[1]);
            spans.per_day = [m[0], m[1]];
        }
        if ((m = find(nb + '(?:για' + sp + '+)?([0-9]+)' + sp + '*(?:ημερεσ|ημερα|ημ|μερεσ|days|day|d)' + na))) {
            rx.days = parseInt(group(m, 1), 10);
            spans.days = [m[0], m[1]];
        } else if ((m = find('(?<![\\p{L}\\p{N}½¼])[xχ×]' + sp + '*([0-9]+)' + na))) {
            rx.days = parseInt(group(m, 1), 10);
            spans.days = [m[0], m[1]];
        } else if ((m = find(nb + 'συνεχωσ' + na))) {
            spans.days = [m[0], m[1]];   // continuously: no number of days, nothing to store
        }
        if ((m = find(nb + '([0-9]+(?:[.,][0-9]+)?)' + sp + '*(mg|mcg|μg|μγ|gr|g|ml|iu)' + na))) {
            rx.strength = group(m, 1) + group(m, 2);
            spans.strength = [m[0], m[1]];
        }

        var drugEnd = 0, unit = 'mg', best = null;
        drugs.forEach(function (d) {
            [d.text].concat(d.aliases || []).forEach(function (name) {
                var fn = fold(trim(String(name))), l = cpLen(fn);
                if (l === 0 || Array.from(folded).slice(0, l).join('') !== fn || isWord(mainChars[l])) return;
                if (best === null || l > best[1]) best = [d, l];
            });
        });
        if (best !== null) {
            rx.drug = best[0].text;
            unit = best[0].unit || 'mg';
            drugEnd = best[1];
        } else {
            k = 0;
            while (k < n && !used[k]) k++;
            var drug = trim(cut(0, k));
            rx.drug = drug !== '' ? drug : null;
            drugEnd = k;
        }
        for (k = 0; k < drugEnd; k++) used[k] = true;
        if (drugEnd > 0) spans.drug = [0, drugEnd];

        if (rx.strength === null) {
            k = drugEnd;
            while (k < n && isSpace(mainChars[k])) k++;
            var e = k;
            while (e < n && !isSpace(mainChars[e]) && !used[e]) e++;
            var tok = cut(k, e);
            if (e > k && /^[0-9]+(?:[.,][0-9]+)?$/.test(tok) && (e >= n || isSpace(mainChars[e]))) {
                rx.strength = tok + unit;
                spans.strength = [k, e];
                for (q = k; q < e; q++) used[q] = true;
            }
        }

        var left = '';
        for (k = 0; k < n; k++) left += used[k] ? ' ' : mainChars[k];
        left = trim(left.replace(SPACES_RE, ' '));
        var notes = [left, tail].filter(function (s) { return s !== ''; });
        rx.note = notes.length ? notes.join(', ') : null;

        if (rx.per_dose !== null && rx.per_day !== null && rx.days !== null) {
            rx.total = Math.round(rx.per_dose * rx.per_day * rx.days * 100) / 100;
        }
        if (comma !== -1) {
            var ns = comma + 1;
            while (ns < chars.length && isSpace(chars[ns])) ns++;
            spans.note = [ns, chars.length];
        }
        return { rx: rx, spans: spans, comma: comma };
    }

    /** The note cut into runs: {text, offset, kind: 'text'|'tag'|'value', row} -- zpms_note_runs() in PHP. */
    function runs(text, spec) {
        var chars = Array.from(String(text || '')), rows = new Parser(chars.join(''), spec, []).parse();
        var kind = chars.map(function () { return null; }), k;
        rows.forEach(function (r, i) {
            if (r.origin === null) for (k = r.pos; k < r.start; k++) kind[k] = -1;
            for (k = r.start; k < r.end; k++) kind[k] = i;
        });
        var out = [], n = chars.length;
        for (k = 0; k < n;) {
            var e = k;
            while (e < n && kind[e] === kind[k]) e++;
            out.push({ text: chars.slice(k, e).join(''), offset: k,
                kind: kind[k] === null ? 'text' : (kind[k] === -1 ? 'tag' : 'value'),
                row: kind[k] !== null && kind[k] >= 0 ? rows[kind[k]] : null });
            k = e;
        }
        return out;
    }

    /** The note with the tag words left out -- zpms_note_strip() in PHP. */
    function strip(text, spec) {
        return runs(text, spec).filter(function (r) { return r.kind !== 'tag'; }).map(function (r) { return r.text; }).join('');
    }

    var api = {
        runs: runs,
        strip: strip,
        fold: fold,
        normalizeText: normalizeText,
        /** One entry per tagged value -- see zpms_note_parse() in web/note_tags.php. */
        parse: function (text, spec, drugs) { return new Parser(String(text || ''), spec, drugs).parse(); },
        parseRx: parseRx,
        parseRxFull: parseRxFull,
        /** The tagged field at a caret position (characters) -- see Parser.contextAt(). */
        context: function (text, caret, spec) { return new Parser(String(text || ''), spec, []).contextAt(caret); }
    };

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.ZpmsNoteTags = api;
    }
})(typeof window !== 'undefined' ? window : this);
