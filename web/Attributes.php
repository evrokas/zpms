<?php

// attributes
/*
 * Attributes:
 *  class
 *  role
 *  data
 *  etc...
 */

class Attributes {
    private $attr;

    function __construct($attrclass = null, $attrname = null) {
        $this->attr = array();

        if(isset($attrclass) && isset($attrname))
            $this->addAttribute($attrclass, $attrname);
    }


    function addAttribute($attrclass, $attrname) {
        if(!isset($this->attr[ $attrclass ]))
            $this->attr[ $attrclass ] = array();
        $this->attr[ $attrclass ][] = $attrname;
        // print_r( $this->attr );
    }

    function removeAttribute($attrclass, $attrname) {
        if(!isset($this->attr[ $attrclass ]))return;
        if(isset($this->attr[ $attrclass ][ $attrname ]))
            unset($this->attr[ $attrclass ][ $attrname ]);
    }

    function addClass($attrname) {
        $this->addAttribute('class', $attrname);
    }

    function removeClass($attrname) {
        $this->removeAttribute('class', $attrname);
    }


    function getAttributes() {
        $str = '';
        foreach($this->attr as $attrclass => $attrdata) {
            // echo "atribute data: " . $attrdata;
            if(count($attrdata))
                $s = $attrclass . "=\"" . implode(' ', $attrdata) . "\"";
            $str .= $s;
        }
    
      return ($str);
    }
}
