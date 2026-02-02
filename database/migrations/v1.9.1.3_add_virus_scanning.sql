-- Add virus scanning support for file areas
-- Requires clamav-daemon and clamdscan packages

-- Add virus scanning configuration to file areas
ALTER TABLE file_areas ADD COLUMN scan_virus BOOLEAN DEFAULT TRUE;
COMMENT ON COLUMN file_areas.scan_virus IS 'Enable virus scanning for files in this area';

-- Add virus scanning results to files table
ALTER TABLE files ADD COLUMN virus_scanned BOOLEAN DEFAULT FALSE;
ALTER TABLE files ADD COLUMN virus_scan_result VARCHAR(20); -- 'clean', 'infected', 'error', 'skipped'
ALTER TABLE files ADD COLUMN virus_signature VARCHAR(255); -- Name of detected virus/malware
ALTER TABLE files ADD COLUMN virus_scanned_at TIMESTAMP;

COMMENT ON COLUMN files.virus_scanned IS 'Whether file has been scanned for viruses';
COMMENT ON COLUMN files.virus_scan_result IS 'Result of virus scan: clean, infected, error, skipped';
COMMENT ON COLUMN files.virus_signature IS 'Malware signature name if infected';
COMMENT ON COLUMN files.virus_scanned_at IS 'Timestamp of last virus scan';

-- Add index for querying files by scan status
CREATE INDEX idx_files_virus_scan ON files(virus_scanned, virus_scan_result);
CREATE INDEX idx_files_infected ON files(virus_scan_result) WHERE virus_scan_result = 'infected';
