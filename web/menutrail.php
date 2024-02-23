<?php

// provide menu tail interface

class MenuTrail {
    private $menu;
    function __construct($amenu) {
        $this->menu = $amenu;
    }

    function isInTrail($apath, $level, $amenu, &$amenutrail) {
        // $path = explode('/', $apath);

        // echo "<pre>";
        // echo "Testing trail in " . $apath[ $level ] . "  for token " . 
        // print_r( $apath ); echo "<br>" . "level: $level<br>";
        // print_r( $this->menu );
        // echo "</pre>";

        foreach($amenu as $menuitem) {
            // echo "test trail: " . array_key_first($menuitem) . " === " . $apath[ $level ] . "<br>";
            if(array_key_first($menuitem) == $apath[ $level ]) {
                // echo "found trail " . $menuitem['text'] . "<br>" . print_r( $menuitem, 1) . "<br>";

                $amenutrail[] = $menuitem['text'];
                if(isset($menuitem['submenu'])) {
                    // echo "Has submenu<br>";
                    $this->isInTrail($apath, $level+1, $menuitem['submenu'], $amenutrail);
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