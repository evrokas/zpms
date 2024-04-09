<?php
// class locationsClass
require_once(__DIR__ . "/../../fw/db/dbal.php");

class locationsClass extends dbAbstractEntityClass {
  private $cdate;
  private $cuser;
  private $name;
  private $machinename;
  private $address;
  function __construct($adata = array() ) {
      parent::__construct('locations', $adata);
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
            
                    $sql = "SELECT * FROM locations WHERE id=:id";
                    $st = $AppDBConnection->getConnection()->prepare( $sql );
                    $st->bindValue(":id", $aid, PDO::PARAM_INT);
                    $st->execute();
                    $row = $st->fetch();
            
                    if($row) {
                        $rclass = new locationsClass( "locations");
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
        
                $sql = "SELECT * FROM locations;";
                $st = $AppDBConnection->getConnection()->prepare( $sql );
                $st->execute();
        
                $list = array();
        
                while( $row = $st->fetch() ) {
                    $rclass = new locationsClass( "locations" );
                    $rclass->loadFields( $row );
                    $list[] = $rclass;
                }
        
                return ($list);
        
            }
  function loadFields($adata) {
      parent::loadFields($adata);
      if(isset($adata['cdate']))$this->cdate = $adata['cdate'];
      if(isset($adata['cuser']))$this->cuser = $adata['cuser'];
      if(isset($adata['name']))$this->name = $adata['name'];
      if(isset($adata['machinename']))$this->machinename = $adata['machinename'];
      if(isset($adata['address']))$this->address = $adata['address'];
  }
  function setcdate( $acdate ) { $this->cdate = $acdate; }
  function getcdate() { return ( $this->cdate); }
  function setcuser( $acuser ) { $this->cuser = $acuser; }
  function getcuser() { return ( $this->cuser); }
  function setname( $aname ) { $this->name = $aname; }
  function getname() { return ( $this->name); }
  function setmachinename( $amachinename ) { $this->machinename = $amachinename; }
  function getmachinename() { return ( $this->machinename); }
  function setaddress( $aaddress ) { $this->address = $aaddress; }
  function getaddress() { return ( $this->address); }
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
        
$sql = "INSERT INTO locations ( cdate,cuser,name,machinename,address ) VALUES ( :cdate,:cuser,:name,:machinename,:address );";
$st = $this->getConnection()->getConnection()->prepare ( $sql );
$st->bindValue( ":cdate", $this->cdate, PDO::PARAM_STR );
$st->bindValue( ":cuser", $this->cuser, PDO::PARAM_STR );
$st->bindValue( ":name", $this->name, PDO::PARAM_STR );
$st->bindValue( ":machinename", $this->machinename, PDO::PARAM_STR );
$st->bindValue( ":address", $this->address, PDO::PARAM_STR );
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
                
            $sql = "UPDATE locations SET cdate=:cdate,cuser=:cuser,name=:name,machinename=:machinename,address=:address WHERE id=:id";

            $st = $this->getConnection()->getConnection()->prepare ( $sql );
            
          $st->bindValue( ":cdate", $this->cdate, PDO::PARAM_STR );
          $st->bindValue( ":cuser", $this->cuser, PDO::PARAM_STR );
          $st->bindValue( ":name", $this->name, PDO::PARAM_STR );
          $st->bindValue( ":machinename", $this->machinename, PDO::PARAM_STR );
          $st->bindValue( ":address", $this->address, PDO::PARAM_STR );
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
        
$sql = "DELETE FROM locations WHERE id = :id;";
$st = $this->getConnection()->getConnection()->prepare($sql);
$st->bindValue( ":id", $this->id, PDO::PARAM_INT );
$st->execute();

        return (true);
        }
    }
    
