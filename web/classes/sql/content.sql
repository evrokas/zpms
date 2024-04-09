/* DBAL_TABLENAME_BEGIN content */
/* DBAL_FIELD guid|char(36) not null */
/* DBAL_FIELD cdate|datetime default current_timestamp */
/* DBAL_FIELD cuser|char(32) not null */
/* DBAL_FIELD title|text */
/* DBAL_FIELD description|text */
/* DBAL_FIELD published|boolean */
/* DBAL_FIELD path|text */
/* DBAL_TABLENAME_END */
DROP TABLE IF EXISTS content;
CREATE TABLE content (
  id INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
  guid char(36) not null,
  cdate datetime default current_timestamp,
  cuser char(32) not null,
  title text,
  description text,
  published boolean,
  path text,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
