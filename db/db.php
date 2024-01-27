<?php
    $argopts="a:";
    $rest=0;
    $file = '';
        
    $options = getopt( $argopts, [],  $rest);
    
    print_r( $options );
    echo "Rest: ", $rest. "\n\r";    

    if($rest < $argc) {
        $file = $argv[$rest++];
        echo "Proccessing file: " . $file;
    }

    if(strlen($file)) {
        $db = yaml_parse_file( $file );
        print_r( $db );
    }
?>

