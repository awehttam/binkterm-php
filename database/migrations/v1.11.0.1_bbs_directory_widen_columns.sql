-- Migration: 1.11.0.1 - Widen bbs_directory columns that were too narrow for real-world data
ALTER TABLE bbs_directory ALTER COLUMN os TYPE VARCHAR(255);
ALTER TABLE bbs_directory ALTER COLUMN location TYPE VARCHAR(255);
