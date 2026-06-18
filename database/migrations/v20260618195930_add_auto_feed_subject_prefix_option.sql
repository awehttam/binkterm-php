-- Migration: 20260618195930 - add auto feed subject prefix option
-- Created: 2026-06-18 19:59:30 UTC

ALTER TABLE auto_feed_sources
    ADD COLUMN IF NOT EXISTS include_feed_name_in_subject BOOLEAN NOT NULL DEFAULT FALSE;

COMMENT ON COLUMN auto_feed_sources.include_feed_name_in_subject IS 'When true, prefix posted echomail subjects with the configured feed name in square brackets';
