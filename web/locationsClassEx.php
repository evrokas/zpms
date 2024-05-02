<?php

class locationsClassEx extends locationsClass {
    static function getbyName($name) {
        global $AppDBConnection;

        if(!$AppDBConnection->isConnected()) {
            if(!$AppDBConnection->Connect()) {
                echo 'Could not connect to database';
                return (null);
            }
        }

        $sql = "SELECT * FROM locations WHERE name=:name";
        $st = $AppDBConnection->getConnection()->prepare( $sql );
        $st->bindValue(":name", $name, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch();

        if($row) {
            $rclass = new locationsClass();
            $rclass->loadFields( $row );
            return $rclass;
        } else return (null);
    }
    
    static function getbyMachineName($mname) {
        global $AppDBConnection;

        if(!$AppDBConnection->isConnected()) {
            if(!$AppDBConnection->Connect()) {
                echo 'Could not connect to database';
                return (null);
            }
        }

        $sql = "SELECT * FROM locations WHERE machinename=:machinename";
        $st = $AppDBConnection->getConnection()->prepare( $sql );
        $st->bindValue(":machinename", $mname, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch();

        if($row) {
            $rclass = new locationsClass();
            $rclass->loadFields( $row );
            return $rclass;
        } else return (null);
    }

    static function getbyGUID($guid) {
        global $AppDBConnection;

        if(!$AppDBConnection->isConnected()) {
            if(!$AppDBConnection->Connect()) {
                echo 'Could not connect to database';
                return (null);
            }
        }

        $sql = "SELECT * FROM locations WHERE guid=:guid";
        $st = $AppDBConnection->getConnection()->prepare( $sql );
        $st->bindValue(":guid", $guid, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch();

        if($row) {
            $rclass = new locationsClass();
            $rclass->loadFields( $row );
            return $rclass;
        } else return (null);
    }

}
