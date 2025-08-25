-- Migration: 1.3.0 - centralize logging paths
-- Created: 2025-08-25 10:51:06

-- This migration documents the centralization of logging paths
-- No database changes needed, but this records the code changes made

-- Changes made in this version:
-- 1. Added Config::LOG_PATH constant for centralized log directory management
-- 2. Added Config::getLogPath() helper method
-- 3. Updated Logger class to use absolute paths from Config
-- 4. Updated all BinkP scripts to use Config::getLogPath() instead of relative paths
-- 5. Removed incorrectly created data/logs directories from scripts/ and public_html/
-- 6. All logs now properly go to data/logs/ directory

-- This prevents the creation of data/logs directories in wrong locations
-- when scripts are run from different working directories

-- No actual SQL changes needed for this migration
SELECT 1;

