CREATE TABLE `pdflib_files` (
  `id` INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
  `guid` CHAR(36) NOT NULL,
  `cdate` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `cuser` CHAR(32) NOT NULL,
  `file_name` varchar(255) DEFAULT NULL ,
  `file_path` text DEFAULT NULL ,
  `file_hash` char(64) NOT NULL ,
  `data` text DEFAULT NULL ,

  PRIMARY KEY (id) 
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
