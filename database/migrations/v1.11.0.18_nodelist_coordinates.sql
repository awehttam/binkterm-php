-- Migration: v1.11.0.18 - Add geocoding coordinates to nodelist table

ALTER TABLE nodelist
    ADD COLUMN IF NOT EXISTS latitude  DECIMAL(10,6) NULL,
    ADD COLUMN IF NOT EXISTS longitude DECIMAL(10,6) NULL;

CREATE INDEX IF NOT EXISTS idx_nodelist_coordinates
    ON nodelist (latitude, longitude)
    WHERE latitude IS NOT NULL AND longitude IS NOT NULL;
