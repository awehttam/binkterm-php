<?php

/*
 * Copyright Matthew Asham and BinktermPHP Contributors
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
 * following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 *
 */

namespace BinktermPHP\Nntp;

use BinktermPHP\MessageHandler;
use PDO;

/**
 * {@see NntpGroupSource} backed by the authenticated user's own `netmail`
 * (docs/proposals/NNTPNetmail.md).
 *
 * Every query is scoped to the rows this user may see via
 * {@see MessageHandler::netmailVisibilityFilter()} +
 * {@see MessageHandler::netmailNotDeletedFilter()} — never a bare
 * `netmail.user_id` match. Two users connected to the same server therefore see
 * entirely different articles under the same group name.
 */
final class NetmailGroupSource implements NntpGroupSource
{
    private const COLUMNS =
        'id, user_id, from_address, to_address, from_name, to_name, subject, message_text,
         date_written, date_received, attributes, is_sent, reply_to_id, message_id,
         reply_address, kludge_lines, bottom_kludges, message_charset';

    private PDO $db;
    private int $userId;
    private string $group;
    private string $description;
    private bool $postable;
    private MessageHandler $handler;
    private NntpNetmailArticleNumbers $numbers;
    private NntpNetmailArticleBuilder $builder;

    public function __construct(
        PDO $db,
        int $userId,
        string $group,
        string $description,
        bool $postable,
        MessageHandler $handler,
        NntpNetmailArticleNumbers $numbers,
        NntpNetmailArticleBuilder $builder
    ) {
        $this->db = $db;
        $this->userId = $userId;
        $this->group = $group;
        $this->description = $description;
        $this->postable = $postable;
        $this->handler = $handler;
        $this->numbers = $numbers;
        $this->builder = $builder;
    }

    public function groupName(): string
    {
        return $this->group;
    }

    public function description(): string
    {
        $desc = trim($this->description);

        return $desc === '' ? $this->group : preg_replace('/\s+/', ' ', $desc);
    }

    public function isPostable(): bool
    {
        return $this->postable;
    }

    public function createdAtUnix(): ?int
    {
        // The per-user netmail group has no meaningful creation time; it exists
        // for the life of the account. NEWGROUPS never reports it as "new".
        return null;
    }

    public function ensureNumbered(): void
    {
        $this->numbers->ensureUser($this->userId);
    }

    public function bounds(): array
    {
        return $this->numbers->groupBounds($this->userId);
    }

    public function range(int $lo, ?int $hi = null, ?int $limit = null): array
    {
        return $this->numbers->range($this->userId, $lo, $hi, $limit);
    }

    public function article(int $number): ?array
    {
        $row = $this->rowForNumber($number);
        if ($row === null) {
            return null;
        }

        return $this->builder->build($row, $number, $this->isOutgoing($row));
    }

    public function overview(int $number): ?string
    {
        $row = $this->rowForNumber($number);
        if ($row === null) {
            return null;
        }

        return $this->builder->overviewLine($row, $number, $this->isOutgoing($row));
    }

    public function messageIdForNumber(int $number): ?string
    {
        $row = $this->rowForNumber($number);

        return $row === null ? null : $this->builder->messageIdFor($row);
    }

    public function overviewBatch(array $numberToId): array
    {
        $rows = $this->loadRows(array_values($numberToId));
        $parentIds = $this->parentMessageIdMap($rows);

        $out = [];
        foreach ($numberToId as $number => $id) {
            if (isset($rows[$id])) {
                $out[$number] = $this->builder->overviewLine($rows[$id], $number, $this->isOutgoing($rows[$id]), $parentIds);
            }
        }

        return $out;
    }

    public function articleBatch(array $numberToId): array
    {
        $rows = $this->loadRows(array_values($numberToId));
        $parentIds = $this->parentMessageIdMap($rows);

        $out = [];
        foreach ($numberToId as $number => $id) {
            if (isset($rows[$id])) {
                $out[$number] = $this->builder->build($rows[$id], $number, $this->isOutgoing($rows[$id]), $parentIds);
            }
        }

        return $out;
    }

    public function resolveMessageId(string $rfcMessageId): ?int
    {
        $parsed = NntpMessageId::parse($rfcMessageId);
        if ($parsed === null) {
            return null;
        }

        $scope = $this->numbers->visibilityScope($this->userId);
        $stmt = $this->db->prepare(
            "SELECT n.id FROM netmail n
             WHERE {$scope['sql']}
               AND (n.message_id = ? OR n.message_id LIKE ?)
             ORDER BY n.id LIMIT 1"
        );
        $stmt->execute([...$scope['params'], $parsed['serial'], '% ' . $parsed['serial']]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }

        return $this->numbers->numberFor($this->userId, (int)$id);
    }

    public function newMessageIdsSince(int $sinceUnix): array
    {
        $scope = $this->numbers->visibilityScope($this->userId);
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . " FROM netmail n
             WHERE {$scope['sql']}
               AND n.date_received >= to_timestamp(?)"
        );
        $stmt->execute([...$scope['params'], $sinceUnix]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = $this->builder->messageIdFor($row);
        }

        return array_values(array_unique($out));
    }

    // ── internals ──────────────────────────────────────────────────────────

    private function isOutgoing(array $row): bool
    {
        return $this->handler->netmailRowIsOutgoing($this->userId, $row);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function rowForNumber(int $number): ?array
    {
        $id = $this->numbers->netmailIdFor($this->userId, $number);
        if ($id === null) {
            return null;
        }
        $rows = $this->loadRows([$id]);

        return $rows[$id] ?? null;
    }

    /**
     * Fetch full rows by `netmail.id`, re-applying this user's visibility scope
     * as a fail-closed guard. Callers only ever pass ids that were already
     * resolved through {@see NntpNetmailArticleNumbers} (itself scoped), so this
     * predicate is belt-and-suspenders: an id this user may not see drops out of
     * the result rather than being returned. Callers tolerate missing keys.
     *
     * @param int[] $ids
     * @return array<int,array<string,mixed>>
     */
    private function loadRows(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $scope = $this->numbers->visibilityScope($this->userId);
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . " FROM netmail n
             WHERE n.id IN ($placeholders) AND ({$scope['sql']})"
        );
        $stmt->execute([...$ids, ...$scope['params']]);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['id']] = $row;
        }

        return $map;
    }

    /**
     * Constructed Message-ID for each distinct parent referenced by a batch of
     * rows. Parents are loaded unscoped — the value is only a Message-ID token
     * for the `References:` header, and a thread the user can see implies they
     * have seen its parent.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,string>  parent netmail id => Message-ID
     */
    private function parentMessageIdMap(array $rows): array
    {
        $parentIds = [];
        foreach ($rows as $row) {
            $pid = (int)($row['reply_to_id'] ?? 0);
            if ($pid > 0) {
                $parentIds[$pid] = true;
            }
        }
        if ($parentIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, message_id, kludge_lines, message_text, from_address
             FROM netmail WHERE id IN ($placeholders)"
        );
        $stmt->execute(array_keys($parentIds));

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $parent) {
            $map[(int)$parent['id']] = $this->builder->messageIdFor($parent);
        }

        return $map;
    }
}
