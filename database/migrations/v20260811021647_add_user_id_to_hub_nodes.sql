-- Migration: 20260811021647 - add user_id to hub_nodes
-- Created: 2026-08-11 02:16:47 UTC

-- Associates a hub_nodes row (node or point) with the local user account
-- that owns it. Nullable: unset for sysop-only nodes/points, and for
-- points not linked to an account. No uniqueness constraint here -- the
-- "one self-service point per network" rule is enforced in application
-- code only, since a sysop must still be able to assign additional
-- points to the same user via the admin Downlinks UI.
ALTER TABLE hub_nodes ADD COLUMN user_id INTEGER REFERENCES users(id);

CREATE INDEX idx_hub_nodes_user_id ON hub_nodes(user_id);
