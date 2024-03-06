<?php

require_once('../fw/bootstrap.php');

function dump_routes() {
    global $info;
    foreach($info as $key => $val )
        if($key == "routes") {
            foreach($val as $rname => $rdest) {
                // kernel_debug("route: " . $rname . " ==> " . $rdest . " url: " . $rdest['url'] . " Title: " . $rdest['title']);
                if(isset($rdest['arg'])) {
                    foreach($rdest['arg'] as $arg) {
                        kernel_debug(" -  arg: " . $arg );
                    }
                }
            }
        }
}



    // Project Zeus - Patient Registration System
    // echo "<pre>";
    // print_r( $info['menu']['main'] );
    // echo "</pre>";

    $kernel = new Kernel($_SERVER, "info.yaml");
    SecurityClass::init($kernel->getConfig('roles') );

    $router = new RouterClass( $kernel->getConfig('routes') );
    // print_r( $router->getAllRoutes() );

    $Renderer = new ZETEMTemplate('templates/', false, true);

    $Request = new RequestClass($_SERVER);
    // $handlers = $Request->getQueryRoute();

    // echo "<pre>" . print_r( $_SERVER, 1 ) . "</pre>";
    // echo( "Method: " . $req->getMethod() . '  string: ' . $req->getQueryString() . "<br/>" );
    // print_r( $handlers );
 
    session_start();
    // echo "<pre>Session: " . print_r( $_SESSION, 1) . "</pre>";

    ob_start();
    registerModules();

    // $match = $router->matchRoute( $handlers[0] );
    $match = $router->matchRoute( $Request );
    // echo "<pre>Match route: " . print_r($match, 1) . "</pre>";

    $content_response = $router->routerCallFunction($match);
    // print_r($content_response);

    {
        // $Renderer->view("main.zetem", $kernel->getConfig() );
        // registerModules();
        $region_resp = array();

        // $cont = new ContentClass('content/homepage.html');

        foreach($kernel->getConfig()['regions'] as $region) {
            // echo "<pre>Region " . print_r( $region, 1 ) . "</pre>";
            $blocks = $kernel->getBlocksInRegion( $region );
            // print_r( $blocks );
            $blk_resp = '';
            foreach($blocks as $block) {
                $blk = $kernel->getModule( $block );
                // echo("Calling module->render() for block " . print_r( $blk, 1). "<br/>");
                if($blk) {
                    $bresponse = $blk->render();
                    // echo("Reponse from module: " .print_r( $blk, 1) . " : << $bresponse >><br/>");
                    $blk_resp .= $bresponse;
                }
            }
            // echo "Region response text " . $blk_resp;
            // $regions_resp[ $region ] = $blk_resp;
            $regions_resp[ $region ] = $Renderer->render('region.zetem', ['region_name' => $region, 'blocks' => $blk_resp]);
//            $Renderer->view('region.zetem', ['region_name' => $region, 'blocks' => $regions_resp[ $region ]]);
        }

        $Renderer->view('main.zetem', 
        [
            'title' => $kernel->getConfig('title'),
            'meta' => $kernel->getConfig('meta'),
            'css' => $kernel->getConfig('css'),
            'head_script' => $kernel->getConfig('head_script'),
            'foot_script' => $kernel->getConfig('foot_script'),
            'regions' => $regions_resp
        ]);
    }

    // include('templates/footer.php');

    ob_end_flush();



    function patient_list($params) {
        echo "Patient list handler";
    }


    function homepage($params) {
        global $Renderer;
        return $Renderer->render("homepage.zetem", []);
    }


    function patients_list($params) {
        global $Renderer;
        global $kernel;

        SecurityClass::require('patients-view-list');

        // $pc = new patientsClass();
        // $pat = $pc->getAll();

        $pat = patientsClass::sgetAll();
        // echo "<pre>";
        // print_r( $pat );
        // echo "</pre>";
        $pp = array();
        foreach($pat as $p) {
            $ppp = array();
            $ppp['id'] = $p->getid();
            $ppp['pname'] = $p->getpname();
            $ppp['pamka'] = $p->getpamka();

            $pp[] = $ppp;
        }

        return $Renderer->render("patients_list.zetem",
            ['pat_list' => $pp,
                // 'notice' => $kernel->ifelseStatus('patient_edit', '', true)
            ]);
    }

    function patient_edit($params) {
        global $Renderer;
        global $kernel;

        SecurityClass::require('patients-edit-patient');

        if(!isset($params['id'])) {
            $kernel->addStatus('error', 'Ο φάκελος του ασθενή δεν βρέθηκε!');
            return ("patients doesn't exist");
        }

        // $pc = new patientsClass();
        // $pat = $pc->getById($params['id']);
        // error_log("\n$params: " .print_r($params, 1)."\n");
        $pat = patientsClass::sgetById( $params['id'] );

        // echo "<pre>patient: " . print_r($pat, 1) . "</pre>";
        $app_list = appointmentsClassEx::getAppointmentsForPatient($pat->getguid());

        $apprender = array();;
        foreach($app_list as $ap) {
            $apprender[] = [
                'index' => count($apprender),
                'markup' => $Renderer->render('view_appointment.zetem', [
                                                'action' => rel_url('/appointment/' . $ap->getid() . '/edit'),
                                                'index' => count($apprender)+1,
                                                'checked' => (!count($apprender)?"checked":""),
                                                'id' => $params['id'], 
                                                'patient' => $pat,
                                                'appointment' => $ap]),
                'attributes' => new Attributes()
            ];
        }

        return ($Renderer->render("edit_patient.zetem", [
            'action' => 'edit', 
            'id' => $params['id'], 
            'patient' => $pat,
            'appointments' => $apprender
        ]));
    }
    function patient_edit_post($params) {
        global $Renderer;
        global $kernel;

        SecurityClass::require('patients-edit-patient');

        if(isset($_POST['cancel'])) {
            $kernel->addStatus('warning', 'Η επεξεργασία του φακέλου ακυρώθηκε.');
            header('location: '.rel_url('/patients'));

        }            

        if(!isset($params['id'])) {
            return ("patients doesn't exist");
        }

        // echo "this is the post version<br/>";
        $pc = new patientsClass();
        $pat = $pc->getById($params['id']);


        if(isset($_POST['submit'])) {
            $kernel->addStatus('notice', 'Ο φάκελος του ασθενή <b>' . $pat->getpname() . '</b> έχει αποθηκευτεί.');
            header('location: '.rel_url('/patient/'.$pat->getid().'/edit'));
        }

        error_log('\nREFERER: '. $_SERVER['HTTP_REFERER']."\n");

        exit();
        // return '';
        // patients_list(['notice' => 'Ο φάκελος του ασθενή ' + $pat->getpname() . ' έχει αποθηκευτεί.']);
    
        // return ($Renderer->render("edit_patients.zetem", ['notice' => 'data were saved', 'id' => $params['id'], 'patient' => $pat]));
    }


    function patient_new($params) {
        global $Renderer;
        global $kernel;

        SecurityClass::require('patients-new-patient');

        $pc = new patientsClass([
            'pname' => randomAlpha(12, 2),
            'pdob' => '1977-07-29 00:00:00',
            'pamka' => randomNumber(11),
            'ptel' => randomNumber(10),
            'pemail' => randomEmail(),
            'paddr' => randomAlnum(12, 3),
        ]);
        
        // $pat = $pc->getById($params['id']);
        return ($Renderer->render("edit_patient.zetem", ['action' => 'new', 'id' => null, 'patient' => $pc]));
    }

    function patient_delete($params) {
        global $kernel;

        SecurityClass::require('patients-delete-patient');

        $pc = new patientsClass();
        $pat = $pc->getById($params['id']);
        $pat->delete();

        $kernel->addStatus('warning', 'Ο φάκελος του ασθενή <b>'.$pat->getpname() . '</b> διαγράφθηκε με επιτυχία.');
        header('location: '.rel_url('/patients'));
        exit();
    }


    function patient_new_post($params) {
        global $Renderer;
        global $kernel;


        SecurityClass::require('patients-new-patient');

        // echo "this is the post version<br/>";
        $pc = new patientsClass([
            // 'guid' => 
            'id' => null,
            'cuser' => $kernel->getUserName(),
            'cdate' => getDBtime(),
            'pname' => $_POST['patient-name'],
            'pdob' => $_POST['patient-dob'],
            'pamka' => $_POST['patient-amka'],
            'ptel' => $_POST['patient-telephone'],
            'paddr' => $_POST['patient-address'],
            'pemail' => $_POST['patient-email'],
            'guid' => guid()
        ]);
        // print_r( $pc );

        $pc->insert();

        $kernel->addStatus('notice', 'Δημιουργήθηκε νέος φάκελος για τον ασθενή <b>' . $pc->getpname() . '</b>');
        header('location: '.rel_url('/patients'));
        exit();
    }

    function appointments_list($params) {
        global $Renderer;
        global $kernel;

        SecurityClass::require('appointments-view-list');

        $app = new appointmentsClass();
        $ap = $app->getAll();
        // $apnt = $ap->getAll();
        // echo "<pre>";
        // print_r( $pat );
        // echo "</pre>";
        $pp = array();
        foreach($ap as $appoint) {
            $pp[] = ['id' => $appoint->getid(),
                    'adate' => $appoint->getadate(),
                    'aplace' => $appoint->getaplace()
                ];

                    // echo "<pre>";
                    // echo "Place: " . $appoint->getaplace() . "<br>";
                    // echo "</pre>";
            // $ppp = array();
            // $ppp['id'] = $p->getid();
            // $ppp['pname'] = $p->getpname();
            // $ppp['pamka'] = $p->getpamka();

            // $pp[] = $ppp;
        }

        return $Renderer->render("appointments_list.zetem",
            ['app_list' => $pp,
                // 'notice' => $kernel->ifelseStatus('patient_edit', '', true)
            ]);
    }


    function appointment_edit($params) {
        global $Renderer;
        global $kernel;

        if(!isset($params['id'])) {
            $kernel->addStatus('error', 'Το ραντεβού δεν βρέθηκε!');
            return ("patients doesn't exist");
        }

        $ap = new appointmentsClass();
        $app = $ap->getById($params['id']);
        return ($Renderer->render("edit_appointment.zetem", ['action' => 'edit', 'id' => $params['id'], 'appointment' => $app]));
        
    }
    function appointment_edit_post($params) {
        SecurityClass::require('appointment-edit');

        if(isset($_POST['cancel'])) {
            $kernel->addStatus('warning', 'Η επεξεργασία του φακέλου ακυρώθηκε.');
            header('location: '.rel_url('/patients'));

        }            

        if(!isset($params['id'])) {
            return ("patients doesn't exist");
        }

        $ap = appointmentsClass::sgetById($params['id']);
        error_log("\bFound appointment: " . print_r($ap, 1) . "\n");
        $ap->setaplace($_POST['appointment-place']);
        $ap->setadate($_POST['appointment-date']);
        $ap->setanote($_POST['appointment-notes']);

        $ap->update();

        error_log('\nREFERER: '. $_SERVER['HTTP_REFERER']."\n");
        header('location: '. $_SERVER['HTTP_REFERER']);

    }
    function appointment_new($params) {
        global $Renderer;
        global $kernel;

        $ap = new appointmentsClass([
            'adate' => getDBtime(),
            'aplace' => 'Mandra'
        ]);
        
        error_log('\nREFERER: '. $_SERVER['HTTP_REFERER']."\n");

        // $pat = $pc->getById($params['id']);
        return ($Renderer->render("edit_appointment.zetem", ['action' => 'new', 'id' => null, 'appointment' => $ap]));
    }

    function appointment_new_post($params) {
        global $Renderer;
        global $kernel;


        $kernel->addStatus('warning', 'Η επεξεργασία του ραντεβού ακυρώθηκε.');
        header('location: '.rel_url($_SERVER['HTTP_REFERER']));
    }

    function appointment_delete($params) {
        global $kernel;

        $ap = new appointmentsClass();
        $app = $ap->getById($params['id']);
        $app->delete();

        $kernel->addStatus('warning', 'Το ραντεβού διαγράφθηκε με τπιτυχία.');
        header('location: '.rel_url('/appointments'));
        exit();

    }




    function patient_appointment_new($params) {
        global $Renderer;
        global $kernel;

        if(!isset($params['id'])) {
            return ("User could not be found");
        }
        error_log("\npatient_appointment_new: ".print_r($params, 1)."\n");
        $p = patientsClass::sgetById( $params['id'] );
        // echo "<pre>" . print_r( $p ) . "</pre>";

        $ap = new appointmentsClass([
            'adate' => getDBtime(),
            'aplace' => 'Mandra',
        'anote' => print_r( $_SERVER, 1) /*'notes'*/]);
        // $kernel->setS
        // $pat = $pc->getById($params['id']);
        return ($Renderer->render("edit_appointment.zetem", ['action' => 'newappointment', 'id' => null, 'appointment' => $ap, 'patient' => $p]));
    }

    function patient_appointment_new_post($params) {
        global $Renderer;
        global $kernel;

        error_log("<pre>patient_appointment_new_post: ".print_r($params, 1)."</pre>");
        error_log("\nPatient id: " );
        if(isset($_POST['cancel'])) {
            $kernel->addStatus('warning', 'Η επεξεργασία του ραντεβού ακυρώθηκε.');
            header('location: '.rel_url('/patient/'.$params['id'].'/edit'));
            exit();
        }            
             
        $pat = patientsClass::sgetById( $params['id'] );
        if(!$pat) {
            echo "User " . $params['id'] . "cannot be found!\n";
            exit();
        }
        // echo "this is the post version<br/>";
        $app = new appointmentsClass([
            // 'guid' => 
            'id' => null,
            'cuser' => 'admin',
            'cdate' => getDBtime(),
            'adate' => $_POST['appointment-date'],
            'aplace' => $_POST['appointment-place'],
            'anote' => $_POST['appointment-notes'],
            'guid' => guid(),
            'pguid' => $pat->getguid()
        ]);
        // print_r( $pc );

        $app->insert();

        error_log('\nREFERER: '. $_SERVER['HTTP_REFERER']."\n");

        $kernel->addStatus('notice', 'Δημιουργήθηκε νέο ραντεβού.');
        header('location: '.rel_url('/patient/'.$pat->getid().'/edit'));
    }


    function login($params) {
        global $Renderer;

        return ($Renderer->render("login.zetem", ['action' => 'login']));
    }

    function login_post($params) {
        global $kernel;

        $us = UsersClassEx::getUser($_POST['username'], hash('sha256', $_POST['password']));
        // echo "<pre>User: " . print_r( $us, 1 ) . "</pre>";
        if($us) {
            $kernel->loginUser($us->getuname(), $us->getroles());
            header('location: '.rel_url('/profile'));
            exit();
        }


    }

    function logout($params) {
        global $kernel;

        echo "Logout user<br>";

        $us = $kernel->getUserName();
        if($us) {
            $kernel->logoutUser();
        }
        header('location: '.rel_url('/'));
        exit();

    }

