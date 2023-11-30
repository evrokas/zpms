<p>
    <?php
        if(isset($_SESSION['username'])) {
            echo "Logged in user: " . $_SESSION['username'] . " ";
            echo '<a href="logout">Logout</a>';
        }
    ?>
</p>

<p>
    This is the home page of Project Zeus
</p>
