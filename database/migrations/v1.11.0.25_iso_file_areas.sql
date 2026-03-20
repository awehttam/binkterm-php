-- v1.11.0.25 ISO-backed file areas support
ALTER TABLE file_areas
    ADD COLUMN IF NOT EXISTS area_type        VARCHAR(20)  NOT NULL DEFAULT 'normal',
    ADD COLUMN IF NOT EXISTS iso_file_path    TEXT,
    ADD COLUMN IF NOT EXISTS iso_mount_point  TEXT,
    ADD COLUMN IF NOT EXISTS iso_mount_status VARCHAR(20),
    ADD COLUMN IF NOT EXISTS iso_mount_error  TEXT,
    ADD COLUMN IF NOT EXISTS iso_last_indexed TIMESTAMPTZ;

ALTER TABLE files
    ADD COLUMN IF NOT EXISTS iso_rel_path TEXT;

CREATE INDEX IF NOT EXISTS idx_files_iso_rel_path
    ON files (file_area_id, iso_rel_path)
    WHERE iso_rel_path IS NOT NULL;
