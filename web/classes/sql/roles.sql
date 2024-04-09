/* DBAL_TABLENAME_BEGIN roles */
/* DBAL_FIELD guid|char(36) not null */
/* DBAL_FIELD text|char(32) not null */
/* DBAL_TABLENAME_END */
DROP TABLE IF EXISTS roles;
CREATE TABLE roles (
  id INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
  guid char(36) not null,
  text char(32) not null,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
