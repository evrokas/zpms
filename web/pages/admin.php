<p>
    <?php
    echo "<pre>";
    print_r( $info );
    print_r( $handlers );

    // print_r( $info['routes']['admin']['url'] );
    
    echo "</pre>";

        // echo "Root path " . $rootpath . '<br/>';

        if(isset($_SESSION['username'])) {
            echo "Logged in user: " . $_SESSION['username'] . " ";
            echo '<a href="logout">Logout</a>';
        }
    ?>
</p>

<p>
    This is the Admin page for Project Zeus
</p>
