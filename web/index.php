<?php

include_once(__DIR__ . "/../config/db.php");       // load database parameters
// require_once(__DIR__ . '/../../config/db.php');

require_once(__FWDIR__ . '/bootstrap.php');

require_once "./patients.php";
require_once "./appointments.php";
require_once "./operations.php";


    ini_set('session.gc_maxlifetime', 3600);
    ini_set('session.cookie_lifetime', 3600);

    // Project Zeus - Patient Registration System
    // echo "<pre>";
    // print_r( $info['menu']['main'] );
    // echo "</pre>";
    
    $kernel = new Kernel($_SERVER, "../config");
    SecurityClass::init($kernel->getConfig('roles') );

    $router = new RouterClass( $kernel->getConfig('routes') );
    // print_r( $router->getAllRoutes() );

    Renderer::init($kernel->getConfig('templates'), false,  $kernel->getConfig('enable_comments'));

    $Request = new RequestClass($_SERVER);
    // $handlers = $Request->getQueryRoute();
    // echopre('Request: ', print_r($Request, 1));
    // echo "<pre>" . print_r( $_SERVER, 1 ) . "</pre>";
    // echo( "Method: " . $req->getMethod() . '  string: ' . $req->getQueryString() . "<br/>" );
    // print_r( $handlers );
    session_start();
    $kernel->isUserLoggedin();
    // if($kernel->isUserLoggedin()) {
        // echo "<pre>User has been logged in!</pre>";
    // } else {
        // echo "<pre>User has *NOT* been logged in!</pre>";
    // }
    // echo "<pre>Session: " . print_r( $_SESSION, 1) . "</pre>";


//    $l = new locationsClass();

//    echopre('locations: ' . print_r($l->getFields(), 1));
//    echopre('locations: ' . print_r(get_class_vars( 'locationsClass'),1) );
    if(isset($_SESSION) && isset($_SESSION['CURRENT_LANGUAGE']))
        $kernel->setCurrentLanguage( $_SESSION['CURRENT_LANGUAGE']);
    else
        $kernel->setCurrentLanguage('gr');
    // $kernel->setCurrentLanguage('en');

    ob_start();

    // echopre('_SERVER: ' . print_r($_SERVER, 1));

    locationsClassEx::setDefaultLocation();
    registerModules();

    // $f1 = new Feeder('/content/locations.feeder.yaml');

    $match = $router->matchRoute( $Request );
    // echopre("Match route: " . print_r($match, 1));
    
    $_SESSION['route_match'] = $match;
    $_SESSION['request'] = $Request->getQueryRoute();

    $content_response = $router->routerCallFunction($match);
    // print_r($content_response);
    // render web page
    $kernel->renderPage();
    
    // finally flush webpage to the user
    ob_end_flush();


/* ----- website handlers ----- */

    function homepage($params) {

        if(!SecurityClass::userLoggedIn()) {
            return Renderer::render("homepage-nologin.zetem", []);
        }

        // user is logged, so show a different page
        if(!isset($_SESSION['location'])) {
            // $locname = $ln[1];

        } else $locname = $_SESSION['location'];


        return Renderer::render("homepage.zetem", ['location' => $locname]);
    }

    function settings($params) {
        global $kernel;

        if(($errmsg=SecurityClass::require('patients-new-patient')))return $errmsg;

        // $dbclinics = formsClass::getForm('clinics');
        // $dbdoctors = formsClass::getForm('doctors');

        return Renderer::render('settings.zetem', [
            'clinics_table' => formsClass::renderFormResults('clinics'),
            'clinics' => formsClass::renderForm('clinics'),

            'doctors_table' => formsClass::renderFormResults('doctors'),
            'doctors' => formsClass::renderForm('doctors')
        ]);

    }


    function app_generate_qr($params) {
        // echopre(print_r($_SERVER, 1));

        if(($_SERVER['REQUEST_METHOD'] === "GET") || (!strlen($_POST['qrtext']))) {
            return Renderer::render('genqr.zetem', []);

        } else {
            // echopre("qrtext: " . $_POST['qrtext']);
            // POST method
            shell_exec("rm -f cache/qr-*");
            $file = tempnam('cache', 'qr-image-');
            $str = tempnam('cache', 'qr-text-');
            $filename = array_reverse( explode('/', $file) )[0];
            // echopre("file: $file <br>filename: $filename <br> str: $str");
            // $file = 'cache/qr-image';
            // $str = 'cache/qr-text';

            file_put_contents($str, $_POST['qrtext']);
            
            $errorcorrection = $_POST['errorcorrection'];
            $imagedpi = $_POST['imagedpi'];
            $imagetype = $_POST['imagetype'];
            $margins = $_POST['margins'];

            $cmd = "qrencode --type=$imagetype --level=$errorcorrection --dpi=$imagedpi --margin=$margins -r $str -o $file";
            echopre("$cmd");
            $s = shell_exec( $cmd );
            // echopre("shell_exec() output: $s");
            return Renderer::render('genqr.zetem', ['qrimage' => $filename,'qrtext' => $_POST['qrtext']]);
        }
    }


function clinics_edit($params) {
    // echopre("Edit clinics");

    $dbForm = formsClass::renderForm('clinics');
    // $dbForm = formsClass::renderForm('operations');

    return $dbForm;
}

function totp_handler($params) {

    global $kernel;

    $tfile = core_get_temp_filename('temp_qrcode.png');
    $str ="QR TEST CODE";

    $cmd0 =  "qrencode -o $tfile --size=10 " . escapeshellarg($str);
    $cmd = escapeshellcmd( $cmd0 );
    error_log("shell command: " . $cmd);

    shell_exec( $cmd );
    if(!file_exists($tfile)) {
        $output = ['result' => 'failed',
                    'error' => 'failed to create qr image',
                    'qrdata' => ''
                ];
    }else {
        $qrdata = base64_encode(file_get_contents($tfile));
        // error_log("qr data: " . $qrdata);
        $output = [
            'result' => 'success',
            'qrdata' => $qrdata
        ];
        unlink( $tfile );
    }
    echo json_encode( $output );
    exit();
}