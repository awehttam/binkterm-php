<?php
/**
 * Migration: 1.10.15 - Username/real_name cross-collision trigger
 *
 * Prevents any username from matching an existing real_name and vice versa,
 * closing a netmail misrouting vulnerability. Run as PHP to avoid semicolon
 * splitting issues with dollar-quoted PostgreSQL function bodies.
 */

$db->exec("
    CREATE OR REPLACE FUNCTION check_username_realname_collision()
    RETURNS TRIGGER AS \$\$
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
    \$\$ LANGUAGE plpgsql
");

$db->exec("DROP TRIGGER IF EXISTS trg_users_name_collision ON users");

$db->exec("
    CREATE TRIGGER trg_users_name_collision
    BEFORE INSERT OR UPDATE OF username, real_name ON users
    FOR EACH ROW EXECUTE FUNCTION check_username_realname_collision()
");

return true;
