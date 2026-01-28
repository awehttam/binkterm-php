-- Migration: v1.8.2_add_session_activity.sql
-- Description: Add activity tracking to user sessions
-- Date: 2026-01-28

ALTER TABLE user_sessions
    ADD COLUMN IF NOT EXISTS activity VARCHAR(255);

COMMENT ON COLUMN user_sessions.activity IS 'Last page title/activity for online status display';
