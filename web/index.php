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
    
    require("menutrail.php");
    $pathtrail = new MenuTrail($kernel->getConfig('menu')['main']);

    echo "<pre>";
        // print_r($Request->getQueryString());
        // echo "<br>";
        // $pathtrail->getTrails();
        // $mtrail = array();
        $pathtrail->isInTrail( $Request->getQueryRoute(), 1, $kernel->getConfig('menu')['main'], $mtrail);
    
        // $pathtrail->isInRouteTrail($Reque)
        print_r( $mtrail );    
    echo "</pre>";
    
    
    require_once('modules.php');
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

        $pc = new patientsClass();
        $pat = $pc->getAll();
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

        $pc = new patientsClass();
        $pat = $pc->getById($params['id']);
        return ($Renderer->render("edit_patients.zetem", ['id' => $params['id'], 'patient' => $pat]));
    }
    function patient_edit_post($params) {
        global $Renderer;
        global $kernel;

        if(!isset($params['id'])) {
            return ("patients doesn't exist");
        }

        // echo "this is the post version<br/>";
        $pc = new patientsClass();
        $pat = $pc->getById($params['id']);

        $kernel->addStatus('notice', 'Ο φάκελος του ασθενή <b>' . $pat->getpname() . '</b> έχει αποθηκευτεί.');
        header('location: '.rel_url('/patients'));
        exit();
        // return '';
        // patients_list(['notice' => 'Ο φάκελος του ασθενή ' + $pat->getpname() . ' έχει αποθηκευτεί.']);
    
        // return ($Renderer->render("edit_patients.zetem", ['notice' => 'data were saved', 'id' => $params['id'], 'patient' => $pat]));
    }

    function patient_delete($params) {
        return ("<p>Patient delete no: " . $params['id'] . "</p>");
    }

    function appointments_list($params) {
        global $Renderer;
        return $Renderer->render("appointments_list.zetem", []);
    }

