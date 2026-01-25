-- PostgreSQL Schema for binkterm-php
-- Converted from SQLite schema

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    real_name VARCHAR(100),
    fidonet_address VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    is_admin BOOLEAN DEFAULT FALSE
);

-- Case-insensitive unique constraint on real_name
CREATE UNIQUE INDEX IF NOT EXISTS users_real_name_lower_idx ON users (LOWER(real_name));

-- Sessions table
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    ip_address INET,
    user_agent TEXT
);

-- Fidonet nodes table
CREATE TABLE IF NOT EXISTS nodes (
    id SERIAL PRIMARY KEY,
    address VARCHAR(20) UNIQUE NOT NULL,
    system_name VARCHAR(100),
    sysop_name VARCHAR(100),
    location VARCHAR(100),
    phone VARCHAR(50),
    baud_rate INTEGER,
    flags TEXT,
    last_seen TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE
);

-- Echo areas table
CREATE TABLE IF NOT EXISTS echoareas (
    id SERIAL PRIMARY KEY,
    tag VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    moderator VARCHAR(100),
    uplink_address VARCHAR(20),
    color VARCHAR(7) DEFAULT '#28a745',
    is_active BOOLEAN DEFAULT TRUE,
    message_count INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Netmail messages table
CREATE TABLE IF NOT EXISTS netmail (
    id SERIAL PRIMARY KEY,
    user_id INTEGER REFERENCES users(id),
    from_address VARCHAR(20) NOT NULL,
    to_address VARCHAR(20) NOT NULL,
    from_name VARCHAR(100) NOT NULL,
    to_name VARCHAR(100) NOT NULL,
    subject VARCHAR(255),
    message_text TEXT,
    date_written TIMESTAMP,
    date_received TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    attributes INTEGER DEFAULT 0,
    is_read BOOLEAN DEFAULT FALSE,
    is_sent BOOLEAN DEFAULT FALSE,
    reply_to_id INTEGER REFERENCES netmail(id)
);

-- Echomail messages table
CREATE TABLE IF NOT EXISTS echomail (
    id SERIAL PRIMARY KEY,
    echoarea_id INTEGER NOT NULL REFERENCES echoareas(id),
    from_address VARCHAR(20) NOT NULL,
    from_name VARCHAR(100) NOT NULL,
    to_name VARCHAR(100),
    subject VARCHAR(255),
    message_text TEXT,
    date_written TIMESTAMP,
    date_received TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reply_to_id INTEGER REFERENCES echomail(id),
    message_id VARCHAR(100),
    origin_line VARCHAR(255),
    kludge_lines TEXT
);

-- Packet tracking table
CREATE TABLE IF NOT EXISTS packets (
    id SERIAL PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    from_address VARCHAR(20),
    to_address VARCHAR(20),
    packet_type VARCHAR(10), -- 'IN' or 'OUT'
    status VARCHAR(20) DEFAULT 'pending', -- pending, processed, error
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP,
    error_message TEXT
);

-- Message links (for threading)
CREATE TABLE IF NOT EXISTS message_links (
    id SERIAL PRIMARY KEY,
    message_type VARCHAR(10) NOT NULL, -- 'netmail' or 'echomail'
    message_id INTEGER NOT NULL,
    reply_id VARCHAR(100),
    see_also_id VARCHAR(100)
);

-- User preferences/settings
CREATE TABLE IF NOT EXISTS user_settings (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    timezone VARCHAR(50) DEFAULT 'America/Los_Angeles',
    messages_per_page INTEGER DEFAULT 25,
    theme VARCHAR(20) DEFAULT 'light',
    show_origin BOOLEAN DEFAULT TRUE,
    show_tearline BOOLEAN DEFAULT TRUE,
    auto_refresh BOOLEAN DEFAULT FALSE
);

-- Message read status tracking
CREATE TABLE IF NOT EXISTS message_read_status (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    message_id INTEGER NOT NULL,
    message_type VARCHAR(10) NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, message_id, message_type)
);

-- User sessions table (renamed from sessions to avoid conflicts)
CREATE TABLE IF NOT EXISTS user_sessions (
    session_id VARCHAR(128) PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    ip_address INET,
    user_agent TEXT
);

-- Pending user registrations (awaiting admin approval)
CREATE TABLE IF NOT EXISTS pending_users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    real_name VARCHAR(100),
    reason TEXT,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address INET,
    user_agent TEXT,
    status VARCHAR(20) DEFAULT 'pending', -- pending, approved, rejected
    reviewed_by INTEGER REFERENCES users(id),
    reviewed_at TIMESTAMP,
    admin_notes TEXT
);

-- Case-insensitive unique constraint on real_name for pending users
CREATE UNIQUE INDEX IF NOT EXISTS pending_users_real_name_lower_idx ON pending_users (LOWER(real_name));

-- Create indexes
CREATE INDEX IF NOT EXISTS idx_users_fidonet_address ON users(fidonet_address);
CREATE INDEX IF NOT EXISTS idx_netmail_user_id ON netmail(user_id);
CREATE INDEX IF NOT EXISTS idx_netmail_to_address ON netmail(to_address);
CREATE INDEX IF NOT EXISTS idx_netmail_from_address ON netmail(from_address);
CREATE INDEX IF NOT EXISTS idx_netmail_date ON netmail(date_written);
CREATE INDEX IF NOT EXISTS idx_echomail_echoarea ON echomail(echoarea_id);
CREATE INDEX IF NOT EXISTS idx_echomail_date ON echomail(date_written);
CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);
CREATE INDEX IF NOT EXISTS idx_user_sessions_user ON user_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_user_sessions_expires ON user_sessions(expires_at);
CREATE INDEX IF NOT EXISTS idx_message_read_status_user ON message_read_status(user_id);
CREATE INDEX IF NOT EXISTS idx_message_read_status_message ON message_read_status(message_id, message_type);

-- Insert default echo areas
INSERT INTO echoareas (tag, description, uplink_address, color, is_local, domain) VALUES
    ('GENERAL', 'General Discussion', '', '#28a745', TRUE, 'local'),
    ('LOCALTEST', 'Local Testing Area', '', '#17a2b8',TRUE, 'local')
ON CONFLICT (tag) DO NOTHING;

-- Insert default admin user (password: admin123)
INSERT INTO users (username, password_hash, real_name, is_admin) VALUES 
    ('admin', '$2y$12$rlwxN0Ov3N0.B9DD0DzDxOQfTNa1k6ITH.mWauNacqes0KNpA4bOu', 'System Administrator', TRUE)
ON CONFLICT (username) DO NOTHING;