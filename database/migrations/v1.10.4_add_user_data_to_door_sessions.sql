-- Add user_data column to store drop file information
-- Bridge will use this to generate DOOR.SYS (avoiding permission issues with web server user)

ALTER TABLE door_sessions
ADD COLUMN IF NOT EXISTS user_data JSONB;

COMMENT ON COLUMN door_sessions.user_data IS 'User data for drop file generation (real_name, location, security_level, etc.)';

-- Remove session_path column since bridge will determine this
ALTER TABLE door_sessions
ALTER COLUMN session_path DROP NOT NULL;

COMMENT ON COLUMN door_sessions.session_path IS 'Session directory path (set by bridge after creation)';
