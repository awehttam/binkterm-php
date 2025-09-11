-- Add address book for netmail recipients
-- Allows users to store frequently contacted addresses with details

CREATE TABLE IF NOT EXISTS address_book (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    full_name VARCHAR(100) NOT NULL,
    node_address VARCHAR(50) NOT NULL,
    email VARCHAR(100),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_address_book_user_id ON address_book(user_id);
CREATE INDEX IF NOT EXISTS idx_address_book_full_name ON address_book(user_id, full_name);
CREATE INDEX IF NOT EXISTS idx_address_book_node_address ON address_book(user_id, node_address);

-- Create unique constraint to prevent duplicate entries per user
CREATE UNIQUE INDEX IF NOT EXISTS idx_address_book_unique_entry 
    ON address_book(user_id, full_name, node_address);

-- Add table comments for documentation
COMMENT ON TABLE address_book IS 'User address book for storing netmail recipient information';
COMMENT ON COLUMN address_book.user_id IS 'Owner of this address book entry';
COMMENT ON COLUMN address_book.full_name IS 'Recipients full name as displayed';
COMMENT ON COLUMN address_book.node_address IS 'Fidonet node address (e.g., 1:234/567)';
COMMENT ON COLUMN address_book.email IS 'Optional email address for user reference';
COMMENT ON COLUMN address_book.description IS 'Optional description or notes about this contact';