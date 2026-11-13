/*
 * Browser-only helpers of web/js/note-tags.js used by the note-box editing aids
 * (phase 3): context() -- which tagged field the caret is in -- and the
 * character spans of a prescription's parts (the blanks). Exits non-zero with a
 * message on the first failure. Run by tests/static/note_tags.php.
 */
'use strict';
const path = require('path');
const T = require(path.join(__dirname, '..', '..', 'web', 'js', 'note-tags.js'));
const spec = { tags: {
    dx: { names: ['δγ', 'διάγνωση'], value: 'text' },
    lvl: { names: ['επ'], value: 'levels' },
    rx: { names: ['συν'], value: 'prescription' },
    proc: { names: ['χειρ'], value: 'text', inner_levels: true },
} };
let failed = 0;
function eq(name, want, got) {
    const a = JSON.stringify(want), b = JSON.stringify(got);
    if (a !== b) { console.log(`FAIL ${name}\n  want ${a}\n  got  ${b}`); failed++; }
}
const t = 'Η μαγνητική @δγ κήλη, στο @επ Ο4-Ο5 και  με. @συν Lyrica 75mg 1x2 για 30 ημέρες, μετά. @δγ ';
const pick = (c) => c && { tag: c.tag, start: c.start, end: c.end, value: c.value, inTag: c.inTag };
eq('caret at the start of a value', { tag: 'dx', start: 16, end: 20, value: 'κήλη', inTag: false }, pick(T.context(t, 16, spec)));
eq('caret at the end of a value', 'dx', T.context(t, 20, spec).tag);
eq('caret on the tag word', true, T.context(t, 13, spec).inTag);
eq('caret in plain text', null, T.context(t, 5, spec));
eq('caret after the comma that ended the value', null, T.context(t, 22, spec));
eq('prescription: commas belong to the value', { tag: 'rx', value: 'Lyrica 75mg 1x2 για 30 ημέρες, μετά' }, (({ tag, value }) => ({ tag, value }))(T.context(t, 60, spec)));
eq('empty value right after "@δγ "', { tag: 'dx', start: 91, end: 91, value: '', inTag: false }, pick(T.context(t, t.length, spec)));
eq('caret right after the tag name', { tag: 'dx', start: 3, end: 3, value: '', inTag: false }, pick(T.context('@δγ', 3, spec)));
eq('an e-mail address is no field', null, T.context('γράψτε στο a@δγ.gr', 14, spec));

const drugs = [{ text: 'Lyrica', aliases: ['λυρικα'], unit: 'mg' }];
const full = T.parseRxFull('Lyrica 75mg 1x2 για 30 ημέρες, μετά το φαγητό', drugs);
eq('rx spans', { drug: [0, 6], strength: [7, 11], per_day: [12, 15], days: [16, 29], note: [31, 45] }, full.spans);
eq('rx parts unchanged', 60, full.rx.total);
eq('bare strength span', [7, 10], T.parseRxFull('λυρικα 150 1χ2 x30', drugs).spans.strength);
eq('συνεχώς is the days blank', [16, 23], T.parseRxFull('Lyrica 75mg 1x2 συνεχώς', drugs).spans.days);
eq('empty note after ", "', [31, 31], T.parseRxFull('Lyrica 75mg 1x2 για 30 ημέρες, ', drugs).spans.note);
eq('parseRx() still returns only the parts', false, 'spans' in T.parseRx('Lyrica 75mg', drugs));

if (failed) { console.log(failed + ' failure(s)'); process.exit(1); }
console.log('ok');
