-- Migration: 20260829200318 - nntp article numbers
-- Created: 2026-08-29 20:03:18 UTC
--
-- Per-echoarea NNTP article numbering for the NNTP server (docs/proposals/NNTPServer.md).
-- NNTP article numbers are per-group integers that must never be reused within a group
-- (RFC 3977). They are NOT derived from echomail.id (a single sequence shared across all
-- areas): instead each area gets its own dense 1..N sequence via this mapping table, and a
-- per-area high-water mark that only ever increases so a pruned message's number is retired,
-- not reissued.
--
-- Numbers are allocated lazily by the NNTP daemon (src/Nntp/NntpArticleNumbers.php) on first
-- read of an area; this migration seeds the mapping for all existing approved echomail.

CREATE TABLE IF NOT EXISTS nntp_article_numbers (
    echoarea_id    INTEGER NOT NULL REFERENCES echoareas(id) ON DELETE CASCADE,
    article_number BIGINT  NOT NULL,
    echomail_id    INTEGER NOT NULL REFERENCES echomail(id) ON DELETE CASCADE,
    PRIMARY KEY (echoarea_id, article_number)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_nntp_article_numbers_msg
    ON nntp_article_numbers (echoarea_id, echomail_id);

CREATE TABLE IF NOT EXISTS nntp_area_watermark (
    echoarea_id         INTEGER PRIMARY KEY REFERENCES echoareas(id) ON DELETE CASCADE,
    last_article_number BIGINT NOT NULL DEFAULT 0
);

-- Backfill: dense 1..N per area, ordered by echomail.id (arrival order; NOT date_written,
-- which can be skewed by a remote system's clock). Approved messages only.
INSERT INTO nntp_article_numbers (echoarea_id, article_number, echomail_id)
SELECT echoarea_id,
       ROW_NUMBER() OVER (PARTITION BY echoarea_id ORDER BY id),
       id
FROM echomail
WHERE COALESCE(moderation_status, 'approved') = 'approved'
ON CONFLICT DO NOTHING;

-- Seed the high-water mark from the backfilled rows.
INSERT INTO nntp_area_watermark (echoarea_id, last_article_number)
SELECT echoarea_id, MAX(article_number)
FROM nntp_article_numbers
GROUP BY echoarea_id
ON CONFLICT (echoarea_id) DO NOTHING;
