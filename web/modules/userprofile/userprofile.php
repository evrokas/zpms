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

        return $this->RenderTemplate([
            'user' => $user,
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

        // echopre("UserProfile module::run()");
        return $this->render($params);
    }
}


function register_userprofile_module() {
    global $kernel;

    $kernel->registerModule( new UserProfileModule(__DIR__, 'userprofile', 'user_profile.zetem'));
}