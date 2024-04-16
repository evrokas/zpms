<?php
// class appointmentsClass
require_once(__DIR__ . "/../../fw/db/dbal.php");

class appointmentsClass extends dbAbstractEntityClass {
  private $guid;
  private $cdate;
  private $cuser;
  private $pguid;
  private $adate;
  private $aplace;
  private $anote;
  private $deleted;
  function __construct($adata = array() ) {
      parent::__construct('appointments', $adata);
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
            
                    $sql = "SELECT * FROM appointments WHERE id=:id";
                    $st = $AppDBConnection->getConnection()->prepare( $sql );
                    $st->bindValue(":id", $aid, PDO::PARAM_INT);
                    $st->execute();
                    $row = $st->fetch();
            
                    if($row) {
                        $rclass = new appointmentsClass( "appointments");
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
        
                $sql = "SELECT * FROM appointments;";
                $st = $AppDBConnection->getConnection()->prepare( $sql );
                $st->execute();
        
                $list = array();
        
                while( $row = $st->fetch() ) {
                    $rclass = new appointmentsClass( "appointments" );
                    $rclass->loadFields( $row );
                    $list[] = $rclass;
                }
        
                return ($list);
        
            }
  function loadFields($adata) {
      parent::loadFields($adata);
      if(isset($adata['guid']))$this->guid = $adata['guid'];
      if(isset($adata['cdate']))$this->cdate = $adata['cdate'];
      if(isset($adata['cuser']))$this->cuser = $adata['cuser'];
      if(isset($adata['pguid']))$this->pguid = $adata['pguid'];
      if(isset($adata['adate']))$this->adate = $adata['adate'];
      if(isset($adata['aplace']))$this->aplace = $adata['aplace'];
      if(isset($adata['anote']))$this->anote = $adata['anote'];
      if(isset($adata['deleted']))$this->deleted = $adata['deleted'];
  }
  function setguid( $aguid ) { $this->guid = $aguid; }
  function getguid() { return ( $this->guid); }
  function setcdate( $acdate ) { $this->cdate = $acdate; }
  function getcdate() { return ( $this->cdate); }
  function setcuser( $acuser ) { $this->cuser = $acuser; }
  function getcuser() { return ( $this->cuser); }
  function setpguid( $apguid ) { $this->pguid = $apguid; }
  function getpguid() { return ( $this->pguid); }
  function setadate( $aadate ) { $this->adate = $aadate; }
  function getadate() { return ( $this->adate); }
  function setaplace( $aaplace ) { $this->aplace = $aaplace; }
  function getaplace() { return ( $this->aplace); }
  function setanote( $aanote ) { $this->anote = $aanote; }
  function getanote() { return ( $this->anote); }
  function setdeleted( $adeleted ) { $this->deleted = $adeleted; }
  function getdeleted() { return ( $this->deleted); }
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
        
$sql = "INSERT INTO appointments ( guid,cdate,cuser,pguid,adate,aplace,anote,deleted ) VALUES ( :guid,:cdate,:cuser,:pguid,:adate,:aplace,:anote,:deleted );";
$st = $this->getConnection()->getConnection()->prepare ( $sql );
$st->bindValue( ":guid", $this->guid, PDO::PARAM_STR );
$st->bindValue( ":cdate", $this->cdate, PDO::PARAM_STR );
$st->bindValue( ":cuser", $this->cuser, PDO::PARAM_STR );
$st->bindValue( ":pguid", $this->pguid, PDO::PARAM_STR );
$st->bindValue( ":adate", $this->adate, PDO::PARAM_STR );
$st->bindValue( ":aplace", $this->aplace, PDO::PARAM_STR );
$st->bindValue( ":anote", $this->anote, PDO::PARAM_STR );
$st->bindValue( ":deleted", $this->deleted, PDO::PARAM_STR );
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
                
            $sql = "UPDATE appointments SET guid=:guid,cdate=:cdate,cuser=:cuser,pguid=:pguid,adate=:adate,aplace=:aplace,anote=:anote,deleted=:deleted WHERE id=:id";

            $st = $this->getConnection()->getConnection()->prepare ( $sql );
            
          $st->bindValue( ":guid", $this->guid, PDO::PARAM_STR );
          $st->bindValue( ":cdate", $this->cdate, PDO::PARAM_STR );
          $st->bindValue( ":cuser", $this->cuser, PDO::PARAM_STR );
          $st->bindValue( ":pguid", $this->pguid, PDO::PARAM_STR );
          $st->bindValue( ":adate", $this->adate, PDO::PARAM_STR );
          $st->bindValue( ":aplace", $this->aplace, PDO::PARAM_STR );
          $st->bindValue( ":anote", $this->anote, PDO::PARAM_STR );
          $st->bindValue( ":deleted", $this->deleted, PDO::PARAM_STR );
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
        
$sql = "DELETE FROM appointments WHERE id = :id;";
$st = $this->getConnection()->getConnection()->prepare($sql);
$st->bindValue( ":id", $this->id, PDO::PARAM_INT );
$st->execute();

        return (true);
        }
    }
    
