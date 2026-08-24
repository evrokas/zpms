CREATE TABLE `appointments` (
  `id` INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
  `guid` CHAR(36) NOT NULL,
  `cdate` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `cuser` CHAR(32) NOT NULL,
  `pguid` CHAR(36) NOT NULL,
  `adate` datetime DEFAULT current_timestamp,
  `aplace` text DEFAULT NULL ,
  `anote` text DEFAULT NULL ,
  `deleted` datetime DEFAULT NULL ,

  PRIMARY KEY (id) 
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
