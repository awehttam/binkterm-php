-- Migration: 20260909150000 - add date display preferences to user_settings
-- Created: 2026-09-09 15:00:00 UTC
--
-- Adds user preferences for:
-- 1. date_display_style: 'system_choice', 'relative' (e.g. 4 days ago), or 'date' (exact date/time)
-- 2. echomail_date_field: 'system_choice', 'received' (date_received), or 'written' (date_written)

ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS date_display_style VARCHAR(20) DEFAULT 'system_choice';
ALTER TABLE user_settings ADD COLUMN IF NOT EXISTS echomail_date_field VARCHAR(20) DEFAULT 'system_choice';
