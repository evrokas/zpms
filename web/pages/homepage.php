<p>
    <?php
        if(isset($_SESSION['username'])) {
            echo "Logged in user: " . $_SESSION['username'] . " ";
            echo '<a href="' . $kernel->relative_url('logout') .'">Logout</a>';
        }
    ?>
</p>


<h1>
    This is the home page of Project Zeus
</h1>

<br/>
<p>
    Lorem ipsum dolor sit amet consectetur adipisicing elit. Odio quisquam praesentium repellendus temporibus
     eligendi ea aliquam quos corporis! Ullam omnis consectetur cupiditate quaerat. Facere impedit laborum quae 
     voluptates aliquid porro!
     Lorem, ipsum dolor sit amet consectetur adipisicing elit. Totam consequatur voluptatum tenetur eos aliquid 
     nulla odit, repellendus ipsa doloribus eaque eum iste placeat. Officia quos voluptatibus ut nostrum, ipsam temporibus!
</p>