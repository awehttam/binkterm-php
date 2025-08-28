-- Add font family and size settings to user_settings table
-- v1.3.0 - Font customization support

ALTER TABLE user_settings 
ADD COLUMN IF NOT EXISTS font_family VARCHAR(100) DEFAULT 'Courier New, Monaco, Consolas, monospace';

ALTER TABLE user_settings 
ADD COLUMN IF NOT EXISTS font_size INTEGER DEFAULT 16;

-- Add comment for documentation
COMMENT ON COLUMN user_settings.font_family IS 'CSS font-family for message display';
COMMENT ON COLUMN user_settings.font_size IS 'Font size in pixels for message display';