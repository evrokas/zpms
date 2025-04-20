<?php

class insuranceInvoicesModule extends moduleClass {
    public function __construct($adir, $amodule, $atemplate) {
        parent::__construct($adir, $amodule, $atemplate);

        $rt = yaml_parse_file(__DIR__ . '/insuranceinvoices.yaml');

        global $kernel;
        $srt = $kernel->resolveModuleDir($rt, $adir, $amodule);
        $kernel->addConfig( $srt );

        global $router;
        $router->initRouteTable($kernel->getConfig('routes'));
    }

    function render($params = array() ) {
        echo("insurancInvoicesModule: render()");
        return $this->renderTemplate([]);
    }

    function run($params = array()) {
        // echo("insuranceInvoicesModule: run() params: " . print_r($params, 1));

        if(isset($params['actions'])) {
            if($params['actions'] === 'process') {
                require 'process.php';

                process_run();
            }
        }

        $invoices = insuranceInvoicesClass::sgetAll();

        return  $this->renderTemplate(["invoices" => $invoices]);
    }
}

function register_insuranceinvoices_module() {
    global $kernel;

    $kernel->registerModule(new insuranceInvoicesModule(__DIR__, 'insuranceinvoices', 'insuranceinvoices.zetem'));
}



function insuranceinvoices_handler(array $params) {
    return Renderer::render('insuranceinvoices.zetem', []);
}