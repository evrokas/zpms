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

DROP TABLE IF EXISTS users;
CREATE TABLE users (
    id		INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
    
    cdate	TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    active	BOOLEAN DEFAULT FALSE,
    expired	BOOLEAN DEFAULT TRUE,
    
    username	VARCHAR(32) NOT NULL,
    password	VARCHAR(64) NOT NULL,
    
    fullname	VARCHAR(64) NOT NULL,
    email	VARCHAR(64) NOT NULL,

    perms	CHAR(36) NOT NULL,
    
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS permissions;
CREATE TABLE permissions (
    id		INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
    guid	CHAR(36) NOT NULL UNIQUE,
    
    name	CHAR(32),		/* permission name */
 
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS permissions_list;
CREATE TABLE permissions_list (
    id		INTEGER NOT NULL AUTO_INCREMENT UNIQUE,
    user	CHAR(36) NOT NULL,		/* user identifier */
    perm	CHAR(36) NOT NULL,		/* permission identifier */

    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    