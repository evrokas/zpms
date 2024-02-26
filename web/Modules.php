
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
        global $Request;
        global $kernel;

        $ptrail = new Menutrail($Request->getQueryRoute(), $kernel->getConfig('menu')['main']);
        $path = array();
        $ptrail->getTrail($path);
        // print_r( $path );
        if(!count($path)) {
            // menu trail could not get a path
            // try router instead, to get the current route
            $rtrail = new Routetrail();
            $rtrail->getTrail($path);
            // print_r( $path );
        }
        return ($this->renderTemplate( ["path" => array_merge(["Home"], $path)] ));
        // return ($this->renderTemplate( ["path" => ["home", "view", "test"]] ));
        // return ($Renderer->render("breadcrumbs.zetem", ["path" => ["home", "view", "test"]]));
    }
}

class mainnavigationModule extends moduleClass {
    protected $menu;
    protected $trail = array();

    function __construct($amodule, $atemplate, $amenu) {
        global $Request;

        parent::__construct($amodule, $atemplate);
        $this->menu = $amenu;
        $pathtrail = new Menutrail($Request->getQueryRoute(), $amenu);
        $pathtrail->getTrail($this->trail);
        // echo "<pre>";
        // print_r($this->menu);
        // print_r($this->trail);
        // echo "</pre>";
    }
    
    function setupMenuAttributes(&$amenu, $alevel) {
        // $amenu['attributes'] = new Attributes();
        // $amenu['attributes']->addClass('menu');
        // $amenu['attributes']->addClass('menu-level-'.$alevel);
        // if(!count($amenu))return;

        foreach($amenu as $mitem => $mdata) {
            // $at = new Attributes();
            $amenu[ $mitem ]['attributes'] = new Attributes();

            if(!isset($amenu[$mitem]['submenu']))
                $amenu[$mitem]['attributes']->addClass('menu-item');
            else {
                $amenu[$mitem]['attributes']->addClass('submenu-item');
                $amenu[$mitem]['attributes']->addClass('submenu-item-level-' . $alevel+1);
            }
                // $at->addClass('submenu-item-level-' . $alevel+1);

            // $amenu[ $mitem ]['attributes'] = $at;
            
            // echo "<pre>" . $amenu[$mitem]['text'] . ' <> ' . $this->trail[$alevel] . " {" . $alevel . "}<br/></pre>";
            if($alevel && ($amenu[$mitem]['text'] == $this->trail[$alevel])
            
            )
                $amenu[$mitem]['attributes']->addClass('in-menu-trail');


            // echo "mdata ($alevel):" . $amenu[$mitem]['text'];
            if(isset($amenu[$mitem]['submenu'])) {  
                // $amenu[$mitem]['submenu']['attributes'] = new Attributes('class', 'submenu');
                // ($amenu[$mitem]['submenu']['attributes'])->addClass('submenu');

                $this->setupMenuAttributes($amenu[ $mitem ]['submenu'], $alevel+1);
            }
            // echo "<pre>"; print_r( $amenu ); echo "</pre>";
            // echo "attrs: " . $amenu[$mitem]['attributes']->getAttributes() . "<br>";
        }
    }
    function render($params = array()) {
        global $Renderer;

        $mmenu = $this->menu;
        $this->setupMenuAttributes($mmenu, 0);
        // echo "<pre>"; print_r( $mmenu ); echo "</pre>";

        return(
            // "main navigation module<br>" .
            $this->RenderTemplate(['menu' => $mmenu]));
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

            $prms = array();
            foreach(['notice', 'error', 'warning'] as $level) {
                $s = $kernel->getStatus('notice', true);
                if(count($s))$prms[ $level ] = implode('<br>', $s);
            }

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
