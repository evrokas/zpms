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
$route = '/';
if($argc>1)
    $route = $argv[1];
echo 'Testing route: ' . $route . "\n";

$match = $Router->matchRoute($route);

if(!$match) {
    echo "No route found!\n";
    exit;
}

function info_due($params) {
    echo "handler execution info_due()\n";
    foreach($params as $p => $pp) {
        echo "[".$p."] => " . $pp . "\n";
    }
}
function info_data_id_no($params) {
    echo "handler execution info_data_id_no()\n";
    foreach($params as $p => $pp) {
        echo "[".$p."] => " . $pp . "\n";
    }
}
function info_data_id_edit($params) {
    echo "handler execution info_data_id_edit()\n";
    foreach($params as $p => $pp) {
        echo "[".$p."] => " . $pp . "\n";
    }
}

function homepage($params) {
    echo "<h1>Homepage</h1>";
}

function admin($params) {
    echo "<h1>Admin page</h1>";
}

// echo "Route found!\nRoute name: " . $match['_routename'] . "\nHandler: " . $match['_routedata']['handler']."\n";
$fexe = $match['_routedata']['handler'];
$params = $match['_params'];

call_user_func($fexe, $params);
