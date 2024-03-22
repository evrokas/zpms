<?php

class header extends moduleClass {
    function render($params = array()) {
      global $kernel;
      global $Renderer;
        $tit = $kernel->getConfig()['title'];
        // echo "header module: param title = " . $tit . "\n";
        return ($Renderer->render("header.zetem", ["title" => $kernel->getConfig()['title'] ]));
    }
}


function register_header_module() {
  global $kernel;

  $kernel->registerModule( new header('header', 'header.zetem'));
}