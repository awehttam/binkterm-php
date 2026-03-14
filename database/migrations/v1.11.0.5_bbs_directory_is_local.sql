-- Migration: 1.11.0.5 - Add is_local flag to bbs_directory
-- Entries marked is_local=TRUE are protected from automated updates
-- (robot processors, import scripts) and may only be edited via the admin UI.

ALTER TABLE bbs_directory
    ADD COLUMN IF NOT EXISTS is_local BOOLEAN NOT NULL DEFAULT FALSE;
