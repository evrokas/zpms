CREATE TABLE `patients` (
  `id` INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
  `guid` CHAR(36) NOT NULL,
  `cdate` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `cuser` CHAR(32) NOT NULL,
  `pname` varchar(64) DEFAULT NULL ,
  `pdob` datetime DEFAULT NULL ,
  `pamka` char(11) DEFAULT NULL ,
  `ptel` varchar(32) DEFAULT NULL ,
  `paddr` varchar(256) DEFAULT NULL ,
  `pemail` varchar(128) DEFAULT NULL ,
  `firstapp` datetime DEFAULT NULL ,
  `pnote` text DEFAULT NULL ,
  `deleted` datetime DEFAULT NULL ,

  PRIMARY KEY (id) 
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
