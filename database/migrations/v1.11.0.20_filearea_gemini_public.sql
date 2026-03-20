-- Add gemini_public flag to file_areas, mirroring the same flag on echoareas.
-- When true, all approved files in the area are browsable and downloadable
-- via the Gemini capsule server.

ALTER TABLE file_areas
    ADD COLUMN gemini_public BOOLEAN NOT NULL DEFAULT FALSE;

CREATE INDEX idx_file_areas_gemini_public
    ON file_areas (gemini_public)
    WHERE gemini_public = TRUE;

COMMENT ON COLUMN file_areas.gemini_public IS 'If true, files in this area are listed and served via the Gemini capsule server';
