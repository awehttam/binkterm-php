-- Migration to add message_read_status table for tracking read messages

-- Create message read status tracking table
CREATE TABLE IF NOT EXISTS message_read_status (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    message_id INTEGER NOT NULL,
    message_type VARCHAR(10) NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, message_id, message_type)
);

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_message_read_status_user ON message_read_status(user_id);
CREATE INDEX IF NOT EXISTS idx_message_read_status_message ON message_read_status(message_id, message_type);