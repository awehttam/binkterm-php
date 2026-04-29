-- Packet radio / MeshCore BBS interface

CREATE TABLE packet_bbs_nodes (
    id SERIAL PRIMARY KEY,
    node_id VARCHAR(64) NOT NULL UNIQUE,
    handle VARCHAR(64),
    interface_type VARCHAR(32) NOT NULL DEFAULT 'meshcore',
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    last_seen_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE TABLE packet_bbs_sessions (
    id SERIAL PRIMARY KEY,
    node_id VARCHAR(64) NOT NULL REFERENCES packet_bbs_nodes(node_id) ON DELETE CASCADE,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    menu_state VARCHAR(64) NOT NULL DEFAULT 'main',
    pagination_cursor INTEGER NOT NULL DEFAULT 1,
    pagination_context TEXT,
    compose_buffer TEXT,
    compose_type VARCHAR(16),
    compose_meta JSONB,
    last_activity_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    UNIQUE (node_id)
);

CREATE TABLE packet_bbs_outbound_queue (
    id SERIAL PRIMARY KEY,
    node_id VARCHAR(64) NOT NULL,
    payload TEXT NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    sent_at TIMESTAMP WITH TIME ZONE,
    retries INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX ON packet_bbs_outbound_queue (node_id, sent_at NULLS FIRST);
