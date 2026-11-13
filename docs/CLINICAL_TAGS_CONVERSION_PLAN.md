# Converting the existing notes to clinical tags -- plan

Status (2026-11-13): tools built, notes exported, decisions of section 4 taken and built
(new tag, levels in diagnoses, old dose notation in the parser); **tagging done 2026-11-14, awaiting review**. Companion to `docs/CLINICAL_TAGS_PLAN.md` (the tag notation itself).

The notes written before the tags existed are plain prose. This plan adds the tags to them
so the patient record summary, the allergy line and the patients-list filters cover the
history too.

**No patient text belongs in this file or in git.** The examples below are invented.

## 1. Decided approach (the user, 2026-11-13)

- **Every note is tagged by hand, one by one, by Claude reading it** -- not by a
  rule-based script. Rules can find levels, but whether «ΚΜΔ» is a finding or a ruled-out
  question, or whether an MRI was ordered or reported, needs the sentence read.
- The work goes through **text files**, not straight into the database:
  1. `zpmscli notes:export` writes every live note into one text file with markers.
  2. Claude tags that file note by note and saves it **under another name**.
  3. The user reads the tagged file by hand (and may fix it).
  4. `zpmscli notes:import <tagged file>` (a dry run first, then `--yes`) writes it back.

## 2. What the database holds (scan of 2026-11-13)

- 350 appointment notes (63k characters, median 144) and 94 patient notes (5k, median 34),
  live and non-empty. One note already used tags.
- Short lines, lists and abbreviations: ΚΜΔ, φ/θ, α/α, MRI ΟΜΣΣ, `Rx/` and `Φ.Αγωγή/`
  blocks; levels as `Ο4/5`, `Ο5/Ι1`, `C6/7`, `Θ10,Θ11`.
- Rough counts (notes): levels 148, exams/imaging 124, injections/RF 48, surgery 45,
  medication blocks 43, old dose notation (`300 x 1`, `2 - 2 - 2`, `1/2`) 44, ΚΜΔ/κήλη 33,
  allergies 18, physiotherapy 13.
- Non-clinical content too: phone numbers, the referring person, one pasted hospital
  admission record.
- Line endings: 212 notes CRLF, 145 LF, 87 single-line -- never mixed within a note.

## 3. Tagging rules (what Claude follows)

1. **Insert only `@tag ` -- never change, add or remove any other character.** No commas
   added, no spelling fixed, abbreviations kept. The import enforces this.
2. **Diagnoses (`@δγ`)**: stated findings and the working diagnosis. Not what is
   uncertain or negated («πιθανόν», «?», «χωρίς», «όχι»), not symptoms the patient
   only reports (unless it's the stated problem of the visit).
3. **Levels (`@επ`)**: a level that stands on its own («μικρή σπονδυλολίσθηση @επ Ο5/Ι1»).
   Levels inside a procedure/therapy value are picked up automatically (`inner_levels`).
4. **Exams (`@εξ`)**: only exams **ordered** (Rx/ blocks, «να κάνει»). A reported result
   («από την MRI ΑΜΣΣ: …») is not an order and is not tagged `@εξ`.
5. **Procedures (`@χειρ`)**: operations done or proposed; operation-type appointments
   always get one.
6. **Therapies (`@θερ`)**: injections, blocks, RF, facet procedures, braces.
7. **Physiotherapy (`@φθ`)**: φ/θ, exercises, πιλάτες, υδροθεραπεία.
8. **Allergies (`@αλ`)**: each substance its own tag («Αλλεργία στην @αλ ασπιρίνη, @αλ
   ιώδιο»). «όχι γνωστή αλλεργία» gets one tag too (it is information).
9. **Medication** (decisions 1 and 3):
   - `@φα` (Τρέχουσα αγωγή) -- what the patient already takes: every line of a
     `Φ.Αγωγή/` list, «παίρνει …», drugs from other doctors.
   - `@συν` -- a drug the doctor prescribes or changes, with a dose («χορήγησα Neurontin
     300mg x 2», «αύξηση του neurontin σε 300mg x 2»: the tag goes before the drug).
   - `@αγ` -- a recommendation without a dose («ΜΣΑΦ επί πόνου», «γαστροπροστασία»), or a
     drug named without any dose in an Rx/ block.
10. **Never tagged**: phone numbers, names of people, referrers, administrative or pasted
    records, dates and scheduling.
11. Where a value runs on to the end of the line because the text has no punctuation,
    that is accepted (decision 5).

Invented example:

```
before:  MRI: ΚΜΔ Ο4/5 αριστερά
         Rx/
         φ/θ, ΜΣΑΦ
after:   MRI: @δγ ΚΜΔ Ο4/5 αριστερά
         Rx/
         @φθ φ/θ, @αγ ΜΣΑΦ
```

## 4. Decisions (the user, 2026-11-13) -- built

1. **Standing medication gets its own tag**: `cur` «Τρέχουσα αγωγή», names `φα`,
   `φάρμακα`, `cur`, `current`; value type `prescription` (so its dose parts are stored),
   colour `plum`, in the summary. Added to `config/note_tags.yaml`.
2. **`inner_levels: true` on `dx`**: «@δγ ΚΜΔ Ο4/5 …» also stores an `lvl` row.
3. **The old dose notation is understood** («try to guess what the notation means»): the
   prescription parser (PHP and JS, shared cases in `tests/note_tags/cases.json`) now
   reads the way the notes are written, so the old lines get `@φα` / `@συν` with real
   dose parts rather than plain text. As read from the notes:

   | Written | Means | Stored |
   |---|---|---|
   | `2 - 2 - 2`, `1-0-1`, `1,5 - 1 - 1,5`, `1 - 1/2 - 1/2`, `x 1-1-1` | tablets morning - noon - evening | per day = times given; per dose = average; total from the daily sum |
   | `150-0-300mg`, `200mg - 100mg - 100mg` | milligrams per time of day | times a day only; no tablet count or total |
   | `tegretol 200 x 3`, `Tramal 100x3` | the number > 4 is the strength (drug's unit, mg) | strength 200mg, 1 x 3 a day |
   | `neurontin 1 x 300` | one dose of 300 a day | strength 300mg, once a day |
   | `Lyrica 75mg x 3` (lone `x N`, N ≤ 6, no other frequency) | times a day | 1 x 3 a day; `x 7` or more stays days, as before |
   | `lobivon 1/2`, `1/4 x 1`, `1/2 x2` | part of a tablet | per dose 0.5 / 0.25 |
   | `t4 25ug`, `T4 50υgr`, `62ugr`, `25μgr` | micrograms | strength 25ug (ugr/υgr/μgr stored as ug) |
   | `tb …`, `caps …` | the form | goes to the note, not the drug name |
   | `thyrohormone 0,1`, `nexium 40` | bare strength of an unlisted drug | strength 0.1mg / 40mg |
   | `co-valsart 160+12,5 x 2` | combination tablet, old A x B | strength 160+12.5mg, x2 |
   | `movatec 7,5mgx2`, `arcoxia 90mgx1` | `x` glued to the unit | times a day, whatever the number |
   | `Ο2-3-4-5`, `Α3/4/5/6`, `Ο2/3,3/4,4/5` | chain of vertebrae; pairs after a comma keep the region | each level: Ο2-Ο3, Ο3-Ο4, Ο4-Ο5 |

   Known limits: «2-3/ημέρα» and changes over time («x 1 και σε 3 ημέρες x2») stay in the note.
   Change for new notes: a lone `x5` without another frequency now means 5 times a day,
   not 5 days (write «για 5 ημέρες»).
4. **Long values** to the end of a line are accepted (no punctuation added).
5. **Abbreviation synonyms** in the patients-list filter (searching «κήλη» finds «ΚΜΔ»):
   not decided; a later, separate change.

## 5. The tools (built 2026-11-13)

Code: `bin/lib/zpms_note_convert.php` (loaded by `bin/lib/zpms_cli.php`), commands in
`bin/zpmscli.php`, tests in `tests/functional/zpmscli.php`.

### `zpmscli notes:export [--out=<file>]`

- Every non-empty patient note and appointment note whose patient and appointment are not
  soft-deleted; by patient, the patient note first, then appointments by date.
- Default file `var/note-conversion/notes-<date-time>.txt` (`var/` is not in git), written
  mode 0600 in a 0700 folder. Changes no data, so no `--yes`.
- Format:

  ```
  # comment lines (ignored)
  === NOTE appointment 67 | patient 12 | 2024-05-03 | eol=crlf | sha=0123456789abcdef ===
  the note, exactly, with \n line breaks
  === END appointment 67 ===
  ```

  `eol` is the note's own line ending (restored on import); `sha` is a fingerprint of the
  stored text (the import refuses a note edited after the export). `operation` appears in
  the header of operation-type appointments.

### Tagging (Claude)

- Reads `notes-original.txt`, writes `notes-tagged.txt` (same folder) note by note, in
  batches, never touching the `===` lines.
- After each batch: a dry-run import of the tagged file must report no problems.

### `zpmscli notes:import <file> [--allow-edits] [--yes]`

- For each note: still there and live, same patient, unchanged since the export
  (fingerprint), and the tagged text is the original **plus `@<known tag name> ` insertions
  only**. An editor that stripped trailing spaces or saved the file with CRLF is tolerated:
  the insertions are applied to the stored text, so its exact characters and line endings
  are kept. Tag names are matched as the parser reads them (case and accents ignored:
  `@ΔΓ`, `@φαρμακα`), and a tag in front of an `@` already in the note is understood.
- The file itself is refused when it has no notes (e.g. the backup JSON given by
  mistake) or a line of text outside every note (a line moved out of its note would
  otherwise be lost silently). Spaces after a `===` line are tolerated.
- Warnings (written anyway): a tag glued to the word before it (not a tag), a tag with no
  value, an impossible level.
- Notes with a problem are skipped and listed; the rest are written. `--allow-edits`
  accepts hand edits beyond tags (the tagged text is then stored as it is).
- With `--yes`: the originals go to `var/note-conversion/backup-<date-time>.json` first,
  then all notes and their `note_fields` rows are written in one transaction (a note that
  changed meanwhile aborts all). One audit row per note (`notes_tags_imported`, tag count
  only, never text).
- `notes:import --revert=<backup.json> [--yes]` (or `--revert <backup.json>`) restores the originals of every note still
  as the import left it (`notes_tags_reverted`). A bare `--revert` is refused; a backup is never overwritten.

### Checked

- The export of 2026-11-13 (444 notes) read back by a dry-run import: 444 unchanged,
  0 problems -- every note round-trips exactly.
- The tests in `tests/functional/zpmscli.php` need the test database, which is not set
  up on the live server; run `bin/run_tests.sh` where `config/db.test.php` exists.

## 6. Steps

1. ~~Build export/import~~ (done).
2. ~~Export~~ (done: `var/note-conversion/notes-original.txt`).
3. ~~Settle section 4; config and parser changes~~ (done). After deploying them:
   `php bin/zpmscli.php note-fields:rebuild --yes` (existing tagged notes re-read).
4. Tag all notes into `var/note-conversion/notes-tagged.txt`; dry-run import clean.
5. User review of the tagged file.
6. Fresh export check: if notes were edited meanwhile, the import skips them -- tag those
   few again from a new export.
7. `notes:import … --allow-edits --yes` (one note was edited by hand at the user's request, see
   `var/note-conversion/review-notes.txt`); check the patient record and filters; keep the backup.
8. Delete the files in `var/note-conversion/` once satisfied (patient data).
