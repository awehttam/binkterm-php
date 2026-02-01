-- File Areas System
-- Migration for TIC file support and file distribution

-- File areas configuration (similar to echoareas)
CREATE TABLE file_areas (
    id SERIAL PRIMARY KEY,
    tag VARCHAR(255) NOT NULL,
    description TEXT,
    domain VARCHAR(255) NOT NULL DEFAULT 'fidonet',
    is_local BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,

    -- File-specific settings
    max_file_size BIGINT DEFAULT 10485760, -- 10MB default
    allowed_extensions TEXT, -- Comma-separated: .txt,.zip,.pdf
    blocked_extensions TEXT, -- Comma-separated: .exe,.bat,.com
    replace_existing BOOLEAN DEFAULT FALSE, -- If true, replace files with same name instead of versioning

    -- Statistics
    file_count INTEGER DEFAULT 0,
    total_size BIGINT DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT unique_file_area_tag_domain UNIQUE(tag, domain)
);

-- File metadata and tracking
CREATE TABLE files (
    id SERIAL PRIMARY KEY,
    file_area_id INTEGER REFERENCES file_areas(id) ON DELETE CASCADE,

    -- File information
    filename VARCHAR(255) NOT NULL,
    filesize BIGINT NOT NULL,
    file_hash VARCHAR(64) NOT NULL, -- SHA256
    storage_path TEXT NOT NULL,

    -- Source information (from TIC file)
    uploaded_from_address VARCHAR(255), -- Fidonet address from TIC "From" field
    source_type VARCHAR(20) DEFAULT 'fidonet', -- 'user', 'fidonet', 'netmail_attachment'

    -- Descriptions (from TIC file)
    short_description VARCHAR(255), -- TIC "Desc" field
    long_description TEXT, -- TIC "LDesc" fields

    -- TIC routing information
    tic_path TEXT, -- TIC "Path" line
    tic_seenby TEXT, -- TIC "Seenby" line
    tic_crc VARCHAR(8), -- TIC "Crc" field (CRC32 checksum)

    -- Status
    status VARCHAR(20) DEFAULT 'approved', -- pending, approved, rejected, quarantined

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT unique_file_hash_per_area UNIQUE(file_area_id, file_hash)
);

-- Indexes for performance
CREATE INDEX idx_files_file_area_id ON files(file_area_id);
CREATE INDEX idx_files_status ON files(status);
CREATE INDEX idx_files_hash ON files(file_hash);
CREATE INDEX idx_files_created_at ON files(created_at DESC);

-- Create default file area
INSERT INTO file_areas (tag, description, domain, is_local) VALUES
('GENERAL_FILES', 'General purpose file area for miscellaneous files', 'fidonet', TRUE);
