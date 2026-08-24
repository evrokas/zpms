<?php

// ─── DICOM EXAMS ──────────────────────────────────────

class dicom_examsClassEx extends dicom_examsClass {

    static function getExamList($limit = 20, $offset = 0) {
        $sql = "SELECT * FROM dicom_exams ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $st = dbConnection::getConnection()->prepare($sql);
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();
        $list = [];
        while ($row = $st->fetch()) {
            $r = new dicom_examsClass();
            $r->loadFields($row);
            $list[] = $r;
        }
        return $list;
    }

    static function getExamCount() {
        $sql = "SELECT COUNT(*) as cnt FROM dicom_exams";
        $st = dbConnection::getConnection()->prepare($sql);
        $st->execute();
        return (int)$st->fetch()['cnt'];
    }

    static function searchExams($term, $modality = null) {
        $sql = "SELECT * FROM dicom_exams WHERE (patient_name LIKE :term OR study_desc LIKE :term)";
        $params = [':term' => '%' . $term . '%'];
        if ($modality) {
            $sql .= " AND modality = :modality";
            $params[':modality'] = $modality;
        }
        $sql .= " ORDER BY created_at DESC";
        $st = dbConnection::getConnection()->prepare($sql);
        $st->execute($params);
        $list = [];
        while ($row = $st->fetch()) {
            $r = new dicom_examsClass();
            $r->loadFields($row);
            $list[] = $r;
        }
        return $list;
    }
}

