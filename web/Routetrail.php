<?php

// provide route tail interface

class Routetrail {
    function getTrail(&$routetrail, $aroute = null) {
        global $router;
        global $Request;
        if(!$aroute)$aroute = $router->matchRoute( $Request );;
        // echo "<pre>";
        // print_r( $aroute['_routedata'] );
        // echo "</pre>";
        $routetrail = array($aroute['_routedata']['title']);
    }
}