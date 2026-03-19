-- Migration: v1.11.0.36_qwk_imported_hashes
-- Per-user content hash table for QWK REP import deduplication.
--
-- Before each message is imported from an uploaded REP packet, RepProcessor
-- checks whether the hash of its authored content (conference + to + subject +
-- body) already exists for this user.  If it does, the message is skipped as a
-- duplicate.  On success the hash is recorded here.
--
-- Entries older than 30 days are pruned on each upload so the table stays small.

CREATE TABLE IF NOT EXISTS qwk_imported_hashes (
    user_id     INTEGER     NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    msg_hash    CHAR(64)    NOT NULL,   -- SHA-256 hex digest
    imported_at TIMESTAMP   NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, msg_hash)
);

CREATE INDEX IF NOT EXISTS idx_qwk_imported_hashes_user_at
    ON qwk_imported_hashes (user_id, imported_at);
