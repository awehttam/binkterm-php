<?php

namespace BinktermPHP;

class UserMeta
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    public function getValue(int $userId, string $key): ?string
    {
        $stmt = $this->db->prepare('
            SELECT valname
            FROM users_meta
            WHERE user_id = ? AND keyname = ?
        ');
        $stmt->execute([$userId, $key]);
        $value = $stmt->fetchColumn();

        if ($value === false) {
            return null;
        }

        return $value;
    }

    public function setValue(int $userId, string $key, ?string $value): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO users_meta (user_id, keyname, valname, updated_at)
            VALUES (?, ?, ?, NOW())
            ON CONFLICT (user_id, keyname)
            DO UPDATE SET valname = EXCLUDED.valname, updated_at = NOW()
        ');
        $stmt->execute([$userId, $key, $value]);
    }
}
