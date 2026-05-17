ALTER TABLE packet_bbs_sessions
    ADD COLUMN IF NOT EXISTS session_state JSONB;

UPDATE packet_bbs_sessions
SET session_state = '{}'::jsonb
WHERE session_state IS NULL;
