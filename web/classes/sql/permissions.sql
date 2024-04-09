/* DBAL_TABLENAME_BEGIN permissions */
/* DBAL_FIELD guid|char(36) not null */
/* DBAL_FIELD text|char(32) not null */
/* DBAL_TABLENAME_END */
DROP TABLE IF EXISTS permissions;
CREATE TABLE permissions (
  id INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
  guid char(36) not null,
  text char(32) not null,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
