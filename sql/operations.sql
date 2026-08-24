CREATE TABLE `operations` (
  `id` INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
  `guid` CHAR(36) NOT NULL,
  `cdate` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `cuser` CHAR(32) NOT NULL,
  `opdate` datetime DEFAULT current_timestamp,
  `pguid` CHAR(36) NOT NULL,
  `pname` varchar(64) NOT NULL ,
  `pdob` datetime DEFAULT NULL ,
  `opdiagnosis` text DEFAULT NULL ,
  `opprocedure` text DEFAULT NULL ,
  `clinic` text DEFAULT NULL ,
  `category` text DEFAULT NULL ,
  `surgeon1` varchar(64) DEFAULT NULL ,
  `surgeon2` varchar(64) DEFAULT NULL ,
  `anesthesiology` varchar(64) DEFAULT NULL ,

  PRIMARY KEY (id) 
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
