<?php
    // Project Zeus - Patient Registration System
    global $rootpath;
    
    $rootpath = substr($_SERVER['PHP_SELF'],0,strrpos($_SERVER['PHP_SELF'],'/')+1);

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

    
    $info = yaml_parse_file('info.yaml');
    // print_r( $info );

    
    kernel_debug('start');
    kernel_debug($rootpath);
    kernel_debug('q:/'.$_SERVER['QUERY_STRING']);

    session_start();

    ob_start();
    require( 'templates/headers.php' );

    $handlers = explode('/', '/'.$_SERVER['QUERY_STRING']);
    $handlers[0] = $_SERVER['QUERY_STRING'];
    
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
