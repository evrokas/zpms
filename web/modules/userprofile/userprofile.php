<?php

require_once(__DIR__ . '/notes_sanitizer.php');

class UserProfileModule extends moduleClass {

    function __construct($adir, $amodule, $atemplate) {
        parent::__construct($adir, $amodule, $atemplate);

        $rt = yaml_parse_file(__DIR__ . '/userprofile.yaml');
        
        global $kernel;
        $srt = $kernel->resolveModuleDir($rt, $adir, $amodule);
        $kernel->addConfig( $srt );

        // re-initialize routes
        global $router;
        $router->initRouteTable($kernel->getConfig('routes'));
        // echo "<pre>Router routes: " . print_r( $router->getAllRoutes(), 1) . "</pre>";
    }

    function render($params = array()) {
        global $kernel;

        if(!($u=$kernel->getUserName())) {
            return "";
        }

        $user = UsersClassEx::getUserAccount( $u );

        $devices = userTokensClassEx::getUserTokens($u);
        $currentSelector = null;
        $cookie = filter_input(INPUT_COOKIE, 'zeusfwrememberme');
        if($cookie) {
            $parsed = userTokensClassEx::parse_token($cookie);
            if($parsed) {
                $currentSelector = $parsed[0];
            }
        }

        // Location select -- same data the location module itself uses
        // (locationsClassEx::sgetAll()/$_SESSION['location']), duplicated
        // here since the header's own location module now only renders a
        // read-only badge; the profile page is where it's actually changed.
        $locs = locationsClassEx::sgetAll( $kernel->getCurrentLanguage() );
        $locationNames = array();
        foreach($locs as $l)
            $locationNames[] = $l->getname();
        $currentLocation = $_SESSION['location'] ?? ($locationNames[1] ?? ($locationNames[0] ?? ''));

        return $this->RenderTemplate([
            'user' => $user,
            'location_places' => $locationNames,
            'location' => $currentLocation,
            $user->getactive()?'checked="checked"':'',
            $user->getExpired()?'checked="checked"':'',
            'devices' => $devices,
            'current_selector' => $currentSelector,
            'notes_html' => $user ? zpms_profile_notes_load((int)$user->getid()) : '',
        ]);
    }

    function run($params = array()) {
        global $kernel;

        // Devices section's per-row Revoke button -- posts back to /profile
        // with action=revoke_device + token_id. The rest of this page's
        // (disabled/read-only) form has no 'action' field at all, so this
        // check can't collide with anything else this route already does.
        if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke_device') {
            if(!csrfClass::verifyRequest()) {
                $kernel->addStatus('error', 'Μη έγκυρο token ασφαλείας (CSRF). Παρακαλώ προσπαθήστε ξανά.');
                header('location: ' . rel_url('/profile'));
                exit();
            }

            $u = $kernel->getUserName();
            $id = (int)($_POST['token_id'] ?? 0);
            if($u && $id) {
                userTokensClassEx::delete_by_id_for_uname($id, $u);
                zpms_audit('device_revoked', ['target_user' => $u, 'detail' => 'token_id=' . $id]);
            }
            header('location: ' . rel_url('/profile'));
            exit();
        }

        // Notes box (Quill editor). Saved by loader.js's autosave (1s after
        // the last keystroke) as an AJAX POST, or as a plain form post
        // without JavaScript. Always the signed-in user's own row -- no id
        // is taken from the request.
        if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_notes') {
            $ajax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') || isset($_POST['use_ajax']);
            $reply = function(int $status, array $body, string $level, string $message) use ($ajax, $kernel) {
                if($ajax) {
                    http_response_code($status);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode($body);
                    exit();
                }
                $kernel->addStatus($level, $message);
                header('location: ' . rel_url('/profile'));
                exit();
            };

            if(!csrfClass::verifyRequest()) {
                $reply(403, ['ok' => false, 'error' => 'csrf'], 'error', 'Μη έγκυρο token ασφαλείας (CSRF). Παρακαλώ προσπαθήστε ξανά.');
            }

            $u = $kernel->getUserName();
            $user = $u ? UsersClassEx::getUserAccount($u) : null;
            if(!$user) {
                $reply(403, ['ok' => false, 'error' => 'no_user'], 'error', 'Δεν βρέθηκε ο λογαριασμός.');
            }

            $raw = (string)($_POST['notes'] ?? '');
            if(strlen($raw) > ZPMS_NOTES_MAX_BYTES) {
                $reply(413, ['ok' => false, 'error' => 'too_large'], 'error', 'Οι σημειώσεις είναι πολύ μεγάλες για αποθήκευση.');
            }

            $clean = zpms_sanitize_notes_html($raw);
            if(zpms_profile_notes_save((int)$user->getid(), $clean)) {
                zpms_audit('profile_notes_updated', ['target_user' => $u]);
            }
            $reply(200, ['ok' => true], 'notice', 'Οι σημειώσεις αποθηκεύτηκαν.');
        }

        // Main account form: active/expired flags and an optional password
        // change, same "leave blank to keep the current password" +
        // password_hash(..., PASSWORD_DEFAULT) convention zeusfw core's own
        // admin_crud.php uses for the same users.upass column (see
        // zeusfw_admin_apply_field()'s 'password' case) -- the plaintext is
        // never stored or redisplayed, so the field is always blank on load
        // regardless of whether an account has a password set.
        if($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_account') {
            if(!csrfClass::verifyRequest()) {
                $kernel->addStatus('error', 'Μη έγκυρο token ασφαλείας (CSRF). Παρακαλώ προσπαθήστε ξανά.');
                header('location: ' . rel_url('/profile'));
                exit();
            }

            $u = $kernel->getUserName();
            $user = UsersClassEx::getUserAccount($u);
            if(!$user) {
                header('location: ' . rel_url('/profile'));
                exit();
            }

            $newPassword = trim((string)($_POST['new_password'] ?? ''));
            $confirmPassword = trim((string)($_POST['confirm_password'] ?? ''));
            $newName = trim((string)($_POST['user_name'] ?? ''));
            $newEmail = trim((string)($_POST['user_email'] ?? ''));

            if($newName === '') {
                $kernel->addStatus('error', 'Το όνομα δεν μπορεί να είναι κενό.');
                header('location: ' . rel_url('/profile'));
                exit();
            }

            if($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $kernel->addStatus('error', 'Το email δεν είναι έγκυρο.');
                header('location: ' . rel_url('/profile'));
                exit();
            }

            // active/expired are shown read-only (disabled checkboxes, which
            // a browser never submits) -- they used to be read from the POST
            // here anyway, so saving your own profile switched your account
            // to inactive. They are left as they are; only an administrator
            // changes them, on /admin/users.
            $fields = [
                'name' => $newName,
                'email' => $newEmail,
            ];
            $changed = [];
            if((string)$user->getname() !== $newName) $changed[] = 'name';
            if((string)$user->getemail() !== $newEmail) $changed[] = 'email';

            if($newPassword !== '') {
                if(mb_strlen($newPassword) < 8) {
                    $kernel->addStatus('error', 'Ο νέος κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.');
                    header('location: ' . rel_url('/profile'));
                    exit();
                }
                if($newPassword !== $confirmPassword) {
                    $kernel->addStatus('error', 'Η επιβεβαίωση κωδικού δεν ταιριάζει με τον νέο κωδικό.');
                    header('location: ' . rel_url('/profile'));
                    exit();
                }
                $fields['upass'] = password_hash($newPassword, PASSWORD_DEFAULT);
                $changed[] = 'password';
            }

            $user->loadFields($fields);
            $user->update();
            if($changed) {
                zpms_audit('profile_updated', ['target_user' => $u, 'detail' => zpms_audit_fields($changed)]);
            }

            $kernel->addStatus('notice', 'Οι αλλαγές αποθηκεύτηκαν.');
            header('location: ' . rel_url('/profile'));
            exit();
        }

        // echopre("UserProfile module::run()");
        return $this->render($params);
    }
}


/** The user's saved notes (sanitized HTML), '' when there are none. */
function zpms_profile_notes_load(int $userId): string {
    try {
        $st = dbConnection::getConnection()->prepare('SELECT notes FROM user_notes WHERE user_id = ?');
        $st->execute([$userId]);
        return (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
        error_log('zpms_profile_notes_load: ' . $e->getMessage());
        return '';
    }
}

/** Stores the notes; true only when the text actually changed. */
function zpms_profile_notes_save(int $userId, string $html): bool {
    if(zpms_profile_notes_load($userId) === $html) return false;
    $st = dbConnection::getConnection()->prepare(
        'INSERT INTO user_notes (user_id, notes, updated_at) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE notes = VALUES(notes), updated_at = VALUES(updated_at)');
    $st->execute([$userId, $html, getDBtime()]);
    return true;
}


function register_userprofile_module() {
    global $kernel;

    $kernel->registerModule( new UserProfileModule(__DIR__, 'userprofile', 'user_profile.zetem'));
}