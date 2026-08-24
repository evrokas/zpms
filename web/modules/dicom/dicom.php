<?php

require_once __DIR__ . '/DicomParser.php';
require_once __DIR__ . '/DicomDirParser.php';
require_once __DIR__ . '/DicomConverter.php';

class dicomModule extends moduleClass {

    const CONFIG = [
        'storage_base'              => 'data/dicom',
        'max_upload_size_mb'        => 500,
        'allowed_extensions'        => ['dcm', 'zip', 'gz'],
        'chunk_size_bytes'          => 2097152,    // 2 MB
        'dcmtk_bin_path'            => '/usr/bin',
        'thumbnail_width'           => 200,
        'full_image_format'         => 'png',
        'thumb_quality'             => 80,
        'share_default_expiry_days' => 30,
    ];

    public function __construct($adir, $amodule, $atemplate) {
        parent::__construct($adir, $amodule, $atemplate);

        $rt = yaml_parse_file(__DIR__ . '/dicom.yaml');

        global $kernel;
        $srt = $kernel->resolveModuleDir($rt, $adir, $amodule);
        $kernel->addConfig($srt);

        global $router;
        $router->initRouteTable($kernel->getConfig('routes'));
    }

    function render($params = array()) {
        return '';  // No region rendering — DICOM uses dedicated page templates
    }

    static function getConfig() {
        return self::CONFIG;
    }

    static function getStorageBase() {
        return __APPDIR__ . '/' . self::CONFIG['storage_base'];
    }
}

function register_dicom_module() {
    global $kernel;
    $kernel->registerModule(new dicomModule(__DIR__, 'dicom', 'dicom.zetem'));
}


// ─── EXAM LIST ─────────────────────────────────────────────────────────────────

function dicom_list($params) {
    if ($ret = SecurityClass::require('dicom-view')) return $ret;

    $page     = (int)($params['page'] ?? 1);
    $per_page = 20;
    $offset   = ($page - 1) * $per_page;

    $exams       = dicom_examsClassEx::getExamList($per_page, $offset);
    $total       = dicom_examsClassEx::getExamCount();
    $total_pages = (int)ceil($total / $per_page);

    return Renderer::render('dicom_list.zetem', [
        'exams'       => $exams,
        'page'        => $page,
        'total_pages' => $total_pages,
        'total'       => $total,
    ]);
}


// ─── UPLOAD PAGE ───────────────────────────────────────────────────────────────

function dicom_upload($params) {
    if ($ret = SecurityClass::require('dicom-upload')) return $ret;

    $config = dicomModule::getConfig();
    return Renderer::render('dicom_upload.zetem', [
        'max_size_mb' => $config['max_upload_size_mb'],
        'chunk_size'  => $config['chunk_size_bytes'],
        'allowed_ext' => implode(', ', $config['allowed_extensions']),
    ]);
}


// ─── AJAX: UPLOAD INIT ─────────────────────────────────────────────────────────

function dicom_upload_init($params) {
    if (SecurityClass::require('dicom-upload')) {
        dicom_json(['error' => 'Unauthorized'], 403);
    }

    $config       = dicomModule::getConfig();
    $filename     = $_POST['filename'] ?? '';
    $filesize     = (int)($_POST['filesize'] ?? 0);
    $total_chunks = (int)($_POST['total_chunks'] ?? 1);

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, $config['allowed_extensions'])) {
        dicom_json(['error' => 'File type not allowed'], 400);
    }

    $max_bytes = $config['max_upload_size_mb'] * 1024 * 1024;
    if ($filesize > $max_bytes) {
        dicom_json(['error' => 'File too large'], 400);
    }

    $token      = bin2hex(random_bytes(16));
    $upload_dir = dicomModule::getStorageBase() . '/uploads/' . $token . '/chunks';
    mkdir($upload_dir, 0755, true);

    $session = [
        'token'        => $token,
        'filename'     => $filename,
        'filesize'     => $filesize,
        'total_chunks' => $total_chunks,
        'received'     => 0,
        'created_at'   => date('Y-m-d H:i:s'),
    ];
    file_put_contents(
        dicomModule::getStorageBase() . '/uploads/' . $token . '/session.json',
        json_encode($session)
    );

    dicom_json([
        'upload_token' => $token,
        'chunk_size'   => $config['chunk_size_bytes'],
    ]);
}


// ─── AJAX: UPLOAD CHUNK ────────────────────────────────────────────────────────

function dicom_upload_chunk($params) {
    if (SecurityClass::require('dicom-upload')) {
        dicom_json(['error' => 'Unauthorized'], 403);
    }

    $token = $_POST['upload_token'] ?? '';
    $index = (int)($_POST['chunk_index'] ?? 0);
    $base  = dicomModule::getStorageBase();

    $session_file = $base . '/uploads/' . $token . '/session.json';
    if (!file_exists($session_file)) {
        dicom_json(['error' => 'Invalid upload token'], 400);
    }

    if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        dicom_json(['error' => 'Chunk upload failed'], 400);
    }

    $chunk_path = $base . '/uploads/' . $token . '/chunks/'
                . str_pad($index, 6, '0', STR_PAD_LEFT);
    move_uploaded_file($_FILES['chunk']['tmp_name'], $chunk_path);

    $session = json_decode(file_get_contents($session_file), true);
    $session['received']++;
    file_put_contents($session_file, json_encode($session));

    dicom_json([
        'received' => $session['received'],
        'total'    => $session['total_chunks'],
        'complete' => ($session['received'] >= $session['total_chunks']),
    ]);
}


// ─── AJAX: UPLOAD FINALIZE ─────────────────────────────────────────────────────

function dicom_upload_finalize($params) {
    if (SecurityClass::require('dicom-upload')) {
        dicom_json(['error' => 'Unauthorized'], 403);
    }

    $config   = dicomModule::getConfig();
    $base     = dicomModule::getStorageBase();
    $token    = $_POST['upload_token'] ?? '';
    $base_dir = $base . '/uploads/' . $token;
    $session_file = $base_dir . '/session.json';

    if (!file_exists($session_file)) {
        dicom_json(['error' => 'Invalid upload token'], 400);
    }
    $session = json_decode(file_get_contents($session_file), true);

    // 1. Assemble chunks
    $ext      = strtolower(pathinfo($session['filename'], PATHINFO_EXTENSION));
    $assembled = $base_dir . '/assembled.' . $ext;
    $out = fopen($assembled, 'wb');
    $chunks = glob($base_dir . '/chunks/*');
    sort($chunks);
    foreach ($chunks as $chunk) {
        $in = fopen($chunk, 'rb');
        stream_copy_to_stream($in, $out);
        fclose($in);
    }
    fclose($out);

    // 2. Create exam DB record
    $db = dbConnection::getConnection();

    $exam = new dicom_examsClass();
    $exam->setstorage_path('');
    $exam->setstatus('processing');
    $exam->setuploaded_by($_SESSION['user_id'] ?? null);
    $exam->setdisk_size(filesize($assembled));
    $exam->setcreated_at(getDBtime());
    $exam->setupdated_at(getDBtime());
    $exam->insert();
    $exam_id = $db->lastInsertId();

    // 3. Create directory structure
    $exam_dir   = $base . '/exams/' . $exam_id;
    $orig_dir   = $exam_dir . '/original';
    $images_dir = $exam_dir . '/images';
    mkdir($orig_dir, 0755, true);
    mkdir($images_dir, 0755, true);

    // Update storage path
    $exam = dicom_examsClass::sgetById($exam_id);
    $exam->setstorage_path('exams/' . $exam_id);
    $exam->update();

    // 4. Extract ZIP or copy single DCM
    if ($ext === 'zip') {
        $zip = new ZipArchive();
        if ($zip->open($assembled) === true) {
            $zip->extractTo($orig_dir);
            $zip->close();
        } else {
            dicom_update_exam_status($exam_id, 'error', 'ZIP extraction failed');
            dicom_json(['error' => 'ZIP extraction failed'], 500);
        }
    } else {
        copy($assembled, $orig_dir . '/' . $session['filename']);
    }

    // 5. Verify DCMTK
    $converter = new DicomConverter($config);
    if (!$converter->checkDcmtk()) {
        dicom_update_exam_status($exam_id, 'error', 'DCMTK not found on server');
        dicom_json(['error' => 'DCMTK not installed'], 500);
    }

    // 6. Parse study metadata from first DICOM file
    $first_dcm = dicom_find_first_dcm($orig_dir);
    if ($first_dcm) {
        $parser = new DicomParser($first_dcm);
        $tags = $parser->parse();
        dicom_update_exam_meta($exam_id, $tags);
    }

    // 7. Count DICOM files
    $dcm_count = count($converter->findDcmFilesPublic($orig_dir));
    $exam = dicom_examsClass::sgetById($exam_id);
    $exam->setfile_count($dcm_count);
    $exam->update();

    // 8. Process: group by series → convert → thumbnails → DB
    $converter->processExam($exam_id, $orig_dir, $images_dir, $db);

    // 9. Mark ready + cleanup temp upload
    dicom_update_exam_status($exam_id, 'ready');
    dicom_delete_directory($base_dir);

    dicom_json([
        'exam_id'  => $exam_id,
        'status'   => 'ready',
        'redirect' => rel_url('/dicom/view/' . $exam_id),
    ]);
}


// ─── VIEWER PAGE ───────────────────────────────────────────────────────────────

function dicom_view_exam($params) {
    if ($ret = SecurityClass::require('dicom-view')) return $ret;

    $exam_id = (int)($params['id'] ?? 0);
    $exam    = dicom_examsClass::sgetById($exam_id);
    if (!$exam) {
        global $kernel;
        $kernel->addStatus('error', 'Exam not found');
        return Renderer::render('dicom_list.zetem', ['exams' => [], 'page' => 1, 'total_pages' => 0, 'total' => 0]);
    }

    $series_list = dicom_seriesClassEx::getByExamId($exam_id);
    $exam_data   = dicom_build_viewer_data($exam, $series_list);

    return Renderer::render('dicom_viewer.zetem', [
        'exam'      => $exam,
        'exam_data' => $exam_data,
        'series'    => $series_list,
    ]);
}


// ─── IMAGE SERVING (auth-gated) ────────────────────────────────────────────────

function dicom_serve_image($params) {
    $series_id   = (int)($params['series_id'] ?? 0);
    $type        = $params['type'] ?? '';
    $filename    = $params['filename'] ?? '';
    $share_token = $_GET['share_token'] ?? null;

    if (!in_array($type, ['thumb', 'full'])) {
        http_response_code(400); exit;
    }
    if (!preg_match('/^[\w\-]+\.(jpg|jpeg|png)$/', $filename)) {
        http_response_code(400); exit;
    }
    if (!dicom_authorize_image($series_id, $share_token)) {
        http_response_code(403); exit;
    }

    $series = dicom_seriesClass::sgetById($series_id);
    if (!$series || !$series->getimages_path()) {
        http_response_code(404); exit;
    }

    $base      = dicomModule::getStorageBase();
    $file_path = $base . '/' . $series->getimages_path() . '/' . $type . '/' . $filename;
    if (!file_exists($file_path)) {
        http_response_code(404); exit;
    }

    $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: private, max-age=86400');
    readfile($file_path);
    exit;
}


// ─── SHARE: CREATE ─────────────────────────────────────────────────────────────

function dicom_share_create($params) {
    if (SecurityClass::require('dicom-share')) {
        dicom_json(['error' => 'Unauthorized'], 403);
    }

    $config  = dicomModule::getConfig();
    $exam_id = (int)($params['id'] ?? 0);
    $days    = (int)($_POST['expiry_days'] ?? $config['share_default_expiry_days']);

    $exam = dicom_examsClass::sgetById($exam_id);
    if (!$exam) {
        dicom_json(['error' => 'Exam not found'], 404);
    }

    $token      = bin2hex(random_bytes(24));
    $expires_at = ($days > 0) ? date('Y-m-d H:i:s', time() + $days * 86400) : null;

    $share = new dicom_sharesClass();
    $share->setexam_id($exam_id);
    $share->settoken($token);
    $share->setcreated_by($_SESSION['user_id'] ?? null);
    $share->setexpires_at($expires_at);
    $share->setcreated_at(getDBtime());
    $share->insert();

    $share_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://'
               . $_SERVER['HTTP_HOST'] . rel_url('/dicom/shared/' . $token);

    dicom_json([
        'token'      => $token,
        'url'        => $share_url,
        'expires_at' => $expires_at,
    ]);
}


// ─── SHARE: PUBLIC VIEW ────────────────────────────────────────────────────────

function dicom_shared_view($params) {
    $token = $params['token'] ?? '';

    $share = dicom_sharesClassEx::getByToken($token);
    if (!$share || !$share->getis_active()) {
        return Renderer::render('dicom_shared_error.zetem', ['error' => 'not_found']);
    }
    if ($share->getexpires_at() && strtotime($share->getexpires_at()) < time()) {
        return Renderer::render('dicom_shared_error.zetem', ['error' => 'expired']);
    }

    $share->setview_count($share->getview_count() + 1);
    $share->update();

    $exam = dicom_examsClass::sgetById($share->getexam_id());
    if (!$exam || $exam->getstatus() !== 'ready') {
        return Renderer::render('dicom_shared_error.zetem', ['error' => 'not_found']);
    }

    $series_list = dicom_seriesClassEx::getByExamId($exam->getid());
    $exam_data   = dicom_build_viewer_data($exam, $series_list, $token);

    return Renderer::render('dicom_viewer.zetem', [
        'exam'        => $exam,
        'exam_data'   => $exam_data,
        'series'      => $series_list,
        'share_token' => $token,
        'readonly'    => true,
    ]);
}


// ─── DELETE EXAM ───────────────────────────────────────────────────────────────

function dicom_delete_exam($params) {
    if ($ret = SecurityClass::require('dicom-delete')) return $ret;

    global $kernel;
    $exam_id = (int)($params['id'] ?? 0);
    $exam    = dicom_examsClass::sgetById($exam_id);

    if (!$exam) {
        $kernel->addStatus('error', 'Exam not found');
    } else {
        $exam_dir = dicomModule::getStorageBase() . '/' . $exam->getstorage_path();
        if (is_dir($exam_dir)) {
            dicom_delete_directory($exam_dir);
        }
        $exam->delete();
        $kernel->addStatus('notice', 'DICOM exam deleted');
    }

    header('location: ' . rel_url('/dicom'));
    exit;
}


// ═══ HELPER FUNCTIONS ══════════════════════════════════════════════════════════

function dicom_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function dicom_update_exam_status($exam_id, $status, $error = null) {
    $exam = dicom_examsClass::sgetById($exam_id);
    if ($exam) {
        $exam->setstatus($status);
        $exam->seterror_message($error);
        $exam->setupdated_at(getDBtime());
        $exam->update();
    }
}

function dicom_update_exam_meta($exam_id, $tags) {
    $exam = dicom_examsClass::sgetById($exam_id);
    if (!$exam) return;

    $study_date = null;
    if (!empty($tags['study_date']) && strlen($tags['study_date']) === 8) {
        $study_date = substr($tags['study_date'], 0, 4) . '-'
                    . substr($tags['study_date'], 4, 2) . '-'
                    . substr($tags['study_date'], 6, 2);
    }

    $exam->setstudy_uid($tags['study_instance_uid'] ?? null);
    $exam->setpatient_name($tags['patient_name'] ?? null);
    $exam->setpatient_id_dcm($tags['patient_id'] ?? null);
    $exam->setstudy_date($study_date);
    $exam->setstudy_desc($tags['study_description'] ?? null);
    $exam->setaccession_no($tags['accession_number'] ?? null);
    $exam->setmodality($tags['modality'] ?? null);
    $exam->setupdated_at(getDBtime());
    $exam->update();
}

function dicom_authorize_image($series_id, $share_token) {
    if (SecurityClass::userLoggedIn()) return true;

    if ($share_token) {
        $share = dicom_sharesClassEx::getByToken($share_token);
        if (!$share || !$share->getis_active()) return false;
        if ($share->getexpires_at() && strtotime($share->getexpires_at()) < time()) return false;

        $series = dicom_seriesClass::sgetById($series_id);
        if (!$series || $series->getexam_id() != $share->getexam_id()) return false;

        return true;
    }
    return false;
}

function dicom_build_viewer_data($exam, $series_list, $share_token = null) {
    $token_param = $share_token ? '?share_token=' . urlencode($share_token) : '';
    $data = ['series' => []];

    foreach ($series_list as $s) {
        if ($s->getstatus() !== 'ready') continue;

        $images   = dicom_imagesClassEx::getBySeries($s->getid());
        $img_list = [];
        foreach ($images as $img) {
            $img_list[] = [
                'thumb_url' => rel_url('/dicom/image/' . $s->getid() . '/thumb/' . $img->getthumb_filename()) . $token_param,
                'full_url'  => rel_url('/dicom/image/' . $s->getid() . '/full/' . $img->getfull_filename()) . $token_param,
            ];
        }

        $data['series'][] = [
            'id'     => $s->getid(),
            'name'   => $s->getseries_desc() ?: ($s->getmodality() . ' Series ' . $s->getseries_number()),
            'images' => $img_list,
        ];
    }

    return json_encode($data);
}

function dicom_find_first_dcm($dir) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iter as $f) {
        if (!$f->isFile()) continue;
        if (strtolower($f->getExtension()) === 'dcm') return $f->getPathname();
        if ($f->getExtension() === '' && DicomParser::isDicom($f->getPathname())) return $f->getPathname();
    }
    return null;
}

function dicom_delete_directory($dir) {
    if (!is_dir($dir)) return;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}
