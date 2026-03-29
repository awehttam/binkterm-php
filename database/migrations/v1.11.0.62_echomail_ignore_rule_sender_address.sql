ALTER TABLE user_echomail_ignore_rules
    ADD COLUMN IF NOT EXISTS sender_address VARCHAR(255) NOT NULL DEFAULT '';

ALTER TABLE user_echomail_ignore_rules
    DROP CONSTRAINT IF EXISTS user_echomail_ignore_rules_user_id_sender_name_subject_cont_key;

ALTER TABLE user_echomail_ignore_rules
    DROP CONSTRAINT IF EXISTS user_echomail_ignore_rules_user_id_sender_name_subject_contains_key;

ALTER TABLE user_echomail_ignore_rules
    DROP CONSTRAINT IF EXISTS user_echomail_ignore_rules_sender_identity_unique;

DROP INDEX IF EXISTS user_echomail_ignore_rules_sender_identity_unique;

CREATE UNIQUE INDEX IF NOT EXISTS user_echomail_ignore_rules_sender_identity_unique
    ON user_echomail_ignore_rules(user_id, sender_name, sender_address, subject_contains);
