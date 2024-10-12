<?php

// extend YAML generated classes to add some functionality

class usersClassEx extends usersClass {
    static function getUser( $uname, $upass ) {

        global $AppDBConnection;

        if(!$AppDBConnection->isConnected()) {
            if(!$AppDBConnection->Connect()) {
                echo 'Could not connect to database';
                return (null);
            }
        }

        $sql = "SELECT * FROM users WHERE uname=:uname AND upass=:upass";
        $st = $AppDBConnection->getConnection()->prepare( $sql );
        $st->bindValue(":uname", $uname, PDO::PARAM_STR);
        $st->bindValue(":upass", $upass, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch();

        if($row) {
            $rclass = new usersClass( "users");
            $rclass->loadFields( $row );
            return $rclass;
        } else return (null);
    }

    static function getUserAccount( $uname ) {
        global $AppDBConnection;

        if(!$AppDBConnection->isConnected()) {
            if(!$AppDBConnection->Connect()) {
                echo 'Could not connect to database';
                return (null);
            }
        }

        $sql = "SELECT * FROM users WHERE uname=:uname";
        $st = $AppDBConnection->getConnection()->prepare( $sql );
        $st->bindValue(":uname", $uname, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch();

        if($row) {
            $rclass = new usersClass( "users");
            $rclass->loadFields( $row );
            return $rclass;
        } else return (null);

    }

}

class patientsClassEx extends patientsClass {
    
    static function sgetByGuid($aguid) {
        global $AppDBConnection;

        if(!$AppDBConnection->isConnected()) {
            if(!$AppDBConnection->Connect()) {
                echo 'Could not connect to database';
                return (null);
            }
        }

        $sql = "SELECT * FROM patients WHERE guid=:guid";
        $st = $AppDBConnection->getConnection()->prepare( $sql );
        $st->bindValue(":guid", $aguid, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch();

        if($row) {
            $rclass = new patientsClass();
            $rclass->loadFields( $row );
            return $rclass;
        } else return (null);
    }
    
    static function search($aterm, $ascope = array()) {
        global $AppDBConnection;

        if(!$AppDBConnection->isConnected()) {
            if(!$AppDBConnection->Connect()) {
                echo 'Could not connect to database';
                return (null);
            }
        }

        $aterm = trim($aterm);
        $aterm = str_replace(['  '], [' '], $aterm);
        $terms = explode(' ', $aterm);
        if(!count($terms))return null;

        // so seearch for words in array
        // build string
        $srch = implode('% ', $terms);
        $srch .= '%';
        $srch2 = '% '.$srch;

        if(count($ascope) == 0)
            $ascope = ['pname', 'pamka', 'ptel'];

        // error_log("\nSearch string: ".iconv('utf-8', 'iso-8859-7',$srch) ."\n");
        $req = array();
        foreach($ascope as $scope) {
            $req[] = "($scope LIKE :term) OR ($scope LIKE :term2)";
        }

        $reqs = implode(' OR ', $req);

        $sql = "SELECT * FROM patients WHERE " . $reqs;
        error_log("\nSQL request: " . $sql . "\n");
        /* (pname LIKE :term) OR (pname LIKE :term2)"; */
        $st = $AppDBConnection->getConnection()->prepare( $sql );
        $st->bindValue(":term", $srch, PDO::PARAM_STR);
        $st->bindValue(":term2", $srch2, PDO::PARAM_STR);
        $st->execute();

        $list = array();        
        while( $row = $st->fetch() ) {
            $rclass = new patientsClass();
            $rclass->loadFields( $row );
            $list[] = $rclass;
        }

        return ($list);
    }

    static function getPatientsByLastAppointment() {
        global $AppDBConnection;

        if(!$AppDBConnection->isConnected()) {
            if(!$AppDBConnection->Connect()) {
                echo 'Could not connect to database';
                return (null);
            }
        }

        $sql = "SELECT pat.*, app.adate FROM patients pat 
            LEFT JOIN ( 
                SELECT pguid, adate, ROW_NUMBER() OVER (PARTITION BY pguid ORDER BY adate DESC) 
                    as rn FROM appointments ) app 
                ON pat.guid = app.pguid AND app.rn = 1 
                ORDER BY app.adate DESC;";

        // $sql = "SELECT * FROM patients;";
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

}

class appointmentsClassEx extends appointmentsClass {
    static function getAppointmentsForPatient($pguid, $order = 'ASC') {
        global $AppDBConnection;

        if(!$AppDBConnection->isConnected()) {
            if(!$AppDBConnection->Connect()) {
                echo 'Could not connect to database';
                return (null);
            }
        }

        $sql = "SELECT * FROM appointments WHERE pguid=:pguid ORDER BY adate $order";
        $st = $AppDBConnection->getConnection()->prepare( $sql );
        $st->bindValue(":pguid", $pguid, PDO::PARAM_STR);
        // $st->bindValue(":order", $order, PDO::PARAM_STR);

        $st->execute();

        $list = array();        
        while( $row = $st->fetch() ) {
            $rclass = new appointmentsClass( "appointments" );
            $rclass->loadFields( $row );
            $list[] = $rclass;
        }

        return ($list);
    }
}



