-- Migration: Add message drafts table
-- Version: 1.6.2
-- Date: 2025-09-13

-- Create drafts table for storing message drafts
CREATE TABLE IF NOT EXISTS drafts (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    type VARCHAR(20) NOT NULL CHECK (type IN ('netmail', 'echomail')),
    to_address VARCHAR(100),
    to_name VARCHAR(255),
    echoarea VARCHAR(100),
    subject VARCHAR(255),
    message_text TEXT,
    reply_to_id INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Add index for efficient lookup by user
CREATE INDEX IF NOT EXISTS idx_drafts_user_id ON drafts(user_id);

-- Add index for lookup by type
CREATE INDEX IF NOT EXISTS idx_drafts_type ON drafts(user_id, type);

-- Migration completed successfully