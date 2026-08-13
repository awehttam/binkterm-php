-- Migration: 20260813052421 - freq requests outbound file id
-- Created: 2026-08-13 05:24:21 UTC
--
-- Records which files.id a fulfilled FREQ request's response file became,
-- so the "File Requests" UI can hyperlink the filename straight to the file
-- viewer instead of the user having to go find it in their private area.

ALTER TABLE freq_requests_outbound ADD COLUMN IF NOT EXISTS file_id INTEGER REFERENCES files(id) ON DELETE SET NULL;
