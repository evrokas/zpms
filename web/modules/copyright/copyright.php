<?php

function register_copyright_module() {
        global $kernel;

        $kernel->registerModule( new htmltextModule(__DIR__, 'copyright', 'htmltext.zetem', 
                '&copy 2023-24 by Evangelos M. Rokas, MD' . ' | <a href="#"><i class="bx bx-cog"></i></a>'));
}
