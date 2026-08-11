-- Migration: 20260811021643 - add manage_hub_point flag to users
-- Created: 2026-08-11 02:16:43 UTC

-- Grants a local user access to the self-serve Point Management page,
-- letting them create and manage their own hub_nodes point registration.
ALTER TABLE users ADD COLUMN manage_hub_point BOOLEAN NOT NULL DEFAULT FALSE;
