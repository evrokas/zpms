# Clinical tags in notes

Plans and decisions: `docs/CLINICAL_TAGS_PLAN.md` (design), `docs/CLINICAL_TAGS_CONVERSION_PLAN.md` (tagging the
old notes). README sections "Clinical tags in the notes — phase 1..5".

## How it works

- A doctor types `@tag value` in a note (`appointments.anote` or `patients.pnote`). The note text is the only
  source of truth.
- On every save, `zpms_note_fields_sync()` (`web/note_tags.php`) deletes and rewrites that note's rows in
  `note_fields`. `note-fields:rebuild --yes` rewrites all of them.
- The rows feed the patient record summary («Κλινική εικόνα»), the allergy line in the header, and the
  patients-list filters and search.
- Soft-deleting copies the time into `note_fields.deleted`; purging removes the rows.

## Tags (`config/note_tags.yaml`)

| Key | Typed names | Value type |
|---|---|---|
| `dx` | δγ, διάγνωση, dx | text (inner levels read) |
| `lvl` | επ, επίπεδα, lvl | levels |
| `alg` | αλ, αλλεργία | text, header alert |
| `exam` | εξ, εξέταση | text |
| `proc` | χειρ, επέμβαση | text (inner levels) |
| `thx` | θερ, θεραπεία | text (inner levels) |
| `pt` | φθ, φυσικοθεραπεία | text |
| `med` | αγ, αγωγή | text |
| `rx` | συν, συνταγή | prescription |
| `cur` | φα, φάρμακα | prescription |

Names are matched folded (case and accents ignored). The yaml also holds lists for the pickers and the drug
aliases/strengths. `note_list_entries` holds the editable lists (`/settings/note-lists`, `note-lists:seed`).

## Parsing rules worth knowing

- Levels are stored in Greek form: `Α` cervical, `Θ` thoracic, `Ο` lumbar, `Ι` sacral — `Ο4-Ο5`, `Ο5-Ι1`.
  `Ο4/5`, `L4-L5`, chains `Ο2-3-4-5`, `Θ11/12/Ο1`, inherited region after a comma (`Ο2/3,3/4`). A bare number is
  not a level. An impossible level is stored with `valid = 0` and left out of the summary.
- Prescriptions: drug, strength (normalised: `12,5` -> `12.5mg`, `ugr`/`μgr` -> `ug`, combinations
  `160+12.5mg`), per dose (`½`), per day (`1x2`, `7,5mgx2` = twice a day, `επί πόνου`), days.

## Two parsers, one behaviour

PHP `web/note_tags.php` (`zpms_note_parse()`, `zpms_note_parse_rx()`) and JS `web/js/note-tags.js` (live
highlighting/suggestions in `note-box.js`) must give identical results. Every parser change:
1. change both,
2. add cases to `tests/note_tags/cases.json` (run by `tests/static/note_tags.php` for PHP and
   `node tests/note_tags/run_cases.js` for JS; editing behaviour in `editing_cases.js`),
3. run `note-fields:rebuild --yes` on each install (with a DB backup first on live).

Bulk tagging of old notes: `notes:export` -> tag the text file -> `notes:import <file> [--allow-edits] --yes`
(writes only where the note is byte-identical to the export; saves a backup JSON; `--revert=` undoes it).
Exports contain patient text: keep under `var/note-conversion/`, mode 0600, delete when done.
