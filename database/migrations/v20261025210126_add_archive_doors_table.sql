-- Migration: 20261025210126 - add archive_doors table for Internet Archive streamable games

CREATE TABLE IF NOT EXISTS archive_doors (
    id SERIAL PRIMARY KEY,
    door_id VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    short_name VARCHAR(50),
    author VARCHAR(100),
    game_version VARCHAR(50),
    release_year INTEGER,
    description TEXT,
    genre JSONB NOT NULL DEFAULT '[]'::jsonb,
    players VARCHAR(100),
    archive_item_id VARCHAR(100) NOT NULL,
    icon_data BYTEA,
    icon_mime VARCHAR(50),
    icon_size INTEGER,
    screenshot_data BYTEA,
    screenshot_mime VARCHAR(50),
    screenshot_size INTEGER,
    enabled BOOLEAN NOT NULL DEFAULT true,
    credit_cost INTEGER NOT NULL DEFAULT 0,
    max_time_minutes INTEGER NOT NULL DEFAULT 60,
    allow_anonymous BOOLEAN NOT NULL DEFAULT false,
    hide_from_web BOOLEAN NOT NULL DEFAULT false,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_archive_doors_enabled ON archive_doors (enabled);
