<?php
/**
 * Migration: 1.11.0.63 - add user_id to mrc_local_presence
 */

return function ($db) {
    $db->exec("
        ALTER TABLE mrc_local_presence
            ADD COLUMN IF NOT EXISTS user_id INTEGER REFERENCES users(id) ON DELETE CASCADE
    ");

    $db->exec("
        UPDATE mrc_local_presence mlp
        SET user_id = u.id
        FROM users u
        WHERE mlp.user_id IS NULL
          AND LOWER(mlp.username) = LOWER(u.username)
    ");

    $db->exec("
        DELETE FROM mrc_local_presence a
        USING mrc_local_presence b
        WHERE a.id > b.id
          AND a.user_id IS NOT NULL
          AND b.user_id IS NOT NULL
          AND a.user_id = b.user_id
          AND a.room_name = b.room_name
    ");

    $stmt = $db->prepare("
        SELECT conname
        FROM pg_constraint
        WHERE conrelid = 'mrc_local_presence'::regclass
          AND contype = 'u'
          AND pg_get_constraintdef(oid) ILIKE '%username%'
          AND pg_get_constraintdef(oid) ILIKE '%bbs_name%'
          AND pg_get_constraintdef(oid) ILIKE '%room_name%'
    ");
    $stmt->execute();

    $legacyConstraints = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($legacyConstraints as $constraintName) {
        $db->exec('ALTER TABLE mrc_local_presence DROP CONSTRAINT IF EXISTS "' . str_replace('"', '""', $constraintName) . '"');
    }

    $stmt = $db->prepare("
        SELECT 1
        FROM pg_constraint
        WHERE conname = :name
        LIMIT 1
    ");
    $stmt->execute(['name' => 'mrc_local_presence_user_room_unique']);

    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        $db->exec("
            ALTER TABLE mrc_local_presence
                ADD CONSTRAINT mrc_local_presence_user_room_unique UNIQUE (user_id, room_name)
        ");
    }

    return true;
};
