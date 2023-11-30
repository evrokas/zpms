<?php
    // Project Zeus - Patient Registration System

    // var_dump( $_SERVER );
    $debug = false;
    $debug = true;

    session_start();

    require( 'templates/headers.php' );

    if(!isset($_GET['page']))
        $_GET['page'] = 'homepage';

    if( $debug ) {
        echo '<pre>'; print_r( $_SERVER ); echo '</pre>';
 
        if(isset($_POST))
            print_r($_POST);
        if(isset($_GET['page'])) {
            echo 'Page request: ' . $_GET['page'];
        }
    }


    require('templates/header.php');
    if(!isset($_SESSION['username'])) {
        // header('Location: index.php/login' );
        require('pages/login.php');
    } else {
        require('pages/' . $_GET['page'] . '.php');
    }

    require('templates/footer.php');
    
?>
