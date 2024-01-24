<p>
    <?php
        if(isset($_SESSION['username'])) {
            echo "Logged in user: " . $_SESSION['username'] . " ";
            echo '<a href="' . rurl('logout') .'">Logout</a>';
        }
    ?>
</p>


<p>
    This is the info page of Project Zeus
</p>
<br/>
<p>
    Lorem ipsum dolor sit amet consectetur adipisicing elit. Odio quisquam praesentium repellendus temporibus
     eligendi ea aliquam quos corporis! Ullam omnis consectetur cupiditate quaerat. Facere impedit laborum quae 
     voluptates aliquid porro!
     Lorem, ipsum dolor sit amet consectetur adipisicing elit. Totam consequatur voluptatum tenetur eos aliquid 
     nulla odit, repellendus ipsa doloribus eaque eum iste placeat. Officia quos voluptatibus ut nostrum, ipsam temporibus!
</p>