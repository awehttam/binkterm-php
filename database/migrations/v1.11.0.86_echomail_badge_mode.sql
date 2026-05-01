-- Migration v1.11.0.86: Add echomail_badge_mode user setting
--
-- Controls what the dashboard echomail badge counts:
--   'new'    (default) — messages arrived since the user last visited an echomail page
--   'unread'           — total messages above the user's last-read watermark (per area)

ALTER TABLE user_settings
    ADD COLUMN IF NOT EXISTS echomail_badge_mode VARCHAR(10) NOT NULL DEFAULT 'new';
