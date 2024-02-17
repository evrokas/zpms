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
    global $info;
    $info = yaml_parse_file('info.yaml');
    // print_r( $info );

    $kernel = new Kernel($_SERVER, $info);

    $router = new RouterClass( $info['routes']);
    // print_r( $router->getAllRoutes() );

    $Renderer = new ZETEMTemplate('templates/', false, true);


    $Request = new RequestClass($_SERVER);
    $handlers = $Request->getQueryRoute();

    // echo "<pre>" . print_r( $_SERVER, 1 ) . "</pre>";
    // echo( "Method: " . $req->getMethod() . '  string: ' . $req->getQueryString() . "<br/>" );
    // print_r( $handlers );


    $match = $router->matchRoute( $handlers[0] );


 
    session_start();

    ob_start();

    require_once('modules.php');
    registerModules();

    if(!$match) {
        error_404();
        exit;
    } else 
    {

        if (false) {
            if(/* false && */ !isset($_SESSION['username'])) {
                // header('Location: index.php/login' );
                require('pages/login.php');
            } else {
                require('pages/' . $page . '.php');
            }
        }

        // $Renderer->view("main.zetem", $kernel->getConfig() );
        registerModules();
        $region_resp = array();

        foreach($kernel->getConfig()['regions'] as $region) {
            // echo "<pre>Region " . print_r( $region, 1 ) . "</pre>";
            $blocks = $kernel->getBlocksInRegion( $region );
            // print_r( $blocks );
            $blk_resp = '';
            foreach($blocks as $block) {
                $blk = $kernel->getModule( $block );
                // echo("Calling module->render() for block " . print_r( $blk, 1). "<br/>");
                if($blk)
                    $blk_resp .= $blk->render();
            }
            // echo "Region response text " . $blk_resp;
            $regions_resp[ $region ] = $blk_resp;

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
    
        // print_r($match);
        // $router->routerCallFunction($match);
    }

    // include('templates/footer.php');

    ob_end_flush();
