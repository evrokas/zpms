<?php
// class usersClass
require_once(__DIR__ . "/../../fw/db/dbal.php");

class usersClass extends dbAbstractEntityClass {
  private $name;
  private $email;
  private $uname;
  private $upass;
  private $cdate;
  private $active;
  private $expired;
  private $roles;
  function __construct($adata = array() ) {
      parent::__construct('users', $adata);
      $this->loadFields( $adata );
  }
  static function sgetById(int $aid) {
                global $AppDBConnection;

                    if(!$AppDBConnection->isConnected()) {
                        if(!$AppDBConnection->Connect()) {
                            echo 'Could not connect to database';
                            return (null);
                        }
                    }
            
                    $sql = "SELECT * FROM users WHERE id=:id";
                    $st = $AppDBConnection->getConnection()->prepare( $sql );
                    $st->bindValue(":id", $aid, PDO::PARAM_INT);
                    $st->execute();
                    $row = $st->fetch();
            
                    if($row) {
                        $rclass = new usersClass( "users");
                        $rclass->loadFields( $row );
                        return $rclass;
                    } else return (null);
            }
  static function sgetAll() {
                global $AppDBConnection;

                if(!$AppDBConnection->isConnected()) {
                    if(!$AppDBConnection->Connect()) {
                        echo 'Could not connect to database';
                        return (null);
                    }
                }
        
                $sql = "SELECT * FROM users;";
                $st = $AppDBConnection->getConnection()->prepare( $sql );
                $st->execute();
        
                $list = array();
        
                while( $row = $st->fetch() ) {
                    $rclass = new usersClass( "users" );
                    $rclass->loadFields( $row );
                    $list[] = $rclass;
                }
        
                return ($list);
        
            }
  function loadFields($adata) {
      parent::loadFields($adata);
      if(isset($adata['name']))$this->name = $adata['name'];
      if(isset($adata['email']))$this->email = $adata['email'];
      if(isset($adata['uname']))$this->uname = $adata['uname'];
      if(isset($adata['upass']))$this->upass = $adata['upass'];
      if(isset($adata['cdate']))$this->cdate = $adata['cdate'];
      if(isset($adata['active']))$this->active = $adata['active'];
      if(isset($adata['expired']))$this->expired = $adata['expired'];
      if(isset($adata['roles']))$this->roles = $adata['roles'];
  }
  function setname( $aname ) { $this->name = $aname; }
  function getname() { return ( $this->name); }
  function setemail( $aemail ) { $this->email = $aemail; }
  function getemail() { return ( $this->email); }
  function setuname( $auname ) { $this->uname = $auname; }
  function getuname() { return ( $this->uname); }
  function setupass( $aupass ) { $this->upass = $aupass; }
  function getupass() { return ( $this->upass); }
  function setcdate( $acdate ) { $this->cdate = $acdate; }
  function getcdate() { return ( $this->cdate); }
  function setactive( $aactive ) { $this->active = $aactive; }
  function getactive() { return ( $this->active); }
  function setexpired( $aexpired ) { $this->expired = $aexpired; }
  function getexpired() { return ( $this->expired); }
  function setroles( $aroles ) { $this->roles = $aroles; }
  function getroles() { return ( $this->roles); }
    function insert() {
        if($this->id != null) {
            echo 'Trying to insert() a record that already exists';
            return (null);
        }

        if(!$this->getConnection()->isConnected()) {
            if(!$this->getConnection()->Connect()) {
                echo 'Could not connect to database';
                return (null);
            }
        }
        
$sql = "INSERT INTO users ( name,email,uname,upass,cdate,active,expired,roles ) VALUES ( :name,:email,:uname,:upass,:cdate,:active,:expired,:roles );";
$st = $this->getConnection()->getConnection()->prepare ( $sql );
$st->bindValue( ":name", $this->name, PDO::PARAM_STR );
$st->bindValue( ":email", $this->email, PDO::PARAM_STR );
$st->bindValue( ":uname", $this->uname, PDO::PARAM_STR );
$st->bindValue( ":upass", $this->upass, PDO::PARAM_STR );
$st->bindValue( ":cdate", $this->cdate, PDO::PARAM_STR );
$st->bindValue( ":active", $this->active, PDO::PARAM_STR );
$st->bindValue( ":expired", $this->expired, PDO::PARAM_STR );
$st->bindValue( ":roles", $this->roles, PDO::PARAM_STR );
$st->execute();
$this->setid( $this->getConnection()->getConnection()->lastInsertId() );
}

        function update() {
            if($this->id == null) {
                echo 'Trying to update() a record that does not exist';
                return (null);
            }
    
            if(!$this->getConnection()->isConnected()) {
                if(!$this->getConnection()->Connect()) {
                    echo 'Could not connect to database';
                    return (null);
                }
            }
                
            $sql = "UPDATE users SET name=:name,email=:email,uname=:uname,upass=:upass,cdate=:cdate,active=:active,expired=:expired,roles=:roles WHERE id=:id";

            $st = $this->getConnection()->getConnection()->prepare ( $sql );
            
          $st->bindValue( ":name", $this->name, PDO::PARAM_STR );
          $st->bindValue( ":email", $this->email, PDO::PARAM_STR );
          $st->bindValue( ":uname", $this->uname, PDO::PARAM_STR );
          $st->bindValue( ":upass", $this->upass, PDO::PARAM_STR );
          $st->bindValue( ":cdate", $this->cdate, PDO::PARAM_STR );
          $st->bindValue( ":active", $this->active, PDO::PARAM_STR );
          $st->bindValue( ":expired", $this->expired, PDO::PARAM_STR );
          $st->bindValue( ":roles", $this->roles, PDO::PARAM_STR );
          $st->bindValue( ":id", $this->id, PDO::PARAM_INT );
          $st->execute();
        }
            

        function delete() {
        if($this->id == null) {
            echo 'Trying to delete() an empty record';
            return (null);
        }
        
        if(!$this->getConnection()->isConnected()) {
            if(!$this->getConnection()->Connect()) {
                echo 'Could not connect to database';
                return (null);
            }
        }
        
$sql = "DELETE FROM users WHERE id = :id;";
$st = $this->getConnection()->getConnection()->prepare($sql);
$st->bindValue( ":id", $this->id, PDO::PARAM_INT );
$st->execute();

        return (true);
        }
    }
    
