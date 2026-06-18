-- Migration: 20260618143620 - add missing chrs charset settings
-- Created: 2026-06-18 14:36:20 UTC

ALTER TABLE networks
    ADD COLUMN IF NOT EXISTS missing_chrs_charset VARCHAR(32);

ALTER TABLE echoareas
    ADD COLUMN IF NOT EXISTS missing_chrs_charset VARCHAR(32);
