<?php

class financeModule extends moduleClass {
    public function __construct($adir, $amodule, $atemplate) {
        parent::__construct($adir, $amodule, $atemplate);

        $rt = yaml_parse_file(__DIR__ . '/finance.yaml');
        
        global $kernel;
        $srt = $kernel->resolveModuleDir($rt, $adir, $amodule);
        $kernel->addConfig( $srt );

        global $router;
        $router->initRouteTable($kernel->getConfig('routes'));

        // init info yaml contains updates template array, so reload files
        // Renderer::scanTemplates();
    }


    function render($params = array()) {
        return $this->renderTemplate([]);
    }

    function run($params = array()) {

        if(($ret=SecurityClass::require('finance-access')))return $ret;


        $operform = formsClass::renderForm('operations');
        // $t = core_get_file_in_lib('test.txt', 'pdflib');
        // echopre("test file: $t");

        $operFormArray = formsClass::getFormRenderArray( 'operations' );
        // echopre("FormArray: " . print_r($operFormArray, 1));
        
        $invFormAr = formsClass::getFormRenderArray('invoices');
        $btn_ok = generateFormButton('submit', 'slabel', 'svalue');
        $invFormAr = formArrayAddButton($invFormAr, $btn_ok);
        $invForm = formsClass::renderFormArray($invFormAr);


        $supplForm = formsClass::renderForm('suppliers');

        $payForm = formsClass::renderForm('payments');

        $banksForm = formsClass::renderForm('bankaccounts');

        return $this->renderTemplate([
                'text' => $operform,
                'invoices' => $invForm,
                'suppliers' => $supplForm,
                'payments' => $payForm,
                'bankaccounts' => $banksForm
            ]);


    }
}

function register_finance_module() {
    global $kernel;

    $kernel->registerModule(new financeModule(__DIR__, 'finance', 'finance.zetem'));
}



// function finance_handler($params) {    
//     return Renderer::render('finance.zetem', ['text' => 'finance-handler']);
// }
