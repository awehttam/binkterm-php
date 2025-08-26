-- Add nodelist support to binkterm-php
-- Migration: add_nodelist_support.sql

-- Enhanced nodelist table to replace/extend existing basic 'nodes' table
CREATE TABLE IF NOT EXISTS nodelist (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    zone INTEGER NOT NULL,
    net INTEGER NOT NULL,
    node INTEGER NOT NULL,
    point INTEGER DEFAULT 0,
    keyword_type VARCHAR(10), -- Zone, Region, Host, Hub, Pvt, or null for normal nodes
    system_name VARCHAR(100),
    location VARCHAR(100),
    sysop_name VARCHAR(100),
    phone VARCHAR(50),
    baud_rate INTEGER,
    flags TEXT, -- JSON array of flags
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(zone, net, node, point)
);

-- Nodelist metadata table
CREATE TABLE IF NOT EXISTS nodelist_metadata (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename VARCHAR(50) NOT NULL,
    day_of_year INTEGER NOT NULL,
    release_date DATE NOT NULL,
    crc_checksum VARCHAR(10),
    imported_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    total_nodes INTEGER DEFAULT 0,
    is_active BOOLEAN DEFAULT 1
);

-- Nodelist flags lookup table for easier querying
CREATE TABLE IF NOT EXISTS nodelist_flags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nodelist_id INTEGER NOT NULL,
    flag_name VARCHAR(20) NOT NULL,
    flag_value VARCHAR(50),
    FOREIGN KEY (nodelist_id) REFERENCES nodelist(id) ON DELETE CASCADE
);

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_nodelist_address ON nodelist(zone, net, node, point);
CREATE INDEX IF NOT EXISTS idx_nodelist_zone ON nodelist(zone);
CREATE INDEX IF NOT EXISTS idx_nodelist_net ON nodelist(net);
CREATE INDEX IF NOT EXISTS idx_nodelist_keyword ON nodelist(keyword_type);
CREATE INDEX IF NOT EXISTS idx_nodelist_sysop ON nodelist(sysop_name);
CREATE INDEX IF NOT EXISTS idx_nodelist_location ON nodelist(location);
CREATE INDEX IF NOT EXISTS idx_nodelist_flags_node ON nodelist_flags(nodelist_id);
CREATE INDEX IF NOT EXISTS idx_nodelist_flags_name ON nodelist_flags(flag_name);
CREATE INDEX IF NOT EXISTS idx_nodelist_metadata_active ON nodelist_metadata(is_active);
CREATE INDEX IF NOT EXISTS idx_nodelist_metadata_date ON nodelist_metadata(release_date);