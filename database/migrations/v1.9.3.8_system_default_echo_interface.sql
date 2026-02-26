-- Migration: 1.9.3.8 - Add system_choice option for default echo interface

-- Update CHECK constraint to include 'system_choice'
ALTER TABLE user_settings DROP CONSTRAINT IF EXISTS user_settings_default_echo_list_check;
ALTER TABLE user_settings ADD CONSTRAINT user_settings_default_echo_list_check
    CHECK (default_echo_list IN ('reader', 'echolist', 'system_choice'));

-- Update default value to 'system_choice'
ALTER TABLE user_settings ALTER COLUMN default_echo_list SET DEFAULT 'system_choice';

-- Update existing 'reader' values to 'system_choice' so they get the new system default
UPDATE user_settings SET default_echo_list = 'system_choice' WHERE default_echo_list = 'reader';

-- Update comment
COMMENT ON COLUMN user_settings.default_echo_list IS 'User preference for default echo area list view: reader (message list), echolist (forum list), or system_choice (use BBS-wide default)';
