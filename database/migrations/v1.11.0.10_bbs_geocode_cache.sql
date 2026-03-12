-- Migration: 1.11.0.10 - Add BBS directory geocode cache

CREATE TABLE IF NOT EXISTS bbs_directory_geocode_cache (
    id SERIAL PRIMARY KEY,
    location_key VARCHAR(64) NOT NULL UNIQUE,
    normalized_location VARCHAR(255) NOT NULL,
    latitude DECIMAL(10,6) NULL,
    longitude DECIMAL(10,6) NULL,
    cached_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_bbs_directory_geocode_cache_cached_at
    ON bbs_directory_geocode_cache (cached_at);
