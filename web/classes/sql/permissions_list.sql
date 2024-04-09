/* DBAL_TABLENAME_BEGIN permissions_list */
/* DBAL_FIELD a|char(36) not null */
/* DBAL_FIELD b|char(36) not null */
/* DBAL_TABLENAME_END */
DROP TABLE IF EXISTS permissions_list;
CREATE TABLE permissions_list (
  id INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
  a char(36) not null,
  b char(36) not null,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
