<?php

include_once(__DIR__ . "/../config/db.php");       // load database parameters
// require_once(__DIR__ . '/../../config/db.php');

// Optional -- only present once an admin has set up the DocArc patient
// lookup integration (see config/docarc_api.php.in). Guarded with
// is_file() (unlike db.php above) since its absence is a normal,
// supported state, not a fatal misconfiguration.
if (is_file(__DIR__ . '/../config/docarc_api.php')) {
    include_once(__DIR__ . '/../config/docarc_api.php');
}

require_once(__FWDIR__ . '/bootstrap.php');
require_once(__DIR__ . '/api_patients.php');
require_once(__DIR__ . '/appointment_files.php');


    ini_set('session.gc_maxlifetime', 3600);
    // cookie_lifetime is deliberately no longer overridden here -- the PHP
    // session cookie itself now always dies on browser close (lifetime 0,
    // set explicitly in zeusfw_session_start() below); persistence across
    // browser restarts is delegated entirely to the separate
    // zeusfwrememberme cookie/user_tokens mechanism, same as DocArc.

    // Project Zeus - Patient Registration System
    // echo "<pre>";
    // print_r( $info['menu']['main'] );
    // echo "</pre>";
    
    $kernel = new Kernel($_SERVER, "../config");
    SecurityClass::init($kernel->getConfig('roles') );

    $router = new RouterClass( $kernel->getConfig('routes') );
    // print_r( $router->getAllRoutes() );

    Renderer::init($kernel->getConfig('templates'), false,  $kernel->safeGetConfig('template_cache_path'), $kernel->getConfig('enable_comments'));

    $Request = new RequestClass($_SERVER);
    // $handlers = $Request->getQueryRoute();
    // echopre('Request: ', print_r($Request, 1));
    // echo "<pre>" . print_r( $_SERVER, 1 ) . "</pre>";
    // echo( "Method: " . $req->getMethod() . '  string: ' . $req->getQueryString() . "<br/>" );
    // print_r( $handlers );
    zeusfw_session_start();

    // Opt in to CSRF enforcement on the shared login_post() -- see
    // csrfClass::$enforceLogin's docblock (zeusfw core/lib/Csrf.php).
    // Safe to do unconditionally: web/templates/content/login.zetem
    // (zpms's own override of the core login form) always renders
    // csrf_field() now, so every real login submission already carries
    // a valid token.
    csrfClass::enableLoginProtection();

    // Same reasoning for the shared webforms dispatcher -- see
    // csrfClass::$enforceWebforms's docblock. generateHTMLForm() already
    // renders csrf_field() into every DB-defined webform unconditionally
    // (framework-wide, harmless for apps that don't check it); this just
    // tells processform() to actually verify it for zpms's own webforms.
    csrfClass::enableWebformProtection();

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


    function patients_list($params) {
        global $kernel;

        SecurityClass::require('patients-view-list');

        // echopre(print_r($params,1));
        // $pc = new patientsClass();
        // $pat = $pc->getAll();

        // $pat = patientsClass::sgetAll();
        if(array_key_exists("key", $params)) {
            $sort_key = $params['key'];

            if(array_key_exists("order", $params)) {
                $order_key = $params['order'];
            }
        }

        if(isset($sort_key)) {
            if(!in_array($sort_key, ['name', 'lastapp']))$sort_key = 'lastapp';
        } else $sort_key = "lastapp";
        
        if(isset($order_key)) {
            if(!in_array($order_key, ['1', '0']))$order_key = "1";
        } else if($sort_key == "lastapp")$order_key = "0"; else $order_key = '1';

        // if(isset($sort_key))echopre("SORT set: " . $sort_key);
        // if(isset($order_key))echopre("ORDER set: " . $order_key);


        switch($sort_key) {
            case 'name':
                $pat = patientsClassEx::getPatientsByName($order_key);
                break;
            case 'lastapp':
                $pat = patientsClassEx::getPatientsByLastAppointment($order_key);
                break;
        }

        // echo "<pre>";
        // print_r( $pat );
        // echo "</pre>";
        $pp = array();
        foreach($pat as $p) {
            if($p['p']->getdeleted() == null) {

                if($p['a'])$dt = date_format(new DateTime($p['a']), "d-m-Y H:i");
                else $dt = null;

                $pp[] = ['id' => $p['p']->getid(),
                        'pname' => $p['p']->getpname(),
                        'pamka' => $p['p']->getpamka(),
                        'lastapp' => $dt
                    ];
            }
        }

        return Renderer::render("patients_list.zetem",
            ['pat_list' => $pp,
                // 'notice' => $kernel->ifelseStatus('patient_edit', '', true)
            ]);
    }

    function patients_search_post($params) {
        SecurityClass::require('patients-view-list');

        if(strlen($_POST['search-term'])>0) {
            header('location: '.rel_url('/patients/search/'.urlencode($_POST['search-term'])));
        } else {
            header('location: '.rel_url('/patients'));
        }

        exit();
    }

    function patients_list_search($params) {
        SecurityClass::require('patients-view-list');

        $pat = patientsClassEx::search(urldecode($params['term']));

        
        // $s = '';
        // foreach($pat as $p) {
        //     $s .= "<pre>name: " . $p->getpname() . "</pre>";
        // }   
        // return ($s);

        $pp = array();
        foreach($pat as $p) {
            $ppp = array();
            $ppp['id'] = $p->getid();
            $ppp['pname'] = $p->getpname();
            $ppp['pamka'] = $p->getpamka();
            $ppp['tel'] = $p->getptel();

            $pp[] = $ppp;
        }

        return Renderer::render("patients_list.zetem",
            [   'search_term' => $params['term'],
                'pat_list' => $pp,
                // 'notice' => $kernel->ifelseStatus('patient_edit', '', true)
            ]);
    }

    function patients_list_search_ajax($params) {
        SecurityClass::require('patients-view-list');

        $arg = $_POST['sterm'];
        // error_log("\nAjax request: ". print_r($arg, 1) . "\n");;
        // error_log("\nurldecode: " . urldecode($arg));
        $pat = patientsClassEx::search($arg);   //, ['pname', 'pamka', 'ptel']);

        error_log("\nSearch response: " . print_r($pat, 1));

        $list = array();
        foreach($pat as $p) {
            // error_log('dob ->' . $p->getpdob());
            $list[] = [
                    'id' => $p->getid(),
                    'name' => $p->getpname(),
                    'amka' => $p->getpamka(),
                    // 'age' => DateTime::createFromFormat('Y-m-d h:m:s', $p->getpdob())->diff(new DateTime('now'))->y,
                    'tel' => $p->getptel(),
                    'link' => rel_url('/patient/' . $p->getid() . '/edit')
                    // date('Y', date_diff(date(), time($p->getpdob)))
                ];
        }

        $base = explode('index.php', $_SERVER['PHP_SELF'])[0];
        $response = [
            'base' => $base,
            'referer' => explode($base, $_SERVER['HTTP_REFERER'])[0],
            'list' => $list
        ];
        // error_log("\nSearch response: " . print_r($list, 1));

        // $json = json_encode($list);
        $json = json_encode($response);
        // echopre("ajax search: " . $json);
        echo $json;
        exit();

    }
    function patient_edit($params) {
        global $kernel;

        if($errmsg=SecurityClass::require('patients-edit-patient'))return $errmsg;

        if(!isset($params['id'])) {
            $kernel->addStatus('error', 'Ο φάκελος του ασθενή δεν βρέθηκε!');
            return ("patients doesn't exist");
        }

        // $pc = new patientsClass();
        // $pat = $pc->getById($params['id']);
        // error_log("\n$params: " .print_r($params, 1)."\n");
        $pat = patientsClass::sgetById( $params['id'] );

        // echo "<pre>patient: " . print_r($pat, 1) . "</pre>";
        $app_list = appointmentsClassEx::getAppointmentsForPatient($pat->getguid(), 'DESC');
        // $loc = locationsClass::sgetAll();
        $loc = locationsClassEx::sgetAll( $kernel->getCurrentLanguage() );

        $apprender = array();
        $appdates = array();
        foreach($app_list as $ap) {
            
            if($ap->getdeleted() == null) {
                $apprender[] = [
                    'index' => count($apprender),
                    'markup' => Renderer::render('view_appointment.zetem', [
                                                    'action' => rel_url('/appointment/' . $ap->getid() . '/edit'),
                                                    'index' => count($apprender)+1,
                                                    'checked' => "checked",/*(!count($apprender)?"checked":""),*/
                                                    'id' => $params['id'], 
                                                    'patient' => $pat,
                                                    'appointment' => $ap,
                                                    'locations' => $loc,
                                                    'files' => appointmentFilesClassEx::getFilesForAppointment($ap->getid())
                                                ]),
                    'attributes' => new Attributes()
                ];
                $appdates[] = [ 'index' => count($apprender), 'date' => $ap->getadate() ];
            }
        }

        return (Renderer::render("edit_patient.zetem", [
            'action' => 'edit', 
            'id' => $params['id'], 
            'patient' => $pat,
            'appdates' => $appdates,
            'appointments' => $apprender
        ]));
    }
    function patient_edit_post($params) {
        global $kernel;

        SecurityClass::require('patients-edit-patient');

        if(!csrfClass::verifyRequest()) {
            if(isset($_POST['use_ajax'])) {
                echo "FAILED";
                exit();
            }
            $kernel->addStatus('error', 'Μη έγκυρο token ασφαλείας (CSRF). Παρακαλώ προσπαθήστε ξανά.');
            header('location: '.rel_url('/patients'));
            exit();
        }

        if(isset($_POST['delete'])) {
            $kernel->addStatus('warning', 'Η επεξεργασία του φακέλου ακυρώθηκε.');
            header('location: '.rel_url('/patients'));
            exit();
        }            

        if(!isset($params['id'])) {
            return ("patient doesn't exist");
        }

        // error_log("Get patient: " . print_r($params, 1 ));
        // echo "this is the post version<br/>";
        $pat = patientsClass::sgetById($params['id']);
        // error_log("Patient: " . print_r($pat, 1)."\n");

        // if(isset($_POST['submit'])) {
        
        
        $pat->loadFields([
            'cuser' => $kernel->getUserName(),
            'cdate' => getDBtime(),
            'pname' => $_POST['patient-name'],
            'pdob' => getDBformattime($_POST['patient-dob']),
            'pamka' => $_POST['patient-amka'],
            'ptel' => $_POST['patient-telephone'],
            'paddr' => $_POST['patient-address'],
            'pemail' => $_POST['patient-email'],
            'pnote' => $_POST['patient-note']
        ]);
        
        $res = $pat->update();

        if(isset($_POST['use_ajax'])) {
            if($res) {
                echo "OK";
            } else {
                echo "FAILED";
            }
            exit();
        }

        if($res) {
            $kernel->addStatus('notice', 'Ο φάκελος του ασθενή <b>' . $pat->getpname() . '</b> έχει αποθηκευτεί.');
        } else {
            $kernel->addStatus('error', 'Αδυναμία αποθήκευσης φακέλου.');
        }

        header('location: '.rel_url('/patient/'.$pat->getid().'/edit'));
        exit();
        // return '';
        // patients_list(['notice' => 'Ο φάκελος του ασθενή ' + $pat->getpname() . ' έχει αποθηκευτεί.']);
    
        // return (Renderer::render("edit_patients.zetem", ['notice' => 'data were saved', 'id' => $params['id'], 'patient' => $pat]));
    }


    function patient_new($params) {
        global $kernel;

        SecurityClass::require('patients-new-patient');

        $pc = new patientsClass([
            'pname' => '', //randomAlpha(12, 2),
            'pdob' => getDBtime(),  //'1977-07-29 00:00:00',
            'pamka' => '', //randomNumber(11),
            'ptel' => '', //randomNumber(10),
            'pemail' => '', //randomEmail(),
            'paddr' => '', //randomAlnum(12, 3),
            'pnote' => '', //randomALnum(20,10)
        ]);
        
        // $pat = $pc->getById($params['id']);
        return (Renderer::render("edit_patient.zetem", ['action' => 'new', 'id' => null, 'patient' => $pc]));
    }

    function patient_delete($params) {
        global $kernel;

        SecurityClass::require('patients-delete-patient');

        $pat = patientsClass::sgetById($params['id']);

        $dtime = getDBtime();    // make delete time same for patient and appointments
                            // so to be able to recover them later

        // also delete all records of appointments for this patient
        $app_list = appointmentsClassEx::getAppointmentsForPatient($pat->getguid());
        foreach($app_list as $ap) {
            $ap->setdeleted( $dtime );
            $ap->update();
            // $ap->delete();
        }

        $pat->setdeleted( $dtime );
        $pat->update();

        // $pat->delete();

        $kernel->addStatus('warning', 'Ο φάκελος του ασθενή <b>'.$pat->getpname() . '</b> διαγράφθηκε με επιτυχία.');
        header('location: '.rel_url('/patients'));
        exit();
    }


    function patient_new_post($params) {
        global $kernel;


        SecurityClass::require('patients-new-patient');

        error_log('\nAjax request: '. $kernel->isAjaxRequest()?"true":"false" . "\n");

        if(isset($_POST['use_ajax'])) {
            echo "REJECTED";
            exit();
        }

        if(!isset($_POST['submit']) && !isset($_POST['submitedit']))exit();

        if(!csrfClass::verifyRequest()) {
            $kernel->addStatus('error', 'Μη έγκυρο token ασφαλείας (CSRF). Παρακαλώ προσπαθήστε ξανά.');
            header('location: '.rel_url('/patient/new'));
            exit();
        }

        // echo "this is the post version<br/>";
        $pc = new patientsClass([
            // 'guid' => 
            'id' => null,
            'cuser' => $kernel->getUserName(),
            'cdate' => getDBtime(),
            'pname' => $_POST['patient-name'],
            'pdob' => getDBformattime($_POST['patient-dob']),
            'pamka' => $_POST['patient-amka'],
            'ptel' => $_POST['patient-telephone'],
            'paddr' => $_POST['patient-address'],
            'pemail' => $_POST['patient-email'],
            'pnote' => $_POST['patient-note'],
            'guid' => guid()
        ]);
        // print_r( $pc );

        $pc->insert();

        $kernel->addStatus('notice', 'Δημιουργήθηκε νέος φάκελος για τον ασθενή <b>' . $pc->getpname() . '</b>');

        if(isset($_POST['submitedit']))
            header('location: '.rel_url('/patient/'.$pc->getid().'/edit'));
        else
            header('location: '.rel_url('/patients'));

        exit();
    }

    /**
     * Live duplicate-name check for the "new patient" form
     * (edit_patient.zetem, action=='new'). Called on every keystroke
     * (debounced client-side, see [data-check-duplicate] in scripts.js)
     * so staff creating a patient record are warned before they save a
     * second record for someone already on file, and can jump straight
     * to the existing one instead. Name-only scope (unlike the general
     * patients_list_search_ajax, which also matches ptel/pamka) since a
     * phone/AMKA coincidence isn't a "same patient name" duplicate.
     */
    function patient_new_check_name($params) {
        header('Content-Type: application/json');

        if (SecurityClass::require('patients-new-patient')) {
            http_response_code(401);
            echo json_encode(['matches' => []]);
            exit();
        }

        $term = trim((string)($_POST['pname'] ?? ''));

        if (mb_strlen($term) < 2) {
            echo json_encode(['matches' => []]);
            exit();
        }

        $found = patientsClassEx::search($term, ['pname'], true, 8);

        $matches = [];
        foreach ((array)$found as $p) {
            $matches[] = [
                'id' => $p->getid(),
                'name' => $p->getpname(),
                'amka' => $p->getpamka(),
                'link' => rel_url('/patient/' . $p->getid() . '/edit'),
            ];
        }

        echo json_encode(['matches' => $matches]);
        exit();
    }

    function appointment_edit_post($params) {
        global $kernel;

        SecurityClass::require('appointment-edit');

        if(!csrfClass::verifyRequest()) {
            if(key_exists('use_ajax', $_POST)) {
                echo "FAILED";
                exit();
            }
            $kernel->addStatus('error', 'Μη έγκυρο token ασφαλείας (CSRF). Παρακαλώ προσπαθήστε ξανά.');
            header('location: '. $_SERVER['HTTP_REFERER']);
            exit();
        }

        if(isset($_POST['delete'])) {
            // $kernel->addStatus('warning', 'Η επεξεργασία του φακέλου ακυρώθηκε.');
            $kernel->pushRouteHistory($_SERVER['HTTP_REFERER']);
            // $kernel->addStatus('warning', 'Ο αποστολέας της εντολής είναι: '.$_SERVER['HTTP_REFERER']);
            header('location: '.rel_url('/appointment/'.$params['id'].'/delete'));
            exit();
        }            

        if(!isset($params['id'])) {
            return ("patients doesn't exist");
        }

        $ap = appointmentsClass::sgetById($params['id']);
        // error_log("\bFound appointment: " . print_r($ap, 1) . "\n");
        // $ap->setaplace($_POST['appointment-place']);
        // Null-safe -- same pattern already used by patient_appointment_new_post()
        // for this identical lookup. A machine name with no matching row (empty
        // locations table, or one deleted between page load and submit) used to
        // be an uncaught fatal error (getname() on null) instead of just saving
        // an empty place, a real crash on a plain edit-and-save.
        $loc = locationsClassEx::getbyMachineName($_POST['appointment-place'], $kernel->getCurrentLanguage());
        $ap->setaplace($loc ? $loc->getname() : '');

        // we cannot search for appointment-date, because of special handling of
        // date fields in the view page, we must search for appointmenet-date-?
        // where ? is the appointment index in the view page, we do not care for
        // the index itself, but for the value of that key
        // ON THE OTHER HAND
        // in the single page new appointment, the post field
        // is named 'appointment-date' 
        foreach($_POST as $postkey => $postval) {
            if(strstr($postkey, "appointment-date")) {
                // error_log($postkey . ' ==> ' . print_r($postkey, 1));
                $ap->setadate(getDBformattime($postval));    //$_POST['appointment-date']));
            }
        }

        $ap->setanote($_POST['appointment-notes']);

        $ap->update();

        // error_log('patient appointment saved');
        if(key_exists('use_ajax', $_POST)) {
            // error_log("\nused AJAX\n");
            echo "OK";
            exit();
        }
        // error_log('\nREFERER: '. $_SERVER['HTTP_REFERER']."\n");
        header('location: '. $_SERVER['HTTP_REFERER']);
    }


    function appointment_delete($params) {
        global $kernel;

        $ap = new appointmentsClass();
        $app = $ap->getById($params['id']);
        $app->setdeleted( getDBtime() );
        $app->update();

        // $app->delete();

        $kernel->addStatus('warning', 'Το ραντεβού διαγράφθηκε με επιτυχία.');

        $s = $kernel->popRouteHistory();

        if(!$s) {
            // No pushed history to return to (e.g. a direct/bookmarked
            // GET) -- appointments are always viewed from inside their
            // patient's own record now, so that's the sane fallback
            // destination rather than a standalone appointments list.
            $pat = patientsClassEx::sgetByGuid($app->getpguid());
            $s = $pat ? rel_url('/patient/'.$pat->getid().'/edit') : rel_url('/patients');
        }
        header('location: '.$s);
        exit();
    }

    function patient_appointment_new($params) {
        global $kernel;

        if(!isset($params['id'])) {
            return ("User could not be found");
        }

        // error_log('\nSERVER: ' . print_r($_SERVER, 1));

        if(isset($_POST['use_ajax'])) {
            // if this is an AJAX request, then do nothing, because the record doesn't exist yet!
            echo "REJECTED";
            exit();
        }
        
        // error_log("\npatient_appointment_new: ".print_r($params, 1)."\n");
        $p = patientsClass::sgetById( $params['id'] );
        // echo "<pre>" . print_r( $p ) . "</pre>";

        $loc = locationsClassEx::sgetAll( $kernel->getCurrentLanguage() );
        // $loc = locationsClass::sgetAll();
        $ap = new appointmentsClass([
            'adate' => getDBtime(),
            'aplace' => locationsClassEx::getCurrentLocation(),
            'anote' => '', //print_r( $_SERVER, 1) /*'notes'*/
        ]);
        // $kernel->setS
        // $pat = $pc->getById($params['id']);
        return (Renderer::render("edit_appointment.zetem", ['action' => 'newappointment', 'id' => null, 'appointment' => $ap, 'patient' => $p, 'locations' => $loc]));
    }

    function patient_appointment_new_post($params) {
        global $kernel;

        if(!csrfClass::verifyRequest()) {
            $kernel->addStatus('error', 'Μη έγκυρο token ασφαλείας (CSRF). Παρακαλώ προσπαθήστε ξανά.');
            header('location: '.rel_url('/patient/'.$params['id'].'/edit'));
            exit();
        }

        // error_log("<pre>patient_appointment_new_post: ".print_r($params, 1)."</pre>");
        // error_log("\nPatient id: " );
        if(isset($_POST['cancel'])) {
            $kernel->addStatus('warning', 'Η επεξεργασία του ραντεβού ακυρώθηκε.');
            header('location: '.rel_url('/patient/'.$params['id'].'/edit'));
            exit();
        }            

        if(!isset($_POST['submit'])) {

        }
             
        $pat = patientsClass::sgetById( $params['id'] );
        if(!$pat) {
            echo "User " . $params['id'] . "cannot be found!\n";
            exit();
        }

        $loc = locationsClassEx::getbyMachineName($_POST['appointment-place'], $kernel->getCurrentLanguage());


        // echo "this is the post version<br/>";
        $app = new appointmentsClass([
            // 'guid' => 
            'id' => null,
            'cuser' => 'admin',
            'cdate' => getDBtime(),
            'adate' => getDBformattime($_POST['appointment-date']),
            'aplace' => ($loc)?$loc->getname():'',   //$_POST['appointment-place'],
            'anote' => $_POST['appointment-notes'],
            'guid' => guid(),
            'pguid' => $pat->getguid()
        ]);
        // print_r( $pc );

        $app->insert();

        // error_log('\nREFERER: '. $_SERVER['HTTP_REFERER']."\n");

        $kernel->addStatus('notice', 'Δημιουργήθηκε νέο ραντεβού.');
        header('location: '.rel_url('/patient/'.$pat->getid().'/edit'));
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


    // AJAX callback for updating patient info data
    function ajax_update_patient_info($params) {
        global $kernel;

        SecurityClass::require('patients-edit-patient');

        $pat = patientsClass::sgetById($params['id']);

        $pat->loadFields([
            'cuser' => $kernel->getUserName(),
            'cdate' => getDBtime(),
            'pname' => $_POST['patient-name'],
            'pdob' => getDBformattime($_POST['patient-dob']),
            'pamka' => $_POST['patient-amka'],
            'ptel' => $_POST['patient-telephone'],
            'paddr' => $_POST['patient-address'],
            'pemail' => $_POST['patient-email'],
            'pnote' => $_POST['patient-note']
        ]);

        // save this final request for after debugging
        // $pat->update();

        error_log('\najax update_patient_info: note: ' . $_POST['patients-note']);
        return ("OK");
    }

    function app_generate_qr($params) {
        // echopre(print_r($_SERVER, 1));

        if(($_SERVER['REQUEST_METHOD'] === "GET") || (!strlen($_POST['qrtext']))) {
            return Renderer::render('genqr.zetem', []);

        } else {
            if(($err = csrfClass::requireValid()))return $err;

            // echopre("qrtext: " . $_POST['qrtext']);
            // POST method

            // Previously built as a raw shell string with $_POST values
            // interpolated directly into it -- classic command injection
            // (e.g. a crafted imagetype value could run arbitrary shell
            // commands). Fixed two ways: every option is validated
            // against the exact choices genqr.zetem's <select> elements
            // offer, falling back to that field's own default on
            // anything else, and the command itself is invoked via
            // proc_open() with an argument array -- matching docarc's
            // docarc_generate_qr_png() -- so argv reaches qrencode
            // directly with no shell ever parsing these values,
            // regardless of what's in them.
            $allowedImageTypes = ['png', 'svg'];
            $allowedDpi = ['75', '100', '150', '200', '300', '600'];
            $allowedMargins = ['1', '2', '3', '4', '5', '6'];
            $allowedErrorCorrection = ['L', 'M', 'Q', 'H'];

            $imagetype = in_array($_POST['imagetype'] ?? '', $allowedImageTypes, true) ? $_POST['imagetype'] : 'png';
            $imagedpi = in_array($_POST['imagedpi'] ?? '', $allowedDpi, true) ? $_POST['imagedpi'] : '150';
            $margins = in_array($_POST['margins'] ?? '', $allowedMargins, true) ? $_POST['margins'] : '3';
            $errorcorrection = in_array($_POST['errorcorrection'] ?? '', $allowedErrorCorrection, true) ? $_POST['errorcorrection'] : 'Q';

            // Fixed string, no user input involved -- but shelling out for
            // a plain glob+delete is unnecessary, so this no longer does
            // either. array_map('unlink', ...) over glob()'s own matches
            // already only touches files that exist, same as -f's
            // "don't error on missing" behavior.
            array_map('unlink', glob('cache/qr-*') ?: []);
            $file = tempnam('cache', 'qr-image-');
            $str = tempnam('cache', 'qr-text-');
            $filename = array_reverse( explode('/', $file) )[0];
            // echopre("file: $file <br>filename: $filename <br> str: $str");
            // $file = 'cache/qr-image';
            // $str = 'cache/qr-text';

            file_put_contents($str, $_POST['qrtext']);

            $command = ['qrencode', '--type=' . $imagetype, '--level=' . $errorcorrection, '--dpi=' . $imagedpi, '--margin=' . $margins, '-r', $str, '-o', $file];
            echopre(print_r($command, 1));

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open($command, $descriptors, $pipes);
            if(is_resource($process)) {
                fclose($pipes[0]);
                stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);
                // echopre("qrencode exit code: $exitCode, stderr: $stderr");
            }

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