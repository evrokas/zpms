/* DBAL_TABLENAME_BEGIN users */
/* DBAL_FIELD name|varchar(64) not null */
/* DBAL_FIELD email|varchar(128) not null */
/* DBAL_FIELD uname|varchar(32) not null */
/* DBAL_FIELD upass|varchar(65) not null */
/* DBAL_FIELD cdate|datetime default current_timestamp */
/* DBAL_FIELD active|boolean default false */
/* DBAL_FIELD expired|boolean default  true */
/* DBAL_FIELD roles|char(36) not null */
/* DBAL_TABLENAME_END */
DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
  name varchar(64) not null,
  email varchar(128) not null,
  uname varchar(32) not null,
  upass varchar(65) not null,
  cdate datetime default current_timestamp,
  active boolean default false,
  expired boolean default  true,
  roles char(36) not null,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
