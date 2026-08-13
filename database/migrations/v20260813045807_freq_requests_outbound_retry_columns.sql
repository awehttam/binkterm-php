-- Migration: 20260813045807 - freq requests outbound retry columns
-- Created: 2026-08-13 04:58:07 UTC
--
-- Adds bookkeeping needed for the scheduled outbound-FREQ retry loop
-- (binkp_scheduler's runScheduledFreqRetries()): last_attempt_at gates the
-- FREQ_POLL_INTERVAL wait per node, and attempts caps how many times a
-- request is retried before it is marked 'failed' (see status column,
-- unchanged type but now also carries the 'failed' value alongside the
-- existing 'pending' | 'complete').

ALTER TABLE freq_requests_outbound ADD COLUMN IF NOT EXISTS last_attempt_at TIMESTAMPTZ;
ALTER TABLE freq_requests_outbound ADD COLUMN IF NOT EXISTS attempts INTEGER NOT NULL DEFAULT 0;
