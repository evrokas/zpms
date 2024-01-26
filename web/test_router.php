<?php
// Router test
// match routing path test

require_once( '../fw/bootstrap.php' );


$info = yaml_parse_file('info.yaml');
$Router = new RouterClass( $info['routes'] );

// print_r( $info ['routes'] );

// print_r( $Router->getAllRoutes() );

// print_r( $Router->getRoute('info2'));

// $params = array();
$match = $Router->matchRoute('/info/data/evangelos/editt');

// if($match)
    print_r( $match );