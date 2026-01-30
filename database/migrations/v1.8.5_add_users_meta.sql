-- Migration: 1.8.5 - Add users_meta key/value store
-- Created: 2026-01-30

CREATE TABLE IF NOT EXISTS users_meta (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    keyname VARCHAR(100) NOT NULL,
    valname TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS users_meta_user_key_idx ON users_meta(user_id, keyname);
CREATE INDEX IF NOT EXISTS users_meta_key_idx ON users_meta(keyname);
