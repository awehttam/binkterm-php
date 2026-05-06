-- Migration: 20260506204346 - add_echomail_moderation_forced_to_users
-- Created: 2026-05-06 20:43:46 UTC

ALTER TABLE users ADD COLUMN echomail_moderation_forced BOOLEAN NOT NULL DEFAULT FALSE;
