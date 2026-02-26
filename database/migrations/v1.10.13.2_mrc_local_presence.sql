-- Add table for local (webdoor) MRC presence tracking

CREATE TABLE IF NOT EXISTS mrc_local_presence (
    id SERIAL PRIMARY KEY,
    username VARCHAR(30) NOT NULL,
    bbs_name VARCHAR(64) NOT NULL,
    room_name VARCHAR(30) NOT NULL REFERENCES mrc_rooms(room_name) ON DELETE CASCADE,
    ip_address VARCHAR(50),
    connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(username, bbs_name, room_name)
);

CREATE INDEX IF NOT EXISTS idx_mrc_local_presence_room ON mrc_local_presence(room_name);
CREATE INDEX IF NOT EXISTS idx_mrc_local_presence_last_seen ON mrc_local_presence(last_seen);
