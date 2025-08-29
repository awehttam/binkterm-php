-- Fix date_received columns to use UTC time defaults
-- v1.3.3 - Ensure date_received is always stored in UTC regardless of system timezone

-- Update netmail table date_received column to use UTC default
ALTER TABLE netmail 
ALTER COLUMN date_received SET DEFAULT (NOW() AT TIME ZONE 'UTC');

-- Update echomail table date_received column to use UTC default  
ALTER TABLE echomail
ALTER COLUMN date_received SET DEFAULT (NOW() AT TIME ZONE 'UTC');

-- Add comments for documentation
COMMENT ON COLUMN netmail.date_received IS 'UTC timestamp when message was received by system';
COMMENT ON COLUMN echomail.date_received IS 'UTC timestamp when message was received by system';