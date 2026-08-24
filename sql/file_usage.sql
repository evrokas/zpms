CREATE TABLE `file_usage` (
  `id` INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
  `guid` CHAR(36) NOT NULL,
  `cdate` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `file_guid` char(36) DEFAULT NULL ,
  `entity_type` varchar(64) DEFAULT NULL ,
  `entity_id` char(36) DEFAULT NULL ,
  `usage_type` varchar(64) DEFAULT \"attachment\",
  `deleted` datetime DEFAULT NULL ,

  PRIMARY KEY (id) 
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
