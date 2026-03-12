-- Migration: 1.11.0.9 - Add optional art format hint to echoareas

ALTER TABLE echoareas
    ADD COLUMN IF NOT EXISTS art_format_hint VARCHAR(32);
