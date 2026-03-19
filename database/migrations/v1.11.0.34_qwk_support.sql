-- Migration: v1.12.0_qwk_support
-- Adds QWK offline mail packet download and REP upload support.
--
-- qwk_conference_state  — tracks the highest message id seen per user per
--                         conference so successive downloads only include new
--                         messages.  Conference 0 (personal mail / netmail)
--                         is represented by the row where is_netmail = TRUE.
--
-- qwk_download_log      — records every QWK packet download with a JSON map
--                         of conference numbers to echo area metadata.
--                         RepProcessor reads the most recent map to reverse-
--                         map conference numbers when a REP upload arrives.

CREATE TABLE IF NOT EXISTS qwk_conference_state (
    id           SERIAL      PRIMARY KEY,
    user_id      INTEGER     NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    echoarea_id  INTEGER     REFERENCES echoareas(id) ON DELETE CASCADE,
    is_netmail   BOOLEAN     NOT NULL DEFAULT FALSE,
    last_msg_id  INTEGER     NOT NULL DEFAULT 0,
    updated_at   TIMESTAMP   NOT NULL DEFAULT NOW()
);

-- Unique: one netmail-state row per user.
CREATE UNIQUE INDEX IF NOT EXISTS qwk_conf_state_netmail_unique
    ON qwk_conference_state (user_id, is_netmail)
    WHERE is_netmail = TRUE;

-- Unique: one echomail-state row per user per area.
CREATE UNIQUE INDEX IF NOT EXISTS qwk_conf_state_echomail_unique
    ON qwk_conference_state (user_id, echoarea_id)
    WHERE is_netmail = FALSE AND echoarea_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_qwk_conf_state_user
    ON qwk_conference_state (user_id);

CREATE TABLE IF NOT EXISTS qwk_download_log (
    id               SERIAL      PRIMARY KEY,
    user_id          INTEGER     NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    downloaded_at    TIMESTAMP   NOT NULL DEFAULT NOW(),
    message_count    INTEGER     NOT NULL DEFAULT 0,
    packet_size      INTEGER     NOT NULL DEFAULT 0,
    conference_map   JSONB       NOT NULL DEFAULT '{}'
);

CREATE INDEX IF NOT EXISTS idx_qwk_download_log_user
    ON qwk_download_log (user_id, downloaded_at DESC);
