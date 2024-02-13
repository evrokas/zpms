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

    $template = new ZETEMTemplate('templates/', false, true);


    $req = new RequestClass($_SERVER);
    $handlers = $req->getQueryRoute();


 
    session_start();

    ob_start();
    require( 'templates/headers.php' );

    // $handlers = explode('/', '/'.$_SERVER['QUERY_STRING']);
    // $handlers[0] = $_SERVER['QUERY_STRING'];
    // echo "<pre>" . print_r( $_SERVER, 1 ) . "</pre>";

    echo( "Method: " . $req->getMethod() . '  string: ' . $req->getQueryString() . "<br/>" );
    // print_r( $handlers );
    // $path = kernel_decoderoute( $handlers );


    print_r( 'test');
    $match = $router->matchRoute( $handlers[0] );
    kernel_debug( print_r( $match, 1 ) );
    print_r( $match );

    if(strlen($handlers[1])) {
        $page = $handlers[1];
    } else {
        $page = 'homepage';
    }

    require( 'templates/header.php' );

    if(!$match) {
        error_404();
        // exit;
    } else {

    
    if(/*false &&*/ !isset($_SESSION['username'])) {
        // header('Location: index.php/login' );
        require('pages/login.php');
    } else {
        require('pages/' . $page . '.php');
    }
    print_r($match);
    $router->routerCallFunction($match);
}

    include('templates/footer.php');

    ob_end_flush();
