-- Migration: 1.10.18 - Add locale preference to user settings
-- Created: 2026-03-05

ALTER TABLE user_settings
    ADD COLUMN IF NOT EXISTS locale VARCHAR(16) DEFAULT 'en';

UPDATE user_settings
SET locale = 'en'
WHERE locale IS NULL OR BTRIM(locale) = '';

ALTER TABLE user_settings
    ALTER COLUMN locale SET DEFAULT 'en';

