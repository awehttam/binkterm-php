-- Migration: 20260823011745 - rlogin doors nullable terminal type
-- Created: 2026-08-23 01:17:45 UTC
--
-- terminal_type is now optional: a NULL value means "use the connecting
-- user's own established terminal type" (their last-known TERM from a
-- telnet/SSH session), falling back to xterm-256color if that isn't known
-- either. Resolved at door-launch time in PHP, not at the database layer.

ALTER TABLE rlogin_doors ALTER COLUMN terminal_type DROP NOT NULL;
ALTER TABLE rlogin_doors ALTER COLUMN terminal_type DROP DEFAULT;
