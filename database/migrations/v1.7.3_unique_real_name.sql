-- Add case-insensitive unique constraint on real_name for users table
-- This prevents duplicate names like "Foo Bar" and "foo bar"

-- Create unique index on lowercase real_name
CREATE UNIQUE INDEX IF NOT EXISTS users_real_name_lower_idx ON users (LOWER(real_name));

-- Also add to pending_users to prevent registration conflicts
CREATE UNIQUE INDEX IF NOT EXISTS pending_users_real_name_lower_idx ON pending_users (LOWER(real_name));
