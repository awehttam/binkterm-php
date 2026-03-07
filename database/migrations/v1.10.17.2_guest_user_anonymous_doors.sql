-- Migration: 1.10.18 - Guest system user and anonymous door session support
--
-- Adds is_system flag to users table and inserts the shared _guest account
-- used for anonymous native door sessions. System users cannot log in and
-- are excluded from admin user lists.

ALTER TABLE users ADD COLUMN IF NOT EXISTS is_system BOOLEAN NOT NULL DEFAULT FALSE;

-- Insert the shared guest system user (no password, cannot log in)
INSERT INTO users (username, password_hash, real_name, is_active, is_system)
VALUES ('_guest', '', 'Guest', FALSE, TRUE)
ON CONFLICT (username) DO NOTHING;
