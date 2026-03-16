-- Migration: v1.11.0.22 - Add subfolder support to files table
-- Allows files to be organized in virtual subfolders within a file area.
-- NULL means the file lives at the root of the area; a non-null value (e.g. 'incoming')
-- places the file in that named subfolder.

ALTER TABLE files ADD COLUMN IF NOT EXISTS subfolder VARCHAR(255) DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_files_area_subfolder ON files(file_area_id, subfolder);
