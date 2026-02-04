-- Allow duplicate content per area (optional)
ALTER TABLE file_areas
    ADD COLUMN IF NOT EXISTS allow_duplicate_hash BOOLEAN DEFAULT FALSE;
