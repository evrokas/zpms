<?php

/* Registers the quill-library (vendor/quill/) so a template can
 * attach_library('quill-library'). Renders nothing itself -- same shape
 * as the flatpickr module. */
class quillModule extends moduleClass {

    function __construct($adir, $amodule, $atemplate) {
        parent::__construct($adir, $amodule, $atemplate);

        $rt = yaml_parse_file(__DIR__ . '/quill.yaml');

        global $kernel;
        $kernel->addConfig( $kernel->resolveModuleDir($rt, $adir, $amodule) );
    }

    function render($params = array()) {
        return '';
    }
}


function register_quill_module() {
    global $kernel;

    $kernel->registerModule( new quillModule(__DIR__, 'quill', '') );
}
