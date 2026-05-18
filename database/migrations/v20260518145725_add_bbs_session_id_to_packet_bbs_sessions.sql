ALTER TABLE packet_bbs_sessions
    ADD COLUMN IF NOT EXISTS bbs_session_id VARCHAR(128) REFERENCES user_sessions(session_id) ON DELETE SET NULL;
