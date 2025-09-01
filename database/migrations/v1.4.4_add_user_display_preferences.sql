-- Migration: Add user display preferences for threading and sort order
-- Version: 1.4.4
-- Description: Add columns for threaded view preference and default sort order

-- Add new preference columns to user_settings
ALTER TABLE user_settings 
ADD COLUMN IF NOT EXISTS threaded_view BOOLEAN DEFAULT FALSE,
ADD COLUMN IF NOT EXISTS default_sort VARCHAR(20) DEFAULT 'date_desc',
ADD COLUMN IF NOT EXISTS font_family VARCHAR(100) DEFAULT 'Courier New, Monaco, Consolas, monospace',
ADD COLUMN IF NOT EXISTS font_size INTEGER DEFAULT 16;

-- Update existing records to have default values
UPDATE user_settings 
SET threaded_view = FALSE, 
    default_sort = 'date_desc',
    font_family = 'Courier New, Monaco, Consolas, monospace',
    font_size = 16
WHERE threaded_view IS NULL 
   OR default_sort IS NULL 
   OR font_family IS NULL 
   OR font_size IS NULL;