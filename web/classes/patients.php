<?php
// class patientsClass
require_once(__DIR__ . "/../../fw/db/dbal.php");

class patientsClass extends dbAbstractEntityClass {
  private $guid;
  private $cdate;
  private $cuser;
  private $pname;
  private $pdob;
  private $pamka;
  private $ptel;
  private $paddr;
  private $pemail;
  private $firstapp;
  private $pnote;
  private $deleted;
  function __construct($adata = array() ) {
      parent::__construct('patients', $adata);
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
            
                    $sql = "SELECT * FROM patients WHERE id=:id";
                    $st = $AppDBConnection->getConnection()->prepare( $sql );
                    $st->bindValue(":id", $aid, PDO::PARAM_INT);
                    $st->execute();
                    $row = $st->fetch();
            
                    if($row) {
                        $rclass = new patientsClass( "patients");
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
        
                $sql = "SELECT * FROM patients;";
                $st = $AppDBConnection->getConnection()->prepare( $sql );
                $st->execute();
        
                $list = array();
        
                while( $row = $st->fetch() ) {
                    $rclass = new patientsClass( "patients" );
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
      if(isset($adata['pname']))$this->pname = $adata['pname'];
      if(isset($adata['pdob']))$this->pdob = $adata['pdob'];
      if(isset($adata['pamka']))$this->pamka = $adata['pamka'];
      if(isset($adata['ptel']))$this->ptel = $adata['ptel'];
      if(isset($adata['paddr']))$this->paddr = $adata['paddr'];
      if(isset($adata['pemail']))$this->pemail = $adata['pemail'];
      if(isset($adata['firstapp']))$this->firstapp = $adata['firstapp'];
      if(isset($adata['pnote']))$this->pnote = $adata['pnote'];
      if(isset($adata['deleted']))$this->deleted = $adata['deleted'];
  }
  function setguid( $aguid ) { $this->guid = $aguid; }
  function getguid() { return ( $this->guid); }
  function setcdate( $acdate ) { $this->cdate = $acdate; }
  function getcdate() { return ( $this->cdate); }
  function setcuser( $acuser ) { $this->cuser = $acuser; }
  function getcuser() { return ( $this->cuser); }
  function setpname( $apname ) { $this->pname = $apname; }
  function getpname() { return ( $this->pname); }
  function setpdob( $apdob ) { $this->pdob = $apdob; }
  function getpdob() { return ( $this->pdob); }
  function setpamka( $apamka ) { $this->pamka = $apamka; }
  function getpamka() { return ( $this->pamka); }
  function setptel( $aptel ) { $this->ptel = $aptel; }
  function getptel() { return ( $this->ptel); }
  function setpaddr( $apaddr ) { $this->paddr = $apaddr; }
  function getpaddr() { return ( $this->paddr); }
  function setpemail( $apemail ) { $this->pemail = $apemail; }
  function getpemail() { return ( $this->pemail); }
  function setfirstapp( $afirstapp ) { $this->firstapp = $afirstapp; }
  function getfirstapp() { return ( $this->firstapp); }
  function setpnote( $apnote ) { $this->pnote = $apnote; }
  function getpnote() { return ( $this->pnote); }
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
        
$sql = "INSERT INTO patients ( guid,cdate,cuser,pname,pdob,pamka,ptel,paddr,pemail,firstapp,pnote,deleted ) VALUES ( :guid,:cdate,:cuser,:pname,:pdob,:pamka,:ptel,:paddr,:pemail,:firstapp,:pnote,:deleted );";
$st = $this->getConnection()->getConnection()->prepare ( $sql );
$st->bindValue( ":guid", $this->guid, PDO::PARAM_STR );
$st->bindValue( ":cdate", $this->cdate, PDO::PARAM_STR );
$st->bindValue( ":cuser", $this->cuser, PDO::PARAM_STR );
$st->bindValue( ":pname", $this->pname, PDO::PARAM_STR );
$st->bindValue( ":pdob", $this->pdob, PDO::PARAM_STR );
$st->bindValue( ":pamka", $this->pamka, PDO::PARAM_STR );
$st->bindValue( ":ptel", $this->ptel, PDO::PARAM_STR );
$st->bindValue( ":paddr", $this->paddr, PDO::PARAM_STR );
$st->bindValue( ":pemail", $this->pemail, PDO::PARAM_STR );
$st->bindValue( ":firstapp", $this->firstapp, PDO::PARAM_STR );
$st->bindValue( ":pnote", $this->pnote, PDO::PARAM_STR );
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
                
            $sql = "UPDATE patients SET guid=:guid,cdate=:cdate,cuser=:cuser,pname=:pname,pdob=:pdob,pamka=:pamka,ptel=:ptel,paddr=:paddr,pemail=:pemail,firstapp=:firstapp,pnote=:pnote,deleted=:deleted WHERE id=:id";

            $st = $this->getConnection()->getConnection()->prepare ( $sql );
            
          $st->bindValue( ":guid", $this->guid, PDO::PARAM_STR );
          $st->bindValue( ":cdate", $this->cdate, PDO::PARAM_STR );
          $st->bindValue( ":cuser", $this->cuser, PDO::PARAM_STR );
          $st->bindValue( ":pname", $this->pname, PDO::PARAM_STR );
          $st->bindValue( ":pdob", $this->pdob, PDO::PARAM_STR );
          $st->bindValue( ":pamka", $this->pamka, PDO::PARAM_STR );
          $st->bindValue( ":ptel", $this->ptel, PDO::PARAM_STR );
          $st->bindValue( ":paddr", $this->paddr, PDO::PARAM_STR );
          $st->bindValue( ":pemail", $this->pemail, PDO::PARAM_STR );
          $st->bindValue( ":firstapp", $this->firstapp, PDO::PARAM_STR );
          $st->bindValue( ":pnote", $this->pnote, PDO::PARAM_STR );
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
        
$sql = "DELETE FROM patients WHERE id = :id;";
$st = $this->getConnection()->getConnection()->prepare($sql);
$st->bindValue( ":id", $this->id, PDO::PARAM_INT );
$st->execute();

        return (true);
        }
    }
    
