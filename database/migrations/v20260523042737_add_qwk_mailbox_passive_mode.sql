-- Migration: 20260523042737 - add qwk mailbox passive mode
-- Created: 2026-05-23 04:27:37 UTC

ALTER TABLE qwk_mailboxes
    ADD COLUMN IF NOT EXISTS passive_mode BOOLEAN NOT NULL DEFAULT TRUE;
