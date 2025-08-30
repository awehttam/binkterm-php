-- Remove redundant uplink_address field from echoareas table
-- This field is no longer needed since uplinks are now managed per-network in network_uplinks table

-- First, let's backup any existing uplink_address data for reference
CREATE TABLE IF NOT EXISTS echoarea_uplink_backup AS 
SELECT id, tag, uplink_address, network_id 
FROM echoareas 
WHERE uplink_address IS NOT NULL AND uplink_address != '';

-- Remove the uplink_address column from echoareas table
ALTER TABLE echoareas DROP COLUMN IF EXISTS uplink_address;