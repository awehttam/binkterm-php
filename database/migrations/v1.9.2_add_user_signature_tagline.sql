-- Add signature preference to user settings
ALTER TABLE user_settings
    ADD COLUMN IF NOT EXISTS signature_text TEXT;

COMMENT ON COLUMN user_settings.signature_text IS 'User signature (up to 4 lines) appended to outbound netmail/echomail';
