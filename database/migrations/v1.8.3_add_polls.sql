CREATE TABLE IF NOT EXISTS polls (
    id SERIAL PRIMARY KEY,
    question TEXT NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS poll_options (
    id SERIAL PRIMARY KEY,
    poll_id INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
    option_text TEXT NOT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS poll_votes (
    id SERIAL PRIMARY KEY,
    poll_id INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
    option_id INTEGER NOT NULL REFERENCES poll_options(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (poll_id, user_id)
);

CREATE INDEX IF NOT EXISTS idx_poll_votes_poll_id ON poll_votes (poll_id);
CREATE INDEX IF NOT EXISTS idx_poll_votes_option_id ON poll_votes (option_id);

WITH inserted_poll AS (
    INSERT INTO polls (question, is_active)
    VALUES ('Are you enjoying this system?', TRUE)
    RETURNING id
)
INSERT INTO poll_options (poll_id, option_text, sort_order)
SELECT id, option_text, sort_order
FROM inserted_poll,
     (VALUES
        ('Yes absolutely!', 0),
        ('No not really', 1),
        ('I have some ideas', 2)
     ) AS options(option_text, sort_order);
