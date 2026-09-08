-- Migration: 20260830195847 - nntp netmail article numbers
-- Created: 2026-08-30 19:58:47 UTC
--
-- Per-user NNTP article numbering for the virtual netmail newsgroup
-- (docs/proposals/NNTPNetmail.md). Mirrors the per-echoarea scheme in
-- nntp_article_numbers / nntp_area_watermark, but partitioned by the reading
-- user instead of by echoarea: each authenticated user sees their own dense
-- 1..N sequence over the netmail rows visible to them (received + sent).
--
-- A single netmail row can be visible to two local users (sender and recipient)
-- and then legitimately holds a different article_number in each user's space;
-- the (user_id, netmail_id) unique index permits that, it is not a collision.
--
-- Numbers are allocated lazily by the NNTP daemon (src/Nntp/NntpNetmailArticleNumbers.php)
-- on first read. There is deliberately NO bulk backfill here: netmail visibility
-- depends on a per-user name+address match that a plain "PARTITION BY user_id"
-- seed cannot express, so the tables ship empty and the first GROUP selection
-- per user performs the initial allocation over that user's full visible history.

CREATE TABLE IF NOT EXISTS nntp_netmail_article_numbers (
    user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    article_number BIGINT  NOT NULL,
    netmail_id     INTEGER NOT NULL REFERENCES netmail(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, article_number)
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_nntp_netmail_article_numbers_msg
    ON nntp_netmail_article_numbers (user_id, netmail_id);

CREATE TABLE IF NOT EXISTS nntp_netmail_watermark (
    user_id             INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    last_article_number BIGINT NOT NULL DEFAULT 0
);
