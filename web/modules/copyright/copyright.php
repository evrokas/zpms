<?php

function register_copyright_module() {
        global $kernel;

//        $kernel->registerModule( new htmltextModule(__DIR__, 'copyright', 'htmltext.zetem', 
//                '&copy 2013-25 by Evangelos M. Rokas, MD' . ' | <a href="#"><i class="bx bx-cog"></i></a>'));
//        $kernel->registerModule( new htmltextModule(__DIR__, 'copyright', 'htmltext.zetem', 
//                '&copy 2013-25 by Evangelos M. Rokas, MD'));
        $kernel->registerModule( new htmltextModule(__DIR__, 'copyright', 'copyright.zetem', 
                '&copy 2013-25 by Evangelos M. Rokas, MD'));
}
