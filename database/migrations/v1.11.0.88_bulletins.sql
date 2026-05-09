CREATE TYPE bulletin_format AS ENUM ('plain', 'markdown');

CREATE TABLE bulletins (
    id           SERIAL PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    body         TEXT NOT NULL,
    format       bulletin_format NOT NULL DEFAULT 'plain',
    sort_order   INT NOT NULL DEFAULT 0,
    is_active    BOOLEAN NOT NULL DEFAULT TRUE,
    active_from  TIMESTAMP WITH TIME ZONE,
    active_until TIMESTAMP WITH TIME ZONE,
    created_by   INT REFERENCES users(id) ON DELETE SET NULL,
    created_at   TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at   TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE bulletin_reads (
    user_id     INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    bulletin_id INT NOT NULL REFERENCES bulletins(id) ON DELETE CASCADE,
    seen_at     TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, bulletin_id)
);

CREATE INDEX idx_bulletins_active ON bulletins (sort_order, id)
    WHERE is_active = TRUE;

CREATE INDEX idx_bulletin_reads_bulletin_id ON bulletin_reads (bulletin_id);
