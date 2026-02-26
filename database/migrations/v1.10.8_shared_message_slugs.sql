-- Migration: v1.10.8 — Add friendly slug URLs to shared_messages
-- Allows shares to be accessed via /shared/{area}@{domain}/{slug}
-- in addition to the existing /shared/{32-char-hex} token URLs.

ALTER TABLE shared_messages
    ADD COLUMN IF NOT EXISTS area_identifier VARCHAR(200) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS slug VARCHAR(200) DEFAULT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS idx_shared_messages_area_slug
    ON shared_messages (area_identifier, slug)
    WHERE area_identifier IS NOT NULL AND slug IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_shared_messages_slug
    ON shared_messages (slug)
    WHERE slug IS NOT NULL;
