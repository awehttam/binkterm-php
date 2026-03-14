-- Rename bbs_directory_geocode_cache to geocode_cache so it serves as a
-- shared geocoding service for both the BBS Directory and the Nodelist map.

ALTER TABLE bbs_directory_geocode_cache RENAME TO geocode_cache;

ALTER INDEX idx_bbs_directory_geocode_cache_cached_at
    RENAME TO idx_geocode_cache_cached_at;
