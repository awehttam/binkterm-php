-- Migration: v1.11.0.31 - Add echomail digest settings to user_settings
ALTER TABLE user_settings
    ADD COLUMN IF NOT EXISTS echomail_digest VARCHAR(10) NOT NULL DEFAULT 'none',
    ADD COLUMN IF NOT EXISTS echomail_digest_last_sent TIMESTAMP NULL;
