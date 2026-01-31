-- Add date format preference for users
-- Migration: v1.8.8

-- Add date_format column to user_settings table
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS date_format VARCHAR(20) DEFAULT 'en-US';

-- Add comment for documentation
COMMENT ON COLUMN user_settings.date_format IS 'User preferred date format locale: en-US (MM/DD/YYYY), en-GB (DD/MM/YYYY), fr-FR (DD/MM/YYYY), de-DE (DD.MM.YYYY), ja-JP (YYYY/MM/DD), etc.';
