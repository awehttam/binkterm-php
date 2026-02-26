-- Migration: 1.9.3.7 - Add echolist filter preference to user_settings

-- Add column for echolist subscribed filter preference (default false = show all)
ALTER TABLE user_settings
ADD COLUMN IF NOT EXISTS echolist_subscribed_only BOOLEAN DEFAULT FALSE;

-- Add comment for documentation
COMMENT ON COLUMN user_settings.echolist_subscribed_only IS 'Whether to show only subscribed echoareas in /echolist (default: false - show all)';
