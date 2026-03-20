-- Migration: v1.11.0.33 - Add is_public flag to file areas
-- Allows individual file areas to be accessible to unauthenticated visitors.
-- Requires a valid license to enable (enforced in PHP).

ALTER TABLE file_areas ADD COLUMN IF NOT EXISTS is_public BOOLEAN NOT NULL DEFAULT FALSE;

-- Partial index for efficient lookup of public areas (sparse - most areas are not public)
CREATE INDEX IF NOT EXISTS idx_file_areas_is_public ON file_areas(is_public) WHERE is_public = TRUE;
