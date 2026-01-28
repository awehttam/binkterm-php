-- Migration: v1.7.9_chat.sql
-- Description: Add chat rooms and messages for multi-user chat
-- Date: 2026-01-28

CREATE TABLE IF NOT EXISTS chat_rooms (
    id SERIAL PRIMARY KEY,
    name VARCHAR(64) NOT NULL UNIQUE,
    description VARCHAR(255),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS chat_messages (
    id SERIAL PRIMARY KEY,
    room_id INTEGER REFERENCES chat_rooms(id) ON DELETE CASCADE,
    from_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    to_user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CHECK (
        (room_id IS NOT NULL AND to_user_id IS NULL)
        OR (room_id IS NULL AND to_user_id IS NOT NULL)
    )
);

CREATE INDEX IF NOT EXISTS idx_chat_messages_room_id ON chat_messages(room_id, id);
CREATE INDEX IF NOT EXISTS idx_chat_messages_to_user ON chat_messages(to_user_id, id);
CREATE INDEX IF NOT EXISTS idx_chat_messages_from_user ON chat_messages(from_user_id, id);
CREATE INDEX IF NOT EXISTS idx_chat_messages_created ON chat_messages(created_at);

INSERT INTO chat_rooms (name, description, is_active)
SELECT 'Lobby', 'General chat lobby', TRUE
WHERE NOT EXISTS (SELECT 1 FROM chat_rooms WHERE name = 'Lobby');
