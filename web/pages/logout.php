
<p>
    Logout page
</p>
<p>
    User : <b><?php echo $_SESSION['username'] ?> </b> logs out
</p>
<?php
    unset( $_SESSION['username'] );
    session_destroy();


    header('Location: ' . rurl('/') );
?>
