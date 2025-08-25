-- Migration: 1.2.0 - add user roles system
-- Created: 2025-08-25 06:55:00

-- Create roles table
CREATE TABLE IF NOT EXISTS user_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    permissions TEXT, -- JSON array of permissions
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Add role_id to users table
ALTER TABLE users ADD COLUMN role_id INTEGER REFERENCES user_roles(id);

-- Insert default roles
INSERT OR IGNORE INTO user_roles (name, description, permissions) VALUES 
    ('admin', 'System Administrator', '["all"]'),
    ('moderator', 'Forum Moderator', '["moderate_echoareas", "manage_users"]'),
    ('user', 'Regular User', '["read_messages", "post_messages"]');

-- Update existing admin users to have admin role
UPDATE users SET role_id = (SELECT id FROM user_roles WHERE name = 'admin') WHERE is_admin = 1;

-- Update regular users to have user role  
UPDATE users SET role_id = (SELECT id FROM user_roles WHERE name = 'user') WHERE is_admin = 0 OR is_admin IS NULL;

-- Create index for faster role lookups
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role_id);

