-- Add last_reminded field to users table for tracking reminder emails
-- This allows tracking when reminders were last sent to users

ALTER TABLE users ADD COLUMN last_reminded TIMESTAMP;

-- Create index for better query performance when searching by last_reminded dates
CREATE INDEX IF NOT EXISTS idx_users_last_reminded ON users(last_reminded);

-- Optional: Add a comment to the column for documentation
COMMENT ON COLUMN users.last_reminded IS 'Timestamp when the last account reminder was sent to this user';