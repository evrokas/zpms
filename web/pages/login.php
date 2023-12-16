<?php

#//    phpinfo();

#//    include "templates/header.php";

    if(isset($_POST)) {
        // the user has posted the form
        if(isset($_POST['username']) && isset($_POST['password'])) {
            if(($_POST['username'] === 'admin') && ($_POST['password'] === 'admin')) {
                $_SESSION['username'] = 'admin';
            }
        }
    }
?>

<div class="inner-wrapper">
    <div class="title">
        Enter your credentials to login
    </div>    
    <form action="#" method="post">
        <div class="field">
            <label for="username">Username</label>
            <input type="text" name="username" id="username">
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input type="password" name="password" id="password">
        </div>

        <div class="buttons">
            <button type="submit">Login</button>
        </div>
    </form>
</div>
