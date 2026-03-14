-- Migration: v1.11.0.15 - Add case-insensitive functional index on users.username
--
-- Several hot-path queries use LOWER(username) comparisons but no functional
-- index existed, forcing PostgreSQL to seq scan the users table:
--
--   1. Auth::login()         WHERE LOWER(username) = LOWER(?) AND is_active = TRUE
--   2. BinkdProcessor        WHERE LOWER(real_name) = LOWER(?) OR LOWER(username) = LOWER(?)
--   3. MessageHandler        WHERE LOWER(real_name) = LOWER(?) OR LOWER(username) = LOWER(?)
--   4. Collision trigger     WHERE LOWER(username) = LOWER(NEW.real_name) OR ...
--
-- A UNIQUE index on LOWER(username) covers all of the above: plain lookups use
-- it directly, and OR conditions with the existing users_real_name_lower_idx
-- allow PostgreSQL to use a bitmap OR index scan instead of a seq scan.
-- UNIQUE also enforces case-insensitive username uniqueness at the DB level.

CREATE UNIQUE INDEX IF NOT EXISTS idx_users_lower_username ON users (LOWER(username));
