/* DBAL_TABLENAME_BEGIN appointments */
/* DBAL_FIELD guid|char(36) not null */
/* DBAL_FIELD cdate|datetime default current_timestamp */
/* DBAL_FIELD cuser|char(32) not null */
/* DBAL_FIELD pguid|char(36) not null */
/* DBAL_FIELD adate|datetime default current_timestamp */
/* DBAL_FIELD aplace|text */
/* DBAL_FIELD anote|text */
/* DBAL_FIELD deleted|datetime default null */
/* DBAL_TABLENAME_END */
DROP TABLE IF EXISTS appointments;
CREATE TABLE appointments (
  id INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
  guid char(36) not null,
  cdate datetime default current_timestamp,
  cuser char(32) not null,
  pguid char(36) not null,
  adate datetime default current_timestamp,
  aplace text,
  anote text,
  deleted datetime default null,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
