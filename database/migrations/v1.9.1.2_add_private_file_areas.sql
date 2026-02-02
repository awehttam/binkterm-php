-- Add is_private flag to file_areas for user private file storage

ALTER TABLE file_areas ADD COLUMN is_private BOOLEAN DEFAULT FALSE;

-- Add index for querying private file areas
CREATE INDEX idx_file_areas_is_private ON file_areas(is_private);

-- Add message_id to files table to link files to netmail/echomail
ALTER TABLE files ADD COLUMN message_id INTEGER;
ALTER TABLE files ADD COLUMN message_type VARCHAR(20); -- 'netmail' or 'echomail'

-- Add index for querying files by message
CREATE INDEX idx_files_message ON files(message_id, message_type);

COMMENT ON COLUMN file_areas.is_private IS 'Private file area for a specific user (netmail attachments)';
COMMENT ON COLUMN files.message_id IS 'ID of netmail/echomail message this file is attached to';
COMMENT ON COLUMN files.message_type IS 'Type of message: netmail or echomail';
