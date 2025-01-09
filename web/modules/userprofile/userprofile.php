<?php

class UserProfileModule extends moduleClass {

    function __construct($adir, $amodule, $atemplate) {
        parent::__construct($adir, $amodule, $atemplate);

        $rt = yaml_parse_file(__DIR__ . '/userprofile.yaml');
        
        global $kernel;
        $srt = $kernel->resolveModuleDir($rt, $adir, $amodule);
        $kernel->addConfig( $srt );

        // echo "<pre>";print_r($kernel->getConfig());echo "</pre>";
        // exit();

        // $region_yaml="
        //     structure:
        //         notification:
        //             - userprofile
        //     ";

        // $kernel->addConfig($region_yaml);

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

        return $this->RenderTemplate([
            'user' => $user,
            $user->getactive()?'checked="checked"':'',
            $user->getExpired()?'checked="checked"':'',
            'totp_activated' => true
        ]);
    }

    function run($params = array()) {
        // echopre("UserProfile module::run()");
        return $this->render($params);
    }
}


function register_userprofile_module() {
    global $kernel;

    $kernel->registerModule( new UserProfileModule(__DIR__, 'userprofile', 'user_profile.zetem'));
}