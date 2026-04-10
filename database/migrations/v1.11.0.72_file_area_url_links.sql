-- Add URL column to files table to support external link entries in file areas
ALTER TABLE files ADD COLUMN url TEXT DEFAULT NULL;

-- Allow NULL for file_hash and storage_path so URL-type link records (which
-- have no physical file) can be stored without dummy values.
ALTER TABLE files ALTER COLUMN file_hash DROP NOT NULL;
ALTER TABLE files ALTER COLUMN storage_path DROP NOT NULL;

-- The unique constraint on (file_area_id, file_hash) must also allow NULLs.
-- PostgreSQL treats NULLs as distinct in unique indexes, so multiple NULL
-- hashes in the same area will not collide — no schema change is needed for
-- the constraint itself.
