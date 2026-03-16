-- Enable pg_trgm for fast ILIKE / similarity searches
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Trigram indexes on files for fast full-text search
CREATE INDEX IF NOT EXISTS idx_files_filename_trgm
    ON files USING GIN (filename gin_trgm_ops);

CREATE INDEX IF NOT EXISTS idx_files_short_description_trgm
    ON files USING GIN (short_description gin_trgm_ops);

-- Composite index for area-scoped status lookups (already common)
CREATE INDEX IF NOT EXISTS idx_files_area_status
    ON files (file_area_id, status);
