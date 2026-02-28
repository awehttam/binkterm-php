-- Migration: 1.10.7 - Gemini Capsule Hosting
-- Creates the table for per-user Gemini capsule files

CREATE TABLE IF NOT EXISTS gemini_capsule_files (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    filename VARCHAR(255) NOT NULL,
    content TEXT NOT NULL DEFAULT '',
    is_published BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_gemini_capsule_user_filename
    ON gemini_capsule_files(user_id, filename);

CREATE INDEX IF NOT EXISTS idx_gemini_capsule_user_id
    ON gemini_capsule_files(user_id);

CREATE INDEX IF NOT EXISTS idx_gemini_capsule_published
    ON gemini_capsule_files(user_id, is_published);
