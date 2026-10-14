-- Migration: 20261014051503 - user ansi avatars
-- Created: 2026-10-14 05:15:03 UTC

-- Account-owned Synchronet-compatible 10x6 CP437 BIN avatars.
-- The record is inert glyph/attribute data, exactly 120 bytes.
CREATE TABLE user_ansi_avatars (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    avatar_bin BYTEA NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT user_ansi_avatars_exact_size CHECK (octet_length(avatar_bin) = 120)
);
