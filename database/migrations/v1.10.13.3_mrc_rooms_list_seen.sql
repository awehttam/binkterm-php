-- Track when rooms are seen in MRC LIST responses

ALTER TABLE mrc_rooms
ADD COLUMN IF NOT EXISTS last_list_seen TIMESTAMP;

CREATE INDEX IF NOT EXISTS idx_mrc_rooms_list_seen ON mrc_rooms(last_list_seen);
