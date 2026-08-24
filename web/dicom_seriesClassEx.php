<?php

// ─── DICOM SERIES ─────────────────────────────────────

class dicom_seriesClassEx extends dicom_seriesClass {

    static function getByExamId($exam_id) {
        $sql = "SELECT * FROM dicom_series WHERE exam_id = :eid ORDER BY series_number ASC";
        $st = dbConnection::getConnection()->prepare($sql);
        $st->bindValue(':eid', $exam_id, PDO::PARAM_INT);
        $st->execute();
        $list = [];
        while ($row = $st->fetch()) {
            $r = new dicom_seriesClass();
            $r->loadFields($row);
            $list[] = $r;
        }
        return $list;
    }
}


