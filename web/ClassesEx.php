<?php

// extend YAML generated classes to add some functionality

class usersClassEx extends usersClass {
    static function getUser( $uname, $upass ) {

        $sql = "SELECT * FROM users WHERE uname=:uname AND upass=:upass";
        $st = dbConnection::getConnection()->prepare( $sql );
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
        $sql = "SELECT * FROM users WHERE uname=:uname";
        $st = dbConnection::getConnection()->prepare( $sql );
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
        $sql = "SELECT * FROM patients WHERE guid=:guid";
        $st = dbConnection::getConnection()->prepare( $sql );
        $st->bindValue(":guid", $aguid, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch();

        if($row) {
            $rclass = new patientsClass();
            $rclass->loadFields( $row );
            return $rclass;
        } else return (null);
    }
    
    static function search($aterm, $ascope = array(), $excludeDeleted = false, $limit = null) {
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
        if ($excludeDeleted) {
            $sql .= " AND deleted IS NULL";
        }
        if ($limit !== null) {
            $sql .= " LIMIT :limit";
        }
        error_log("\nSQL request: " . $sql . "\n");
        /* (pname LIKE :term) OR (pname LIKE :term2)"; */
        $st = dbConnection::getConnection()->prepare( $sql );
        $st->bindValue(":term", $srch, PDO::PARAM_STR);
        $st->bindValue(":term2", $srch2, PDO::PARAM_STR);
        if ($limit !== null) {
            $st->bindValue(":limit", (int)$limit, PDO::PARAM_INT);
        }
        $st->execute();

        $list = array();
        while( $row = $st->fetch() ) {
            $rclass = new patientsClass();
            $rclass->loadFields( $row );
            $list[] = $rclass;
        }

        return ($list);
    }

    static function getPatientsByLastAppointment($order) {
        // Concatenated directly into the SQL string below (ORDER BY takes
        // no bind parameter in any DB driver) -- the one caller today
        // (web/index.php's patients_list()) already restricts $order to
        // '0'/'1' before calling this, but that's the caller's own
        // decision, not something this function can rely on. Defaulting
        // any unrecognized value to a fixed, safe direction closes that
        // gap for any future caller that doesn't pre-validate.
        switch($order) {
            case '0': $order = "DESC"; break;
            case '1': $order = "ASC"; break;
            default: $order = "ASC";
        }


        // sql statement extracted from ChatGPT (!!)
        // sorts the patient list in descending order, according to last appointment
/*         $sql = "SELECT pat.*, app.adate FROM patients pat 
            LEFT JOIN ( 
                SELECT pguid, adate, ROW_NUMBER() OVER (PARTITION BY pguid ORDER BY adate DESC) 
                    as rn FROM appointments ) app 
                ON pat.guid = app.pguid AND app.rn = 1 
                ORDER BY app.adate " . $order;  
 */
        // previous query, puts all patients with no appointment at end,
        // so the following improved query, sorts the patient list in descending order, according to
        // last appointment if it exists, otherwise it uses the date the patient record is created
        $sql = "SELECT
                    pat.*,
                    app.adate,  -- Assuming you have a patient name or other details in Table A
                    COALESCE(app.adate, pat.cdate) AS order_date,  -- Use appointment_date if available, otherwise cdate
                    app.adate,
                    pat.cdate
                FROM
                    patients pat
                LEFT JOIN
                    (
                        SELECT
                            pguid, adate,
                            ROW_NUMBER() OVER (PARTITION BY pguid ORDER BY adate DESC) AS rn
                        FROM
                            appointments
                    ) app ON pat.guid = app.pguid AND app.rn = 1
                WHERE
                    pat.deleted IS NULL
                ORDER BY
                    order_date " . $order; /* DESC"; */


        // $sql = "SELECT * FROM patients;";
        $st = dbConnection::getConnection()->prepare( $sql );
        $st->execute();

        $list = array();

        while( $row = $st->fetch() ) {
            $rclass = new patientsClass( "patients" );
            $rclass->loadFields( $row );
            $list[] = ['p'=>$rclass, 'a'=>$row['adate'] ];
        }

        return ($list);
    }

    static function getPatientsByName($order = 'ASC') {
        // See getPatientsByLastAppointment()'s comment above -- same
        // defense-in-depth reasoning applies here.
        switch($order) {
            case '0': $order = "DESC"; break;
            case '1': $order = "ASC"; break;
            default: $order = "ASC";
        }

        // sql statement extracted from ChatGPT (!!)
        $sql = "SELECT * FROM patients WHERE deleted IS NULL ORDER BY pname " . $order;
        $st = dbConnection::getConnection()->prepare( $sql );
        $st->execute();

        $list = array();

        while( $row = $st->fetch() ) {
            $rclass = new patientsClass( "patients" );
            $rclass->loadFields( $row );
            $list[] = ['p'=>$rclass, 'a'=>$row['adate'] ];
        }

        return ($list);
    }

}

class appointmentsClassEx extends appointmentsClass {
    static function getAppointmentsForPatient($pguid, $order = 'ASC') {
        $sql = "SELECT * FROM appointments WHERE pguid=:pguid ORDER BY adate $order";
        $st = dbConnection::getConnection()->prepare( $sql );
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

    // 1-based position of $appointmentId among the same patient's
    // appointments that fall on the same calendar date, ordered by id --
    // used by appointment_files_resolve_storage_path() (web/appointment_files.php)
    // to decide whether a date folder needs a "-2"/"-3"/... suffix.
    static function getSameDayPositionForPatient($pguid, $date, $appointmentId): int {
        $sql = "SELECT id FROM appointments WHERE pguid=:pguid AND deleted IS NULL AND DATE(adate)=:adate ORDER BY id ASC";
        $st = dbConnection::getConnection()->prepare( $sql );
        $st->bindValue(":pguid", $pguid, PDO::PARAM_STR);
        $st->bindValue(":adate", $date, PDO::PARAM_STR);
        $st->execute();

        $position = 1;
        while( $row = $st->fetch() ) {
            if((int)$row['id'] === (int)$appointmentId) {
                return $position;
            }
            $position++;
        }

        // appointment not found among non-deleted same-day rows (e.g. it
        // was itself soft-deleted) -- fall back to "1st" rather than 0
        return 1;
    }
}

// Attachments (photos/scanned documents) uploaded against a specific
// appointment -- see web/appointment_files.php for upload/delete/download
// and CLAUDE.md's appointment-files section for the on-disk layout this
// class's queries support.
class appointmentFilesClassEx extends appointmentFilesClass {

    // All attachments for one appointment, newest first -- powers the
    // "existing files" list on view_appointment.zetem.
    static function getFilesForAppointment($appointmentId): array {
        $sql = "SELECT * FROM appointment_files WHERE appointment_id=:appointment_id ORDER BY cdate DESC";
        $st = dbConnection::getConnection()->prepare( $sql );
        $st->bindValue(":appointment_id", $appointmentId, PDO::PARAM_INT);
        $st->execute();

        $list = array();
        while( $row = $st->fetch() ) {
            $rclass = new appointmentFilesClass();
            $rclass->loadFields( $row );
            $list[] = $rclass;
        }

        return ($list);
    }

    // IDOR-guarded single-file lookup for delete/download -- a file id
    // alone is never enough, it must also belong to the appointment named
    // in the URL, same guard shape as
    // userTokensClassEx::delete_by_id_for_uname()'s WHERE id=:id AND
    // uname=:uname (zeusfw/core/ClassExFW.php).
    static function sgetByIdForAppointment($fileId, $appointmentId) {
        $sql = "SELECT * FROM appointment_files WHERE id=:id AND appointment_id=:appointment_id";
        $st = dbConnection::getConnection()->prepare( $sql );
        $st->bindValue(":id", $fileId, PDO::PARAM_INT);
        $st->bindValue(":appointment_id", $appointmentId, PDO::PARAM_INT);
        $st->execute();
        $row = $st->fetch();

        if($row) {
            $rclass = new appointmentFilesClass();
            $rclass->loadFields( $row );
            return $rclass;
        } else return (null);
    }
}
