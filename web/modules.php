
<?php
// modules


class moduleClass {
    static private $modulename;     // module name
    static private $template;       // template associateed wi

    function __construct($amodule, $atemplate) {
        self::$modulename = $amodule;
        self::$template = $atemplate;

    }
    function render($params = null) {
        $params[] = ['module' => self::$template];

        echo "render module " . self::$modulename;
        global $Renderer;
        return $Renderer->render( self::$template, $params ); 
    }

    function getName() {
        return (self::$modulename);
    }

    function getTemplate() {
        return (self::$template);
    }

    function setTemplate($atemplate) {
        self::$template = $atemplate;
    }
}

class topbarModule extends moduleClass {
    function render($params = null) {
        return("topbarModule: " . print_r($params, 1));
    }
}

class htmltextModule extends moduleClass {
    static protected $htmltext;

    function __construct($amodule, $atemplate, $text) {
        parent::__construct($amodule, $atemplate);
        self::$htmltext = $text;
    }

    function render($params = null) {
        global $Renderer;
        return($Renderer->render($this->getTemplate(), ['text' =>self::$htmltext, 'params' => $params]));
    }
}

class header extends moduleClass {
    function render($params = null) {
      global $kernel;
      global $Renderer;
        $tit = $kernel->getConfig()['title'];
        // echo "header module: param title = " . $tit . "\n";
        return ($Renderer->render("header.zetem", ["title" => $kernel->getConfig()['title'] ]));
    }
}

class breadcrumbsModule extends moduleClass {
    function render($params = null) {
        global $Renderer;

        return ($Renderer->render("breadcrumbs.zetem", ["path" => ["home", "view", "test"]]));
    }
}

class mainnavigationModule extends moduleClass {
    function render($params = null) {
        global $Renderer;
        global $kernel;

        return($Renderer->render(self::getTemplate(), ['menu' => $kernel->getConfig()['menu']['topnavigation']]));
    }
}

class contentModule extends moduleClass {
    function render($params = null) {
        global $Renderer;
        global $kernel;
        global $Request;

            return ("<p>Content for route: " . $Request->getQueryRoute()[0]. "</p>");
    }
}
function registerModules() {
    global $kernel;


    $kernel->registerModule( new header('header', 'header.zetem'));
    $kernel->registerModule( new breadcrumbsModule('breadcrumbs', 'breadcrumbs.zetem'));
    $kernel->registerModule( new mainnavigationModule('mainnavigation', 'top_menu.zetem'));
    // $kernel->registerModule( new moduleClass('topbar', 'topbar.zetem') );
    // $kernel->registerModule( new moduleClass('content', 'content.zetem') );
    $kernel->registerModule( new htmltextModule('copyright', 'htmltext.zetem', '(c) by Evangelos M. Rokas'));
    $kernel->registerModule( new contentModule('content', 'content.zetem'));
}