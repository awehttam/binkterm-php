-- Restructure address book to separate descriptive name from messaging user ID
-- This provides clearer separation between display name and actual messaging target

-- Add the new user_id column for messaging
ALTER TABLE address_book ADD COLUMN messaging_user_id VARCHAR(100);

-- Rename full_name to name for descriptive purposes
ALTER TABLE address_book RENAME COLUMN full_name TO name;

-- Copy data from name to messaging_user_id initially (can be updated later)
UPDATE address_book SET messaging_user_id = name;

-- Make messaging_user_id NOT NULL after data copy
ALTER TABLE address_book ALTER COLUMN messaging_user_id SET NOT NULL;

-- Update indexes
DROP INDEX IF EXISTS idx_address_book_full_name;
CREATE INDEX IF NOT EXISTS idx_address_book_name ON address_book(user_id, name);
CREATE INDEX IF NOT EXISTS idx_address_book_messaging_user_id ON address_book(user_id, messaging_user_id);

-- Update the unique constraint to use messaging_user_id + node_address
DROP INDEX IF EXISTS idx_address_book_unique_entry;
CREATE UNIQUE INDEX IF NOT EXISTS idx_address_book_unique_entry 
    ON address_book(user_id, messaging_user_id, node_address);

-- Update column comments
COMMENT ON COLUMN address_book.name IS 'Descriptive name for this contact (e.g., "John Smith", "Work colleague")';
COMMENT ON COLUMN address_book.messaging_user_id IS 'User ID/handle to use when sending messages to this contact';
COMMENT ON COLUMN address_book.node_address IS 'Fidonet node address (e.g., 1:234/567)';