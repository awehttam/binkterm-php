-- Migration: 1.11.0.7 - Add latitude/longitude coordinates to bbs_directory

ALTER TABLE bbs_directory
    ADD COLUMN IF NOT EXISTS latitude DOUBLE PRECISION,
    ADD COLUMN IF NOT EXISTS longitude DOUBLE PRECISION;

CREATE INDEX IF NOT EXISTS idx_bbs_directory_coordinates
    ON bbs_directory(latitude, longitude);
