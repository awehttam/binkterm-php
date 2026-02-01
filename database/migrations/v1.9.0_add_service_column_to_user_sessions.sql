-- Migration: 1.9.0 - add service column to user sessions
-- Created: 2026-02-01 05:43:51

-- Add service column to track which service the user is logged into
ALTER TABLE user_sessions ADD COLUMN service VARCHAR(20) DEFAULT 'web' NOT NULL;

-- Create index for service lookups
CREATE INDEX idx_user_sessions_service ON user_sessions(service);

-- Update existing sessions to have 'web' service
UPDATE user_sessions SET service = 'web' WHERE service IS NULL;
