-- Migration: 1.8.9 - Add Community Wireless Node List WebDoor

-- Community Wireless Networks table
CREATE TABLE IF NOT EXISTS cwn_networks (
    id SERIAL PRIMARY KEY,
    ssid VARCHAR(100) NOT NULL,
    latitude DECIMAL(10, 3) NOT NULL,  -- WGS84, 3 decimal places (~111m precision)
    longitude DECIMAL(10, 3) NOT NULL,
    description TEXT NOT NULL,
    wifi_password VARCHAR(100),        -- Optional, for public networks
    network_type VARCHAR(50),          -- mesh, bbs, community, experimental, etc.
    submitted_by INT NOT NULL REFERENCES users(id),
    submitted_by_username VARCHAR(50) NOT NULL,
    bbs_name VARCHAR(50) NOT NULL,     -- For future federation
    date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    date_verified TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Composite unique constraint on SSID + location
    UNIQUE(ssid, latitude, longitude)
);

-- Search history for analytics
CREATE TABLE IF NOT EXISTS cwn_searches (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id),
    search_type VARCHAR(50),           -- location, radius, keyword
    search_query TEXT,
    results_count INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Usage tracking
CREATE TABLE IF NOT EXISTS cwn_sessions (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id),
    session_started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    session_ended_at TIMESTAMP,
    actions_taken INT DEFAULT 0,       -- Number of submissions/searches
    credits_earned INT DEFAULT 0,
    credits_spent INT DEFAULT 0
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_cwn_networks_location ON cwn_networks(latitude, longitude);
CREATE INDEX IF NOT EXISTS idx_cwn_networks_active ON cwn_networks(is_active, date_added DESC);
CREATE INDEX IF NOT EXISTS idx_cwn_networks_user ON cwn_networks(submitted_by);
CREATE INDEX IF NOT EXISTS idx_cwn_searches_user ON cwn_searches(user_id, created_at DESC);
