<?php

class datepickerModule extends moduleClass {

    function __construct($adir, $amodule, $atemplate) {
        parent::__construct($adir, $amodule, $atemplate);

        $rt = yaml_parse_file(__DIR__ . '/datepicker.yaml');

        global $kernel;
        $srt = $kernel->resolveModuleDir($rt, $adir, $amodule);

        $kernel->addConfig( $srt );   
    }
    
    function render($params = array()) {
        return("datepickerModule: " . print_r($params, 1));
    }
}


function register_datepicker_module() {
    global $kernel;

    $kernel->registerModule( new datepickerModule(__DIR__, 'datepicker', '') );
}
