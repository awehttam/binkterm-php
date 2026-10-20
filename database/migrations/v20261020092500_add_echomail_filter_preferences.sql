-- Migration: Add echomail filter preferences to user_settings
-- Persists echomail active network, active sub-area, and updates default subscribed_only preference

ALTER TABLE user_settings
    ADD COLUMN IF NOT EXISTS echomail_network VARCHAR(64) DEFAULT 'all',
    ADD COLUMN IF NOT EXISTS echomail_subarea VARCHAR(128) DEFAULT 'all',
    ALTER COLUMN echolist_subscribed_only SET DEFAULT TRUE;

COMMENT ON COLUMN user_settings.echomail_network IS 'Selected network filter for echomail/echolist (default: all)';
COMMENT ON COLUMN user_settings.echomail_subarea IS 'Selected sub-area tag filter for echomail/echolist (default: all)';
