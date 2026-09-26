-- Migration: 20260926023027 - add rlogin doors asset sizes
-- Created: 2026-09-26 02:30:27 UTC

ALTER TABLE rlogin_doors ADD COLUMN IF NOT EXISTS icon_size INTEGER;
ALTER TABLE rlogin_doors ADD COLUMN IF NOT EXISTS screenshot_size INTEGER;

-- Backfill existing rows so Content-Length no longer has to fall back to
-- strlen() on the fetched blob for doors created before this column existed.
UPDATE rlogin_doors SET icon_size = octet_length(icon_data) WHERE icon_data IS NOT NULL AND icon_size IS NULL;
UPDATE rlogin_doors SET screenshot_size = octet_length(screenshot_data) WHERE screenshot_data IS NOT NULL AND screenshot_size IS NULL;
