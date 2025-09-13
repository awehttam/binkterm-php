-- Migration: Fix draft timestamps to use timezone-aware storage
-- Version: 1.6.3
-- Date: 2025-09-13

-- Convert existing TIMESTAMP columns to TIMESTAMPTZ for timezone awareness
-- This ensures consistent UTC storage regardless of server timezone settings

-- Drop and recreate with proper timezone handling
ALTER TABLE drafts
ALTER COLUMN created_at TYPE TIMESTAMPTZ USING created_at AT TIME ZONE 'UTC';

ALTER TABLE drafts
ALTER COLUMN updated_at TYPE TIMESTAMPTZ USING updated_at AT TIME ZONE 'UTC';

-- Set default to store in UTC
ALTER TABLE drafts
ALTER COLUMN created_at SET DEFAULT NOW() AT TIME ZONE 'UTC';

ALTER TABLE drafts
ALTER COLUMN updated_at SET DEFAULT NOW() AT TIME ZONE 'UTC';

-- Migration completed successfully