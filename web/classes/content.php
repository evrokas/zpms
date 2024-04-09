<?php
// class contentBaseClass
require_once(__DIR__ . "/../../fw/db/dbal.php");

class contentBaseClass extends dbAbstractEntityClass {
  private $guid;
  private $cdate;
  private $cuser;
  private $title;
  private $description;
  private $published;
  private $path;
  private $viewmode;
  function __construct($adata = array() ) {
      parent::__construct('content', $adata);
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
            
                    $sql = "SELECT * FROM content WHERE id=:id";
                    $st = $AppDBConnection->getConnection()->prepare( $sql );
                    $st->bindValue(":id", $aid, PDO::PARAM_INT);
                    $st->execute();
                    $row = $st->fetch();
            
                    if($row) {
                        $rclass = new contentBaseClass( "content");
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
        
                $sql = "SELECT * FROM content;";
                $st = $AppDBConnection->getConnection()->prepare( $sql );
                $st->execute();
        
                $list = array();
        
                while( $row = $st->fetch() ) {
                    $rclass = new contentBaseClass( "content" );
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
      if(isset($adata['title']))$this->title = $adata['title'];
      if(isset($adata['description']))$this->description = $adata['description'];
      if(isset($adata['published']))$this->published = $adata['published'];
      if(isset($adata['path']))$this->path = $adata['path'];
      if(isset($adata['viewmode']))$this->viewmode = $adata['viewmode'];
  }
  function setguid( $aguid ) { $this->guid = $aguid; }
  function getguid() { return ( $this->guid); }
  function setcdate( $acdate ) { $this->cdate = $acdate; }
  function getcdate() { return ( $this->cdate); }
  function setcuser( $acuser ) { $this->cuser = $acuser; }
  function getcuser() { return ( $this->cuser); }
  function settitle( $atitle ) { $this->title = $atitle; }
  function gettitle() { return ( $this->title); }
  function setdescription( $adescription ) { $this->description = $adescription; }
  function getdescription() { return ( $this->description); }
  function setpublished( $apublished ) { $this->published = $apublished; }
  function getpublished() { return ( $this->published); }
  function setpath( $apath ) { $this->path = $apath; }
  function getpath() { return ( $this->path); }
  function setviewmode( $aviewmode ) { $this->viewmode = $aviewmode; }
  function getviewmode() { return ( $this->viewmode); }
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
        
$sql = "INSERT INTO content ( guid,cdate,cuser,title,description,published,path,viewmode ) VALUES ( :guid,:cdate,:cuser,:title,:description,:published,:path,:viewmode );";
$st = $this->getConnection()->getConnection()->prepare ( $sql );
$st->bindValue( ":guid", $this->guid, PDO::PARAM_STR );
$st->bindValue( ":cdate", $this->cdate, PDO::PARAM_STR );
$st->bindValue( ":cuser", $this->cuser, PDO::PARAM_STR );
$st->bindValue( ":title", $this->title, PDO::PARAM_STR );
$st->bindValue( ":description", $this->description, PDO::PARAM_STR );
$st->bindValue( ":published", $this->published, PDO::PARAM_STR );
$st->bindValue( ":path", $this->path, PDO::PARAM_STR );
$st->bindValue( ":viewmode", $this->viewmode, PDO::PARAM_STR );
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
                
            $sql = "UPDATE content SET guid=:guid,cdate=:cdate,cuser=:cuser,title=:title,description=:description,published=:published,path=:path,viewmode=:viewmode WHERE id=:id";

            $st = $this->getConnection()->getConnection()->prepare ( $sql );
            
          $st->bindValue( ":guid", $this->guid, PDO::PARAM_STR );
          $st->bindValue( ":cdate", $this->cdate, PDO::PARAM_STR );
          $st->bindValue( ":cuser", $this->cuser, PDO::PARAM_STR );
          $st->bindValue( ":title", $this->title, PDO::PARAM_STR );
          $st->bindValue( ":description", $this->description, PDO::PARAM_STR );
          $st->bindValue( ":published", $this->published, PDO::PARAM_STR );
          $st->bindValue( ":path", $this->path, PDO::PARAM_STR );
          $st->bindValue( ":viewmode", $this->viewmode, PDO::PARAM_STR );
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
        
$sql = "DELETE FROM content WHERE id = :id;";
$st = $this->getConnection()->getConnection()->prepare($sql);
$st->bindValue( ":id", $this->id, PDO::PARAM_INT );
$st->execute();

        return (true);
        }
    }
    
