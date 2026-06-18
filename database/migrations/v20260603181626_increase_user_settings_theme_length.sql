-- Migration: 20260603181626 - increase user_settings theme length
-- Created: 2026-06-03 18:16:26 UTC

ALTER TABLE user_settings
    ALTER COLUMN theme TYPE VARCHAR(300);
