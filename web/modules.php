
<?php
// modules


class moduleClass {
    private $modulename;     // module name
    private $template;       // template associateed wi

    function __construct($amodule, $atemplate) {
        $this->modulename = $amodule;
        $this->template = $atemplate;

    }
    function render($params = array()) {
        $params[] = ['module' => $this->template];

        // echo "render module " . $this->modulename;
        global $Renderer;
        return $this->renderTemplate( $params );

        // return $Renderer->render( $this->template, $params ); 
    }

    function getName() {
        return ($this->modulename);
    }

    function getTemplate() {
        return ($this->template);
    }

    function setTemplate($atemplate) {
        $this->template = $atemplate;
    }

    function renderTemplate($aparms = array()) {
        global $Renderer;

        return (
            // $this->modulename . ": renderTemplate(): " . $this->template . ": " .
            $Renderer->render($this->template, $aparms));
    }
}

class topbarModule extends moduleClass {
    function render($params = array()) {
        return("topbarModule: " . print_r($params, 1));
    }
}

class htmltextModule extends moduleClass {
    protected $htmltext;

    function __construct($amodule, $atemplate, $text) {
        parent::__construct($amodule, $atemplate);
        $this->htmltext = $text;
    }

    function render($params = array()) {
        global $Renderer;
        return($Renderer->render($this->getTemplate(), ['text' =>$this->htmltext, 'params' => $params]));
    }
}

class header extends moduleClass {
    function render($params = array()) {
      global $kernel;
      global $Renderer;
        $tit = $kernel->getConfig()['title'];
        // echo "header module: param title = " . $tit . "\n";
        return ($Renderer->render("header.zetem", ["title" => $kernel->getConfig()['title'] ]));
    }
}

class breadcrumbsModule extends moduleClass {
    function render($params = array()) {
        global $Renderer;

        return ($this->renderTemplate( ["path" => ["home", "view", "test"]] ));
        // return ($Renderer->render("breadcrumbs.zetem", ["path" => ["home", "view", "test"]]));
    }
}

class mainnavigationModule extends moduleClass {
    protected $menu;

    function __construct($amodule, $atemplate, $amenu) {
        parent::__construct($amodule, $atemplate);
        $this->menu = $amenu;
        // echo "<pre>";
        // print_r($this->menu);
        // echo "</pre>";
    }
    
    function render($params = array()) {
        global $Renderer;

        return(
            // "main navigation module<br>" .
            $this->RenderTemplate(['menu' => $this->menu]));
    }
}

class contentModule extends moduleClass {
    function render($params = array()) {
        global $Renderer;
        global $kernel;
        global $Request;
        global $content_response;

            return ($content_response);
    }
}

class notificationsModule extends moduleClass {
    function render($params = array()) {
        global $kernel;
        global $_SESSION;
        
            print_r( $_SESSION );
            $prms = array();
            if(($s=$kernel->ifelseStatus('notify_message', '', true)))$prms['notice'] = $s;
            if(($s=$kernel->ifelseStatus('error_message', '', true)))$prms['error'] = $s;
            if(($s=$kernel->ifelseStatus('warning_message', '', true)))$prms['warning'] = $s;
            echo "Notifications module<br>".print_r($prms)."<br>";

        return $this->RenderTemplate($prms);
    }
}
function registerModules() {
    global $kernel;


    $kernel->registerModule( new header('header', 'header.zetem'));
    $kernel->registerModule( new breadcrumbsModule('breadcrumbs', 'breadcrumbs.zetem'));
    $kernel->registerModule( new mainnavigationModule('mainnavigation', 'main_navigation.zetem', $kernel->getConfig('menu')['main']));
    // $kernel->registerModule( new moduleClass('topbar', 'topbar.zetem') );
    // $kernel->registerModule( new moduleClass('content', 'content.zetem') );
    $kernel->registerModule( new htmltextModule('copyright', 'htmltext.zetem', '(c) by Evangelos M. Rokas'));
    $kernel->registerModule( new contentModule('content', 'content.zetem'));
    $kernel->registerModule( new notificationsModule('notifications', 'notifications.zetem'));
}