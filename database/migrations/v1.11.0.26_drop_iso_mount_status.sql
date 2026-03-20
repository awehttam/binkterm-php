-- Migration: 1.11.0.26 - Drop ISO auto-mount columns
-- ISO mounting is now handled manually by the sysop; these columns are no longer used.

ALTER TABLE file_areas DROP COLUMN IF EXISTS iso_mount_status;
ALTER TABLE file_areas DROP COLUMN IF EXISTS iso_mount_error;
ALTER TABLE file_areas DROP COLUMN IF EXISTS iso_file_path;
