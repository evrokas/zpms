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
      global $kernel;

        // echo("insuranceInvoicesModule: run() params: " . print_r($params, 1));
        if(($ret=SecurityClass::require('insuranceinvoices-access')))return $ret;

        if(isset($params['actions'])) {
            switch($params['actions']) {
                case 'process':
                    if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
                        return ('Error uploading file.');
                    }
                
                    require 'process.php';
                    process_run();
                    break;
                
                case 'view':
                    $id = $params['id']??null;
                    if(!$id) {
                        $kernel->addStatus('error', 'insuranceinvoices(view): `id` argument is missing');
                        return  "Invoice id was not set";
                    }

                    $rec = insuranceInvoicesClass::sgetById($id);
                    if($rec) {
                        if(file_exists($rec->getpdf_path())) {
                            $pinfo = pathinfo($rec->getpdf_path());
                            $filename = $pinfo['filename'] . '.' . $pinfo['extension'];

                            header('refresh:3;url=' . rel_url('/apps/insuranceinvoices'));
                            header('Content-Description: File Transfer');
                            header('Content-Type: application/octet-stream');
                            header('Content-Disposition: attachment; filename=' . $filename);
                            header('Expires: 0');
                            header('Cache-Control: must-revalidate');
                            header('Pragma: public');         
                            header('Content-Length: ' . filesize( $rec->getpdf_path() ) );
                            readfile($rec->getpdf_path());          
                
                            $kernel->addStatus('notice', 'file `'.$rec->getpdf_path() . '` was downloaded succesfully');
            
                        } else $kernel->addStatus('error', "file `' . $rec->getpdf_path() . '` was not found in the library");
            
                    } else $kernel->addStatus('error', "insuranceinvoices(view): entry with id = " . $params['id'] . " is not valid");
                    break;

                case 'delete':
                    $id = $params['id']??null;
                    if(!$id) {
                        $kernel->addStatus('error', 'insuranceinvoices(view): `id` argument is missing');
                        return  "Invoice id was not set";
                    }

                    $rec = insuranceInvoicesClass::sgetById($id);
                    if($rec) {
                        if(file_exists($rec->getpdf_path())) {
                            $f = $rec->getpdf_path();
                            unlink( $f );
                            $rec->delete();

                            $kernel->addStatus('notice', 'file `'. $f .'` was deleted succesfully' );
                        }
                    }
                    break;

                default:
                    return ("Uknown action for insurance invoices module");
            };
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