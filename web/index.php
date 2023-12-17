<?php
    // Project Zeus - Patient Registration System

    $rootpath = substr($_SERVER['PHP_SELF'],0,strrpos($_SERVER['PHP_SELF'],'/')+1);
    
    $info = yaml_parse_file('info.yaml');
    // print_r( $info );


    session_start();

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

    require('templates/footer.php');
    
?>
