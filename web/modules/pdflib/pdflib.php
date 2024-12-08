<?php

class pdflibModule extends moduleClass {
    public function __construct($adir, $amodule, $atemplate) {
        parent::__construct($adir, $amodule, $atemplate);

        $rt = yaml_parse_file(__DIR__ . '/pdflib.yaml');
        
        global $kernel;
        $srt = $kernel->resolveModuleDir($rt, $adir, $amodule);
        $kernel->addConfig( $srt );

        global $router;
        $router->initRouteTable($kernel->getConfig('routes'));
    }


    function render($params = array()) {
        return $this->renderTemplate([]);
    }

    function run($params = array()) {
        return $this->renderTemplate([]);
    }
}

function register_pdflib_module() {
    global $kernel;

    $kernel->registerModule(new pdflibModule(__DIR__, 'pdflib', 'pdflib.zetem'));
}