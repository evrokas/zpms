<?php
// class permissionsClass
require_once(__DIR__ . "/../../fw/db/dbal.php");

class permissionsClass extends dbAbstractEntityClass {
  private $guid;
  private $text;
  function __construct($adata = array() ) {
      parent::__construct('permissions', $adata);
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
            
                    $sql = "SELECT * FROM permissions WHERE id=:id";
                    $st = $AppDBConnection->getConnection()->prepare( $sql );
                    $st->bindValue(":id", $aid, PDO::PARAM_INT);
                    $st->execute();
                    $row = $st->fetch();
            
                    if($row) {
                        $rclass = new permissionsClass( "permissions");
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
        
                $sql = "SELECT * FROM permissions;";
                $st = $AppDBConnection->getConnection()->prepare( $sql );
                $st->execute();
        
                $list = array();
        
                while( $row = $st->fetch() ) {
                    $rclass = new permissionsClass( "permissions" );
                    $rclass->loadFields( $row );
                    $list[] = $rclass;
                }
        
                return ($list);
        
            }
  function loadFields($adata) {
      parent::loadFields($adata);
      if(isset($adata['guid']))$this->guid = $adata['guid'];
      if(isset($adata['text']))$this->text = $adata['text'];
  }
  function setguid( $aguid ) { $this->guid = $aguid; }
  function getguid() { return ( $this->guid); }
  function settext( $atext ) { $this->text = $atext; }
  function gettext() { return ( $this->text); }
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
        
$sql = "INSERT INTO permissions ( guid,text ) VALUES ( :guid,:text );";
$st = $this->getConnection()->getConnection()->prepare ( $sql );
$st->bindValue( ":guid", $this->guid, PDO::PARAM_STR );
$st->bindValue( ":text", $this->text, PDO::PARAM_STR );
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
                
            $sql = "UPDATE permissions SET guid=:guid,text=:text WHERE id=:id";

            $st = $this->getConnection()->getConnection()->prepare ( $sql );
            
          $st->bindValue( ":guid", $this->guid, PDO::PARAM_STR );
          $st->bindValue( ":text", $this->text, PDO::PARAM_STR );
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
        
$sql = "DELETE FROM permissions WHERE id = :id;";
$st = $this->getConnection()->getConnection()->prepare($sql);
$st->bindValue( ":id", $this->id, PDO::PARAM_INT );
$st->execute();

        return (true);
        }
    }
    
