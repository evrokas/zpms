# Clinical tags in the note boxes -- plan (approved design, nothing built yet)

Status: design approved by the user after several mockup rounds, 2026-11-13. Implementation
not started. This file is the complete specification: a new session should be able to build
it from this file alone. Visual mockups (same content, drawn): the "ZPMS Tag Styles" page,
https://claude.ai/artifact/46PGs1Y4w3avRscQd7ZYyy, and the plan page "ZPMS Clinical Tags",
https://claude.ai/artifact/41Y52Wzz9m12Q42jZVetPk. Both are also saved in this repository as
SVG images, so they survive without the links: `docs/clinical-tags/mockup.svg` (the final
mockup) and `docs/clinical-tags/plan.svg` (the plan page). Open them in a browser or GitHub's
file view (they hold the page itself in a `<foreignObject>`, fonts embedded; text stays
selectable). Read them for the look; this file wins if they ever disagree.

Start with **phase 1** (section 14). Keep this file updated as phases land (mark them done,
note deviations), like the other `docs/*_PLAN.md` files.

## 1. The idea

While writing an ordinary clinical note, the doctor marks facts inline with a short Greek tag
and keeps writing:

> Η μαγνητική δείχνει @δγ κήλη μεσοσπονδυλίου δίσκου, στο @επ Ο4-Ο5 με πίεση της ρίζας.

The note stays plain, readable prose (it is saved exactly as typed). ZPMS also extracts every
tagged value into its own table, so the patient record can show diagnoses, levels, allergies,
the plan and medication at a glance, and the patients list can be filtered by them.

Design principles the user insisted on:
- Text must flow while typing; the paragraph must stay as readable as possible.
- **No chips or boxes inside the text.** Only pale colour, never strong colour.
- Everything typed on a **Greek keyboard** (tags, lists, drug lookup); English still accepted.
- Suggestions like Epic SmartLists: fixed lists plus the user's previous entries, to keep data
  consistent and typing fast. Free text is always allowed.

Where tags work: **appointment notes** (`appointments.anote`) and **the patient note**
(`patients.pnote`). Not the home-page personal notes (`user_notes`): those are not about one
patient.

## 2. Syntax

- A tag is `@` + a known tag name, at the start of the text or after whitespace/punctuation.
  An e-mail address (`a@b.gr`) or a lone `@` is never a tag.
- Tag names are matched ignoring case and Greek accents (`@ΔΙΑΓΝΩΣΗ` = `@διαγνωση` = `@δγ`).
- The value starts after the tag name and following spaces, and ends at the first of:
  - `,` (except for `prescription`, see below)
  - semicolon: Greek `·` (U+0387 / U+00B7) or `;`
  - a full stop followed by whitespace or end of text (a `.` inside a number, `0.5mg`, ends nothing)
  - a line break
  - the next tag
  - end of text
- Value type `levels` ends by itself after the last level token (and `και`/`,`-joined levels),
  so «στο @επ Ο4-Ο5 με πίεση» takes only «Ο4-Ο5».
- Value type `prescription` may contain commas; it ends at a semicolon, a full stop + space,
  a line break, the next tag, or end of text.
- An empty value (tag followed directly by punctuation) is ignored.

## 3. Tag definitions (not ad hoc)

Two layers:

1. **Value types** -- code, few: `text`, `levels`, `prescription`. Each owns its parser (end
   rule, normalisation), its picker and its tests. Possible later types: a period/date
   (`@επαν 3 μήνες` -> follow-up reminder), a score (`@vas 7/10`).
2. **Tag definitions** -- one config file, no code: `config/note_tags.yaml`, committed and
   changed through a normal update (decided by the user, 2026-11-13; a Settings page to edit
   tags can come later if tags are added often). Everything (parser, menus, colours, summary, filters,
   Settings list page) reads only this.

Rules that keep old notes valid:
- `note_fields` stores the **internal key** (`dx`), never the typed name.
- Names are only ever **added**, never removed (old notes contain «@δγ» forever).
- After any definition change, the rebuild command re-parses every note.

The nine tags (`names`: the first is the main short Greek tag shown in menus; all accepted):

```yaml
tags:
  dx:   { label: Διάγνωση,        names: [δγ, διάγνωση, dx, diagnosis],        colour: rose,     family: findings,   value: text,         picker: list,      summary: true, search: true }
  lvl:  { label: Επίπεδα,         names: [επ, επίπεδα, lvl, levels],           colour: lavender, family: findings,   value: levels,       picker: grid,      summary: true, search: true }
  alg:  { label: Αλλεργία,        names: [αλ, αλλεργία, alg, allergy],         colour: orange,   family: warning,    value: text,         picker: list,      summary: true, header_alert: true }
  exam: { label: Εξέταση,         names: [εξ, εξέταση, exam],                  colour: sky,      family: plan,       value: text,         picker: checklist, summary: true, next_words: true }
  proc: { label: Επέμβαση,        names: [χειρ, επέμβαση, proc, procedure],    colour: mint,     family: plan,       value: text,         picker: list,      summary: true, search: true }
  thx:  { label: Θεραπεία,        names: [θερ, θεραπεία, thx, therapy],        colour: teal,     family: plan,       value: text,         picker: list,      summary: true, next_words: true }
  pt:   { label: Φυσικοθεραπεία,  names: [φθ, φυσικοθεραπεία, pt],             colour: sage,     family: plan,       value: text,         picker: list,      summary: true, next_words: true }
  med:  { label: Αγωγή,           names: [αγ, αγωγή, med],                     colour: sand,     family: medication, value: text,         picker: list,      summary: true }
  rx:   { label: Συνταγή,         names: [συν, συνταγή, rx, prescription],     colour: amber,    family: medication, value: prescription, picker: blanks,    summary: true }
```

`@αγ` (recommended treatment, general) is deliberately separate from `@συν` (a specific drug
prescribed today).

Pale colours (light theme; tint = wash, mark = underline, ink = reading text):

| key | tint | mark | ink |
|---|---|---|---|
| dx | #fbebea | #e6b2ac | #8c3b34 |
| lvl | #efebfa | #c4b6ea | #594796 |
| alg | #fde8dc | #efb08a | #9a4a17 |
| exam | #e6f0fb | #a9c6ea | #2f5e8f |
| proc | #e5f4ec | #a6d8bd | #2b6a4e |
| thx | #e2f3f3 | #9fd3d3 | #22686a |
| pt | #eef3dd | #c5d58f | #57661e |
| med | #f8f0df | #e0c88f | #7c5b1a |
| rx | #fbecd6 | #e8bf7f | #855212 |

## 4. Levels (value type `levels`)

Greek and English notation both accepted, mixed, any case, `-` or `/`, short forms:
`Ο4-Ο5`, `ο4-5`, `Ο4/Ο5`, `L4-L5`, `l4-5`, `Ο5-Ι1`, `Α5-Α6`, `Θ12`, `Θ12-Ο1`.
Regions: Α = αυχενική = C (1-7), Θ = θωρακική = T (1-12), Ο = οσφυϊκή = L (1-5), Ι = ιερό = S (1).
Watch Greek capital Ο/Α/Ι vs Latin O/A/I (treat Latin O/A/I as their Greek region letters too
where unambiguous, e.g. `O4-O5`). Multiple: `Ο4-Ο5 και Ο5-Ι1`, `C5-C6, C6-C7`.

- The note keeps exactly what was typed.
- `note_fields.normalized` stores the English form (`L4-L5`; multiple joined with `, `) for
  search and the summary.
- Impossible levels (`Ο6`, `Α9`) are kept, marked invalid (`valid = 0`), shown with a quiet
  warning while editing, left out of the summary. A save is never refused.
- The grid picker shows the region's segments; cells are written in the notation the user
  last typed (default Greek).

## 5. Prescriptions (value type `prescription`)

One readable phrase with up to five parts:
`Lyrica 75mg 1x2 για 30 ημέρες, μετά το φαγητό`

| Part | Picker list | Read from free typing |
|---|---|---|
| drug | drug list (written as on the box: Lyrica), found by Greek spelling (`λυρ`) | first word(s), matched to the list or kept as typed |
| strength | that drug's strengths (Lyrica: 25, 75, 150, 300mg) | number + unit (mg, μγ, mcg, g, ml); `0.5` and `0,5` |
| per_day | 1x1, 1x2, 1x3, ½x2, επί πόνου | `1x2`, `1χ2` (Greek χ = x), `2 φορές`, `επί πόνου` |
| days | 7, 14, 30, 60, συνεχώς | `για 30 ημέρες`, `30 ημ`, `x30` |
| note (optional) | μετά το φαγητό, πριν το φαγητό, πριν τον ύπνο, με σταδιακή αύξηση + own | text after the comma; anything not placed elsewhere |

- Picking a drug writes the phrase with blanks; Tab / Shift+Tab move between blanks; each blank
  has its own short list; any value can be typed. An empty note blank disappears.
- Total doses = doses per day x days (2 x 30 = 60), shown in the bar while writing and in the
  summary when computable.
- Two drugs in one sentence: separate with `·` (or `;`).
- Record only; no connection to e-prescribing.

## 6. Suggestions

Sources, in this order:
1. **Fixed list** for the tag (`note_list_entries`, seeded with the starter lists in section 13,
   editable in Settings). Marked as list wording.
2. **Previous entries** for the tag from `note_fields` (only notes the user may read), most
   used first, with a count.
3. **Next words** per list entry (e.g. after «μαγνητική τομογραφία»: ΟΜΣΣ, ΑΜΣΣ, ΘΜΣΣ,
   εγκεφάλου, με σκιαγραφικό), plus words previously written after that entry.

All matching ignores case and Greek accents; drug names also match their Greek spelling
(`aliases` column).

Interaction:
- Typing `@` opens the **tag menu** (all nine, coloured, filter by any name).
- **Ghost completion (default):** best match in faint grey after the caret.
  **Tab takes the next word only** (never the whole suggestion by default); **→ (at end of
  text) takes the rest**; typing anything else ignores it.
- **↓** opens the full list (fixed first, then previous).
- **Checklist** (exams) and **grid** (levels): several at once; written into the sentence as
  text («μαγνητική τομογραφία ΟΜΣΣ, ηλεκτρομυογράφημα κάτω άκρων», «Ο3-Ο4 και Ο4-Ο5»).
- **Blanks** for prescriptions (section 5).
- **Next-word strip** under the line after exam / physiotherapy / therapy entries; a number
  key or click inserts the word.

Keyboard, same everywhere:

| Key | Lists (one choice) | Checklist / grid (several) | Blanks |
|---|---|---|---|
| ↑ ↓ | move highlight | move highlight (grid: rows) | move in the blank's list |
| ← → | back to text (→ takes suggestion) | grid: move along the row | back to text |
| Space | keeps typing (entries contain spaces) | **tick / untick** | keeps typing |
| Enter | **accept** highlighted | **accept**: write all ticked | accept, next blank |
| Tab | take next word of the suggestion | accept | next blank (Shift+Tab back) |
| Esc | close, no change | close, nothing written | leave blanks as they are |

Highlighted row: pale teal background; grid: teal ring on the current cell, ticked cells lilac.
Mouse clicks / taps = Space (tick) or Enter (pick).

**Phone** (no Tab/arrows): the suggestion appears as word buttons in a bar just above the
keyboard; tapping a word takes the suggestion up to that word, «✓ όλο» takes all; tapping the
grey ghost text does the same. Lists, checklists and blanks use the same bar, with
«επόμενο ›» instead of Tab.

## 7. Writing look

- The tag word (`@δγ`) is always faint grey (`#a7b4b7` light theme).
- **The sentence holding the caret**: each value gets its pale wash (tint), drawn with
  `box-shadow: 0 0 0 2px <tint>` + background, **never padding** (letters must not move).
- **Finished sentences**: only a very pale thin underline under each value
  (`text-decoration: underline 1.5px color-mix(in srgb, <mark> 55%, transparent)`,
  `text-underline-offset: 5px`).

Implementation: the note box **stays a plain `<textarea>`** (autosave via `web/js/loader.js`,
copy/paste and phone keyboards unchanged). A "mirror" div with identical font, size,
line-height, padding, width and wrapping sits exactly behind it and renders the coloured
copy; the textarea text is made transparent with a visible `caret-color`. Nothing in the
mirror may take extra space (no padding, no inline marks with width). Ghost text, menus,
checklist, grid and blanks are small hand-written scripts (no library), same family as the
patient-name search in `web/js/scripts.js`.

## 8. Editing an existing value

A click/tap puts the caret exactly where clicked; nothing pops up over the text. When the
caret is inside a tagged value:
- its sentence takes the writing wash, and a thin dashed frame (`outline: 1px dashed <mark>`)
  marks the value's exact words;
- a slim **field bar** at the top of the note box (above the keyboard on a phone) names the
  field and offers: **λίστα (↓)**, **αλλαγή πεδίου**, **αφαίρεση ετικέτας**. It disappears
  when the caret leaves the value.

Actions:
1. Typing inside the value changes it; the frame follows the same end rules.
2. ↓ list: current value shown first; **Enter replaces the whole value** inside the frame.
3. Levels: ↓ reopens the grid with the current levels selected; clicks/Space add or remove.
4. Prescriptions from a list entry get their blanks back when the caret enters one.
5. Wrong field: clicking the grey tag word opens the tag menu; the value stays, only the
   field and colour change (the tag word is rewritten).
6. Remove tag, keep words: one Backspace right after a tag word deletes the whole tag word;
   or «αφαίρεση ετικέτας» from the bar.
7. From the reading view: a click opens the card for writing with the caret on the clicked
   word (tag words reappear; the caret stays on the same word). Read-only accounts: no change.

## 9. Reading look and the patient record

- Read-only notes (closed cards, accounts without edit permission, printouts): rendered by PHP
  with the tag words removed and each value in its ink colour. Everything escaped with the
  usual template escaping.
- **Patient header**: an allergy line (pale orange) with every `alg` value, newest first, each
  linked to its source note.
- **«Κλινική εικόνα» panel** under the identity header: one small box per tag with
  `summary: true`, entries newest first with date (or «σημείωση ασθενή» for the patient note)
  and a link to the source.
- **Patients list filters** (tags with `search: true`): diagnosis, level (normalised),
  procedure; matched against `note_fields`.

## 10. Storage

New tables (yaml under `web/classes/yaml/`, generated like the others):

`note_fields` -- one row per tagged value; rebuilt for a note on every save of that note.
- `patient_id`, `patient_guid`
- `source` (`appointment` | `patient`), `appointment_id` (null for the patient note)
- `tag_key` (`dx`, `lvl`, ...), `value_text` (as typed), `normalized` (levels; lowercase
  accent-free text for others), `valid` (tinyint)
- `pos` (character offset in the note)
- prescription parts: `rx_drug`, `rx_strength`, `rx_per_day_text`, `rx_per_day` (decimal,
  null for "επί πόνου"), `rx_days` (null = συνεχώς/unknown), `rx_note`, `rx_total`
- `cdate`

`note_list_entries` -- the fixed lists.
- `tag_key`, `text`, `aliases` (Greek spellings, comma-separated), `next_words` (JSON array),
  `rx_strengths` / `rx_defaults` (JSON, prescriptions only), `sort`, `active`, `cdate`, `cuser`

Previous entries and counts are read from `note_fields` (no separate counter).

The note text (`anote`, `pnote`) remains the single source of truth.

## 11. Where to hook in (current code)

- Patient note: `patient_edit_post()`, `patient_new_post()`, `ajax_update_patient_info()` in
  `web/index.php` (`pnote` from `$_POST['patient-note']`); textarea in
  `web/templates/content/edit_patient.zetem` (`name="patient-note"`, autosave `loader="info-loader"`).
- Appointment note: `appointment_edit_post()` (around `$ap->setanote(...)`),
  `patient_appointment_new_post()`, `consultation_new_post()`, `pending_appointment_convert_post()`
  in `web/index.php`; textareas in `view_appointment.zetem` (autosave per card),
  `edit_appointment.zetem`, `new_consultation.zetem`.
- After each successful save of a note: `zpms_note_fields_sync(source, id, patient, text)`
  (delete that note's rows, insert parsed ones, in one transaction).
- Deleting/purging: `bin/zpmscli.php` (`patients:purge`, `appointments:purge`,
  `cleanup:deleted`) and the web delete paths must delete matching `note_fields` rows.
- Rebuild: `bin/zpmscli.php note-fields:rebuild [--patient=<id>]`.
- JS strings go through `zpms_js_text()`; every visible term through `t()` with Greek in
  `config/dictionary.gr.php`.

## 12. Privacy and audit

- Values are patient data: never in `audit_log.detail` or `logs/audit.log` (only event and
  field names, e.g. `fields=anote`), same rule as the notes (see README "GDPR audit log").
- Deleted with the patient/appointment on purge.
- Suggestions and the summary only use notes the signed-in user is allowed to read
  (respect the existing RBAC permissions for viewing patients).

## 13. Starter lists (Greek)

- **dx**: Κήλη μεσοσπονδυλίου δίσκου, Δισκοπάθεια, Σπονδυλική στένωση, Σπονδυλολίσθηση,
  Ριζοπάθεια, Αυχενική μυελοπάθεια, Μηνιγγίωμα, Γλοίωμα, Υδροκέφαλος, Σύνδρομο καρπιαίου σωλήνα
- **lvl**: built in (section 4)
- **alg**: Πενικιλλίνη, ΜΣΑΦ, Ασπιρίνη, Ιωδιούχα σκιαγραφικά, Λάτεξ, Καμία γνωστή αλλεργία
- **exam**: Μαγνητική τομογραφία, Αξονική τομογραφία, Ηλεκτρομυογράφημα, Ακτινογραφία,
  Δυναμική ακτινογραφία, Σπινθηρογράφημα οστών, Αιματολογικός έλεγχος.
  Next words: ΟΜΣΣ, ΑΜΣΣ, ΘΜΣΣ, εγκεφάλου, με σκιαγραφικό, άνω άκρων, κάτω άκρων
- **proc**: Μικροδισκεκτομή, Πεταλεκτομή, Αποσυμπίεση, Σπονδυλοδεσία, Πρόσθια αυχενική
  δισκεκτομή και σπονδυλοδεσία, Τρηματοτομή, Απελευθέρωση καρπιαίου σωλήνα, Κρανιοτομία
- **thx**: Επισκληρίδιος έγχυση, Ριζική διήθηση, Διήθηση facet, Ραδιοσυχνότητες, Κηδεμόνας,
  Αυχενικό κολάρο. Next words: the level and side (δεξιά, αριστερά, αμφοτερόπλευρα)
- **pt**: Φυσικοθεραπεία ΟΜΣΣ, Φυσικοθεραπεία ΑΜΣΣ, Ασκήσεις σταθεροποίησης κορμού,
  Κινησιοθεραπεία, Υδροθεραπεία. Next words: 10 συνεδρίες, 15 συνεδρίες, 3 φορές την εβδομάδα
- **med**: ΜΣΑΦ επί πόνου, Παρακεταμόλη επί πόνου, Μυοχαλαρωτικό, Πρεγκαμπαλίνη,
  Γκαμπαπεντίνη, Κορτικοστεροειδή σε σχήμα, Γαστροπροστασία
- **rx** (name / Greek alias / strengths): Lyrica / λυρικα / 25, 75, 150, 300mg ·
  Neurontin / νευροντιν / 300, 400mg · Depon / ντεπον / 500mg · Apotel / αποτελ / 500mg ·
  Mydocalm / μυντοκαλμ / 150mg · Medrol / μεντρολ / 16mg

The lists grow from the user's previous entries; frequent free-text entries can be promoted
from the Settings page.

## 14. Phases

Each phase is usable on its own and adds tests to `bin/run_tests.sh`.

1. **Rules and storage.** `config/note_tags.yaml`; the parser in PHP and in JS with one shared
   JSON file of test cases run by both (syntax rules, Greek/English levels, prescriptions,
   e-mail addresses not tags, Greek `·`); `note_fields` + `note_list_entries` with the starter
   lists; sync on every note save (section 11); purge paths; `note-fields:rebuild`.
   Done when: saving an appointment or patient note produces the right rows; purge removes
   them; rebuild reproduces them.
2. **Writing look.** Mirror behind the textarea: wash on the current sentence, pale underline
   elsewhere, grey tag words; the `@` tag menu. Done when typing never shifts letters
   (check in a real browser at desktop and phone widths).
3. **Suggestions and editing.** Ghost completion (Tab word by word, → rest), ↓ list,
   checklist, grid, prescription blanks, next-word strip, keyboard table, phone word bar,
   value frame + field bar, change field, remove tag. Suggestion endpoint returns only data
   the user may read.
4. **Reading.** Clean read-only rendering, allergy line on the header, «Κλινική εικόνα» panel.
5. **Lists and search.** Settings page «Λίστες σημειώσεων» (per tag: add, edit, reorder,
   deactivate entries; next words; prescription strengths/defaults; "promote frequent
   free-text entries"); patients-list filters.

## 15. Open / later

- Tag definitions in a Settings page instead of the config file (only if tags are added often).
- New value types: follow-up period (`@επαν`) with reminders, scores (`@vas`).
- ICD-10 codes for diagnoses.
