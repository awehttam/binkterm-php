-- Add TIC password support for file areas

ALTER TABLE file_areas
    ADD COLUMN password VARCHAR(255);

COMMENT ON COLUMN file_areas.password IS 'Optional TIC file area password (FSC-87 Pw field)';
