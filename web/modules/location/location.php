<?php

class locationModule extends moduleClass {
    function __construct($adir, $amodule, $atemplate) {
        parent::__construct($adir, $amodule, $atemplate);
        $rt = yaml_parse_file(__DIR__ . '/location.yaml');

        global $kernel;
        $srt = $kernel->resolveModuleDir($rt, $adir, $amodule);

        // update kernel configuration
        $kernel->addConfig( $srt );

        // echopre(print_r($kernel->getConfig(),1));

        // update router tables
        global $router;
        $router->initRouteTable($kernel->getConfig('routes'));
    }

    function render($params = array()) {
        global $kernel;

        if(!SecurityClass::userLoggedIn())return "";

        $loc = locationsClassEx::sgetAll( $kernel->getCurrentLanguage() );
        $ln = array();
        foreach($loc as $l)
            $ln[] = $l->getname();

        if(!isset($_SESSION['location']))
            $locname = $ln[1];
        else $locname = $_SESSION['location'];


        return $this->renderTemplate([
            'location' => $locname,
            'location_places' => $ln
        ]);
    }

    function run($params = array()) {
        if(!SecurityClass::userLoggedIn())return "";

        if(isset($_POST['location'])) {
            prelog("Location module::run(): location:". $_POST['location'] );
            $_SESSION['location'] = $_POST['location'];
        }
        return $this->render([]);
    }
}


function register_location_module() {
    global $kernel;

    $kernel->registerModule(new locationModule(__DIR__, 'location', 'location_info.zetem'));
}