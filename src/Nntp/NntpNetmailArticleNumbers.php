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
 * Per-user NNTP article numbering for the virtual netmail newsgroup
 * (docs/proposals/NNTPNetmail.md — "Article numbering").
 *
 * The per-echoarea analogue is {@see NntpArticleNumbers}. This class is the same
 * scheme — dense per user, never reused, monotonic watermark, retired numbers
 * return 423 — but partitioned by the *reading* user and scoped to the netmail
 * that user is entitled to see. Ownership is NOT `netmail.user_id`: it is the
 * compound predicate owned by {@see MessageHandler::netmailVisibilityFilter()},
 * so this class delegates every "which rows count" decision there.
 *
 * There is no bulk backfill migration; the first GROUP selection per user does
 * the initial allocation over that user's whole visible history.
 *
 * Backing store: nntp_netmail_article_numbers (user_id, article_number,
 * netmail_id) + nntp_netmail_watermark (user_id, last_article_number).
 */
final class NntpNetmailArticleNumbers
{
    private PDO $db;
    private MessageHandler $handler;
    /** 'either' (received + sent) or 'recipient' (received only). */
    private string $side;

    public function __construct(PDO $db, MessageHandler $handler, string $side = 'either')
    {
        $this->db = $db;
        $this->handler = $handler;
        $this->side = $side === 'recipient' ? 'recipient' : 'either';
    }

    /**
     * The visibility + soft-delete predicate for this user, as one SQL fragment
     * (alias `n`) plus its positional params.
     *
     * @return array{sql:string,params:list<mixed>}
     */
    public function visibilityScope(int $userId): array
    {
        $vis = $this->handler->netmailVisibilityFilter($userId, 'n', $this->side);
        $del = $this->handler->netmailNotDeletedFilter($userId, 'n');

        return [
            'sql' => '(' . $vis['sql'] . ') AND (' . $del['sql'] . ')',
            'params' => array_merge($vis['params'], $del['params']),
        ];
    }

    /**
     * Assign article numbers to any of this user's visible, not-yet-numbered
     * netmail rows in `netmail.id` (arrival) order. Serialized per user by the
     * `nntp_netmail_watermark` row lock; safe to call on every request.
     */
    public function ensureUser(int $userId): void
    {
        $scope = $this->visibilityScope($userId);

        $pending = $this->db->prepare(
            "SELECT EXISTS (
                 SELECT 1 FROM netmail n
                 WHERE {$scope['sql']}
                   AND NOT EXISTS (
                       SELECT 1 FROM nntp_netmail_article_numbers x
                       WHERE x.user_id = ? AND x.netmail_id = n.id
                   )
             ) AS pending"
        );
        $pending->execute([...$scope['params'], $userId]);
        if (!$pending->fetchColumn()) {
            return;
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $wmStmt = $this->db->prepare(
                'INSERT INTO nntp_netmail_watermark (user_id, last_article_number)
                 VALUES (?, 0)
                 ON CONFLICT (user_id)
                   DO UPDATE SET last_article_number = nntp_netmail_watermark.last_article_number
                 RETURNING last_article_number'
            );
            $wmStmt->execute([$userId]);
            $watermark = (int)$wmStmt->fetchColumn();

            $insert = $this->db->prepare(
                "INSERT INTO nntp_netmail_article_numbers (user_id, article_number, netmail_id)
                 SELECT ?, ? + ROW_NUMBER() OVER (ORDER BY n.id), n.id
                 FROM netmail n
                 WHERE {$scope['sql']}
                   AND NOT EXISTS (
                       SELECT 1 FROM nntp_netmail_article_numbers x
                       WHERE x.user_id = ? AND x.netmail_id = n.id
                   )
                 ORDER BY n.id
                 ON CONFLICT DO NOTHING"
            );
            $insert->execute([$userId, $watermark, ...$scope['params'], $userId]);
            $allocated = $insert->rowCount();

            if ($allocated > 0) {
                $bump = $this->db->prepare(
                    'UPDATE nntp_netmail_watermark
                     SET last_article_number = last_article_number + ?
                     WHERE user_id = ?'
                );
                $bump->execute([$allocated, $userId]);
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{low:int,high:int,count:int}
     */
    public function groupBounds(int $userId): array
    {
        $scope = $this->visibilityScope($userId);

        $stmt = $this->db->prepare(
            "SELECT
                 COALESCE((SELECT last_article_number FROM nntp_netmail_watermark WHERE user_id = ?), 0) AS high,
                 (SELECT MIN(article_number) FROM nntp_netmail_article_numbers WHERE user_id = ?) AS low_num,
                 (SELECT COUNT(*) FROM nntp_netmail_article_numbers a
                    JOIN netmail n ON n.id = a.netmail_id
                    WHERE a.user_id = ? AND {$scope['sql']}) AS cnt"
        );
        $stmt->execute([$userId, $userId, $userId, ...$scope['params']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $high = (int)($row['high'] ?? 0);
        $low = $row['low_num'] !== null ? (int)$row['low_num'] : $high + 1;

        return ['low' => $low, 'high' => $high, 'count' => (int)($row['cnt'] ?? 0)];
    }

    /**
     * netmail.id for an article number, or null (never assigned / retired / no
     * longer visible to this user).
     */
    public function netmailIdFor(int $userId, int $number): ?int
    {
        $scope = $this->visibilityScope($userId);

        $stmt = $this->db->prepare(
            "SELECT a.netmail_id
             FROM nntp_netmail_article_numbers a
             JOIN netmail n ON n.id = a.netmail_id
             WHERE a.user_id = ? AND a.article_number = ? AND {$scope['sql']}"
        );
        $stmt->execute([$userId, $number, ...$scope['params']]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int)$id;
    }

    /**
     * Article number for a netmail row in this user's space, or null if unallocated.
     */
    public function numberFor(int $userId, int $netmailId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT article_number FROM nntp_netmail_article_numbers
             WHERE user_id = ? AND netmail_id = ?'
        );
        $stmt->execute([$userId, $netmailId]);
        $num = $stmt->fetchColumn();

        return $num === false ? null : (int)$num;
    }

    /**
     * (article_number => netmail_id) pairs within [$lo, $hi], visible only,
     * ordered by article number. $hi null means "to the end".
     *
     * @return array<int,int>
     */
    public function range(int $userId, int $lo, ?int $hi = null, ?int $limit = null): array
    {
        $scope = $this->visibilityScope($userId);

        $sql =
            "SELECT a.article_number, a.netmail_id
             FROM nntp_netmail_article_numbers a
             JOIN netmail n ON n.id = a.netmail_id
             WHERE a.user_id = ?
               AND a.article_number >= CAST(? AS BIGINT)
               AND (CAST(? AS BIGINT) IS NULL OR a.article_number <= CAST(? AS BIGINT))
               AND {$scope['sql']}
             ORDER BY a.article_number";
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $lo, $hi, $hi, ...$scope['params']]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$number, $netmailId]) {
            $out[(int)$number] = (int)$netmailId;
        }

        return $out;
    }
}
