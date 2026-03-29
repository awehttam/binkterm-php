-- Migration: 1.11.0.52 - File upload approval queue

ALTER TABLE files
    ADD COLUMN IF NOT EXISTS approved_by_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS approved_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS rejected_by_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS rejected_at TIMESTAMPTZ NULL,
    ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_files_status_created_at ON files(status, created_at);
