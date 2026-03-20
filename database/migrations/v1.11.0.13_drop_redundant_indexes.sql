-- v1.11.0.13 - Drop explicit indexes that duplicate unique-constraint indexes
-- Each of these columns has a UNIQUE constraint, which PostgreSQL automatically
-- backs with a unique index (*_key). The explicit idx_* indexes below cover the
-- same columns and are redundant — they waste disk space and add write overhead.

DROP INDEX IF EXISTS idx_binkp_insecure_nodes_address;
DROP INDEX IF EXISTS idx_gateway_tokens_token;
DROP INDEX IF EXISTS idx_nodelist_address;
DROP INDEX IF EXISTS idx_password_reset_tokens_token;
DROP INDEX IF EXISTS idx_shared_messages_key;
DROP INDEX IF EXISTS idx_users_referral_code;
DROP INDEX IF EXISTS idx_webdoor_sessions_session_id;
DROP INDEX IF EXISTS idx_dosbox_doors_door_id;
DROP INDEX IF EXISTS idx_door_sessions_session_id;
DROP INDEX IF EXISTS idx_shared_files_share_key;
