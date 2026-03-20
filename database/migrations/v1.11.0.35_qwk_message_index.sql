-- Migration: v1.11.0.35_qwk_message_index
-- Per-user QWK message number index for reply threading and netmail address
-- resolution.
--
-- Rows are replaced on every QWK download for a given user, so the table
-- always reflects the most recent packet built for that user.  RepProcessor
-- reads this table when processing an uploaded REP to:
--   1. Resolve reply threading:  qwk_msg_num -> db_id
--   2. Resolve netmail to-address: qwk_msg_num -> from_address of the original

CREATE TABLE IF NOT EXISTS qwk_message_index (
    user_id      INTEGER     NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    qwk_msg_num  INTEGER     NOT NULL,
    type         VARCHAR(8)  NOT NULL CHECK (type IN ('netmail', 'echomail')),
    db_id        INTEGER     NOT NULL,
    from_address VARCHAR(50),
    PRIMARY KEY (user_id, qwk_msg_num)
);
