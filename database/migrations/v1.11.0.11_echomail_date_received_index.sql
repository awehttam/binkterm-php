-- v1.11.0.11 - Add index on echomail(date_received) for date range search performance
-- date_written already has an index; date_received did not, causing full table scans
-- when ECHOMAIL_ORDER_DATE is set to 'received' (the default).

CREATE INDEX IF NOT EXISTS idx_echomail_date_received ON echomail(date_received);
