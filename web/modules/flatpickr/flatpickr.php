<?php

class flatpickrModule extends moduleClass {

    function __construct($adir, $amodule, $atemplate) {
        parent::__construct($adir, $amodule, $atemplate);

        $rt = yaml_parse_file(__DIR__ . '/flatpickr.yaml');

        global $kernel;
        $srt = $kernel->resolveModuleDir($rt, $adir, $amodule);

        $kernel->addConfig( $srt );
    }

    function render($params = array()) {
        return("flatpickrModule: " . print_r($params, 1));
    }
}


function register_flatpickr_module() {
    global $kernel;

    $kernel->registerModule( new flatpickrModule(__DIR__, 'flatpickr', '') );
}
