-- Migration: v1.11.0.23 - Replace unique file hash constraint with plain index
--
-- The UNIQUE(file_area_id, file_hash) constraint breaks local netmail attachment
-- delivery when the same file is sent more than once to the same recipient.
-- The INSERT for the second attachment throws a constraint violation after the
-- file has already been moved from the temp dir, leaving an orphaned file on
-- disk with no database record.
--
-- Private areas legitimately receive the same file multiple times (e.g. the
-- same attachment from different netmail messages).  Drop the unique constraint
-- and replace it with a plain index to keep lookup performance.

ALTER TABLE files DROP CONSTRAINT IF EXISTS unique_file_hash_per_area;

CREATE INDEX IF NOT EXISTS idx_files_area_hash ON files(file_area_id, file_hash);
