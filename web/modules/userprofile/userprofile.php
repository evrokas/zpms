<?php

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
            'totp_activated' => true,
            // TOTP is hidden from the profile page for now -- totp_handler()
            // (web/index.php) is a stub that encodes a hardcoded literal
            // string into the QR code, not a real per-user TOTP secret, so
            // showing the section as a working security feature would be
            // actively misleading. Flip this back to true once real TOTP
            // secret generation/verification is implemented; nothing else
            // needs to change.
            'totp_ui_enabled' => false,
            'devices' => $devices,
            'current_selector' => $currentSelector,
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
            }
            header('location: ' . rel_url('/profile'));
            exit();
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

            if($newName === '') {
                $kernel->addStatus('error', 'Το όνομα δεν μπορεί να είναι κενό.');
                header('location: ' . rel_url('/profile'));
                exit();
            }

            $fields = [
                'active' => isset($_POST['useractive']) ? 1 : 0,
                'expired' => isset($_POST['userexpired']) ? 1 : 0,
                'name' => $newName,
            ];

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
            }

            $user->loadFields($fields);
            $user->update();

            $kernel->addStatus('notice', 'Οι αλλαγές αποθηκεύτηκαν.');
            header('location: ' . rel_url('/profile'));
            exit();
        }

        // echopre("UserProfile module::run()");
        return $this->render($params);
    }
}


function register_userprofile_module() {
    global $kernel;

    $kernel->registerModule( new UserProfileModule(__DIR__, 'userprofile', 'user_profile.zetem'));
}