-- v1.10.15.1: Widen MRC message columns that can contain pipe/MCI codes
--
-- BBS names (f2/from_site) and usernames (f1/from_user) may include pipe
-- colour codes, making the encoded string longer than the 30-char spec
-- display limit. Widen to 100 chars to accommodate real-world traffic.

ALTER TABLE mrc_messages
    ALTER COLUMN from_user TYPE VARCHAR(100),
    ALTER COLUMN from_site TYPE VARCHAR(100),
    ALTER COLUMN to_user   TYPE VARCHAR(100);

ALTER TABLE mrc_outbound
    ALTER COLUMN field1 TYPE VARCHAR(100),
    ALTER COLUMN field4 TYPE VARCHAR(100);
