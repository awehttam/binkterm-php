-- Migration: Add saved messages functionality
-- Version: 1.4.6
-- Description: Add support for users to save echomail messages for later viewing

-- Create saved_messages table
CREATE TABLE saved_messages (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    message_id INTEGER NOT NULL,
    message_type VARCHAR(20) NOT NULL CHECK (message_type IN ('echomail', 'netmail')),
    saved_at TIMESTAMP DEFAULT NOW(),
    notes TEXT NULL
);

-- Create indexes for performance
CREATE INDEX idx_saved_messages_user ON saved_messages(user_id);
CREATE INDEX idx_saved_messages_message ON saved_messages(message_id, message_type);
CREATE INDEX idx_saved_messages_user_type ON saved_messages(user_id, message_type);
CREATE INDEX idx_saved_messages_saved_at ON saved_messages(saved_at);

-- Create unique constraint to prevent duplicate saves
CREATE UNIQUE INDEX idx_saved_messages_unique ON saved_messages(user_id, message_id, message_type);

-- Add comments for documentation
COMMENT ON TABLE saved_messages IS 'Stores user-saved messages for later reference';
COMMENT ON COLUMN saved_messages.user_id IS 'ID of the user who saved the message';
COMMENT ON COLUMN saved_messages.message_id IS 'ID of the saved message (echomail.id or netmail.id)';
COMMENT ON COLUMN saved_messages.message_type IS 'Type of message (echomail or netmail)';
COMMENT ON COLUMN saved_messages.saved_at IS 'When the message was saved';
COMMENT ON COLUMN saved_messages.notes IS 'Optional user notes about why they saved this message';