-- Migration: Add index on echomail date_received for efficient maintenance operations
-- Version: 1.6.5
-- Date: 2026-01-08
--
-- This index significantly improves performance of the echomail_maintenance.php script
-- when deleting messages by count, as it needs to ORDER BY date_received

-- Add composite index for efficient querying and sorting by date_received
CREATE INDEX IF NOT EXISTS idx_echomail_date_received ON echomail(echoarea_id, date_received);

-- The composite index (echoarea_id, date_received) allows PostgreSQL to:
-- 1. Quickly filter messages by echoarea_id
-- 2. Sort by date_received without a separate sort operation
-- 3. Use index-only scans for count operations
-- This is critical for the deleteByCount operation in maintenance scripts
