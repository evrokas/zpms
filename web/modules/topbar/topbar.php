<?php

class topbarModule extends moduleClass {
    function render($params = array()) {
        return("topbarModule: " . print_r($params, 1));
    }
}


function register_topbar_module() {
    global $kernel;

    $kernel->registerModule( new moduleClass('topbar', 'topbar.zetem') );
}