<?php

class UserProfileModule extends moduleClass {

    function __construct($amodule, $atemplate) {
        parent::__construct($amodule, $atemplate);

        //add route definitions
        $profile_yaml="
        routes:
            userprofile:
                title: 'User profile'
                name: userprofile
                url: /profile
                module: userprofile
                # handler: module
                method: get
            userprofile_post:
                title: 'User profile'
                name: userprofile_post
                url: /profile
                module: userprofile
                # handler: module
                method: post          
        ";

        global $kernel;
        $kernel->addConfig( $profile_yaml );


        // $region_yaml="
        //     structure:
        //         notification:
        //             - userprofile
        //     ";

        // $kernel->addConfig($region_yaml);
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

        return $this->RenderTemplate(['user' => $user,
            $user->getactive()?'checked="checked"':'',
            $user->getExpired()?'checked="checked"':'']);
    }

    function run($params = array()) {
        // echopre("UserProfile module::run()");
        return $this->render($params);
    }
}


function register_userprofile_module() {
    global $kernel;

    $kernel->registerModule( new UserProfileModule('userprofile', 'user_profile.zetem'));
}