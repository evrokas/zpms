-- Add ErnsAuth username mapping to users table.
-- NULL means "use uname as the ErnsAuth username" (backwards-compatible default).
ALTER TABLE users
    ADD COLUMN ernsauth_username varchar(128) DEFAULT NULL AFTER roles;
