<?php
    // Project Zeus - Patient Registration System

    // var_dump( $_SERVER );
    $debug = false;
#    $debug = true;

    $cssfiles = array();
    $cssfiles[] = 'css/styles.css';
    $cssfiles[] = 'css/boxicons.min.css';

    $rootpath = substr($_SERVER['PHP_SELF'],0,strrpos($_SERVER['PHP_SELF'],'/')+1);
    
    $info = yaml_parse_file('info.yaml');
    // print_r( $info );


    session_start();

    require( 'templates/headers.php' );

    $handlers = explode('/', '/'.$_SERVER['QUERY_STRING']);
    $handlers[0] = $_SERVER['QUERY_STRING'];
    
    $page = 'homepage';
    
    if( $debug ) {
        echo '<pre>'; print_r( $_SERVER ); echo '</pre>';

        if(isset($_POST))
            echo "Post data: " . print_r($_POST, 1);
        
        echo 'Page request: ' . $page;
    }


    require('templates/header.php');
    if(false && !isset($_SESSION['username'])) {
        // header('Location: index.php/login' );
        require('pages/login.php');
    } else {
        require('pages/' . $page . '.php');
    }

    require('templates/footer.php');
    
?>
