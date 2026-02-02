-- Add default echo area list preference setting

ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS default_echo_list VARCHAR(20) DEFAULT 'reader';

-- Add check constraint to ensure only valid values
ALTER TABLE user_settings DROP CONSTRAINT IF EXISTS user_settings_default_echo_list_check;
ALTER TABLE user_settings ADD CONSTRAINT user_settings_default_echo_list_check
    CHECK (default_echo_list IN ('reader', 'echolist'));

-- Add comment
COMMENT ON COLUMN user_settings.default_echo_list IS 'User preference for default echo area list view: reader (message list) or echolist (forum list)';
