<?php

/**
 * Appointment attachments (photos/scanned documents uploaded against a
 * specific appointment entry) -- see CLAUDE.md's appointment-files section
 * and /root/.claude/plans/zpms-project-is-a-purrfect-kay.md for the full
 * design. Modeled on web/modules/pdflib/pdflib.php, the one confirmed-
 * working file upload feature in this app, but deliberately does not
 * replicate two of its gaps: it checks $_FILES[...]['error'] before
 * treating an upload as present, and it checks SecurityClass::require()'s
 * return value instead of ignoring it (most existing call sites in this
 * codebase, including appointment_edit_post(), do not check it -- that is
 * a pre-existing fail-open bug, not a pattern to copy).
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

function appointment_files_json($data, int $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

function appointment_file_upload($params) {
    global $kernel;

    if(($ret = SecurityClass::require('appointment-edit'))) {
        appointment_files_json(['success' => false, 'error' => 'unauthorized'], 401);
    }

    if(!isset($params['id'])) {
        appointment_files_json(['success' => false, 'error' => 'appointment not found'], 404);
    }

    $ap = appointmentsClass::sgetById($params['id']);
    if(!$ap || $ap->getdeleted() !== null) {
        appointment_files_json(['success' => false, 'error' => 'appointment not found'], 404);
    }

    if(!isset($_FILES['appointmentFile']) || $_FILES['appointmentFile']['error'] !== UPLOAD_ERR_OK) {
        appointment_files_json(['success' => false, 'error' => 'no file uploaded'], 400);
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
    ]);
    $entry->insert();

    $isImage = str_starts_with($mimeType, 'image/');

    appointment_files_json([
        'success' => true,
        'file' => [
            'id' => $entry->getid(),
            'file_name' => $entry->getfile_name(),
            'size' => (int)$entry->getfile_size(),
            'mime_type' => $mimeType,
            'is_image' => $isImage,
            'download_url' => rel_url('/appointment/' . $ap->getid() . '/files/' . $entry->getid() . '/download'),
            'delete_url' => rel_url('/appointment/' . $ap->getid() . '/files/' . $entry->getid() . '/delete'),
        ],
    ]);
}

function appointment_file_delete($params) {
    if(($ret = SecurityClass::require('appointment-edit'))) {
        appointment_files_json(['success' => false, 'error' => 'unauthorized'], 401);
    }

    if(!isset($params['id']) || !isset($params['fileid'])) {
        appointment_files_json(['success' => false, 'error' => 'file not found'], 404);
    }

    $file = appointmentFilesClassEx::sgetByIdForAppointment($params['fileid'], $params['id']);
    if(!$file) {
        appointment_files_json(['success' => false, 'error' => 'file not found'], 404);
    }

    $absolutePath = core_get_dir_in_lib('appointment_files') . '/' . $file->getfile_path();

    // Delete the DB row first -- the UI's view of "deleted" is
    // authoritative even if the unlink below fails for some reason; an
    // orphaned file with no DB reference is harmless clutter, not a
    // correctness problem (same ordering rationale used elsewhere in
    // this codebase for hard deletes).
    $file->delete();

    if(is_file($absolutePath)) {
        unlink($absolutePath);
    }

    appointment_files_json(['success' => true]);
}

function appointment_file_download($params) {
    if(($ret = SecurityClass::require('appointment-edit'))) {
        http_response_code(401);
        exit();
    }

    if(!isset($params['id']) || !isset($params['fileid'])) {
        http_response_code(404);
        exit();
    }

    $file = appointmentFilesClassEx::sgetByIdForAppointment($params['fileid'], $params['id']);
    if(!$file) {
        http_response_code(404);
        exit();
    }

    $absolutePath = core_get_dir_in_lib('appointment_files') . '/' . $file->getfile_path();
    if(!is_file($absolutePath)) {
        http_response_code(404);
        exit();
    }

    header('Content-Type: ' . $file->getmime_type());
    header('Content-Disposition: inline; filename="' . addslashes($file->getfile_name()) . '"');
    header('Content-Length: ' . filesize($absolutePath));
    readfile($absolutePath);
    exit();
}
