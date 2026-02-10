-- Migration: 1.9.3.9 - Convert timestamp columns to TIMESTAMPTZ for users, shoutbox, and file areas
-- This ensures consistent timezone handling across the application
-- Interpret existing TIMESTAMP values using the current PostgreSQL timezone setting
-- NOTE: Ensure the PostgreSQL session/server timezone is set to the timezone that
-- matches how existing TIMESTAMP values were originally written (typically server local time).

-- Users table
ALTER TABLE users
    ALTER COLUMN created_at TYPE TIMESTAMPTZ USING created_at AT TIME ZONE current_setting('TIMEZONE'),
    ALTER COLUMN last_login TYPE TIMESTAMPTZ USING last_login AT TIME ZONE current_setting('TIMEZONE');

-- Shoutbox table
ALTER TABLE shoutbox_messages
    ALTER COLUMN created_at TYPE TIMESTAMPTZ USING created_at AT TIME ZONE current_setting('TIMEZONE');

-- File areas tables
ALTER TABLE file_areas
    ALTER COLUMN created_at TYPE TIMESTAMPTZ USING created_at AT TIME ZONE current_setting('TIMEZONE'),
    ALTER COLUMN updated_at TYPE TIMESTAMPTZ USING updated_at AT TIME ZONE current_setting('TIMEZONE');

ALTER TABLE files
    ALTER COLUMN created_at TYPE TIMESTAMPTZ USING created_at AT TIME ZONE current_setting('TIMEZONE'),
    ALTER COLUMN updated_at TYPE TIMESTAMPTZ USING updated_at AT TIME ZONE current_setting('TIMEZONE');

-- Pending users table
ALTER TABLE pending_users
    ALTER COLUMN requested_at TYPE TIMESTAMPTZ USING requested_at AT TIME ZONE current_setting('TIMEZONE'),
    ALTER COLUMN reviewed_at TYPE TIMESTAMPTZ USING reviewed_at AT TIME ZONE current_setting('TIMEZONE');

-- Gateway tokens table
ALTER TABLE gateway_tokens
    ALTER COLUMN created_at TYPE TIMESTAMPTZ USING created_at AT TIME ZONE current_setting('TIMEZONE'),
    ALTER COLUMN expires_at TYPE TIMESTAMPTZ USING expires_at AT TIME ZONE current_setting('TIMEZONE'),
    ALTER COLUMN used_at TYPE TIMESTAMPTZ USING used_at AT TIME ZONE current_setting('TIMEZONE');

-- Polls table
ALTER TABLE polls
    ALTER COLUMN created_at TYPE TIMESTAMPTZ USING created_at AT TIME ZONE current_setting('TIMEZONE'),
    ALTER COLUMN updated_at TYPE TIMESTAMPTZ USING updated_at AT TIME ZONE current_setting('TIMEZONE');

-- Poll votes table
ALTER TABLE poll_votes
    ALTER COLUMN created_at TYPE TIMESTAMPTZ USING created_at AT TIME ZONE current_setting('TIMEZONE');

-- Chat rooms table
ALTER TABLE chat_rooms
    ALTER COLUMN created_at TYPE TIMESTAMPTZ USING created_at AT TIME ZONE current_setting('TIMEZONE');

-- Chat messages table
ALTER TABLE chat_messages
    ALTER COLUMN created_at TYPE TIMESTAMPTZ USING created_at AT TIME ZONE current_setting('TIMEZONE');

-- Chat room bans table
ALTER TABLE chat_room_bans
    ALTER COLUMN created_at TYPE TIMESTAMPTZ USING created_at AT TIME ZONE current_setting('TIMEZONE'),
    ALTER COLUMN expires_at TYPE TIMESTAMPTZ USING expires_at AT TIME ZONE 'UTC';

-- Saved messages table
ALTER TABLE saved_messages
    ALTER COLUMN saved_at TYPE TIMESTAMPTZ USING saved_at AT TIME ZONE current_setting('TIMEZONE');

-- Shared messages table
ALTER TABLE shared_messages
    ALTER COLUMN created_at TYPE TIMESTAMPTZ USING created_at AT TIME ZONE current_setting('TIMEZONE'),
    ALTER COLUMN expires_at TYPE TIMESTAMPTZ USING expires_at AT TIME ZONE current_setting('TIMEZONE'),
    ALTER COLUMN last_accessed_at TYPE TIMESTAMPTZ USING last_accessed_at AT TIME ZONE current_setting('TIMEZONE');

-- Message read status table
ALTER TABLE message_read_status
    ALTER COLUMN read_at TYPE TIMESTAMPTZ USING read_at AT TIME ZONE current_setting('TIMEZONE');

-- Users meta table
ALTER TABLE users_meta
    ALTER COLUMN updated_at TYPE TIMESTAMPTZ USING updated_at AT TIME ZONE current_setting('TIMEZONE');

-- User transactions table
ALTER TABLE user_transactions
    ALTER COLUMN created_at TYPE TIMESTAMPTZ USING created_at AT TIME ZONE current_setting('TIMEZONE');

-- Drafts table
ALTER TABLE drafts
    ALTER COLUMN created_at TYPE TIMESTAMPTZ USING created_at AT TIME ZONE 'UTC',
    ALTER COLUMN updated_at TYPE TIMESTAMPTZ USING updated_at AT TIME ZONE 'UTC';

-- Registration attempts table
ALTER TABLE registration_attempts
    ALTER COLUMN attempt_time TYPE TIMESTAMPTZ USING attempt_time AT TIME ZONE current_setting('TIMEZONE');

-- WebDoor sessions table
ALTER TABLE webdoor_sessions
    ALTER COLUMN created_at TYPE TIMESTAMPTZ USING created_at AT TIME ZONE current_setting('TIMEZONE'),
    ALTER COLUMN expires_at TYPE TIMESTAMPTZ USING expires_at AT TIME ZONE current_setting('TIMEZONE'),
    ALTER COLUMN ended_at TYPE TIMESTAMPTZ USING ended_at AT TIME ZONE current_setting('TIMEZONE');

-- WebDoor storage table
ALTER TABLE webdoor_storage
    ALTER COLUMN saved_at TYPE TIMESTAMPTZ USING saved_at AT TIME ZONE current_setting('TIMEZONE');

-- Update comments
COMMENT ON COLUMN users.created_at IS 'Account creation timestamp (timezone-aware)';
COMMENT ON COLUMN users.last_login IS 'Last login timestamp (timezone-aware)';
COMMENT ON COLUMN shoutbox_messages.created_at IS 'Message creation timestamp (timezone-aware)';
COMMENT ON COLUMN file_areas.created_at IS 'File area creation timestamp (timezone-aware)';
COMMENT ON COLUMN file_areas.updated_at IS 'File area last update timestamp (timezone-aware)';
COMMENT ON COLUMN files.created_at IS 'File upload timestamp (timezone-aware)';
COMMENT ON COLUMN files.updated_at IS 'File last update timestamp (timezone-aware)';
COMMENT ON COLUMN pending_users.requested_at IS 'Request timestamp (timezone-aware)';
COMMENT ON COLUMN pending_users.reviewed_at IS 'Review timestamp (timezone-aware)';
COMMENT ON COLUMN gateway_tokens.created_at IS 'Token creation timestamp (timezone-aware)';
COMMENT ON COLUMN gateway_tokens.expires_at IS 'Token expiration timestamp (timezone-aware)';
COMMENT ON COLUMN gateway_tokens.used_at IS 'Token usage timestamp (timezone-aware)';
COMMENT ON COLUMN polls.created_at IS 'Poll creation timestamp (timezone-aware)';
COMMENT ON COLUMN polls.updated_at IS 'Poll update timestamp (timezone-aware)';
COMMENT ON COLUMN poll_votes.created_at IS 'Vote timestamp (timezone-aware)';
COMMENT ON COLUMN chat_rooms.created_at IS 'Chat room creation timestamp (timezone-aware)';
COMMENT ON COLUMN chat_messages.created_at IS 'Chat message timestamp (timezone-aware)';
COMMENT ON COLUMN chat_room_bans.created_at IS 'Ban creation timestamp (timezone-aware)';
COMMENT ON COLUMN chat_room_bans.expires_at IS 'Ban expiration timestamp (timezone-aware)';
COMMENT ON COLUMN saved_messages.saved_at IS 'Message save timestamp (timezone-aware)';
COMMENT ON COLUMN shared_messages.created_at IS 'Share creation timestamp (timezone-aware)';
COMMENT ON COLUMN shared_messages.expires_at IS 'Share expiration timestamp (timezone-aware)';
COMMENT ON COLUMN shared_messages.last_accessed_at IS 'Last access timestamp (timezone-aware)';
COMMENT ON COLUMN message_read_status.read_at IS 'Message read timestamp (timezone-aware)';
COMMENT ON COLUMN users_meta.updated_at IS 'Metadata update timestamp (timezone-aware)';
COMMENT ON COLUMN user_transactions.created_at IS 'Transaction timestamp (timezone-aware)';
COMMENT ON COLUMN drafts.created_at IS 'Draft creation timestamp (timezone-aware)';
COMMENT ON COLUMN drafts.updated_at IS 'Draft update timestamp (timezone-aware)';
COMMENT ON COLUMN registration_attempts.attempt_time IS 'Registration attempt timestamp (timezone-aware)';
COMMENT ON COLUMN webdoor_sessions.created_at IS 'Session creation timestamp (timezone-aware)';
COMMENT ON COLUMN webdoor_sessions.expires_at IS 'Session expiration timestamp (timezone-aware)';
COMMENT ON COLUMN webdoor_sessions.ended_at IS 'Session end timestamp (timezone-aware)';
COMMENT ON COLUMN webdoor_storage.saved_at IS 'Storage save timestamp (timezone-aware)';
