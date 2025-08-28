-- Migration: 1.1.1 - Add user preferences
-- Created: 2025-08-25 06:45:00

-- Add email notification preferences for users
ALTER TABLE user_settings ADD COLUMN email_notifications BOOLEAN DEFAULT TRUE;

-- Add signature field for users
ALTER TABLE users ADD COLUMN signature TEXT;

-- Create index for faster user settings lookups
CREATE INDEX IF NOT EXISTS idx_user_settings_notifications ON user_settings(email_notifications);