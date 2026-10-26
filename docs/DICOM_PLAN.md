# DICOM imaging in ZPMS -- plan (draft, nothing built yet)

Status: planning. Written 2026-10-26 after reviewing the unmerged `dicom` branch
(commit `3d15fcd`, "add DICOM imaging module with upload, viewer, and sharing").
That branch has code but no written plan, and shares no history with the current
`master`/`prod`, so it is a source to **port from**, not to merge.

## 1. What the `dicom` branch already has (reusable)

- `web/modules/dicom/DicomParser.php`, `DicomDirParser.php`, `DicomConverter.php`
  (DCMTK `dcm2pnm` -> PNG, thumbnails via GD).
- Tables: exam -> series -> image, plus shares (`sql/dicom.sql`, `web/classes/yaml/dicom_*.yaml`).
- Chunked zip upload (2 MB chunks, 500 MB cap), thumbnail grid + lightbox viewer,
  public share link `/dicom/shared/{token}` with expiry and view counter.

Not reusable as is: the old Material Blue redesign, `SecurityClass::require()` (passes for
any logged-in user; use `rbacClass::require()`), and no link to a ZPMS patient.

## 2. Goal

Staff import an imaging exam (usually from a patient's CD, sometimes a download), choose
**which series** to keep, link it to a patient, and see it in the patient record's
Έγγραφα block.

## 3. Choosing series in the browser, before uploading

- Folder picker: `<input type="file" webkitdirectory>` (works in all browsers; the
  File System Access API is Chromium-only). Drag-and-drop of a folder as a nicety.
- **Fast path -- `DICOMDIR`** (CDs almost always have it): parse that one file to build
  the patient -> study -> series table (description, modality, number of images, size,
  date, transfer syntax). Match each referenced path against the real file list
  (case, backslashes, 8-char names such as `IM000001` without extension); warn about
  missing files; list files the index does not mention as "other files".
- **Fallback -- header scan** (downloads, no `DICOMDIR`): read only the first
  ~64-256 KB of each file, detect real DICOM by the `DICM` marker at byte 128, parse
  the few tags needed (patient, study/series UID, descriptions, modality, instance
  number, transfer syntax) and group by `SeriesInstanceUID`. Hand-written parser
  (~150 lines) to respect the "no third-party libraries" rule; `dicom-parser` is the
  alternative.
- Table with one row per series and a checkbox; scouts/dose reports unticked by default;
  warn if the folder mixes patients. No pixel decoding in the browser at first
  (compressed JPEG2000/JPEG-LS is hard); descriptions and counts only.
- The browser's reading is a convenience only: **the server re-reads every file's own
  header** and files it by the UIDs inside, ignoring what the client claimed.

## 4. Upload

- One request per file, 3-4 in parallel, progress bar, retry, resume (replaces the
  branch's chunked zip). Per-file and per-exam size limits enforced server-side.
- Duplicates detected by `SOPInstanceUID` (uploading the same CD twice stores nothing twice).

## 5. Stored data

- **Originals**: only the ticked series, byte for byte, never rewritten, under
  `data/dicom/<exam>/<series>/` (outside the web root, named by UIDs, never client file
  names, never patient data in paths). Always streamed through PHP after a permission
  check, never served statically.
- **Derived**: thumbnails and full-size PNGs, made after upload in the background. A
  cache that can be rebuilt from the originals.
- **Database**: exam/series/image rows with UIDs, modality, descriptions, dates, sizes,
  status, and a nullable link to the ZPMS patient. The DICOM patient name stays on the
  exam row only (for matching) and never goes to logs.
- **Disk**: a CT/MRI is often 100-500 MB. Show used space in the app; per-upload limit.
- **Backups**: include `data/dicom/` originals and the database, **not** the derived
  images (rebuild after restore). Old backup generations keep deleted exams until they
  age out -- state this in the README as for other documents.
- **Deleting**: removes files, derived images and rows together, audit event with only
  the exam reference. Manual and permission-gated unless a retention period is chosen
  (imaging often has a legal minimum). Erasing a patient removes their exams.

## 6. Access, sharing, audit

- New permissions `dicom-view`, `dicom-upload`, `dicom-share`, `dicom-delete`, seeded in
  `rbac_seed.php`, checked with `rbacClass::require()`.
- Audit log: upload, view, share create/disable, delete -- references only, no names.
- Share links (optional, later): short default expiry, "disable link" switch, optional
  verification code like DocArc's QR links.

## 7. Viewer

First version: the branch's thumbnail grid + lightbox. `dcm2pnm` gives still images with a
fixed brightness window, which is not enough for CT/MRI scrolling, window/level or
measurements. If that is needed, evaluate OHIF/Cornerstone with Orthanc as the store
(one more server to run) instead of hand-writing a viewer.

## 8. Build order

1. Scan page: folder picker, `DICOMDIR` parser, header-scan fallback, series table. No upload.
2. Per-file upload and server-side filing.
3. Link to a patient; show in the Έγγραφα block.
4. Conversion, thumbnails, viewer.
5. Audit log, permissions, backups, README.

## 9. Open questions

- Where do files live (app server or separate storage) and how much space is there?
- Keep derived images or rebuild on demand?
- Automatic retention period, or manual deletion only?
- Does the server's DCMTK build decode compressed transfer syntaxes (check early)?
- Typical exam size and mix: mostly CDs with `DICOMDIR`, some downloads.
