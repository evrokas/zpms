CREATE TABLE `locations` (
  `id` INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
  `guid` CHAR(36) NOT NULL,
  `lang` char(8) DEFAULT NULL ,
  `cdate` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `cuser` CHAR(32) NOT NULL,
  `name` varchar(64) DEFAULT NULL ,
  `machinename` varchar(64) DEFAULT NULL ,
  `address` text DEFAULT NULL ,

  PRIMARY KEY (id) 
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
