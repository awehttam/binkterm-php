-- Migration: 20261102022622 - bbs_directory_source_tags
-- Created: 2026-11-02 02:26:22 UTC

ALTER TABLE bbs_directory ALTER COLUMN source TYPE VARCHAR(50);
