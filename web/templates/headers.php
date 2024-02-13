<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zeus | Patient Management System</title>
    

<?php
    foreach($info['css'] as $css) {
        echo '<link rel="stylesheet" href="' . $kernel->relative_url($css) . '">';
    }
?>

</head>
<body>
