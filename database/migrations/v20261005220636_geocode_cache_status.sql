-- Migration: distinguish genuine geocoder no-result from a retryable
-- provider/network failure.
--
-- geocode_cache previously stored a null lat/lon for BOTH cases identically,
-- so a transient Nominatim outage during automated geo-backfill could create
-- a permanent null cache entry that would never be retried. Provider
-- failures are never written to this table at all (see
-- BbsDirectoryGeocoder::geocodeLocation()) -- only 'success' and 'no_result'
-- are ever persisted, so status only needs those two values.
--
-- Backward compatibility: existing rows have no way to know retroactively
-- whether a null lat/lon was a genuine no-result or an old provider failure
-- that predates this fix. They are classified as 'no_result' (the only
-- honest default -- we cannot know they were failures), and 'success' for
-- rows that already have coordinates. Historical null rows are NOT bulk
-- re-geocoded by this migration.
ALTER TABLE geocode_cache
    ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'no_result'
        CHECK (status IN ('success', 'no_result'));

UPDATE geocode_cache
    SET status = 'success'
    WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND status <> 'success';

UPDATE geocode_cache
    SET status = 'no_result'
    WHERE (latitude IS NULL OR longitude IS NULL) AND status <> 'no_result';
