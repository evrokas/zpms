<?php

/**
 * Appointment attachments (photos/scanned documents uploaded against a
 * specific appointment entry) -- see CLAUDE.md's appointment-files section
 * and /root/.claude/plans/zpms-project-is-a-purrfect-kay.md for the full
 * design. Modeled on web/modules/pdflib/pdflib.php, the one confirmed-
 * working file upload feature in this app, but deliberately does not
 * replicate one of its gaps: it checks $_FILES[...]['error'] before
 * treating an upload as present. It also, unlike most call sites
 * elsewhere in this app at the time this file was written, checked
 * SecurityClass::require()'s return value rather than discarding it --
 * that discipline turned out not to matter in practice, since that
 * function itself always returned success for any logged-in user
 * regardless of role (a real, separate bug in zeusfw core; see
 * zeusfw's core/lib/Rbac.php docblock for the full story and
 * rbacClass::require(), used here now, which has neither problem).
 *
 * Storage layout is a human-browsable tree under
 * core_get_dir_in_lib('appointment_files'):
 *   <patient name>_<guid8>/<appointment date>[-N]/<original filename>
 * so staff can find a patient's appointment photos directly on the
 * filesystem, without going through the app -- see
 * appointment_files_resolve_storage_path() below. The resolved path is
 * persisted in appointment_files.file_path at upload time and never
 * recomputed, so download/delete are always consistent with what's
 * actually on disk even if same-day appointment ordering later changes.
 */

const APPOINTMENT_FILE_MAX_BYTES = 15 * 1024 * 1024; // 15MB

const APPOINTMENT_FILE_ALLOWED_MIME_EXTENSIONS = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
    'application/pdf' => 'pdf',
];

// Thumbnails are always re-encoded as JPEG (no alpha channel needed for a
// compact list preview) and capped to fit within this box, matching the
// existing .file-preview-popup CSS's own max-width/max-height so the
// hover preview never has to upscale or waste bytes.
const APPOINTMENT_FILE_THUMBNAIL_MAX_DIMENSION = 320;
const APPOINTMENT_FILE_THUMBNAIL_JPEG_QUALITY = 80;

// Sanitizes a patient's name for use as a filesystem directory component:
// strips path separators/control characters, trims stray dots/spaces (both
// of which are unsafe or meaningless as a trailing directory-name
// character on common filesystems), and caps the length. Greek/UTF-8
// characters are left as-is.
function appointment_files_sanitize_name(string $name): string {
    $name = preg_replace('/[\/\\\\\x00-\x1F\x7F]/u', '', $name);
    $name = trim($name === null ? '' : $name, " .\t\n\r\0\x0B");
    if($name === '') {
        $name = 'patient';
    }
    if(mb_strlen($name) > 100) {
        $name = mb_substr($name, 0, 100);
    }
    return $name;
}

// Builds the "<name>_<guid8>" patient folder segment -- the guid suffix
// disambiguates two different patients who share the exact same name
// (this app already needs AMKA for that same reason), so name alone is
// never used as the folder key.
function appointment_files_patient_folder(string $patientName, string $patientGuid): string {
    $safeName = appointment_files_sanitize_name($patientName);
    $shortGuid = substr(preg_replace('/[^a-zA-Z0-9]/', '', $patientGuid), -8);
    if($shortGuid === '') {
        $shortGuid = substr(hash('crc32b', $patientGuid), 0, 8);
    }
    return $safeName . '_' . $shortGuid;
}

// Builds the "<date>" or "<date>-N" appointment-date folder segment. N is
// this appointment's 1-based position among the same patient's
// appointments on the same calendar date (appointmentsClassEx::
// getSameDayPositionForPatient()) -- the first (or only) appointment that
// day gets a bare date, a 2nd/3rd/... gets a running numeric suffix.
function appointment_files_date_folder(string $patientGuid, string $adate, int $appointmentId): string {
    $date = substr($adate, 0, 10);
    $position = appointmentsClassEx::getSameDayPositionForPatient($patientGuid, $date, $appointmentId);
    return $position <= 1 ? $date : ($date . '-' . $position);
}

// Finds a free filename inside $dir for $originalFilename, appending
// " (2)", " (3)", ... before the extension on a collision -- the folder
// is already scoped to one specific patient+date, so a same-name
// collision is rare, but not impossible (two uploads both named
// "xray.jpg" to the same appointment).
function appointment_files_dedupe_filename(string $dir, string $originalFilename): string {
    $originalFilename = basename($originalFilename);
    if($originalFilename === '') {
        $originalFilename = 'file';
    }

    $ext = '';
    $base = $originalFilename;
    $dotPos = strrpos($originalFilename, '.');
    if($dotPos !== false && $dotPos > 0) {
        $base = substr($originalFilename, 0, $dotPos);
        $ext = substr($originalFilename, $dotPos); // includes leading "."
    }

    $candidate = $originalFilename;
    $n = 2;
    while(file_exists($dir . '/' . $candidate)) {
        $candidate = $base . ' (' . $n . ')' . $ext;
        $n++;
    }
    return $candidate;
}

// Human-readable file size, used by view_appointment.zetem's existing-files
// list (e.g. "2.4 MB").
function appointment_files_format_size(int $bytes): string {
    if($bytes <= 0) return '0 Bytes';

    $units = ['Bytes', 'KB', 'MB', 'GB'];
    $i = (int)floor(log($bytes, 1024));
    $i = max(0, min($i, count($units) - 1));

    return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
}

function appointment_files_mkdir(string $path) {
    if(is_dir($path)) return;
    $msk = umask();
    umask(0022);
    mkdir($path, 0777, true);
    umask($msk);
}

// Resolves where a newly-uploaded file should live: builds the patient
// and date folders (creating them on demand), dedupes the filename within
// that folder, and returns [absolute path for move_uploaded_file(),
// path relative to the appointment_files library dir for the file_path
// column].
function appointment_files_resolve_storage_path(array $appointment, string $patientName, string $patientGuid, string $originalFilename): array {
    $baseDir = core_get_dir_in_lib('appointment_files');

    $patientFolder = appointment_files_patient_folder($patientName, $patientGuid);
    $dateFolder = appointment_files_date_folder($patientGuid, $appointment['adate'], $appointment['id']);

    $dir = $baseDir . '/' . $patientFolder . '/' . $dateFolder;
    appointment_files_mkdir($dir);

    $filename = appointment_files_dedupe_filename($dir, $originalFilename);

    $relativePath = $patientFolder . '/' . $dateFolder . '/' . $filename;
    return [$dir . '/' . $filename, $relativePath];
}

// Attempts to fully decode $absoluteSourcePath as an image -- unlike the
// finfo_file() check in appointment_file_upload(), which only sniffs the
// first few header bytes, this actually reads the whole file through GD.
// A file can pass finfo's sniff yet still fail here if the data is
// truncated/corrupted mid-stream -- observed in practice with some
// clipboard-paste sources (e.g. iOS Safari's async Clipboard API handing
// back incomplete image bytes despite a valid-looking header). Returns a
// GD image resource (EXIF-orientation-corrected for JPEGs) on success, or
// null if the data doesn't actually decode as a real image, or GD isn't
// installed at all.
function appointment_files_decode_image(string $absoluteSourcePath) {
    if(!extension_loaded('gd')) {
        return null;
    }

    $data = @file_get_contents($absoluteSourcePath);
    if($data === false || $data === '') {
        return null;
    }

    $source = @imagecreatefromstring($data);
    if(!$source) {
        return null;
    }

    // Correct JPEG EXIF orientation (phone camera photos) -- GD strips
    // EXIF metadata when re-encoding, so without this a generated
    // thumbnail would silently lose whatever rotation the browser would
    // otherwise have applied to the original.
    if(function_exists('exif_read_data')) {
        $exif = @exif_read_data($absoluteSourcePath);
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? null) : null;
        if(in_array((int)$orientation, [3, 6, 8], true)) {
            $angle = [3 => 180, 6 => -90, 8 => 90][(int)$orientation];
            $rotated = imagerotate($source, $angle, 0);
            if($rotated !== false) {
                imagedestroy($source);
                $source = $rotated;
            }
        }
    }

    if(imagesx($source) <= 0 || imagesy($source) <= 0) {
        imagedestroy($source);
        return null;
    }

    return $source;
}

// Saves a small JPEG preview of an already-decoded image (fit within
// APPOINTMENT_FILE_THUMBNAIL_MAX_DIMENSION x same, aspect ratio
// preserved) in a "thumbs/" subfolder alongside the original -- e.g.
// "<patient>/<date>/thumbs/xray.jpg" next to "<patient>/<date>/xray.jpg".
// Returns the thumbnail's path relative to the appointment_files library
// dir, or null if it couldn't be written (e.g. a disk/permissions issue
// -- doesn't affect the already-saved original either way).
function appointment_files_save_thumbnail($source, string $relativeSourcePath): ?string {
    $srcWidth = imagesx($source);
    $srcHeight = imagesy($source);

    $scale = min(1, APPOINTMENT_FILE_THUMBNAIL_MAX_DIMENSION / max($srcWidth, $srcHeight));
    $dstWidth = max(1, (int)round($srcWidth * $scale));
    $dstHeight = max(1, (int)round($srcHeight * $scale));

    $thumb = imagecreatetruecolor($dstWidth, $dstHeight);
    // White background under any transparency (e.g. a pasted PNG
    // screenshot) -- the thumbnail is always saved as JPEG, which has no
    // alpha channel.
    $white = imagecolorallocate($thumb, 255, 255, 255);
    imagefill($thumb, 0, 0, $white);
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $dstWidth, $dstHeight, $srcWidth, $srcHeight);

    $thumbRelativeDir = dirname($relativeSourcePath) . '/thumbs';
    $thumbAbsoluteDir = core_get_dir_in_lib('appointment_files') . '/' . $thumbRelativeDir;
    appointment_files_mkdir($thumbAbsoluteDir);

    $baseName = pathinfo($relativeSourcePath, PATHINFO_FILENAME) . '.jpg';
    $thumbFilename = appointment_files_dedupe_filename($thumbAbsoluteDir, $baseName);

    $ok = imagejpeg($thumb, $thumbAbsoluteDir . '/' . $thumbFilename, APPOINTMENT_FILE_THUMBNAIL_JPEG_QUALITY);
    imagedestroy($thumb);

    return $ok ? ($thumbRelativeDir . '/' . $thumbFilename) : null;
}

// Starts an explicit output buffer for the current handler -- protects
// the response from stray output (zeusfw's core_get_dir_in_lib()/
// core_get_file_in_lib() unconditionally echopre()'s "Create folder: ..."
// the first time a directory doesn't exist yet; a PHP notice/warning if
// display_errors happens to be on; etc.) landing in front of what must be
// a clean JSON body or file stream. Call as the very first line of every
// handler below.
function appointment_files_start_clean_output() {
    ob_start();
}

// Discards ALL active output buffering, not just the level
// appointment_files_start_clean_output() opened. web/index.php itself
// wraps every route dispatch in its own ob_start() -- opened before the
// router ever calls into a handler, only closed via ob_end_flush() at the
// very end of that file -- which every handler here bypasses by calling
// exit() first. Closing only our own level left that outer buffer open:
// readfile() below was writing straight into it instead of streaming to
// the client, only flushed implicitly when PHP tore down the script at
// exit() -- and anything else that had already landed in that buffer
// before our handler ran would end up prepended ahead of the real file
// bytes in the response body, corrupting it despite a numerically-correct
// Content-Length computed from the file alone. (This was the actual cause
// of a pasted image that displayed fine as a local preview -- built
// straight from the pasted blob, no server round-trip involved -- but
// came back corrupted from both the thumbnail and the full download.)
// Looping until ob_get_level() is 0 guarantees a clean, directly-streamed
// response no matter how many levels are stacked up by the time we get
// here.
function appointment_files_discard_buffer() {
    while(ob_get_level() > 0) {
        ob_end_clean();
    }
}

// Discard-then-abort for appointment_file_download()'s early-exit paths,
// which have no JSON body to build via appointment_files_json().
function appointment_files_abort(int $status) {
    appointment_files_discard_buffer();
    http_response_code($status);
    exit();
}

function appointment_files_json($data, int $status = 200) {
    appointment_files_discard_buffer();
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

// Maps a PHP upload error code to a specific, user-facing message. Every
// $_FILES[...]['error'] value used to collapse into the same generic "no
// file uploaded" string below, regardless of cause -- actively misleading
// for UPLOAD_ERR_INI_SIZE/UPLOAD_ERR_FORM_SIZE in particular, since a file
// rejected for exceeding upload_max_filesize/post_max_size genuinely WAS
// selected and sent, it just never made it into $_FILES with any usable
// data. This is exactly what a pasted screenshot hits in practice: this
// server's own upload_max_filesize (see web/.user.ini) is raised to
// accommodate APPOINTMENT_FILE_MAX_BYTES below, but a deployment that
// hasn't picked that file up (.user.ini requires a CGI/FPM SAPI -- see its
// own comment) silently truncates the upload at the PHP layer, before
// $upload['size'] > APPOINTMENT_FILE_MAX_BYTES ever gets a chance to run
// and report its own, clearer "file too large" message -- so the user saw
// nothing but a confusing, inaccurate "no file uploaded" for a file they
// definitely did select and send.
function appointment_files_upload_error_message(int $error): string {
    switch($error) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'image exceeds this server\'s upload limit (' . ini_get('upload_max_filesize') . ') -- try a smaller image';
        case UPLOAD_ERR_PARTIAL:
            return 'upload was interrupted -- please try again';
        case UPLOAD_ERR_NO_FILE:
            return 'no file uploaded';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return 'upload failed on the server -- please try again';
        default:
            return 'upload failed';
    }
}

function appointment_file_upload($params) {
    global $kernel;
    appointment_files_start_clean_output();

    if(($ret = rbacClass::require(ZPMS_PERM_APPOINTMENT_EDIT))) {
        appointment_files_json(['success' => false, 'error' => 'unauthorized'], 401);
    }

    if(!csrfClass::verifyRequest()) {
        appointment_files_json(['success' => false, 'error' => 'invalid csrf token'], 403);
    }

    if(!isset($params['id'])) {
        appointment_files_json(['success' => false, 'error' => 'appointment not found'], 404);
    }

    $ap = appointmentsClass::sgetById($params['id']);
    if(!$ap || $ap->getdeleted() !== null) {
        appointment_files_json(['success' => false, 'error' => 'appointment not found'], 404);
    }

    if(!isset($_FILES['appointmentFile'])) {
        appointment_files_json(['success' => false, 'error' => 'no file uploaded'], 400);
    }

    if($_FILES['appointmentFile']['error'] !== UPLOAD_ERR_OK) {
        appointment_files_json(['success' => false, 'error' => appointment_files_upload_error_message($_FILES['appointmentFile']['error'])], 400);
    }

    $upload = $_FILES['appointmentFile'];

    if($upload['size'] <= 0 || $upload['size'] > APPOINTMENT_FILE_MAX_BYTES) {
        appointment_files_json(['success' => false, 'error' => 'file too large'], 400);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $upload['tmp_name']);
    finfo_close($finfo);

    if(!isset(APPOINTMENT_FILE_ALLOWED_MIME_EXTENSIONS[$mimeType])) {
        appointment_files_json(['success' => false, 'error' => 'unsupported file type'], 400);
    }

    $patient = patientsClassEx::sgetByGuid($ap->getpguid());
    $patientName = $patient ? $patient->getpname() : 'unknown';
    $patientGuid = $ap->getpguid();

    [$destPath, $relativePath] = appointment_files_resolve_storage_path(
        ['id' => $ap->getid(), 'adate' => $ap->getadate()],
        $patientName,
        $patientGuid,
        basename($upload['name'])
    );

    if(!move_uploaded_file($upload['tmp_name'], $destPath)) {
        appointment_files_json(['success' => false, 'error' => 'upload failed'], 500);
    }

    $isImage = str_starts_with($mimeType, 'image/');
    $thumbnailPath = null;

    if($isImage && extension_loaded('gd')) {
        $decoded = appointment_files_decode_image($destPath);
        if(!$decoded) {
            // finfo only sniffs the header, so a file can pass that check
            // and still not be a real, complete image -- seen with
            // corrupted/truncated data from some clipboard-paste sources
            // (e.g. iOS Safari). Reject outright rather than silently
            // archiving a file staff can never actually open (which
            // would otherwise show up as an unexplained broken-image
            // placeholder with no way to view it either).
            unlink($destPath);
            appointment_files_json(['success' => false, 'error' => 'corrupt or unreadable image data -- please try again'], 400);
        }
        $thumbnailPath = appointment_files_save_thumbnail($decoded, $relativePath);
        imagedestroy($decoded);
    }

    // Currently only ever populated by the "paste from clipboard" flow
    // (appointment-files.js) -- a pasted image has no filename of its own
    // to describe it. A normal click-to-browse/drag-drop upload simply
    // never sends this field, so $description stays ''/NULL for those.
    $description = trim((string)($_POST['description'] ?? ''));

    $entry = new appointmentFilesClass([
        'guid' => guid(),
        'cdate' => getDBtime(),
        'cuser' => $kernel->getUserName(),
        'appointment_id' => $ap->getid(),
        'file_name' => basename($upload['name']),
        'file_path' => $relativePath,
        'file_size' => $upload['size'],
        'mime_type' => $mimeType,
        'file_hash' => hash_file('sha256', $destPath),
        'thumbnail_path' => $thumbnailPath,
        'description' => $description !== '' ? $description : null,
    ]);
    $entry->insert();

    appointment_files_json([
        'success' => true,
        'file' => [
            'id' => $entry->getid(),
            'file_name' => $entry->getfile_name(),
            'size' => (int)$entry->getfile_size(),
            'mime_type' => $mimeType,
            'is_image' => $isImage,
            'description' => $entry->getdescription(),
            'download_url' => rel_url('/appointment/' . $ap->getid() . '/files/' . $entry->getid() . '/download'),
            'thumbnail_url' => rel_url('/appointment/' . $ap->getid() . '/files/' . $entry->getid() . '/thumbnail'),
            'delete_url' => rel_url('/appointment/' . $ap->getid() . '/files/' . $entry->getid() . '/delete'),
        ],
    ]);
}

function appointment_file_delete($params) {
    appointment_files_start_clean_output();

    if(($ret = rbacClass::require(ZPMS_PERM_APPOINTMENT_EDIT))) {
        appointment_files_json(['success' => false, 'error' => 'unauthorized'], 401);
    }

    if(!csrfClass::verifyRequest()) {
        appointment_files_json(['success' => false, 'error' => 'invalid csrf token'], 403);
    }

    if(!isset($params['id']) || !isset($params['fileid'])) {
        appointment_files_json(['success' => false, 'error' => 'file not found'], 404);
    }

    $file = appointmentFilesClassEx::sgetByIdForAppointment($params['fileid'], $params['id']);
    if(!$file) {
        appointment_files_json(['success' => false, 'error' => 'file not found'], 404);
    }

    $baseDir = core_get_dir_in_lib('appointment_files');
    $absolutePath = $baseDir . '/' . $file->getfile_path();
    $thumbnailPath = $file->getthumbnail_path();
    $absoluteThumbnailPath = $thumbnailPath ? ($baseDir . '/' . $thumbnailPath) : null;

    // Delete the DB row first -- the UI's view of "deleted" is
    // authoritative even if the unlink calls below fail for some reason;
    // orphaned files with no DB reference are harmless clutter, not a
    // correctness problem (same ordering rationale used elsewhere in
    // this codebase for hard deletes).
    $file->delete();

    if(is_file($absolutePath)) {
        unlink($absolutePath);
    }
    if($absoluteThumbnailPath && is_file($absoluteThumbnailPath)) {
        unlink($absoluteThumbnailPath);
    }

    appointment_files_json(['success' => true]);
}

// Builds a Content-Disposition header value that's correct for non-ASCII
// (routinely Greek) filenames. A bare `addslashes()`'d name inside plain
// filename="..." only protects against breaking out of the quotes -- it
// doesn't declare an encoding, so a browser that takes the bytes literally
// (rather than guessing UTF-8) can mangle or mis-save the name. Sends both
// a sanitized-ASCII filename= fallback and an RFC 6266 filename*=UTF-8''...
// parameter, so a browser that understands the modern form uses the real
// name and one that doesn't still gets a safe, if less pretty, fallback.
function appointment_files_content_disposition($disposition, string $originalFilename): string {
    $asciiFallback = preg_replace('/[^\x20-\x7E]/', '_', $originalFilename);
    $asciiFallback = str_replace(['"', '\\'], '_', $asciiFallback);
    if($asciiFallback === '') {
        $asciiFallback = 'file';
    }
    return $disposition . '; filename="' . $asciiFallback . '"'
        . "; filename*=UTF-8''" . rawurlencode($originalFilename);
}

function appointment_file_download($params) {
    appointment_files_start_clean_output();

    if(($ret = rbacClass::require(ZPMS_PERM_APPOINTMENT_EDIT))) {
        appointment_files_abort(401);
    }

    if(!isset($params['id']) || !isset($params['fileid'])) {
        appointment_files_abort(404);
    }

    $file = appointmentFilesClassEx::sgetByIdForAppointment($params['fileid'], $params['id']);
    if(!$file) {
        appointment_files_abort(404);
    }

    $absolutePath = core_get_dir_in_lib('appointment_files') . '/' . $file->getfile_path();
    if(!is_file($absolutePath)) {
        appointment_files_abort(404);
    }

    appointment_files_discard_buffer();
    header('Content-Type: ' . $file->getmime_type());
    header('Content-Disposition: ' . appointment_files_content_disposition('inline', $file->getfile_name()));
    header('Content-Length: ' . filesize($absolutePath));
    readfile($absolutePath);
    exit();
}

// Always returns *something* displayable, so the frontend never has to
// branch on whether a thumbnail actually exists: serves the generated
// thumbnail when present, otherwise falls back to the full original --
// covers PDFs (no thumbnail by design), uploads made before this column
// existed, and any image whose thumbnail generation failed or was
// skipped (GD unavailable).
function appointment_file_thumbnail($params) {
    appointment_files_start_clean_output();

    if(($ret = rbacClass::require(ZPMS_PERM_APPOINTMENT_EDIT))) {
        appointment_files_abort(401);
    }

    if(!isset($params['id']) || !isset($params['fileid'])) {
        appointment_files_abort(404);
    }

    $file = appointmentFilesClassEx::sgetByIdForAppointment($params['fileid'], $params['id']);
    if(!$file) {
        appointment_files_abort(404);
    }

    $baseDir = core_get_dir_in_lib('appointment_files');
    $thumbnailPath = $file->getthumbnail_path();

    $absolutePath = null;
    $mimeType = null;
    if($thumbnailPath && is_file($baseDir . '/' . $thumbnailPath)) {
        $absolutePath = $baseDir . '/' . $thumbnailPath;
        $mimeType = 'image/jpeg';
    } else {
        $originalPath = $baseDir . '/' . $file->getfile_path();
        if(is_file($originalPath)) {
            $absolutePath = $originalPath;
            $mimeType = $file->getmime_type();
        }
    }

    if(!$absolutePath) {
        appointment_files_abort(404);
    }

    appointment_files_discard_buffer();
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline');
    header('Content-Length: ' . filesize($absolutePath));
    readfile($absolutePath);
    exit();
}
