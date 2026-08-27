-- Migration: 20260827034721 - add registration screening columns and tor exit node cache
-- Created: 2026-08-27 03:47:21 UTC

-- Risk screening results captured at registration time (see docs/proposals/NewUserScreeningIPAddress.md)
ALTER TABLE pending_users ADD COLUMN IF NOT EXISTS risk_score INTEGER NOT NULL DEFAULT 0;
ALTER TABLE pending_users ADD COLUMN IF NOT EXISTS risk_flags JSONB;
ALTER TABLE pending_users ADD COLUMN IF NOT EXISTS screening_forced_review BOOLEAN NOT NULL DEFAULT FALSE;

-- Cached Tor exit node list, refreshed periodically by scripts/update_tor_exit_list.php
CREATE TABLE IF NOT EXISTS tor_exit_nodes (
    ip_address INET PRIMARY KEY,
    last_seen TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Tracks the last successful run of each screening list-refresh job so the
-- binkp scheduler can gate them to a fixed interval.
CREATE TABLE IF NOT EXISTS screening_list_refresh (
    list_name VARCHAR(64) PRIMARY KEY,
    last_run_at TIMESTAMPTZ,
    last_success_at TIMESTAMPTZ,
    last_status VARCHAR(20),
    last_error TEXT,
    entry_count INTEGER NOT NULL DEFAULT 0
);
