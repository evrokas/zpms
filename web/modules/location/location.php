<?php

class locationModule extends moduleClass {
    function __construct($amodule, $atemplate) {
        parent::__construct($amodule, $atemplate);

        $rt = yaml_parse_file(__DIR__ . '/location.yaml');

        // update kernel configuration
        global $kernel;
        $kernel->addConfig( $rt );

        // echopre(print_r($kernel->getConfig(),1));

        // update router tables
        global $router;
        $router->initRouteTable($kernel->getConfig('routes'));
    }

    function render($params = array()) {

        $loc = locationsClass::sgetAll();
        $ln = array();;
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
        if(isset($_POST['location'])) {
            prelog("Location module::run(): location:". $_POST['location'] );
            $_SESSION['location'] = $_POST['location'];
        }
        return $this->render([]);
    }
}


function register_location_module() {
    global $kernel;

    $kernel->registerModule(new locationModule('location', 'location_info.zetem'));
}