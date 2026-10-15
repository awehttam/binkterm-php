<?php

declare(strict_types=1);

namespace BinktermPHP\Avatar;

use PDO;

/**
 * Owner-scoped read, save, and remove operations.
 * The write subject is always the authenticated user id passed by the route.
 * JSON fields named user_id or id are ignored.
 *
 * Copyright (c) 2026 Cythu contributors. Adapted for BinktermPHP.
 * BSD-3-Clause. See the repository LICENSE.
 */
final class AnsiAvatarApi
{
    public function __construct(private readonly PDO $db) {}

    /**
     * @return array{ansi:string,cells:list<list<array<string,mixed>>>}|null
     */
    public function read(int $userId): ?array
    {
        $avatar = (new AnsiAvatarStore($this->db))->find($userId);
        return $avatar === null ? null : AnsiAvatarPayload::present($avatar);
    }

    public function userIsActive(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $stmt = $this->db->prepare('SELECT id FROM users WHERE id = ? AND is_active = TRUE LIMIT 1');
        $stmt->execute([$userId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * @return array{ansi:string,cells:list<list<array<string,mixed>>>}
     */
    public function save(int $ownerId, string $json): array
    {
        $avatar = AnsiAvatarPayload::parseBody($json);
        (new AnsiAvatarStore($this->db))->save($ownerId, $avatar);
        return AnsiAvatarPayload::present($avatar);
    }

    public function remove(int $ownerId): void
    {
        (new AnsiAvatarStore($this->db))->remove($ownerId);
    }
}
