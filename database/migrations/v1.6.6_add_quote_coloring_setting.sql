-- Add quote coloring preference to user_settings
-- This allows users to enable/disable multi-level quote coloring in messages

ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS quote_coloring BOOLEAN DEFAULT TRUE;

-- Add comment for documentation
COMMENT ON COLUMN user_settings.quote_coloring IS 'Enable colored quote lines based on nesting depth (>, >>, etc.)';
