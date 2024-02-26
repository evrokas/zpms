<?php

// provide menu tail interface

class Menutrail {
    private $menu;
    private $path;

    function __construct($apath, $amenu) {
        $this->path = $apath;
        $this->menu = $amenu;
    }

    function getTrail(&$amenutrail, $amenu = null, $level = 1) {
        // $path = explode('/', $apath);
        if(!$amenu)$amenu = $this->menu;
        // echo "<pre>";
        // echo "Testing trail in " . $apath[ $level ] . "  for token " . 
        // print_r( $apath ); echo "<br>" . "level: $level<br>";
        // print_r( $this->menu );
        // echo "</pre>";

        foreach($amenu as $menuitem) {
            // echo "test trail: " . array_key_first($menuitem) . " === " . $apath[ $level ] . "<br>";
            if(array_key_first($menuitem) == $this->path[ $level ]) {
                // echo "found trail " . $menuitem['text'] . "<br>" . print_r( $menuitem, 1) . "<br>";

                $amenutrail[] = $menuitem['text'];
                if(isset($menuitem['submenu'])) {
                    // echo "Has submenu<br>";
                    $this->getTrail($amenutrail, $menuitem['submenu'], $level+1);
                }
            }
        }
    }

    function getTrails() {
        foreach($this->menu as $menuitem) {
            echo "menuitem: " . $menuitem['text'] . "<br>";
        }
    }
}