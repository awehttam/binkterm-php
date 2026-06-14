-- Migration: 20260614205336 - retain approved pending users
-- Created: 2026-06-14 20:53:36 UTC

ALTER TABLE pending_users
    ADD COLUMN IF NOT EXISTS created_user_id INT REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE pending_users
    DROP CONSTRAINT IF EXISTS pending_users_username_key;

DROP INDEX IF EXISTS pending_users_real_name_lower_idx;

CREATE UNIQUE INDEX IF NOT EXISTS idx_pending_users_pending_username_lower
    ON pending_users (LOWER(username))
    WHERE status = 'pending';

CREATE UNIQUE INDEX IF NOT EXISTS idx_pending_users_pending_real_name_lower
    ON pending_users (LOWER(real_name))
    WHERE status = 'pending';

CREATE INDEX IF NOT EXISTS idx_pending_users_created_user_id
    ON pending_users(created_user_id);
