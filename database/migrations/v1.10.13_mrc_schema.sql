-- MRC (Multi Relay Chat) Schema Migration
-- Version 1.9.3.1
-- Creates tables for MRC chat system following MRC Protocol v1.3

-- MRC room list and metadata
CREATE TABLE IF NOT EXISTS mrc_rooms (
    room_name VARCHAR(30) PRIMARY KEY,
    topic VARCHAR(55),
    topic_set_by VARCHAR(30),
    topic_set_at TIMESTAMP,
    has_password BOOLEAN DEFAULT FALSE,
    join_part_messages BOOLEAN DEFAULT TRUE,
    topic_locked BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- MRC user presence tracking
CREATE TABLE IF NOT EXISTS mrc_users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(30) NOT NULL,
    bbs_name VARCHAR(64) NOT NULL,
    room_name VARCHAR(30) NOT NULL REFERENCES mrc_rooms(room_name) ON DELETE CASCADE,
    ip_address VARCHAR(50),
    connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_afk BOOLEAN DEFAULT FALSE,
    afk_message VARCHAR(55),
    UNIQUE(username, bbs_name, room_name)
);

-- MRC message history (last 1000 messages per room)
CREATE TABLE IF NOT EXISTS mrc_messages (
    id SERIAL PRIMARY KEY,
    from_user VARCHAR(30) NOT NULL,
    from_site VARCHAR(30) NOT NULL,
    from_room VARCHAR(30),
    to_user VARCHAR(30),
    to_room VARCHAR(30),
    message_body TEXT NOT NULL,
    msg_ext VARCHAR(30),
    is_private BOOLEAN DEFAULT FALSE,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- MRC outbound message queue
CREATE TABLE IF NOT EXISTS mrc_outbound (
    id SERIAL PRIMARY KEY,
    field1 VARCHAR(30) NOT NULL,  -- FromUser or CLIENT/SERVER
    field2 VARCHAR(120) NOT NULL, -- FromSite or command
    field3 VARCHAR(30),           -- FromRoom
    field4 VARCHAR(30),           -- ToUser
    field5 VARCHAR(30),           -- MsgExt
    field6 VARCHAR(30),           -- ToRoom
    field7 VARCHAR(140) NOT NULL, -- MessageBody
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP,
    priority INTEGER DEFAULT 0
);

-- MRC connection state (stores daemon state like last ping, connected status, etc.)
CREATE TABLE IF NOT EXISTS mrc_state (
    key VARCHAR(50) PRIMARY KEY,
    value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_mrc_messages_room_time ON mrc_messages(to_room, received_at);
CREATE INDEX IF NOT EXISTS idx_mrc_messages_private ON mrc_messages(to_user, received_at);
CREATE INDEX IF NOT EXISTS idx_mrc_messages_received ON mrc_messages(received_at DESC);
CREATE INDEX IF NOT EXISTS idx_mrc_outbound_pending ON mrc_outbound(sent_at, priority);
CREATE INDEX IF NOT EXISTS idx_mrc_users_room ON mrc_users(room_name);
CREATE INDEX IF NOT EXISTS idx_mrc_users_last_seen ON mrc_users(last_seen);

-- Insert initial state values
INSERT INTO mrc_state (key, value) VALUES ('connected', 'false') ON CONFLICT (key) DO NOTHING;
INSERT INTO mrc_state (key, value) VALUES ('last_ping', '0') ON CONFLICT (key) DO NOTHING;
INSERT INTO mrc_state (key, value) VALUES ('server_info', '{}') ON CONFLICT (key) DO NOTHING;
