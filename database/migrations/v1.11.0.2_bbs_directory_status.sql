-- Migration: 1.11.0.2 - Add status and submitted_by_user_id to bbs_directory
ALTER TABLE bbs_directory
    ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'pending', 'rejected')),
    ADD COLUMN IF NOT EXISTS submitted_by_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL;

-- Mark all existing rows as active
UPDATE bbs_directory SET status = 'active' WHERE status IS NULL OR status = '';

CREATE INDEX IF NOT EXISTS idx_bbs_directory_status ON bbs_directory(status);
