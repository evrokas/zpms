DROP TABLE IF EXISTS patients;
CREATE TABLE patients (
    id      INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
    guid    CHAR(36) NOT NULL,

    cdate   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    cuser   CHaR(16) NOT NULL,

    pname   VARCHAR(64) NOT NULL,
    pdob    TIMESTAMP,
    pamka   CHAR(11),

    ptel    VARCHAR(32),
    paddr   VARCHAR(256),
    pemail  VARCHAR(128),

    firstapp    TIMESTAMP,              /* first appointment */


    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

