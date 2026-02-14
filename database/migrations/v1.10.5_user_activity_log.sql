-- Migration: v1.10.5 - User Activity Log
-- Creates lookup tables for activity categories and types, and the main activity log table.

-- Category lookup table
CREATE TABLE IF NOT EXISTS activity_categories (
    id SMALLINT PRIMARY KEY,
    name VARCHAR(20) NOT NULL UNIQUE
);

INSERT INTO activity_categories (id, name) VALUES
    (1, 'message'),
    (2, 'file'),
    (3, 'door'),
    (4, 'nodelist'),
    (5, 'chat'),
    (6, 'auth')
ON CONFLICT (id) DO NOTHING;

-- Activity type lookup table (references category)
CREATE TABLE IF NOT EXISTS activity_types (
    id SMALLINT PRIMARY KEY,
    category_id SMALLINT NOT NULL REFERENCES activity_categories(id),
    name VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL
);

INSERT INTO activity_types (id, category_id, name, label) VALUES
    (1,  1, 'echomail_area_view', 'Echoarea Viewed'),
    (2,  1, 'echomail_send',      'Echomail Sent'),
    (3,  1, 'netmail_read',       'Netmail Read'),
    (4,  1, 'netmail_send',       'Netmail Sent'),
    (5,  2, 'filearea_view',      'File Area Viewed'),
    (6,  2, 'file_download',      'File Downloaded'),
    (7,  2, 'file_upload',        'File Uploaded'),
    (8,  3, 'webdoor_play',       'WebDoor Played'),
    (9,  3, 'dosdoor_play',       'DOS Door Played'),
    (10, 4, 'nodelist_view',      'Nodelist Viewed'),
    (11, 4, 'node_view',          'Node Viewed'),
    (12, 5, 'chat_send',          'Chat Message Sent'),
    (13, 6, 'login',              'Login')
ON CONFLICT (id) DO NOTHING;

-- Main activity log table
CREATE TABLE IF NOT EXISTS user_activity_log (
    id BIGSERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    activity_type_id SMALLINT NOT NULL REFERENCES activity_types(id),
    object_id INTEGER,
    object_name VARCHAR(255),
    meta JSONB,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_activity_user_id    ON user_activity_log(user_id);
CREATE INDEX IF NOT EXISTS idx_activity_type_id    ON user_activity_log(activity_type_id);
CREATE INDEX IF NOT EXISTS idx_activity_created_at ON user_activity_log(created_at);
CREATE INDEX IF NOT EXISTS idx_activity_object_name ON user_activity_log(object_name) WHERE object_name IS NOT NULL;
