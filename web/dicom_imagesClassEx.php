<?php

// ─── DICOM IMAGES ─────────────────────────────────────

class dicom_imagesClassEx extends dicom_imagesClass {

    static function getBySeries($series_id) {
        $sql = "SELECT * FROM dicom_images WHERE series_id = :sid ORDER BY instance_number ASC";
        $st = dbConnection::getConnection()->prepare($sql);
        $st->bindValue(':sid', $series_id, PDO::PARAM_INT);
        $st->execute();
        $list = [];
        while ($row = $st->fetch()) {
            $r = new dicom_imagesClass();
            $r->loadFields($row);
            $list[] = $r;
        }
        return $list;
    }
}

