<?php


class dicom_sharesClassEx extends dicom_sharesClass {

    static function getByToken($token) {
        $sql = "SELECT * FROM dicom_shares WHERE token = :token";
        $st = dbConnection::getConnection()->prepare($sql);
        $st->bindValue(':token', $token, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch();
        if ($row) {
            $r = new dicom_sharesClass();
            $r->loadFields($row);
            return $r;
        }
        return null;
    }
}
