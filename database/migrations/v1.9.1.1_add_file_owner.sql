-- Add owner_id to files table for tracking file ownership

ALTER TABLE files ADD COLUMN owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL;

-- Add index for querying files by owner
CREATE INDEX idx_files_owner_id ON files(owner_id);

COMMENT ON COLUMN files.owner_id IS 'User who uploaded the file (NULL for TIC files or system uploads)';
