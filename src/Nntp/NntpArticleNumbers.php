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

use PDO;

/**
 * Per-echoarea NNTP article numbering (docs/proposals/NNTPServer.md — "Article Numbering").
 *
 * Numbers are dense per area and, per RFC 3977, never reused within a group. Backing
 * store: `nntp_article_numbers` (echoarea_id, article_number, echomail_id) plus
 * `nntp_area_watermark` (echoarea_id, last_article_number) — a high-water mark that
 * only ever increases, so a pruned message's number is retired rather than reissued.
 *
 * Allocation is lazy: {@see ensureArea()} is called at the top of GROUP / LISTGROUP /
 * OVER / ARTICLE-by-number and assigns numbers to any not-yet-mapped approved echomail
 * rows in id order. Nothing hooks the echomail insert paths.
 */
class NntpArticleNumbers
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Assign article numbers to any approved echomail rows in the area that do not
     * have one yet, in `echomail.id` (arrival) order. Serialized per area by the
     * `nntp_area_watermark` row lock; safe to call on every request.
     */
    public function ensureArea(int $echoareaId): void
    {
        $pending = $this->db->prepare(
            'SELECT EXISTS (
                 SELECT 1 FROM echomail em
                 WHERE em.echoarea_id = :id
                   AND COALESCE(em.moderation_status, \'approved\') = \'approved\'
                   AND NOT EXISTS (
                       SELECT 1 FROM nntp_article_numbers n
                       WHERE n.echoarea_id = :id AND n.echomail_id = em.id
                   )
             ) AS pending'
        );
        $pending->execute(['id' => $echoareaId]);
        if (!$pending->fetchColumn()) {
            return;
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            // Take the per-area lock (and create the watermark row on first use).
            $wmStmt = $this->db->prepare(
                'INSERT INTO nntp_area_watermark (echoarea_id, last_article_number)
                 VALUES (:id, 0)
                 ON CONFLICT (echoarea_id)
                   DO UPDATE SET last_article_number = nntp_area_watermark.last_article_number
                 RETURNING last_article_number'
            );
            $wmStmt->execute(['id' => $echoareaId]);
            $watermark = (int)$wmStmt->fetchColumn();

            $insert = $this->db->prepare(
                'INSERT INTO nntp_article_numbers (echoarea_id, article_number, echomail_id)
                 SELECT :id,
                        :wm + ROW_NUMBER() OVER (ORDER BY em.id),
                        em.id
                 FROM echomail em
                 WHERE em.echoarea_id = :id
                   AND COALESCE(em.moderation_status, \'approved\') = \'approved\'
                   AND NOT EXISTS (
                       SELECT 1 FROM nntp_article_numbers n
                       WHERE n.echoarea_id = :id AND n.echomail_id = em.id
                   )
                 ORDER BY em.id
                 ON CONFLICT DO NOTHING'
            );
            $insert->execute(['id' => $echoareaId, 'wm' => $watermark]);
            $allocated = $insert->rowCount();

            if ($allocated > 0) {
                $bump = $this->db->prepare(
                    'UPDATE nntp_area_watermark
                     SET last_article_number = last_article_number + :n
                     WHERE echoarea_id = :id'
                );
                $bump->execute(['n' => $allocated, 'id' => $echoareaId]);
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
     * GROUP/LISTGROUP bounds for an area.
     *
     * @return array{low:int,high:int,count:int}
     */
    public function groupBounds(int $echoareaId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                 COALESCE((SELECT last_article_number FROM nntp_area_watermark WHERE echoarea_id = :id), 0) AS high,
                 (SELECT MIN(article_number) FROM nntp_article_numbers WHERE echoarea_id = :id) AS low_num,
                 (SELECT COUNT(*) FROM nntp_article_numbers n
                    JOIN echomail em ON em.id = n.echomail_id
                    WHERE n.echoarea_id = :id
                      AND COALESCE(em.moderation_status, \'approved\') = \'approved\') AS cnt'
        );
        $stmt->execute(['id' => $echoareaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $high = (int)($row['high'] ?? 0);
        $low = $row['low_num'] !== null ? (int)$row['low_num'] : $high + 1;

        return ['low' => $low, 'high' => $high, 'count' => (int)($row['cnt'] ?? 0)];
    }

    /**
     * echomail.id for a given article number in an area, or null (never assigned /
     * pruned / not currently approved).
     */
    public function echomailIdFor(int $echoareaId, int $number): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT n.echomail_id
             FROM nntp_article_numbers n
             JOIN echomail em ON em.id = n.echomail_id
             WHERE n.echoarea_id = :id AND n.article_number = :num
               AND COALESCE(em.moderation_status, \'approved\') = \'approved\''
        );
        $stmt->execute(['id' => $echoareaId, 'num' => $number]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int)$id;
    }

    /**
     * Article number for an echomail row in an area, or null if not yet allocated.
     */
    public function numberFor(int $echoareaId, int $echomailId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT article_number FROM nntp_article_numbers
             WHERE echoarea_id = :id AND echomail_id = :mid'
        );
        $stmt->execute(['id' => $echoareaId, 'mid' => $echomailId]);
        $num = $stmt->fetchColumn();

        return $num === false ? null : (int)$num;
    }

    /**
     * All (article_number => echomail_id) pairs in an area within [lo, hi], approved
     * only, ordered by article number. $hi null means "to the end". $limit caps rows.
     *
     * @return array<int,int>
     */
    public function range(int $echoareaId, int $lo, ?int $hi = null, ?int $limit = null): array
    {
        $sql =
            'SELECT n.article_number, n.echomail_id
             FROM nntp_article_numbers n
             JOIN echomail em ON em.id = n.echomail_id
             WHERE n.echoarea_id = :id
               AND n.article_number >= :lo
               AND (CAST(:hi AS BIGINT) IS NULL OR n.article_number <= CAST(:hi AS BIGINT))
               AND COALESCE(em.moderation_status, \'approved\') = \'approved\'
             ORDER BY n.article_number';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue('id', $echoareaId, PDO::PARAM_INT);
        $stmt->bindValue('lo', $lo, PDO::PARAM_INT);
        $stmt->bindValue('hi', $hi, $hi === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$number, $echomailId]) {
            $out[(int)$number] = (int)$echomailId;
        }

        return $out;
    }
}
