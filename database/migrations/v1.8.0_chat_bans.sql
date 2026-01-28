-- Migration: v1.8.0_chat_bans.sql
-- Description: Add chat room bans (kick/ban)
-- Date: 2026-01-28

CREATE TABLE IF NOT EXISTS chat_room_bans (
    id SERIAL PRIMARY KEY,
    room_id INTEGER NOT NULL REFERENCES chat_rooms(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    banned_by INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP,
    UNIQUE(room_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_chat_room_bans_active ON chat_room_bans(room_id, user_id, expires_at);
