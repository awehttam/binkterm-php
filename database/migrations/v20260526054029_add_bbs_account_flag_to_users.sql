-- Migration: 20260526054029 - add bbs account flag to users
-- Created: 2026-05-26 05:40:29 UTC

-- Add your SQL statements here
-- Each statement should end with semicolon followed by newline

-- Example:
-- ALTER TABLE users ADD COLUMN new_field VARCHAR(100);

-- CREATE INDEX idx_new_field ON users(new_field);
ALTER TABLE users
ADD COLUMN IF NOT EXISTS is_bbs_account BOOLEAN NOT NULL DEFAULT FALSE;
