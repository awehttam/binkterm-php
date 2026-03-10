-- Migration: 1.11 - BBS Directory and Echomail Robot Framework

-- Generic echomail robot rules table
CREATE TABLE IF NOT EXISTS echomail_robots (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    echoarea_id INTEGER NOT NULL REFERENCES echoareas(id) ON DELETE CASCADE,
    subject_pattern VARCHAR(255),
    processor_type VARCHAR(100) NOT NULL,
    processor_config JSONB DEFAULT '{}',
    enabled BOOLEAN DEFAULT TRUE,
    last_processed_echomail_id INTEGER DEFAULT 0,
    last_run_at TIMESTAMPTZ,
    messages_examined INTEGER DEFAULT 0,
    messages_processed INTEGER DEFAULT 0,
    last_error TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_echomail_robots_echoarea ON echomail_robots(echoarea_id);
CREATE INDEX IF NOT EXISTS idx_echomail_robots_enabled ON echomail_robots(enabled);

-- BBS directory listings
CREATE TABLE IF NOT EXISTS bbs_directory (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sysop VARCHAR(100),
    location VARCHAR(100),
    os VARCHAR(50),
    telnet_host VARCHAR(255),
    telnet_port INTEGER DEFAULT 23,
    website VARCHAR(255),
    notes TEXT,
    source VARCHAR(20) DEFAULT 'manual',
    last_seen TIMESTAMPTZ,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_bbs_directory_name_lower ON bbs_directory(LOWER(name));
CREATE INDEX IF NOT EXISTS idx_bbs_directory_is_active ON bbs_directory(is_active);
CREATE INDEX IF NOT EXISTS idx_bbs_directory_last_seen ON bbs_directory(last_seen DESC NULLS LAST);
