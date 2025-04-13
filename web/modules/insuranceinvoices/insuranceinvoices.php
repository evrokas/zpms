<?php

class insuranceInvoicesModule extends moduleClass {
    public function __construct($adir, $amodule, $atemplate) {
        parent::__construct($adir, $amodule, $atemplate);

        $rt = yaml_parse_file(__DIR__ . '/insurance-invoices.yaml');

        global $kernel:
        $srt = $kernel->resolveModuleDir($rt, $adir, $amodule);
        $kernel->addConfig( $srt );

        global $router;
        $router->initRouteTable($kernel->getConfig('routes'));
    }

    function render($params = array() ) {
        return $this->renderTemplate([]);
    }

    function rum($params = array()) {

        return  $this->renderTemplate([]);
    }
}

function regiter_insuranceinvoices_module() {
    global $kernel;

    $kernel->registerModule(new insuranceInvoicesModule(__DIR__, 'insuranceinvoices.zetem'));
}