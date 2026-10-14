<?php

declare(strict_types=1);

namespace BinktermPHP\Avatar;

use PDO;

/**
 * Additive avatar persistence. The 120-byte BIN is stored as bytea.
 * Encode and decode go through canonical Base64 so PDO never has to guess
 * the PostgreSQL bytea text format.
 *
 * Copyright (c) 2026 Cythu contributors. Adapted for BinktermPHP.
 * BSD-3-Clause. See the repository LICENSE.
 */
final class AnsiAvatarStore
{
    public function __construct(private readonly PDO $db) {}

    public function find(int $userId): ?AnsiAvatar
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('errors.avatar.invalid');
        }
        $stmt = $this->db->prepare(
            "SELECT translate(encode(avatar_bin, 'base64'), E'\\n\\r', '')
             FROM user_ansi_avatars WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }
        return AnsiAvatar::fromBase64((string)$value);
    }

    public function save(int $userId, AnsiAvatar $avatar): void
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('errors.avatar.invalid');
        }
        $stmt = $this->db->prepare(
            "INSERT INTO user_ansi_avatars (user_id, avatar_bin, updated_at)
             VALUES (?, decode(?, 'base64'), NOW())
             ON CONFLICT (user_id) DO UPDATE
             SET avatar_bin = EXCLUDED.avatar_bin,
                 updated_at = NOW()"
        );
        $stmt->execute([$userId, $avatar->toBase64()]);
    }

    public function remove(int $userId): void
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('errors.avatar.invalid');
        }
        $stmt = $this->db->prepare('DELETE FROM user_ansi_avatars WHERE user_id = ?');
        $stmt->execute([$userId]);
    }
}
