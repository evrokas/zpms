CREATE TABLE `files` (
  `id` INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
  `guid` CHAR(36) NOT NULL,
  `cdate` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `cuser` CHAR(32) NOT NULL,
  `furi` varchar(512) DEFAULT NULL ,
  `fpath` varchar(512) DEFAULT NULL ,
  `fname` varchar(255) DEFAULT NULL ,
  `fmime` varchar(128) DEFAULT NULL ,
  `fsize` int unsigned DEFAULT NULL ,
  `fhash` char(64) DEFAULT NULL ,
  `fstatus` enum('active','deleted','orphaned') DEFAULT active,
  `deleted` datetime DEFAULT NULL ,

  PRIMARY KEY (id) 
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
