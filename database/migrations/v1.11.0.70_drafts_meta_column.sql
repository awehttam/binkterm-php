-- Migration: Add meta JSONB column to drafts table
-- Version: 1.11.0.70
-- Purpose: Store additional draft metadata (e.g. cross-post areas) that does not
--          fit in dedicated columns.

ALTER TABLE drafts ADD COLUMN IF NOT EXISTS meta JSONB;
