-- Migration: v1.11.0.32 - File area echomail comments
-- Adds a linked comment echoarea to file areas and a cached comment count to files.

ALTER TABLE file_areas
    ADD COLUMN IF NOT EXISTS comment_echoarea_id INTEGER REFERENCES echoareas(id) ON DELETE SET NULL;

ALTER TABLE files
    ADD COLUMN IF NOT EXISTS comment_count INTEGER NOT NULL DEFAULT 0;

COMMENT ON COLUMN file_areas.comment_echoarea_id IS
    'Optional linked echomail area for file comments. NULL = comments disabled for this area.';

COMMENT ON COLUMN files.comment_count IS
    'Cached count of echomail comments for this file. Updated when comments are posted via the web UI.';
