-- Multi-Network Support Migration v1.5.0
-- This migration adds support for multiple FTN networks (FidoNet, DoveNet, etc.)

-- Networks table to define available FTN networks
CREATE TABLE IF NOT EXISTS networks (
    id SERIAL PRIMARY KEY,
    domain VARCHAR(20) UNIQUE NOT NULL,  -- e.g., 'fidonet', 'dovenet', 'fsxnet'
    name VARCHAR(100) NOT NULL,          -- e.g., 'FidoNet', 'DoveNet', 'fsxNet'
    description TEXT,                    -- Human-readable description
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Add network_id to echoareas table to associate areas with specific networks  
ALTER TABLE echoareas ADD COLUMN IF NOT EXISTS network_id INTEGER;

-- Create index for better performance
CREATE INDEX IF NOT EXISTS idx_echoareas_network_id ON echoareas(network_id);

-- Network-specific uplinks table (replaces the JSON uplinks configuration)
CREATE TABLE IF NOT EXISTS network_uplinks (
    id SERIAL PRIMARY KEY,
    network_id INTEGER NOT NULL REFERENCES networks(id) ON DELETE CASCADE,
    address VARCHAR(50) NOT NULL,       -- Full FTN address with domain
    hostname VARCHAR(255) NOT NULL,
    port INTEGER DEFAULT 24554,
    password VARCHAR(100),
    is_enabled BOOLEAN DEFAULT TRUE,
    is_default BOOLEAN DEFAULT FALSE,   -- Default uplink for this network
    compression BOOLEAN DEFAULT FALSE,
    crypt BOOLEAN DEFAULT FALSE,
    poll_schedule VARCHAR(50) DEFAULT '0 */4 * * *',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(network_id, address)
);

-- Create indexes
CREATE INDEX IF NOT EXISTS idx_network_uplinks_network ON network_uplinks(network_id);
CREATE INDEX IF NOT EXISTS idx_network_uplinks_address ON network_uplinks(address);
CREATE INDEX IF NOT EXISTS idx_network_uplinks_enabled ON network_uplinks(is_enabled);

-- Insert default FidoNet network
INSERT INTO networks (domain, name, description, is_active) 
VALUES ('fidonet', 'FidoNet', 'The original FidoNet network founded in 1984', TRUE)
ON CONFLICT (domain) DO NOTHING;

-- Migrate existing echoareas to FidoNet network
UPDATE echoareas 
SET network_id = (SELECT id FROM networks WHERE domain = 'fidonet')
WHERE network_id IS NULL;

-- Make network_id NOT NULL after migration
ALTER TABLE echoareas ALTER COLUMN network_id SET NOT NULL;

-- Add foreign key constraint after data migration
ALTER TABLE echoareas ADD CONSTRAINT fk_echoareas_network 
    FOREIGN KEY (network_id) REFERENCES networks(id) ON DELETE RESTRICT;

-- Add constraint to ensure only one default uplink per network
CREATE UNIQUE INDEX IF NOT EXISTS idx_network_uplinks_default 
ON network_uplinks(network_id) WHERE is_default = TRUE;

-- Add some common network domains as examples (commented out - uncomment to activate)
-- INSERT INTO networks (domain, name, description, is_active) VALUES
--     ('dovenet', 'DoveNet', 'DoveNet - A friendly FTN network', FALSE),
--     ('fsxnet', 'fsxNet', 'fsxNet - Another popular FTN network', FALSE),
--     ('micronet', 'MicroNet', 'MicroNet - Vintage computer focused network', FALSE)
-- ON CONFLICT (domain) DO NOTHING;