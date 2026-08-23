-- Migration: 20260823005727 - rlogin doors table
-- Created: 2026-08-23 00:57:27 UTC
--
-- RLogin doors have no filesystem footprint (no executable, no manifest
-- directory) -- unlike DOS/Native/JS-DOS/WebDoors, which are all backed by
-- files on disk. This table is the sole source of truth for RLogin door
-- definitions, including uploaded icon/screenshot images stored as BYTEA,
-- replacing the file-manifest + JSON-config pattern used by the other door
-- types. Rows are synced into dosbox_doors (door_type='rlogin') the same way
-- the other door types are, so door_sessions' FK and shared session
-- infrastructure keep working unchanged.

CREATE TABLE IF NOT EXISTS rlogin_doors (
    id SERIAL PRIMARY KEY,
    door_id VARCHAR(50) UNIQUE NOT NULL,

    -- Game/service metadata
    name VARCHAR(100) NOT NULL,
    short_name VARCHAR(50),
    author VARCHAR(100),
    game_version VARCHAR(50),
    release_year INTEGER,
    description TEXT,
    genre JSONB NOT NULL DEFAULT '[]',
    players VARCHAR(100),
    icon_data BYTEA,
    icon_mime VARCHAR(50),
    screenshot_data BYTEA,
    screenshot_mime VARCHAR(50),

    -- Connection / rlogin handshake settings
    bbs_type VARCHAR(30) NOT NULL DEFAULT 'plain_rlogin',
    host VARCHAR(255) NOT NULL,
    port INTEGER NOT NULL DEFAULT 513,
    client_username VARCHAR(255) NOT NULL DEFAULT '{user_name}',
    server_username VARCHAR(255) NOT NULL DEFAULT '{user_name}',
    terminal_type VARCHAR(100) NOT NULL DEFAULT 'xterm-256color',
    terminal_speed INTEGER NOT NULL DEFAULT 38400,
    output_encoding VARCHAR(20) NOT NULL DEFAULT 'utf8',
    pre_login_command TEXT,
    pre_login_timeout INTEGER NOT NULL DEFAULT 10,

    -- Requirements
    admin_only BOOLEAN NOT NULL DEFAULT false,

    -- Runtime config
    enabled BOOLEAN NOT NULL DEFAULT false,
    credit_cost INTEGER NOT NULL DEFAULT 0,
    max_time_minutes INTEGER NOT NULL DEFAULT 30,
    max_sessions INTEGER NOT NULL DEFAULT 10,
    allow_anonymous BOOLEAN NOT NULL DEFAULT false,
    guest_max_sessions INTEGER NOT NULL DEFAULT 2,
    hide_from_web BOOLEAN NOT NULL DEFAULT false,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
