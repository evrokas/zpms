/* DBAL_TABLENAME_BEGIN locations */
/* DBAL_FIELD cdate|datetime default current_timestamp */
/* DBAL_FIELD cuser|varchar(32) */
/* DBAL_FIELD name|varchar(64) */
/* DBAL_FIELD machinename|varchar(64) */
/* DBAL_FIELD address|text */
/* DBAL_TABLENAME_END */
DROP TABLE IF EXISTS locations;
CREATE TABLE locations (
  id INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
  cdate datetime default current_timestamp,
  cuser varchar(32),
  name varchar(64),
  machinename varchar(64),
  address text,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
