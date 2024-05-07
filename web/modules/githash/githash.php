<?php
class githashModule extends moduleClass {
    // function __construct($amodule, $atemplate) {
        // parent::__construct($amodule, $atemplate);
    // }
    
    function render($params = array()) {
        // $hash = __FUNCTION__;
        $rootdir = explode('index.php', $_SERVER['SCRIPT_FILENAME'])[0] . "../";
        
        $headfile = file_get_contents($rootdir . ".git/HEAD");
        $headfile = explode(' ', trim($headfile))[1];
        $hash = file_get_contents($rootdir . ".git/" . $headfile);
        $branch = explode('/', $headfile)[2];

        // error_log( "git hash directory: $branch, hash: $hash\n" );
        return $this->renderTemplate(['branch' => $branch, 'hash' => $hash, 'db' => DB_NAME /*$GLOBALS['DATABASE']*/]);
    }
}


function register_githash_module() {
    global $kernel;

    $kernel->registerModule( new githashModule(__DIR__, 'githash', 'githash.zetem'));
}
