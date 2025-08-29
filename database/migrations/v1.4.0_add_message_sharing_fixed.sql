-- Migration: Add message sharing functionality
-- Version: 1.4.0
-- Description: Add support for sharing echomail messages via web links

-- Create shared_messages table
CREATE TABLE shared_messages (
    id SERIAL PRIMARY KEY,
    message_id INTEGER NOT NULL,
    message_type VARCHAR(20) NOT NULL CHECK (message_type IN ('echomail', 'netmail')),
    shared_by_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    share_key VARCHAR(32) UNIQUE NOT NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    access_count INTEGER DEFAULT 0,
    last_accessed_at TIMESTAMP NULL,
    is_public BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE
);

-- Create indexes for performance
CREATE INDEX idx_shared_messages_key ON shared_messages(share_key);
CREATE INDEX idx_shared_messages_user ON shared_messages(shared_by_user_id);
CREATE INDEX idx_shared_messages_message ON shared_messages(message_id, message_type);
CREATE INDEX idx_shared_messages_expires ON shared_messages(expires_at) WHERE expires_at IS NOT NULL;
CREATE INDEX idx_shared_messages_active ON shared_messages(is_active) WHERE is_active = TRUE;

-- Add sharing preference columns to user_settings table
-- These will fail if columns already exist, but that's OK for migrations
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS allow_sharing BOOLEAN DEFAULT TRUE;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS default_share_expiry INTEGER DEFAULT 168;
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS max_shares_per_user INTEGER DEFAULT 50;

-- Create function to clean up expired shares
CREATE OR REPLACE FUNCTION cleanup_expired_shares()
RETURNS INTEGER
LANGUAGE plpgsql
AS $func$
DECLARE
    deleted_count INTEGER;
BEGIN
    DELETE FROM shared_messages 
    WHERE expires_at IS NOT NULL 
      AND expires_at < NOW();
    
    GET DIAGNOSTICS deleted_count = ROW_COUNT;
    RETURN deleted_count;
END;
$func$;

-- Add comments for documentation
COMMENT ON TABLE shared_messages IS 'Stores information about shared message links';
COMMENT ON COLUMN shared_messages.share_key IS 'Unique random string used in share URLs';
COMMENT ON COLUMN shared_messages.expires_at IS 'When the share link expires (NULL = never expires)';
COMMENT ON COLUMN shared_messages.is_public IS 'Whether the share can be accessed without login';
COMMENT ON COLUMN shared_messages.is_active IS 'Whether the share is currently active (allows soft deletion)';
COMMENT ON FUNCTION cleanup_expired_shares() IS 'Removes expired share links and returns count of deleted records';