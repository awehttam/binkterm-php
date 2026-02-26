ALTER TABLE echoareas
    ADD COLUMN IF NOT EXISTS gemini_public BOOLEAN NOT NULL DEFAULT FALSE;

CREATE INDEX IF NOT EXISTS idx_echoareas_gemini_public
    ON echoareas (gemini_public)
    WHERE gemini_public = TRUE;
