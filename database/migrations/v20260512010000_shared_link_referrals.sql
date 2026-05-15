-- Migration: v20260512010000 - Track external referrers for shared links

CREATE TABLE IF NOT EXISTS shared_link_referrals (
    id SERIAL PRIMARY KEY,
    share_type VARCHAR(16) NOT NULL,
    share_id INTEGER NOT NULL,
    referrer_url TEXT NOT NULL,
    referrer_host VARCHAR(255) DEFAULT NULL,
    access_count INTEGER NOT NULL DEFAULT 1,
    first_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT chk_shared_link_referrals_type
        CHECK (share_type IN ('message', 'file')),
    CONSTRAINT uq_shared_link_referrals_share_referrer
        UNIQUE (share_type, share_id, referrer_url)
);

CREATE INDEX IF NOT EXISTS idx_shared_link_referrals_share
    ON shared_link_referrals (share_type, share_id);

CREATE INDEX IF NOT EXISTS idx_shared_link_referrals_host
    ON shared_link_referrals (share_type, referrer_host);

COMMENT ON TABLE shared_link_referrals IS 'Aggregated external referrer counts for shared message and file links';
COMMENT ON COLUMN shared_link_referrals.share_type IS 'Shared link type: message or file';
COMMENT ON COLUMN shared_link_referrals.share_id IS 'Primary key from shared_messages or shared_files depending on share_type';
COMMENT ON COLUMN shared_link_referrals.referrer_url IS 'Normalized external referrer URL without fragment';
COMMENT ON COLUMN shared_link_referrals.referrer_host IS 'Lowercased referrer hostname for rollups/filtering';
