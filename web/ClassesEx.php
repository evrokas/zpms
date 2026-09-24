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

// Resolves the account's real Όνομα (users.name) for the topbar's
// userblock -- see web/templates/modules/userblock.zetem, which
// overrides zeusfw core's own core/templates/modules/userblock.zetem
// purely at this app's template layer (same filename, scanned after
// core's per config/settings.info.yaml's templates: list, so it wins --
// no zeusfw core file was touched for this, and every other app on the
// framework keeps the bare-username block it always had). Falls back to
// the bare username itself when the account can't be found or has no
// name on file, so the block never renders blank.
function zpms_userblock_display_name(string $uname): string {
    $account = usersClassEx::getUserAccount($uname);
    if ($account && trim((string)$account->getname()) !== '') {
        return $account->getname();
    }
    return $uname;
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

    /**
     * Resolves a pending phone booking (name/AMKA/phone, all free text --
     * see pending_appointments.yaml) to at most one existing patients row,
     * for pending_appointment_convert()/_post() in web/index.php: "the
     * patient showed up, does a record for them already exist, or do we
     * create one." Checked in this order -- name first, then AMKA, then
     * phone, stopping at the first that resolves -- because a name typo
     * is the most likely disagreement between what was scribbled down
     * during the phone call and what's actually on file, while a shared
     * AMKA/phone is a much stronger signal once name doesn't match at
     * all (e.g. a maiden name vs. married name, or a name spelled two
     * different ways across two calls).
     *
     * Deliberately exact-match, not the fuzzy substring search()
     * above -- this decides whether to silently attach a new appointment
     * to somebody else's record, so a loose match here would be a real
     * data-integrity risk, not just a noisier suggestion list. And
     * deliberately refuses to guess when a check finds more than one row
     * (two patients sharing a name, or two rows with the same phone
     * number) -- every ambiguous case falls through to the next check,
     * and if none resolve uniquely, returns null so the caller creates a
     * new patient record rather than attaching to the wrong one.
     *
     * An empty AMKA/phone is never matched against -- patients.pamka/ptel
     * left blank on file would otherwise all "match" a pending booking
     * that also has no AMKA/phone, which is not a real signal of anything.
     */
    static function findMatchingPatient(string $name, ?string $amka, ?string $phone): ?patientsClass {
        $name = trim($name);
        $amka = trim((string)$amka);
        $phone = trim((string)$phone);

        if ($name !== '') {
            $match = self::findUniqueMatchByColumn('pname', $name, true);
            if ($match) return $match;
        }
        if ($amka !== '') {
            $match = self::findUniqueMatchByColumn('pamka', $amka, false);
            if ($match) return $match;
        }
        if ($phone !== '') {
            $match = self::findUniqueMatchByColumn('ptel', $phone, false);
            if ($match) return $match;
        }
        return null;
    }

    /**
     * $column is always one of the three hardcoded literals passed by
     * findMatchingPatient() above, never request data, so interpolating
     * it directly into the SQL is safe -- same convention as this app's
     * other internal-literal-column call sites (e.g.
     * getPatientsByLastAppointment()'s $order below).
     */
    private static function findUniqueMatchByColumn(string $column, string $value, bool $caseInsensitive): ?patientsClass {
        $comparison = $caseInsensitive ? "LOWER($column) = LOWER(:value)" : "$column = :value";
        $sql = "SELECT * FROM patients WHERE ($comparison) AND deleted IS NULL LIMIT 2";
        $st = dbConnection::getConnection()->prepare($sql);
        $st->bindValue(':value', $value, PDO::PARAM_STR);
        $st->execute();
        $rows = $st->fetchAll();

        if (count($rows) !== 1) {
            // Zero matches, or more than one -- ambiguous either way is
            // "don't guess", not "pick the first one".
            return null;
        }

        $rclass = new patientsClass();
        $rclass->loadFields($rows[0]);
        return $rclass;
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
        // Never trust $order as a raw SQL fragment, even though every
        // current call site passes a hardcoded literal -- whitelist
        // rather than interpolate an unvalidated value.
        $order = (strtoupper((string)$order) === 'DESC') ? 'DESC' : 'ASC';

        // Primary sort is by CALENDAR DATE, not the full adate datetime --
        // view_appointment.zetem's inline card only ever exposes a plain
        // type="date" field (no time-of-day) for editing an existing
        // appointment, so two appointments landing on the same day would
        // otherwise get silently ordered by a time-of-day value staff can
        // never see or change after creation (only edit_appointment.zetem's
        // initial datetime-local input sets it at all). Secondary sort by
        // id, always DESC regardless of $order -- same-day appointments
        // should show newest-created first when the page as a whole is
        // newest-first (patient_edit()'s own 'DESC' call), since id is a
        // reliable stand-in for creation order (cdate has only whole-second
        // precision and isn't indexed/sorted on elsewhere in this app).
        $sql = "SELECT * FROM appointments WHERE pguid=:pguid ORDER BY DATE(adate) $order, id DESC";
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

// Aggregated per-appointment edit history -- see
// web/classes/yaml/appointment_history.yaml's own docblock for the
// 5-minute-session design this table exists to support, and
// web/appointment_history.php for the write-side merge-or-insert logic
// (zpms_record_appointment_change()) that's the only thing that ever
// inserts/updates a row here.
class appointmentHistoryClassEx extends appointmentHistoryClass {

    // The one session row this appointment/user pair could still extend --
    // i.e. the most recent one, regardless of how long ago that was.
    // zpms_record_appointment_change() itself decides whether "most
    // recent" is actually within the 5-minute window; this just finds the
    // candidate to check that against.
    static function getMostRecentSession($appointmentId, string $cuser) {
        $sql = "SELECT * FROM appointment_history WHERE appointment_id=:appointment_id AND cuser=:cuser ORDER BY last_change_at DESC LIMIT 1";
        $st = dbConnection::getConnection()->prepare( $sql );
        $st->bindValue(":appointment_id", $appointmentId, PDO::PARAM_INT);
        $st->bindValue(":cuser", $cuser, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch();

        if($row) {
            $rclass = new appointmentHistoryClass();
            $rclass->loadFields( $row );
            return $rclass;
        } else return (null);
    }

    // Every session for one appointment, newest first -- powers the
    // "Ιστορικό Αλλαγών" section on view_appointment.zetem.
    static function getHistoryForAppointment($appointmentId): array {
        $sql = "SELECT * FROM appointment_history WHERE appointment_id=:appointment_id ORDER BY last_change_at DESC";
        $st = dbConnection::getConnection()->prepare( $sql );
        $st->bindValue(":appointment_id", $appointmentId, PDO::PARAM_INT);
        $st->execute();

        $list = array();
        while( $row = $st->fetch() ) {
            $rclass = new appointmentHistoryClass();
            $rclass->loadFields( $row );
            $list[] = $rclass;
        }

        return ($list);
    }
}

// The "Εκκρεμή Ραντεβού" waiting room table -- see
// web/classes/yaml/pending_appointments.yaml's own docblock for the full
// shape (a row here regardless of origin: booked on /consultation/new, or
// pulled in by bin/sync_google_calendar.php from a Calendar-native
// event). pending_appointments_list() (web/index.php) itself fetches the
// still-pending rows directly via the base class's own sgetAll(); this
// class exists for the one query specific enough to document separately.
class pendingAppointmentsClassEx extends pendingAppointmentsClass {

    // Powers the "Προηγούμενα Ραντεβού" section on
    // pending_appointments_list.zetem, below the still-pending list --
    // every pending_appointments row whose own appointment_datetime has
    // already passed and isn't cancelled, whether or not it ever synced
    // with Google Calendar (google_event_id) and whether or not it was
    // ever converted into a real `appointments`/patient record.
    //
    // Used to require google_event_id IS NOT NULL -- deliberately dropped:
    // pending_appointments_list() (web/index.php) now excludes any row
    // whose date has passed from the still-pending section above (staff
    // asked for that queue to only ever show upcoming/today's actionable
    // bookings, not clutter indefinitely with stale ones nobody acted on),
    // so a past, non-Calendar-synced booking needed somewhere to still be
    // visible -- otherwise it would simply vanish from the page the moment
    // its date passed, on an install with no Calendar integration
    // configured at all (every row's google_event_id stays NULL there
    // forever, so the old filter would have hidden every one of these).
    // This also fixed a narrower, pre-existing version of the same gap: a
    // row converted without ever having synced to Calendar previously had
    // nowhere to show up here either, despite being exactly the kind of
    // settled history this section exists for.
    //
    // Filtered on cancelled_at IS NULL -- an earlier version of this
    // deliberately did NOT filter on it, on the theory that a cancelled
    // event is still "history" either way. In practice that was wrong:
    // pending_appointment_delete() and bin/sync_google_calendar.php both
    // delete the event from Calendar itself when cancelling (see either's
    // own docblock), so a cancelled row was never a real appointment that
    // happened -- it was un-done, and showing it here read as "this
    // appointment is shown even though it was deleted," not as history.
    // converted_at is still NOT filtered on -- a converted row's patient
    // name links through to their real record (via converted_patient_id);
    // an unconverted one (a no-show, or simply never acted on before its
    // date passed) shows as plain text with its own "Επαναπρογραμματισμός"
    // (reschedule) action, since there's no patient record to link to and
    // -- unlike a converted or cancelled row -- it's still something a
    // reschedule can meaningfully apply to.
    static function getPastPendingAppointments(): array {
        $rows = self::sgetAll('appointment_datetime < NOW() AND cancelled_at IS NULL', null);
        usort($rows, fn($a, $b) => strcmp($b->getappointment_datetime(), $a->getappointment_datetime()));
        return $rows;
    }
}
