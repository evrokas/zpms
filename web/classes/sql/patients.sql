/* DBAL_TABLENAME_BEGIN patients */
/* DBAL_FIELD guid|char(36) not null */
/* DBAL_FIELD cdate|datetime default current_timestamp */
/* DBAL_FIELD cuser|char(32) not null */
/* DBAL_FIELD pname|varchar(64) not null */
/* DBAL_FIELD pdob|datetime */
/* DBAL_FIELD pamka|char(11) */
/* DBAL_FIELD ptel|varchar(32) */
/* DBAL_FIELD paddr|varchar(256) */
/* DBAL_FIELD pemail|varchar(128) */
/* DBAL_FIELD firstapp|datetime */
/* DBAL_FIELD pnote|text */
/* DBAL_FIELD deleted|datetime default null */
/* DBAL_TABLENAME_END */
DROP TABLE IF EXISTS patients;
CREATE TABLE patients (
  id INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
  guid char(36) not null,
  cdate datetime default current_timestamp,
  cuser char(32) not null,
  pname varchar(64) not null,
  pdob datetime,
  pamka char(11),
  ptel varchar(32),
  paddr varchar(256),
  pemail varchar(128),
  firstapp datetime,
  pnote text,
  deleted datetime default null,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
