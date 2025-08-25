-- Migration to add color field to echoareas table

-- Add color column if it doesn't exist
ALTER TABLE echoareas ADD COLUMN color VARCHAR(7) DEFAULT '#28a745';

-- Update existing echo areas with different colors
UPDATE echoareas SET color = '#28a745' WHERE tag = 'GENERAL';
UPDATE echoareas SET color = '#17a2b8' WHERE tag = 'LOCALTEST';
UPDATE echoareas SET color = '#dc3545' WHERE tag = 'FIDONET.NA';
UPDATE echoareas SET color = '#ffc107' WHERE tag = 'SYSOP';

-- Add LOCALTEST if it doesn't exist
INSERT OR IGNORE INTO echoareas (tag, description, uplink_address, color) VALUES 
    ('LOCALTEST', 'Local Testing Area', '1:123/1', '#17a2b8');