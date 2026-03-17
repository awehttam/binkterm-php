-- Migration: v1.11.0.30 - Add forward_netmail_email to user_settings
ALTER TABLE user_settings
    ADD COLUMN IF NOT EXISTS forward_netmail_email BOOLEAN NOT NULL DEFAULT FALSE;
