-- Migration: 20260523012838 - qwk_mail_exchange_support
-- Created: 2026-05-23 01:28:38 UTC

-- Add your SQL statements here
-- Each statement should end with semicolon followed by newline

-- Example:
-- ALTER TABLE users ADD COLUMN new_field VARCHAR(100);

-- CREATE INDEX idx_new_field ON users(new_field);
CREATE TABLE IF NOT EXISTS qwk_mailboxes (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    bbs_id VARCHAR(8) NOT NULL,
    host VARCHAR(255) NOT NULL,
    port INTEGER NOT NULL DEFAULT 21,
    username VARCHAR(100) NOT NULL,
    password TEXT NOT NULL,
    ftp_remote_path VARCHAR(500) NOT NULL DEFAULT '/',
    poll_schedule VARCHAR(100),
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    last_polled_at TIMESTAMPTZ NULL,
    last_error TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS echo_area_qwk_subscriptions (
    id SERIAL PRIMARY KEY,
    echoarea_id INTEGER NOT NULL REFERENCES echoareas(id) ON DELETE CASCADE,
    mailbox_id INTEGER NOT NULL REFERENCES qwk_mailboxes(id) ON DELETE CASCADE,
    conference_tag VARCHAR(50) NOT NULL,
    conference_number INTEGER NOT NULL,
    auto_created BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT echo_area_qwk_subscriptions_area_mailbox_key UNIQUE (echoarea_id, mailbox_id),
    CONSTRAINT echo_area_qwk_subscriptions_mailbox_conf_key UNIQUE (mailbox_id, conference_number)
);

CREATE TABLE IF NOT EXISTS qwk_outbound_messages (
    id SERIAL PRIMARY KEY,
    echomail_id INTEGER NOT NULL REFERENCES echomail(id) ON DELETE CASCADE,
    mailbox_id INTEGER NOT NULL REFERENCES qwk_mailboxes(id) ON DELETE CASCADE,
    queued_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    sent_at TIMESTAMPTZ NULL,
    CONSTRAINT qwk_outbound_messages_unique UNIQUE (echomail_id, mailbox_id)
);

CREATE TABLE IF NOT EXISTS echo_area_gates (
    id SERIAL PRIMARY KEY,
    source_area_id INTEGER NOT NULL REFERENCES echoareas(id) ON DELETE CASCADE,
    target_area_id INTEGER NOT NULL REFERENCES echoareas(id) ON DELETE CASCADE,
    bidirectional BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT echo_area_gates_unique UNIQUE (source_area_id, target_area_id),
    CONSTRAINT echo_area_gates_no_self CHECK (source_area_id <> target_area_id)
);

ALTER TABLE echomail
    ADD COLUMN IF NOT EXISTS qwk_mailbox_id INTEGER REFERENCES qwk_mailboxes(id) ON DELETE SET NULL,
    ADD COLUMN IF NOT EXISTS qwk_conference_number INTEGER,
    ADD COLUMN IF NOT EXISTS qwk_msg_number INTEGER,
    ADD COLUMN IF NOT EXISTS source_msgid VARCHAR(255);

CREATE INDEX IF NOT EXISTS idx_qwk_subscriptions_area
    ON echo_area_qwk_subscriptions (echoarea_id);

CREATE INDEX IF NOT EXISTS idx_qwk_subscriptions_mailbox
    ON echo_area_qwk_subscriptions (mailbox_id);

CREATE INDEX IF NOT EXISTS idx_qwk_outbound_pending
    ON qwk_outbound_messages (mailbox_id, sent_at);

CREATE INDEX IF NOT EXISTS idx_echo_area_gates_source
    ON echo_area_gates (source_area_id);

CREATE INDEX IF NOT EXISTS idx_echo_area_gates_target
    ON echo_area_gates (target_area_id);

CREATE UNIQUE INDEX IF NOT EXISTS idx_echomail_qwk_dedupe
    ON echomail (qwk_mailbox_id, qwk_conference_number, qwk_msg_number)
    WHERE qwk_mailbox_id IS NOT NULL
      AND qwk_conference_number IS NOT NULL
      AND qwk_msg_number IS NOT NULL;

CREATE INDEX IF NOT EXISTS idx_echomail_source_msgid_area
    ON echomail (source_msgid, echoarea_id)
    WHERE source_msgid IS NOT NULL;
