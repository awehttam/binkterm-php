-- Migration: v1.7.7_webdoor.sql
-- Description: Add WebDoor (web doors/games) support tables
-- Date: 2026-01-26

-- WebDoor game sessions
CREATE TABLE IF NOT EXISTS webdoor_sessions (
    id SERIAL PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL UNIQUE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    game_id VARCHAR(64) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    ended_at TIMESTAMP,
    playtime_seconds INTEGER DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_webdoor_sessions_session_id ON webdoor_sessions(session_id);
CREATE INDEX IF NOT EXISTS idx_webdoor_sessions_user_id ON webdoor_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_webdoor_sessions_game_id ON webdoor_sessions(game_id);

-- WebDoor game storage (save games)
CREATE TABLE IF NOT EXISTS webdoor_storage (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    game_id VARCHAR(64) NOT NULL,
    slot INTEGER NOT NULL DEFAULT 0,
    data JSONB NOT NULL DEFAULT '{}',
    metadata JSONB NOT NULL DEFAULT '{}',
    saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, game_id, slot)
);

CREATE INDEX IF NOT EXISTS idx_webdoor_storage_user_game ON webdoor_storage(user_id, game_id);

-- WebDoor leaderboards
CREATE TABLE IF NOT EXISTS webdoor_leaderboards (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    game_id VARCHAR(64) NOT NULL,
    board VARCHAR(64) NOT NULL DEFAULT 'default',
    score INTEGER NOT NULL,
    metadata JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_webdoor_leaderboards_game_board ON webdoor_leaderboards(game_id, board);
CREATE INDEX IF NOT EXISTS idx_webdoor_leaderboards_score ON webdoor_leaderboards(game_id, board, score DESC);
CREATE INDEX IF NOT EXISTS idx_webdoor_leaderboards_user ON webdoor_leaderboards(user_id, game_id, board);
CREATE INDEX IF NOT EXISTS idx_webdoor_leaderboards_created ON webdoor_leaderboards(created_at);

-- Comments for documentation
COMMENT ON TABLE webdoor_sessions IS 'Active WebDoor game sessions';
COMMENT ON TABLE webdoor_storage IS 'WebDoor game save data per user/game/slot';
COMMENT ON TABLE webdoor_leaderboards IS 'WebDoor game leaderboard entries';
