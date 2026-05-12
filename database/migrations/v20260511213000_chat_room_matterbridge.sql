-- Migration: v20260511213000_chat_room_matterbridge.sql
-- Description: Add Matterbridge metadata to local chat rooms
-- Date: 2026-05-11

ALTER TABLE chat_rooms
    ADD COLUMN IF NOT EXISTS matterbridge_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS matterbridge_gateway VARCHAR(100),
    ADD COLUMN IF NOT EXISTS matterbridge_options JSONB NOT NULL DEFAULT '{}'::jsonb;
