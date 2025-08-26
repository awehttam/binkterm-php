-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    real_name VARCHAR(100),
    fidonet_address VARCHAR(50),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME,
    is_active BOOLEAN DEFAULT 1,
    is_admin BOOLEAN DEFAULT 0
);

-- Sessions table
CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Fidonet nodes table
CREATE TABLE IF NOT EXISTS nodes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    address VARCHAR(20) UNIQUE NOT NULL,
    system_name VARCHAR(100),
    sysop_name VARCHAR(100),
    location VARCHAR(100),
    phone VARCHAR(50),
    baud_rate INTEGER,
    flags TEXT,
    last_seen DATETIME,
    is_active BOOLEAN DEFAULT 1
);

-- Echo areas table
CREATE TABLE IF NOT EXISTS echoareas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tag VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    moderator VARCHAR(100),
    uplink_address VARCHAR(20),
    color VARCHAR(7) DEFAULT '#28a745',
    is_active BOOLEAN DEFAULT 1,
    message_count INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Netmail messages table
CREATE TABLE IF NOT EXISTS netmail (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    from_address VARCHAR(20) NOT NULL,
    to_address VARCHAR(20) NOT NULL,
    from_name VARCHAR(100) NOT NULL,
    to_name VARCHAR(100) NOT NULL,
    subject VARCHAR(255),
    message_text TEXT,
    date_written DATETIME,
    date_received DATETIME DEFAULT CURRENT_TIMESTAMP,
    attributes INTEGER DEFAULT 0,
    is_read BOOLEAN DEFAULT 0,
    is_sent BOOLEAN DEFAULT 0,
    reply_to_id INTEGER,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (reply_to_id) REFERENCES netmail(id)
);

-- Echomail messages table
CREATE TABLE IF NOT EXISTS echomail (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    echoarea_id INTEGER NOT NULL,
    from_address VARCHAR(20) NOT NULL,
    from_name VARCHAR(100) NOT NULL,
    to_name VARCHAR(100),
    subject VARCHAR(255),
    message_text TEXT,
    date_written DATETIME,
    date_received DATETIME DEFAULT CURRENT_TIMESTAMP,
    reply_to_id INTEGER,
    message_id VARCHAR(100),
    origin_line VARCHAR(255),
    kludge_lines TEXT,
    FOREIGN KEY (echoarea_id) REFERENCES echoareas(id),
    FOREIGN KEY (reply_to_id) REFERENCES echomail(id)
);

-- Packet tracking table
CREATE TABLE IF NOT EXISTS packets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename VARCHAR(255) NOT NULL,
    from_address VARCHAR(20),
    to_address VARCHAR(20),
    packet_type VARCHAR(10), -- 'IN' or 'OUT'
    status VARCHAR(20) DEFAULT 'pending', -- pending, processed, error
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME,
    error_message TEXT
);

-- Message links (for threading)
CREATE TABLE IF NOT EXISTS message_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message_type VARCHAR(10) NOT NULL, -- 'netmail' or 'echomail'
    message_id INTEGER NOT NULL,
    reply_id VARCHAR(100),
    see_also_id VARCHAR(100)
);

-- User preferences/settings
CREATE TABLE IF NOT EXISTS user_settings (
    user_id INTEGER PRIMARY KEY,
    timezone VARCHAR(50) DEFAULT 'America/Los_Angeles',
    messages_per_page INTEGER DEFAULT 25,
    theme VARCHAR(20) DEFAULT 'light',
    show_origin BOOLEAN DEFAULT 1,
    show_tearline BOOLEAN DEFAULT 1,
    auto_refresh BOOLEAN DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Message read status tracking
CREATE TABLE IF NOT EXISTS message_read_status (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    message_id INTEGER NOT NULL,
    message_type VARCHAR(10) NOT NULL,
    read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE(user_id, message_id, message_type)
);

-- User sessions table (renamed from sessions to avoid conflicts)
CREATE TABLE IF NOT EXISTS user_sessions (
    session_id VARCHAR(128) PRIMARY KEY,
    user_id INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

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
INSERT OR IGNORE INTO echoareas (tag, description, uplink_address, color) VALUES 
    ('GENERAL', 'General Discussion', '1:123/1', '#28a745'),
    ('LOCALTEST', 'Local Testing Area', '1:123/1', '#17a2b8'),
    ('FIDONET.NA', 'Fidonet North America', '1:123/1', '#dc3545'),
    ('SYSOP', 'Sysop Discussion', '1:123/1', '#ffc107');

-- Pending user registrations (awaiting admin approval)
CREATE TABLE IF NOT EXISTS pending_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    real_name VARCHAR(100),
    reason TEXT,
    requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    user_agent TEXT,
    status VARCHAR(20) DEFAULT 'pending', -- pending, approved, rejected
    reviewed_by INTEGER,
    reviewed_at DATETIME,
    admin_notes TEXT,
    FOREIGN KEY (reviewed_by) REFERENCES users(id)
);

-- Insert default admin user (password: admin123)
INSERT OR IGNORE INTO users (username, password_hash, real_name, is_admin) VALUES 
    ('admin', '$2y$12$rlwxN0Ov3N0.B9DD0DzDxOQfTNa1k6ITH.mWauNacqes0KNpA4bOu', 'System Administrator', 1);