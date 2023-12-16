<p>
    <?php
    // print_r( $info );
    echo "<pre>";
    print_r( $handlers );
    echo "</pre>";

        // echo "Root path " . $rootpath . '<br/>';

        if(isset($_SESSION['username'])) {
            echo "Logged in user: " . $_SESSION['username'] . " ";
            echo '<a href="logout">Logout</a>';
        }
    ?>
</p>

<p>
    This is the home page of Project Zeus
</p>
