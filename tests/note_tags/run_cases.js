/*
 * Runs web/js/note-tags.js over every test case and prints the results as
 * JSON (one array of rows per case). Called by tests/static/note_tags.php,
 * which compares them with the expectations and with the PHP parser's own
 * results. Usage: node run_cases.js <spec.json> <drugs.json> <cases.json>
 */
'use strict';
const fs = require('fs');
const path = require('path');
const tags = require(path.join(__dirname, '..', '..', 'web', 'js', 'note-tags.js'));

const [specFile, drugsFile, casesFile] = process.argv.slice(2);
const spec = JSON.parse(fs.readFileSync(specFile, 'utf8'));
const drugs = JSON.parse(fs.readFileSync(drugsFile, 'utf8'));
const cases = JSON.parse(fs.readFileSync(casesFile, 'utf8')).cases;
process.stdout.write(JSON.stringify(cases.map((c) => ({
    rows: tags.parse(c.text, spec, drugs),
    runs: tags.runs(c.text, spec).map((r) => [r.text, r.offset, r.kind]),
    plain: tags.strip(c.text, spec),
}))));
