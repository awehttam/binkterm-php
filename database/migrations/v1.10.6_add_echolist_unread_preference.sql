-- Migration: v1.10.6 - Add echolist unread-only preference
-- Adds a persistent preference for "Show only areas with unread messages"
-- to match the existing echolist_subscribed_only preference.

ALTER TABLE user_settings
    ADD COLUMN IF NOT EXISTS echolist_unread_only BOOLEAN DEFAULT FALSE;

COMMENT ON COLUMN user_settings.echolist_unread_only IS
    'Whether to show only echoareas with unread messages in the echo list (default: false)';
