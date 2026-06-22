-- Migration: 20260622183830 - auto feed poster name and multi echoareas
-- Created: 2026-06-22 18:38:30 UTC

ALTER TABLE auto_feed_sources
    ADD COLUMN poster_name VARCHAR(100);

UPDATE auto_feed_sources f
SET poster_name = COALESCE(NULLIF(BTRIM(u.real_name), ''), NULLIF(BTRIM(u.username), ''), 'Auto Feed')
FROM users u
WHERE u.id = f.post_as_user_id
  AND (f.poster_name IS NULL OR BTRIM(f.poster_name) = '');

UPDATE auto_feed_sources
SET poster_name = 'Auto Feed'
WHERE poster_name IS NULL OR BTRIM(poster_name) = '';

ALTER TABLE auto_feed_sources
    ALTER COLUMN poster_name SET NOT NULL;

CREATE TABLE auto_feed_source_echoareas (
    auto_feed_source_id INTEGER NOT NULL REFERENCES auto_feed_sources(id) ON DELETE CASCADE,
    echoarea_id INTEGER NOT NULL REFERENCES echoareas(id) ON DELETE CASCADE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (auto_feed_source_id, echoarea_id)
);

INSERT INTO auto_feed_source_echoareas (auto_feed_source_id, echoarea_id, created_at)
SELECT id, echoarea_id, NOW()
FROM auto_feed_sources
WHERE echoarea_id IS NOT NULL
ON CONFLICT (auto_feed_source_id, echoarea_id) DO NOTHING;

ALTER TABLE auto_feed_sources
    DROP COLUMN echoarea_id,
    DROP COLUMN post_as_user_id;
