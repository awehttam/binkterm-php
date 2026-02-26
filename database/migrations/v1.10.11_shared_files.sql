CREATE TABLE IF NOT EXISTS shared_files (
    id SERIAL PRIMARY KEY,
    file_id INTEGER NOT NULL REFERENCES files(id) ON DELETE CASCADE,
    shared_by_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    share_key VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMP WITH TIME ZONE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    access_count INTEGER NOT NULL DEFAULT 0,
    last_accessed_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_shared_files_share_key ON shared_files(share_key);
CREATE INDEX IF NOT EXISTS idx_shared_files_file_id ON shared_files(file_id);
CREATE INDEX IF NOT EXISTS idx_shared_files_user_id ON shared_files(shared_by_user_id);
