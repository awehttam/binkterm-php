-- Enhance user_sessions for "who's online" feature
-- Add location field to users and pending_users tables

-- Add location field to users table
ALTER TABLE users ADD COLUMN IF NOT EXISTS location VARCHAR(100);

-- Add location field to pending_users table (for registration)
ALTER TABLE pending_users ADD COLUMN IF NOT EXISTS location VARCHAR(100);

-- Add last_activity to user_sessions for tracking online status
ALTER TABLE user_sessions ADD COLUMN IF NOT EXISTS last_activity TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP;

-- Index for finding recently active users
CREATE INDEX IF NOT EXISTS idx_user_sessions_last_activity ON user_sessions(last_activity);

-- View for online users (active in last 15 minutes)
CREATE OR REPLACE VIEW online_users AS
SELECT
    s.user_id,
    s.last_activity,
    s.ip_address,
    u.username,
    u.real_name,
    u.location,
    u.fidonet_address
FROM user_sessions s
JOIN users u ON s.user_id = u.id
WHERE s.last_activity > NOW() - INTERVAL '15 minutes'
ORDER BY s.last_activity DESC;

COMMENT ON COLUMN users.location IS 'User geographic location for display in who-is-online';
COMMENT ON COLUMN user_sessions.last_activity IS 'Last activity timestamp for online status tracking';
