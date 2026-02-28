-- Migration: 1.10.15 - Prevent username/real_name cross-collision
-- Ensures no username can equal any real_name (case-insensitive) across all users,
-- closing a netmail misrouting vulnerability. Unique constraints on each column
-- already exist; this trigger enforces the cross-check.

CREATE OR REPLACE FUNCTION check_username_realname_collision()
RETURNS TRIGGER AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM users
        WHERE id != COALESCE(NEW.id, -1)
          AND (
              LOWER(username) = LOWER(NEW.real_name)
           OR LOWER(real_name) = LOWER(NEW.username)
          )
    ) THEN
        RAISE EXCEPTION 'Username or real name conflicts with an existing user';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_users_name_collision
BEFORE INSERT OR UPDATE OF username, real_name ON users
FOR EACH ROW EXECUTE FUNCTION check_username_realname_collision();
