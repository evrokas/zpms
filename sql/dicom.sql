CREATE TABLE IF NOT EXISTS dicom_exams (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    study_uid       VARCHAR(128) DEFAULT NULL,
    patient_name    VARCHAR(255) DEFAULT NULL,
    patient_id_dcm  VARCHAR(64)  DEFAULT NULL,
    study_date      DATE         DEFAULT NULL,
    study_time      TIME         DEFAULT NULL,
    study_desc      VARCHAR(255) DEFAULT NULL,
    accession_no    VARCHAR(64)  DEFAULT NULL,
    modality        VARCHAR(16)  DEFAULT NULL,
    file_count      INT UNSIGNED DEFAULT 0,
    disk_size       BIGINT UNSIGNED DEFAULT 0,
    storage_path    VARCHAR(512) NOT NULL DEFAULT '',
    status          VARCHAR(20)  DEFAULT 'uploading',
    error_message   TEXT DEFAULT NULL,
    uploaded_by     INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_study_uid (study_uid),
    INDEX idx_patient (patient_name, patient_id_dcm),
    INDEX idx_date (study_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dicom_series (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id         INT UNSIGNED NOT NULL,
    series_uid      VARCHAR(128) DEFAULT NULL,
    series_number   INT DEFAULT NULL,
    series_desc     VARCHAR(255) DEFAULT NULL,
    modality        VARCHAR(16)  DEFAULT NULL,
    frame_count     INT UNSIGNED DEFAULT 0,
    images_path     VARCHAR(512) DEFAULT NULL,
    status          VARCHAR(20)  DEFAULT 'pending',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_exam (exam_id),
    FOREIGN KEY (exam_id) REFERENCES dicom_exams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dicom_images (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    series_id       INT UNSIGNED NOT NULL,
    instance_number INT DEFAULT 0,
    sop_instance_uid VARCHAR(128) DEFAULT NULL,
    dcm_filename    VARCHAR(255) NOT NULL,
    thumb_filename  VARCHAR(255) DEFAULT NULL,
    full_filename   VARCHAR(255) DEFAULT NULL,
    width           INT UNSIGNED DEFAULT NULL,
    height          INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_series (series_id),
    INDEX idx_instance (series_id, instance_number),
    FOREIGN KEY (series_id) REFERENCES dicom_series(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dicom_shares (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id     INT UNSIGNED NOT NULL,
    token       VARCHAR(64) NOT NULL UNIQUE,
    created_by  INT UNSIGNED DEFAULT NULL,
    expires_at  DATETIME DEFAULT NULL,
    view_count  INT UNSIGNED DEFAULT 0,
    is_active   TINYINT(1) DEFAULT 1,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_exam (exam_id),
    FOREIGN KEY (exam_id) REFERENCES dicom_exams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
