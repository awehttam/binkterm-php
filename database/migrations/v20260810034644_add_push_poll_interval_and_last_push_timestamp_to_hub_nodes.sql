-- Migration: 20260810034644 - add push poll interval and last push timestamp to hub_nodes
-- Created: 2026-08-10 03:46:44 UTC

-- Makes the hub node outbound "push" polling interval configurable per node
-- (previously a single hardcoded 300s interval shared by all nodes in
-- Scheduler::HUB_PUSH_POLL_INTERVAL). Defaults to 360 minutes (6 hours).
-- last_push_at tracks when we last dialed out to push to this node, so the
-- scheduler can gate per-node instead of using one global in-memory timer.
ALTER TABLE hub_nodes ADD COLUMN push_poll_interval_minutes INTEGER NOT NULL DEFAULT 360;
ALTER TABLE hub_nodes ADD COLUMN last_push_at TIMESTAMPTZ;
