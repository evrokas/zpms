<?php

require_once('../fw/bootstrap.php');

    // Project Zeus - Patient Registration System
    global $rootpath;
    
    $rootpath = substr($_SERVER['PHP_SELF'],0,strrpos($_SERVER['PHP_SELF'],'/')+1);

    global $info;
    $info = yaml_parse_file('info.yaml');
    // print_r( $info );

    function kernel_getrootpath() {
        global $rootpath;
        return ($rootpath);
    }

    global $footer_message;
    $footer_message = '';

    function kernel_debug($dbg) {
        global $footer_message;
        $footer_message .= '<br/>' . $dbg;
    }

    function rurl($apath) {      
        if($apath =='/')
            $apath = '';
        return ( kernel_getrootpath()  . $apath);
    }

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

    function kernel_decoderoute( $ahndlr ) {
        global $info;

        kernel_debug("Searching for path: " . $ahndlr[0] . " in routes");
        
        // find route in route table
        // traverse route urls in route table


        $collectroutes = [];    // collected routes table
        
            // $hcnt=1;        // handle counter
            // initially collect all routes, assume that all routes are correct(!)
        $collectroutes = $info['routes'];
        // kernel_debug('ROUTES: '. print_r( $collectroutes, 1));
        // iterate over each route in the table
        foreach($info['routes'] as $routename => $routedata) {
               kernel_debug(" - explore route : " . $routename . " with data " . print_r( $routedata, 1));
            
            for($hcnt=1;$hcnt<count($ahndlr);$hcnt++) {
            // explode route url
                if($routedata['url'][0] === '/')
                    $routedata['url'] = trim($routedata['url'], '/');

                $rexpld = explode('/', $routedata['url']);
                
                kernel_debug(" - explode: " . $routename . " > " . print_r( $rexpld, 1 ));

                kernel_debug(' - - compare ' . $rexpld[ $hcnt ] . ' to ' . $ahndlr[$hcnt]);

                if(preg_match( $rexpld[$hcnt], "/\{.+\}/", $matches, PREG_UNMATCHED_AS_NULL)) {
                    kernel_debug('parameter');
                }
                if($rexpld[ $hcnt ] != $ahndlr[ $hcnt ]) {
                    unset( $collectroutes[ $routename ] );  
                    break;   // remove route from array
                }
            }
        }

        kernel_debug('ROUTES: '. print_r( $collectroutes, 1));

        if(count($collectroutes)>0) {
            foreach($collectroutes as $colkey => $colval) {
                kernel_debug('route: ' . $colkey . ' idx: '. $colval['_index'] . ' url: ' . $colval['url']);
            }
        }
            // kernel_debug( print_r( $collectroutes, 1 ));
    }
    
    // kernel_debug('start');
    // kernel_debug($rootpath);
    // kernel_debug('q:/'.$_SERVER['QUERY_STRING']);
    // kernel_debug( print_r( $info, 1) );

    // dump_routes();

    session_start();

    ob_start();
    require( 'templates/headers.php' );

    $handlers = explode('/', '/'.$_SERVER['QUERY_STRING']);
    $handlers[0] = $_SERVER['QUERY_STRING'];
    // print_r( $handlers );
    // $path = kernel_decoderoute( $handlers );

    $router = new RouterClass( $info['routes']);
    // print_r( $router->getAllRoutes() );

    print_r( 'test');
    $match = $router->matchRoute( $handlers[0] );
    print_r( $match );

    if(strlen($handlers[1])) {
        $page = $handlers[1];
    } else {
        $page = 'homepage';
    }

    require('templates/header.php');
    if(/*false &&*/ !isset($_SESSION['username'])) {
        // header('Location: index.php/login' );
        require('pages/login.php');
    } else {
        require('pages/' . $page . '.php');
    }


    include('templates/footer.php');


    ob_end_flush();

?>
