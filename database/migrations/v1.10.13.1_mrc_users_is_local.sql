-- Add is_local flag to mrc_users
-- Distinguishes users connected through our own BBS (webdoor sessions)
-- from foreign users seen in room USERLIST responses.
-- Only local users should receive periodic IAMHERE keepalives.

ALTER TABLE mrc_users ADD COLUMN IF NOT EXISTS is_local BOOLEAN NOT NULL DEFAULT false;
