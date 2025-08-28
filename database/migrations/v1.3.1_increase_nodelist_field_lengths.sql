-- Migration: 1.3.1 - Increase nodelist field lengths for real-world data
-- Created: 2025-08-28

-- Increase filename length for longer nodelist filenames
ALTER TABLE nodelist_metadata ALTER COLUMN filename TYPE VARCHAR(100);

-- Increase phone field length for longer phone numbers/connection info
ALTER TABLE nodelist ALTER COLUMN phone TYPE VARCHAR(100);

-- Increase flag value length for longer flag values
ALTER TABLE nodelist_flags ALTER COLUMN flag_value TYPE VARCHAR(100);

-- Increase system name and location lengths for longer entries
ALTER TABLE nodelist ALTER COLUMN system_name TYPE VARCHAR(200);
ALTER TABLE nodelist ALTER COLUMN location TYPE VARCHAR(200);
ALTER TABLE nodelist ALTER COLUMN sysop_name TYPE VARCHAR(150);