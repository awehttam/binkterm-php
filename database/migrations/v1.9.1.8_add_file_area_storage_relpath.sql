-- Add relative storage path for file areas
ALTER TABLE file_areas
    ADD COLUMN IF NOT EXISTS storage_relpath TEXT;
