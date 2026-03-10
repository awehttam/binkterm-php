-- Migration: 1.11.0.4 - Add ssh_port and software columns to bbs_directory

ALTER TABLE bbs_directory
    ADD COLUMN IF NOT EXISTS ssh_port  INTEGER,
    ADD COLUMN IF NOT EXISTS software  VARCHAR(100);
