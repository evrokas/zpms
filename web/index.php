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

    $router = new RouterClass( $kernel->getConfig('routes') );
    // print_r( $router->getAllRoutes() );

    $Renderer = new ZETEMTemplate('templates/', false, true);


    $Request = new RequestClass($_SERVER);
    // $handlers = $Request->getQueryRoute();

    // echo "<pre>" . print_r( $_SERVER, 1 ) . "</pre>";
    // echo( "Method: " . $req->getMethod() . '  string: ' . $req->getQueryString() . "<br/>" );
    // print_r( $handlers );
 
    session_start();
    ob_start();

    // $match = $router->matchRoute( $handlers[0] );
    $match = $router->matchRoute( $Request );
    // print_r($match);

    $content_response = $router->routerCallFunction($match);
    // print_r($content_response);

    // require_once('Modules.php');
    // require_once("Attributes.php");
    // require_once("Menutrail.php");
    // require_once('Routetrail.php');
    // $pathtrail = new Menutrail($Request->getQueryRoute(), $kernel->getConfig('menu')['main']);

    // echo "<pre>";
        // print_r($Request->getQueryString());
        // echo "<br>";
        // $pathtrail->getTrails();
    // $mtrail = array();
    // $pathtrail->getTrail($mtrail);
    
        // $pathtrail->isInRouteTrail($Reque)
        // print_r( $mtrail );    
    // echo "</pre>";
    
    registerModules();


    // if(!$match) {
    //     error_404();
    //     exit;
    // } else 
    {

        // if (false) {
        //     if(/* false && */ !isset($_SESSION['username'])) {
        //         // header('Location: index.php/login' );
        //         require('pages/login.php');
        //     } else {
        //         require('pages/' . $page . '.php');
        //     }
        // }

        // $Renderer->view("main.zetem", $kernel->getConfig() );
        registerModules();
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

        if(!isset($params['id'])) {
            $kernel->addStatus('error', 'Ο φάκελος του ασθενή δεν βρέθηκε!');
            return ("patients doesn't exist");
        }

        // $pc = new patientsClass();
        // $pat = $pc->getById($params['id']);

        $pat = patientsClass::sgetById( $params['id'] );
        return ($Renderer->render("edit_patient.zetem", ['action' => 'edit', 'id' => $params['id'], 'patient' => $pat]));
    }
    function patient_edit_post($params) {
        global $Renderer;
        global $kernel;

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
            header('location: '.rel_url('/patients'));
        }

        exit();
        // return '';
        // patients_list(['notice' => 'Ο φάκελος του ασθενή ' + $pat->getpname() . ' έχει αποθηκευτεί.']);
    
        // return ($Renderer->render("edit_patients.zetem", ['notice' => 'data were saved', 'id' => $params['id'], 'patient' => $pat]));
    }


    function patient_new($params) {
        global $Renderer;
        global $kernel;

        $pc = new patientsClass([
            'pname' => 'rokas rokas',
            'pdob' => '1977-07-29T00:00',
            'pamka' => '29097704978',
            'ptel' => '7548347829',
            'paddr' => 'themisotcleous, 3',
            'pemail' => 'evrokas@hotmail.com'
        ]);
        
        // $pat = $pc->getById($params['id']);
        return ($Renderer->render("edit_patient.zetem", ['action' => 'new', 'id' => null, 'patient' => $pc]));
    }

    function patient_delete($params) {
        global $kernel;

        $pc = new patientsClass();
        $pat = $pc->getById($params['id']);
        $pat->delete();

        $kernel->addStatus('warning', 'Ο φάκελος διαγράφθηκε με τπιτυχία.');
        header('location: '.rel_url('/patients'));
        exit();
    }

    function patient_new_post($params) {
        global $Renderer;
        global $kernel;

        // echo "this is the post version<br/>";
        $pc = new patientsClass([
            // 'guid' => 
            'id' => null,
            'cuser' => 'admin',
            'cdate' => '1977-07-29 00:00:00',
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

        $kernel->addStatus('notice', 'Δημιουργήθηκε νέος φάκελος για τον ασθενή <b>' . $pc->getpname() . '</b>.');
        header('location: '.rel_url('/patients'));
    }

    function appointments_list($params) {
        global $Renderer;
        global $kernel;

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
        
    }
    function appointment_new($params) {
        global $Renderer;
        global $kernel;

        $ap = new appointmentsClass([
            'adate' => '1977-07-29 00:00:00',
            'aplace' => 'Mandra'
        ]);
        
        // $pat = $pc->getById($params['id']);
        return ($Renderer->render("edit_appointment.zetem", ['action' => 'new', 'id' => null, 'appointment' => $ap]));
    }

    function appointment_new_post($params) {
        global $Renderer;
        global $kernel;


        if(isset($_POST['cancel'])) {
            $kernel->addStatus('warning', 'Η επεξεργασία του ραντεβού ακυρώθηκε.');
            header('location: '.rel_url('/appointments'));
            exit();
        }            


        // echo "this is the post version<br/>";
        $app = new appointmentsClass([
            // 'guid' => 
            'id' => null,
            'cuser' => 'admin',
            'cdate' => '1977-07-29 00:00:00',
            'adate' => $_POST['appointment-date'],
            'aplace' => $_POST['appointment-place'],
            'guid' => guid(),
            'pguid' => guid()
        ]);
        // print_r( $pc );

        $app->insert();

        $kernel->addStatus('notice', 'Δημιουργήθηκε νέο ραντεβού.');
        header('location: '.rel_url('/appointments'));
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