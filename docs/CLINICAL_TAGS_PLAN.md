# Clinical tags in the note boxes -- plan (approved design; all five phases built)

Status: design approved by the user after several mockup rounds, 2026-11-13. **Phases 1 (rules
and storage), 2 (writing and reading looks) and 3 (suggestions and editing), 4 (patient record) and 5 (lists
and search) are built** -- see sections 16-20 for what was decided while building them. What is
left for later is in section 15. This file is the complete specification: a new session should be able to build
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
patient. Not pending-appointment notes either (`pending_appointments.notes`): their text becomes
the appointment note when the pending appointment is converted, and is parsed then.

## 2. Syntax

- A tag is `@` + a known tag name, at the start of the text or after whitespace/punctuation.
  An e-mail address (`a@b.gr`) or a lone `@` is never a tag.
- Tag names are matched ignoring case and Greek accents (`@ΔΙΑΓΝΩΣΗ` = `@διαγνωση` = `@δγ`).
- A tag name must be followed by whitespace, punctuation or end of text, and the **longest
  matching name wins**. Several short names are prefixes of long ones (`επ`/`επέμβαση`,
  `αλ`/`αλλεργία`, `συν`/`συνταγή`, `θερ`/`θεραπεία`): `@επέμβαση μικροδισκεκτομή` is a
  procedure, never `@επ` + «έμβαση…». Unknown names (`@abc`) are plain text.
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
- A **sentence** (for the writing wash, section 7) ends at the same marks: a full stop
  followed by whitespace, `·`, `;` (also the Greek question mark), a line break.

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

The nine tags (`names`: the first is the main short Greek tag shown in menus; all accepted).
`colour` names a palette entry defined in the same file (`palette:` with `tint`, `mark`, `ink`
hex values, table below), so the file is the only source for colours too:

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

Palette (light theme; tint = wash, mark = underline, ink = reading text), written in
`config/note_tags.yaml` under `palette:` keyed by colour name (rose = dx row, lavender = lvl,
orange = alg, sky = exam, mint = proc, teal = thx, sage = pt, sand = med, amber = rx):

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
- **Levels inside other values are recorded too** (decided 2026-11-13): a level written
  inside a `proc` or `thx` value («@θερ επισκληρίδιος έγχυση στο Ο5-Ι1», «@χειρ μικροδισκεκτομή
  Ο4-Ο5») stays part of that value and is *also* stored as an `lvl` row (`origin` column =
  the outer row), so it appears in the level summary and the level filter. Which tags are
  scanned this way is a flag in the tag file (`inner_levels: true` on `proc` and `thx`).

## 5. Prescriptions (value type `prescription`)

One readable phrase with up to five parts:
`Lyrica 75mg 1x2 για 30 ημέρες, μετά το φαγητό`

| Part | Picker list | Read from free typing |
|---|---|---|
| drug | drug list (written as on the box: Lyrica), found by Greek spelling (`λυρ`) | first word(s), matched to the list or kept as typed |
| strength | that drug's strengths (Lyrica: 25, 75, 150, 300mg) | number + unit (mg, μγ, mcg, g, ml); `0.5` and `0,5`; a **bare number right after the drug** is its strength in that drug's unit (default mg): «λυρικα 150 1χ2» = 150mg |
| per_day | 1x1, 1x2, 1x3, ½x2, επί πόνου | `1x2`, `1χ2` (Greek χ = x), `½x2`, `0,5x2`, `2 φορές`, `επί πόνου`. In `AxB`, A = amount per dose (tablets), B = doses per day |
| days | 7, 14, 30, 60, συνεχώς | `για 30 ημέρες`, `30 ημ`, `x30` |
| note (optional) | μετά το φαγητό, πριν το φαγητό, πριν τον ύπνο, με σταδιακή αύξηση + own | text after the comma; anything not placed elsewhere |

- Picking a drug writes the phrase with blanks; Tab / Shift+Tab move between blanks; each blank
  has its own short list; any value can be typed. An empty note blank disappears.
- Total = amount per dose x doses per day x days, in tablets (1x2 for 30 days = 60; ½x2 for
  30 days = 30), shown in the bar while writing and in the summary when computable (not for
  «επί πόνου» or «συνεχώς»).
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
- **Checklist** (exams): several at once; each pick is written **with its own tag**, because a
  comma ends a text value: «@εξ μαγνητική τομογραφία ΟΜΣΣ, @εξ ηλεκτρομυογράφημα κάτω
  άκρων». (The mockup image still shows the picks under one tag; this file wins.)
- **Grid** (levels): several at once under one tag («Ο3-Ο4 και Ο4-Ο5»); the `levels` type
  accepts «και» and commas between levels.
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

Tab, ←/→ and ↑/↓ are taken over **only while a suggestion, menu or blank is showing**;
otherwise every key keeps its normal behaviour (Tab moves to the next field, ↓ moves the caret).

Highlighted row: pale teal background; grid: teal ring on the current cell, ticked cells lilac.
Mouse clicks / taps = Space (tick) or Enter (pick).

**Phone** (no Tab/arrows): the suggestion appears as word buttons in a bar just above the
keyboard; tapping a word takes the suggestion up to that word, «✓ όλο» takes all; tapping the
grey ghost text does the same. Lists, checklists and blanks use the same bar, with
«επόμενο ›» instead of Tab.

## 7. Writing look

**When which look is shown** (decided 2026-11-13): a note box **without the caret shows the
reading look** (section 9: tag words hidden, values in tinted ink), also for staff who can edit.
Clicking or tapping into it switches that box to the writing look, with the caret on the
clicked word (the tag words reappear, so the line may shift a little; the caret stays on the
word). Leaving the box (blur) switches it back. Accounts that cannot edit always see the
reading look.

In the writing look:

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
mirror may take extra space (no padding, no inline marks with width). The note boxes size
themselves to their content today (`field-sizing: content; min-block-size: 5lh`,
`web/css/styles.css`), so the mirror must follow the textarea's size and scroll position on
every input and resize, or the colours drift off the words. Ghost text, menus,
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
7. From the reading look (any box without the caret): a click switches the box to writing with
   the caret on the clicked word (section 7). Accounts that cannot edit: no change.

## 9. Reading look and the patient record

- The reading look (every note box without the caret, and always for accounts that cannot
  edit; section 7): rendered by PHP on page load with the tag words removed and each value in
  its ink colour, everything escaped with the usual template escaping; the JS redraws it after
  an edit. (Should a print view be added later, it uses the same rendering.)
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
- `pos` (character offset in the note); `origin` (id of the outer `proc`/`thx` row for a level
  found inside it, otherwise null)
- prescription parts: `rx_drug`, `rx_strength`, `rx_per_day_text` (as written, «1x2»),
  `rx_per_dose` (decimal, ½ = 0.5), `rx_per_day` (doses per day; null for «επί πόνου»),
  `rx_days` (null = συνεχώς/unknown), `rx_note`, `rx_total` (tablets, null when not computable)
- `deleted` (datetime, copied when the source appointment or patient is soft-deleted)
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
- Deleting in the web app is a **soft delete** (`appointment_perform_delete()` and
  `patient_delete()` call `setdeleted()`, `web/index.php`): set `note_fields.deleted` for that
  appointment's rows (and for all the patient's rows on a patient delete). The summary, the
  filters and the suggestions always ignore rows with `deleted` set.
- Purging (`bin/zpmscli.php` `patients:purge`, `appointments:purge`, `cleanup:deleted`)
  removes the matching `note_fields` rows for good.
- Rebuild: `bin/zpmscli.php note-fields:rebuild [--patient=<id>]`.
- JS strings go through `zpms_js_text()`; every visible term through `t()` with Greek in
  `config/dictionary.gr.php`.

## 12. Privacy and audit

- Values are patient data: never in `audit_log.detail` or `logs/audit.log` (only event and
  field names, e.g. `fields=anote`), same rule as the notes (see README "GDPR audit log").
- Hidden when the patient/appointment is soft-deleted; removed for good on purge.
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

1. **Rules and storage.** *(Done 2026-11-13; see section 16.)* `config/note_tags.yaml`; the parser in PHP and in JS with one shared
   JSON file of test cases run by both (syntax rules, Greek/English levels, prescriptions,
   e-mail addresses not tags, Greek `·`, longest-name matching, levels inside proc/thx, bare
   strengths, ½ doses); `note_fields` + `note_list_entries` with the starter lists; sync on
   every note save (section 11); soft-delete and purge paths; `note-fields:rebuild`.
   Done when: saving an appointment or patient note produces the right rows; a soft delete
   hides them, purge removes them; rebuild reproduces them.
2. **Writing and reading looks in the box.** *(Done 2026-11-13; see section 17.)* Reading look when the box has no caret, writing
   look when it has; mirror behind the textarea (following its auto-size): wash on the current
   sentence, pale underline elsewhere, grey tag words; the `@` tag menu. Done when typing never
   shifts letters and the mirror stays aligned while the box grows (check in a real browser at
   desktop and phone widths).
3. **Suggestions and editing.** *(Done 2026-11-13; see section 18.)* Ghost completion (Tab word by word, → rest), ↓ list,
   checklist, grid, prescription blanks, next-word strip, keyboard table, phone word bar,
   value frame + field bar, change field, remove tag. Suggestion endpoint returns only data
   the user may read.
4. **Patient record.** *(Done 2026-11-13; see section 19.)* Allergy line on the header, «Κλινική εικόνα» panel.
5. **Lists and search.** *(Done 2026-11-13; see section 20.)* Settings page «Λίστες σημειώσεων» (per tag: add, edit, reorder,
   deactivate entries; next words; prescription strengths/defaults; "promote frequent
   free-text entries"); patients-list filters.

## 15. Open / later

- Tag definitions in a Settings page instead of the config file (only if tags are added often).
- New value types: follow-up period (`@επαν`) with reminders, scores (`@vas`).
- ICD-10 codes for diagnoses.

## 16. Phase 1 as built (2026-11-13)

Files: `config/note_tags.yaml`; `web/note_tags.php` (parser, sync, hide/purge, rebuild, seed;
plain PDO so `bin/zpmscli.php` runs the same code); `web/js/note-tags.js` (the same parser,
`window.ZpmsNoteTags.parse(text, spec, drugs)`, also loadable from node);
`web/classes/yaml/note_fields.yaml`, `note_list_entries.yaml`; `tests/note_tags/cases.json`
(shared cases), `tests/static/note_tags.php`, `tests/functional/note_fields.php`.

Decisions taken while building (section 2 and 10 did not settle them):
- **Starter lists live in `config/note_tags.yaml`** under `starter_lists:`, next to the tags.
  `zpmscli note-lists:seed` copies a tag's list into `note_list_entries` only while that tag has
  no rows, so later edits and deactivations (phase 5) are never undone. `next_words` given for a
  tag is copied to every entry. Drugs: `strengths` -> `rx_strengths` JSON; the unit of the first
  strength is the drug's unit (for a bare number). `rx_defaults` stays empty for now.
- **The parser takes the drug list as an argument** (`zpms_note_rx_drugs($db)`: the active `rx`
  entries; the config's starter list before the table exists). The browser will need it sent
  along with the tag spec (`zpms_note_tags_spec()`) from phase 2 on.
- Extra syntax rules (in the shared test cases, both parsers):
  - a `:` right after the tag name is skipped (`@εξ: μαγνητική`);
  - a comma between two digits is a decimal comma and ends nothing (`0,5 mg`), in every value
    type, matching the full-stop rule;
  - an unmatched closing bracket or quote `)`, `]`, `»` ends a value (`(@εξ ακτινογραφία)`), and
    an opening one left at the end of a value is dropped;
  - a levels tag whose value is no level at all (`@επ δεξιά`) is kept as text, `valid = 0`,
    `normalized` empty; a row with several levels is invalid if any one of them is;
  - a two-level span must go downwards (`Ο4-Ο5`, `Θ12-Ο1`; `Ο5-Ο4` is invalid). `Ι`/S allows 1 only.
- **One row per value; positions** (`pos`) are characters (code points) at the `@`; a level found
  inside a `proc`/`thx` value has `pos` at the level and `origin` = the id of the outer row.
- **Two drugs in one sentence** need two tags (`@συν Lyrica … · @συν Mydocalm …`): the prescription
  value ends at the semicolon, as section 2 says.
- **Prescription parts**: the note is "after the first comma" plus every word not placed elsewhere
  (`με σταδιακή αύξηση`), joined with `, `. «2 φορές» = 1 per dose x 2; «συνεχώς» = no days.
  Strengths are stored as typed without the space (`0,5mg`, `150mg`).
- **When rows are written**: an appointment edit re-reads the note only when the note itself
  changed (autosave posts the whole card on every pause). `ajax_update_patient_info()` was not
  hooked: it does not save anything (its `update()` is commented out). `consultation_new_post()`
  creates a pending appointment, whose notes are read when it is converted (section 1).
- **Soft delete** is also hooked in `bin/sync_google_calendar.php` (a cancellation in Calendar
  deletes the appointment) and in `zpmscli patients:delete` / `appointments:delete`.
  `cleanup:orphans` also removes rows whose note is gone.
- `note_fields.cdate` is when the row was written (a rebuild resets it). Later phases should date
  summary entries by the appointment date, not by `cdate`.
- The schema generator has no secondary indexes, so `note_fields` has none yet; fine at this size.
- Audit: `zpmscli` writes `note_fields_rebuilt` / `note_lists_seeded` rows (counts only).

After deploying: `bin/update.sh` (new tables), `zpmscli note-lists:seed --yes`,
`zpmscli note-fields:rebuild --yes`, `php bin/import_dictionary.php`.

## 17. Phase 2 as built (2026-11-13)

Files: `web/js/note-box.js` (the boxes), `web/css/note-tags.css`, library `note-tags` in
`config/settings.info.yaml` (attached by `edit_patient.zetem`, `view_appointment.zetem`,
`edit_appointment.zetem`); in `web/note_tags.php`: `zpms_note_runs()` (the note cut into
text / tag word / value runs -- the same function in note-tags.js, in the shared test cases),
`zpms_note_strip()`, `zpms_note_reading_html()`, `zpms_note_tags_assets()`.

- **Markup**: each tagged note is `<div class="zt-box"><div class="zt-read">…</div><textarea>`.
  PHP renders the reading look into `.zt-read`; the script shows it (`.zt-on .zt-reading`) and
  hides the textarea. Without the script (or with an unreadable tag file) the box is the plain
  textarea, as before. Every run of the reading look carries `data-o` (its character offset), so
  a click puts the caret on the same letter in the note (`caretPositionFromPoint` /
  `caretRangeFromPoint`). Tab into the box puts the caret at the end.
- `zpms_note_tags_assets()` prints, once per page, the palette as `.zt-c-<colour>` classes and the
  tag definitions as `<script type="application/json" id="zt-spec">`. Colours stay only in the
  tag file.
- The reading div copies the textarea's computed font, padding, border and minimum height, so it
  matches the box in each of the places a note is shown (patient record panel, appointment card,
  new-appointment page, phone layout) without repeating their CSS.
- **Writing look**: the mirror copies the textarea's font/padding/border metrics and its exact
  sub-pixel box (a width rounded by 0.2px wrapped differently), and follows its size
  (ResizeObserver, `field-sizing: content`) and scroll. Checked in Chromium at 1280px and 390px:
  textarea and mirror boxes identical while the note grows from 5 to 20+ lines, text overlaid
  exactly. **Not checked in Firefox/Safari** (they lack `field-sizing`, so the textarea scrolls;
  the mirror follows `scrollTop`).
- A level inside a procedure/therapy keeps its own lavender inside the outer value. An impossible
  level gets a wavy orange underline while writing (the "quiet warning" of section 4).
- **@ menu**: opens on typing `@` where a tag may start (not `info@`), filters by any name
  (accents/case ignored), ↑/↓, Enter or Tab inserts `@<main name> `, Esc closes it for that `@`.
  Tap/click works the same (the menu keeps the caret in the box). Keys are taken only while it
  shows; Tab otherwise moves to the next field as before. The insert uses `setRangeText()` plus an
  `input` event (autosave runs), so Ctrl+Z does not undo the insertion itself.
- **Previews**: the header's first-line note and the collapsed appointment row show the note
  without tag words (`zpms_note_strip()`; the header updates live through `ZpmsNoteBox.strip`).
- **Pending-appointment boxes** (`new_consultation.zetem`, `pending_appointment_edit.zetem`) are
  left as plain textareas (section 1); tags typed there are read when the appointment is converted.
- **Fixed on the way**: the appointment note was printed into its textarea with ZETEM's raw
  `{{ }}` (`view_appointment.zetem`, `edit_appointment.zetem`) -- a note containing
  `</textarea><script>` would have run as script. Now escaped, covered by a test. The patient name
  on the new-appointment page had the same problem.

## 18. Phase 3 as built (2026-11-13)

Files: `web/js/note-box.js` (all aids), `web/js/note-tags.js` (`context()` -- the field under
the caret -- and `parseRxFull()` -- a prescription's parts with their character spans, used for
the blanks; both browser-only, tested by `tests/note_tags/editing_cases.js`),
`note_tags_suggestions()` / `zpms_note_suggestions()` in `web/note_tags.php` (route
`/notes/suggestions`), CSS in `web/css/note-tags.css`, strings in `zpms_js_text()` (`zt*`).

- **Suggestion data**: one request per page, on the first focus of a note box. Per tag: the
  active list entries in order (with aliases, next words, strengths, defaults) and up to 40
  previous entries, grouped by `normalized`, most used first, with a count; rows that are
  soft-deleted, invalid, or levels found inside another value are left out. Refused (403) to an
  account that can edit neither patients nor appointments; patient-note values need
  `patients-view-list`, appointment-note values `appointment-view` or `appointment-edit`. The
  spelling shown for a previous entry is one of the spellings used (`MAX(value_text)`), not
  necessarily the most frequent one.
- **Ghost completion** shows only when nothing follows the caret on its line (it is drawn over
  the box, so it would otherwise cover text). A Greek spelling of a drug (`λυρ`) shows
  «→ Lyrica»; taking it writes the drug name. Not offered for levels (the grid is for those).
- **Picking a drug** (list, ghost) writes `<drug> <first strength> 1x2 για 30 ημέρες` --
  `rx_defaults` (`{"strength","per_day","days"}`) overrides these per drug -- and selects the
  strength blank. Blanks are re-derived from the text every time (`parseRxFull()`), so they also
  come back when the caret enters an existing prescription with a known drug (section 8.4). The
  days blank's options are whole phrases («για 30 ημέρες», «συνεχώς»). A blank's list shows only
  while the blank is selected or the caret is inside it -- not at its end, where Enter must stay a
  line break. Tab from the last blank leaves the blanks. The note blank adds «, » when visited and
  removes it again if left empty.
- **↓ inside a value** opens its picker (list / exam checklist / level grid), as the plan says;
  so inside a value ↓ does not move the caret down a line. Outside a value ↓ is untouched.
- **Level grid**: disc levels as cells (Α2-Α3 … Α7-Θ1, Θ1-Θ2 … Θ12-Ο1, Ο1-Ο2 … Ο5-Ι1); a single
  vertebra (Θ12) is not in the grid but can still be typed. The notation (Greek/English) follows
  the field's current value, else the last one used (browser storage), default Greek.
- **Next-word strip: Alt+1 … Alt+9, not plain digits** (a deviation from section 6). Plain digits
  would make it impossible to type a number right after an entry, and some next words are numbers
  themselves («10 συνεδρίες»). Clicks/taps work as planned.
- **Field bar** sits just above the box's top-right corner (left on a phone), shows the
  prescription total («Σύνολο: 42 δισκία») or «Ελέγξτε το επίπεδο» for an impossible level.
  Remove tag puts the caret at the start of the value.
- **Phone**: the word bar is fixed to the bottom of the visual viewport (above the on-screen
  keyboard); checked with Chromium's iPhone 13 emulation, not on a real phone.
- Programmatic edits (`setRangeText`) are not in the browser's undo history (Ctrl+Z).

## 19. Phase 4 as built (2026-11-13)

`zpms_note_clinical_picture()` (web/note_tags.php) builds both blocks; `patient_edit()` passes it
to `edit_patient.zetem` as `clinical`; styles in `web/css/note-tags.css`.

- **Order**: the patient note's values first (it is the standing record, so "newest" by
  definition), then appointment notes by appointment date, newest first. Boxes follow the order
  of the tag file.
- **A value repeated in several notes is listed once** (its newest occurrence), with «×N». The
  plan said "entries newest first"; without grouping, a diagnosis carried through ten visits
  would fill its box. Grouping uses `normalized`, so «Ριζοπάθεια» and «ριζοπάθεια» are one entry.
- **Who sees what**: patient-note values for everyone who can open the record; appointment-note
  values only for a viewer who may see the appointment cards (appointment-view or -edit) -- the
  same rule as the cards. Soft-deleted appointments, hidden rows and impossible levels are left
  out. Levels found inside a procedure/therapy are in the levels box, as section 4 asks.
- **Links**: an appointment value links to its card (`#app-N`, which opens the row); a
  patient-note value opens the details panel at the Notes field. The allergy line is coloured
  from the allergy tag's palette entry (`header_alert: true`).
- Prescription entries show their total («Σύνολο: 60 δισκία»).
- The blocks are drawn when the page loads; a value typed in a note shows in them after a reload.

## 20. Phase 5 as built (2026-11-13)

**Settings → «Λίστες σημειώσεων»** (`/settings/note-lists`, `web/note_lists.php`,
`note_lists.zetem`; linked from the Settings page's section bar):
- Gated on `settings-manage`, like the rest of Settings. One tag at a time (tabs); levels have no
  list (the grid is built in).
- Per entry: text, other spellings (aliases), next words (tags with `next_words`), strengths
  (prescriptions), active, ↑/↓. Comma-separated fields; duplicates and extra spaces are tidied.
  Entries are **deactivated, never deleted** (an inactive entry is no longer suggested; notes that
  use it are unaffected; `note-lists:seed` never brings it back). `rx_defaults` has no form yet --
  set it in the table if a drug needs other default blanks.
- «Συχνές καταχωρίσεις από τις σημειώσεις»: values typed in notes at least twice and not in the
  list, most used first, each with «Προσθήκη στη λίστα». These come from patients' notes, so they
  are shown only to an account that may read notes (same rule as the suggestions) -- the
  `maintenance` role, which holds `settings-manage` but no patient permission, sees the lists but
  not this block.
- Audit event `note_list_changed` with tag, action and entry id -- never the entry text.

**Patients-list filters** (`/patients`, «Κλινικά φίλτρα»; `zpms_note_patient_filter_where()`):
- One field per tag with `search: true` (diagnosis, levels, procedure), `tag_<key>=` in the
  address, kept by the pager and sort links, combinable with the name search and area. Diagnosis
  and procedure offer their list as a `<datalist>`.
- Text: the folded words anywhere in a value («στενωση» finds «Σπονδυλική στένωση»). Levels: read
  like a typed level, Greek or English, short forms («ο4-5», «L3-L4»); every level given must be
  present (a single vertebra «Ο3» does not match the disc level «Ο3-Ο4»). Levels inside a
  procedure/therapy count.
- Only notes the viewer may read: patient notes always (the list needs `patients-view-list`),
  appointment notes with `appointment-view`/`-edit`. Soft-deleted and invalid rows never match.
