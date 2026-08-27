-- Migration: 20260827042334 - add disposable email domains cache table
-- Created: 2026-08-27 04:23:34 UTC

-- Cached disposable / throwaway email provider domains, refreshed periodically
-- by scripts/update_disposable_email_list.php for the registration screening
-- feature (see docs/proposals/NewUserScreeningIPAddress.md).
CREATE TABLE IF NOT EXISTS disposable_email_domains (
    domain VARCHAR(255) PRIMARY KEY,
    last_seen TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
