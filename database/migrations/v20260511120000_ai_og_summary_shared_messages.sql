-- Migration: v20260511120000 — Add AI-generated og:description cache to shared_messages
-- Stores the user-authored or AI-generated link preview description for a shared message.
-- NULL means no description has been set; the shared page falls back to a body excerpt.

ALTER TABLE shared_messages
    ADD COLUMN IF NOT EXISTS ai_og_summary TEXT DEFAULT NULL;
