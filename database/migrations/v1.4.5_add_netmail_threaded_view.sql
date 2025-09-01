-- Migration: Add netmail threading preference
-- Version: 1.4.5
-- Description: Add netmail_threaded_view column for separate netmail threading preference

-- Add netmail threading preference column
ALTER TABLE user_settings 
ADD COLUMN IF NOT EXISTS netmail_threaded_view BOOLEAN DEFAULT FALSE;

-- Update existing records to have default values
UPDATE user_settings 
SET netmail_threaded_view = FALSE
WHERE netmail_threaded_view IS NULL;