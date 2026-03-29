-- Migration: 1.11.0.65 - Add about_me field to users table
ALTER TABLE users ADD COLUMN IF NOT EXISTS about_me TEXT;
