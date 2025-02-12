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

        $corefile = file_get_contents($rootdir . "/fw/.git/HEAD");
        $corefile = explode(' ', trim($corefile))[1];
        $corehash = file_get_contents($rootdir . "/fw/.git/" . $corefile);
        $corebranch = explode('/', $corefile)[2];

        $tagfiles = glob($rootdir . ".git/refs/tags/*");
        $tags = [];
        $tag_str = null;
        foreach($tagfiles as $tag) {
            $tag_name = basename( $tag );
            $tags[ $tag_name ] = file_get_contents($tag);
            if($hash === $tags[ $tag_name ])
                $tag_str = $tag_name;
        }
        
        if(!$tag_str)
            $tag_str = $hash;


        // error_log( "git hash directory: $branch, hash: $hash\n" );
        return $this->renderTemplate(
            [
                'branch' => $branch, 
                'hash' => $hash, 
                'db' => DB_NAME,
            
                'corebranch' => $corebranch,
                'corehash' => $corehash,
                
                'tag' => $tag_str
            ]);
    }
}


function register_githash_module() {
    global $kernel;

    $kernel->registerModule( new githashModule(__DIR__, 'githash', 'githash.zetem'));
}
