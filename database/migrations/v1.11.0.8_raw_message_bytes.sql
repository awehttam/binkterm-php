-- Migration: 1.11.0.8 - Preserve raw imported message bytes for art rendering

ALTER TABLE netmail
    ADD COLUMN IF NOT EXISTS raw_message_bytes BYTEA NULL,
    ADD COLUMN IF NOT EXISTS message_charset VARCHAR(32) NULL,
    ADD COLUMN IF NOT EXISTS art_format VARCHAR(32) NULL;

ALTER TABLE echomail
    ADD COLUMN IF NOT EXISTS raw_message_bytes BYTEA NULL,
    ADD COLUMN IF NOT EXISTS message_charset VARCHAR(32) NULL,
    ADD COLUMN IF NOT EXISTS art_format VARCHAR(32) NULL;
