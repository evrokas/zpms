<?php

class locationsClassEx extends locationsClass {
    static function getbyName($name) {
        $sql = "SELECT * FROM locations WHERE name=:name";
        $st = dbConnection::getConnection()->prepare( $sql );
        $st->bindValue(":name", $name, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch();

        if($row) {
            $rclass = new locationsClass();
            $rclass->loadFields( $row );
            return $rclass;
        } else return (null);
    }

    static function sgetAll($lang = null, $nothing = null) {
        $rows = parent::sgetAll();
        if(!$lang)return $rows;

        $arr = array();
        foreach($rows as $el) {
            if($el->getlang() === $lang)
                $arr[] = $el;
        }
        return ($arr);
    }

    static function getbyMachineName($mname, $lang) {
        $sql = "SELECT * FROM locations WHERE machinename=:machinename AND lang=:lang";
        $st = dbConnection::getConnection()->prepare( $sql );
        $st->bindValue(":machinename", $mname, PDO::PARAM_STR);
        $st->bindValue(":lang", $lang, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch();

        if($row) {
            $rclass = new locationsClass();
            $rclass->loadFields( $row );
            return $rclass;
        } else return (null);
    }

    static function getbyGUID($guid) {
        $sql = "SELECT * FROM locations WHERE guid=:guid";
        $st = dbConnection::getConnection()->prepare( $sql );
        $st->bindValue(":guid", $guid, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch();

        if($row) {
            $rclass = new locationsClass();
            $rclass->loadFields( $row );
            return $rclass;
        } else return (null);
    }


    static function setDefaultLocation() {
        global $kernel;

        $loc = locationsClassEx::sgetAll( $kernel->getCurrentLanguage() );
        $ln = array();
        foreach($loc as $l)
            $ln[] = $l->getname();

        if(isset($_SESSION['location'])) {
            $locname = $_SESSION['location'];
            // echopre("location variable was already set: $locname");
        } else {
            $lcookie = filter_input(INPUT_COOKIE, 'location');
            if($lcookie) {
                $_SESSION['location'] = $lcookie;
                $locname = $_SESSION['location'];
                // echopre("location cookie was already set: $lcookie");
            } else {
                $locname = $ln[1];
                $_SESSION['location'] = $locname;
                // echopre("location cookie was not set");

            }
        }

        // echopre("remove location cookie");
        setcookie('location', '', time()-3600, '/');
        unset($_COOKIE['location']);

        $expiry = time() + 30*24*60*60;

        // echopre("set new cookie: $locname, that expires on $expiry");
        setcookie('location', $locname, $expiry, '/');
    }

    static function getCurrentLocation() {
        global $kernel;

            $loc = locationsClassEx::sgetAll( $kernel->getCurrentLanguage() );
            $ln = array();
            foreach($loc as $l)
                $ln[] = $l->getname();

                if(!isset($_SESSION['location']))
                $locname = $ln[1];
            else $locname = $_SESSION['location'];

        return ($locname);
    }

}
