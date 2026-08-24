<?php
/**
 * ZPMS SSO endpoint — served directly by Apache (bypasses zeusfw routing).
 * Handles ErnsAuth challenge-response SSO flow for ZPMS login.
 */

session_start();

define('__APPDIR__', dirname(__DIR__));
require_once __APPDIR__ . '/config/db.php';
require_once __APPDIR__ . '/config/ernsauth.php';
require_once __APPDIR__ . '/lib/ErnsAuthClient.php';

header('Content-Type: application/json');

if (!defined('ERNSAUTH_ENABLED') || !ERNSAUTH_ENABLED) {
    http_response_code(503);
    echo json_encode(['error' => 'SSO not enabled']);
    exit;
}

$action = $_GET['action'] ?? '';

// ── Create challenge ──────────────────────────────────────────────────────────

if ($action === 'sso_create_challenge') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $username = trim($body['username'] ?? '');
    if ($username === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Username is required']);
        exit;
    }

    // Validate the ZPMS account before touching ErnsAuth
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        http_response_code(503);
        echo json_encode(['error' => 'Database unavailable']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT uname, roles, active, expired, ernsauth_username FROM users WHERE uname = :username LIMIT 1');
    $stmt->execute([':username' => $username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(403);
        echo json_encode(['error' => 'No ZPMS account for this user']);
        exit;
    }
    if (!$row['active']) {
        http_response_code(403);
        echo json_encode(['error' => 'Account is disabled']);
        exit;
    }
    if ($row['expired']) {
        http_response_code(403);
        echo json_encode(['error' => 'Account has expired']);
        exit;
    }

    $client = new ErnsAuthClient(ERNSAUTH_URL, ERNSAUTH_API_KEY);
    try {
        $result = $client->createChallenge(
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        );
    } catch (RuntimeException $e) {
        http_response_code(503);
        echo json_encode(['error' => 'ErnsAuth service unavailable']);
        exit;
    }

    $_SESSION['sso_challenge_id']       = $result['challenge_id'];
    $_SESSION['sso_zpms_username']      = $row['uname'];
    $_SESSION['sso_zpms_roles']         = $row['roles'];
    // Use ernsauth_username if set, otherwise fall back to uname
    $_SESSION['sso_ernsauth_username']  = $row['ernsauth_username'] ?: $row['uname'];

    echo json_encode([
        'challenge_id'     => $result['challenge_id'],
        'challenge_number' => $result['challenge_number'],
        'expires_at'       => $result['expires_at'],
    ]);
    exit;
}

// ── Poll challenge ────────────────────────────────────────────────────────────

if ($action === 'sso_poll_challenge') {
    $challenge_id = $_GET['challenge_id'] ?? '';

    if (
        empty($challenge_id) ||
        !isset($_SESSION['sso_challenge_id']) ||
        $_SESSION['sso_challenge_id'] !== $challenge_id
    ) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid challenge']);
        exit;
    }

    $client = new ErnsAuthClient(ERNSAUTH_URL, ERNSAUTH_API_KEY);
    try {
        $poll = $client->pollChallenge($challenge_id);
    } catch (RuntimeException $e) {
        http_response_code(503);
        echo json_encode(['error' => 'ErnsAuth service unavailable']);
        exit;
    }

    $status = $poll['status'] ?? 'pending';

    if ($status !== 'approved') {
        echo json_encode(['status' => $status]);
        exit;
    }

    // Exchange auth_code server-side — never forwarded to browser
    try {
        $user_info = $client->exchangeCode($poll['auth_code']);
    } catch (RuntimeException $e) {
        http_response_code(503);
        echo json_encode(['error' => 'ErnsAuth service unavailable']);
        exit;
    }

    // Verify the approving ErnsAuth user matches the expected ErnsAuth username for this account
    $ernsauth_username = $user_info['username'] ?? '';
    if ($ernsauth_username !== $_SESSION['sso_ernsauth_username']) {
        http_response_code(403);
        echo json_encode(['error' => 'ErnsAuth account does not match the submitted username']);
        exit;
    }

    unset($_SESSION['sso_challenge_id'], $_SESSION['sso_ernsauth_username']);
    $_SESSION['sso_pending_login'] = [
        'uname' => $_SESSION['sso_zpms_username'],
        'roles' => $_SESSION['sso_zpms_roles'],
    ];
    unset($_SESSION['sso_zpms_username'], $_SESSION['sso_zpms_roles']);

    // Compute redirect relative to this script's web root
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    echo json_encode(['authenticated' => true, 'redirect' => $base . '/login/sso-complete']);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
