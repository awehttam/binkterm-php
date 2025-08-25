-- Migration script to add user_settings and user_sessions tables

-- Create user_settings table
CREATE TABLE IF NOT EXISTS user_settings (
    user_id INTEGER PRIMARY KEY,
    timezone VARCHAR(50) DEFAULT 'America/Los_Angeles',
    messages_per_page INTEGER DEFAULT 25,
    theme VARCHAR(20) DEFAULT 'light',
    show_origin BOOLEAN DEFAULT 1,
    show_tearline BOOLEAN DEFAULT 1,
    auto_refresh BOOLEAN DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create user_sessions table (separate from old sessions table)
CREATE TABLE IF NOT EXISTS user_sessions (
    session_id VARCHAR(128) PRIMARY KEY,
    user_id INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create indexes for new tables
CREATE INDEX IF NOT EXISTS idx_user_sessions_user ON user_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_user_sessions_expires ON user_sessions(expires_at);

-- Note: If old sessions table exists, manually migrate data if needed
-- For now, we'll start fresh with user_sessions table