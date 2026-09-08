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
 * {@see NntpGroupSource} backed by an `echoareas` row — the FTN echomail path.
 *
 * This is a straight lift of the echoarea-specific logic that used to live inline
 * in {@see NntpSession} (article loads, parent-Message-ID prefetch, the NEWNEWS
 * and Message-ID lookups). Behaviour is unchanged; article numbering is still
 * {@see NntpArticleNumbers} keyed on `echoarea_id`, translation still
 * {@see NntpArticleBuilder}.
 */
final class EchomailGroupSource implements NntpGroupSource
{
    private const COLUMNS =
        'id, echoarea_id, from_address, from_name, to_name, subject, message_text,
         date_written, date_received, reply_to_id, message_id, origin_line,
         kludge_lines, bottom_kludges, message_charset';

    private PDO $db;
    /** @var array<string,mixed>  echoareas row, including the derived `nntp_group` */
    private array $area;
    private int $areaId;
    private string $group;
    private NntpArticleNumbers $numbers;
    private NntpArticleBuilder $builder;
    private bool $postable;

    /**
     * @param array<string,mixed> $area      echoareas row with `nntp_group` set
     * @param bool                $postable  whether the authenticated user may POST
     *                                       to this group (global NNTP posting flag
     *                                       AND area write access) — drives the
     *                                       LIST ACTIVE `y`/`n` status field
     */
    public function __construct(
        PDO $db,
        array $area,
        NntpArticleNumbers $numbers,
        NntpArticleBuilder $builder,
        bool $postable = false
    ) {
        $this->db = $db;
        $this->area = $area;
        $this->areaId = (int)$area['id'];
        $this->group = (string)($area['nntp_group'] ?? '');
        $this->numbers = $numbers;
        $this->builder = $builder;
        $this->postable = $postable;
    }

    public function groupName(): string
    {
        return $this->group;
    }

    public function description(): string
    {
        $desc = trim((string)($this->area['description'] ?? ''));

        return $desc === '' ? $this->group : preg_replace('/\s+/', ' ', $desc);
    }

    public function isPostable(): bool
    {
        // Advertise `y` when the authenticated user can actually POST here, so
        // newsreaders enable their compose/reply UI for the group. Per RFC 3977
        // this flag is advisory; the POST response remains authoritative and may
        // still reject an individual article (rate limit, moderation, etc.).
        return $this->postable;
    }

    public function createdAtUnix(): ?int
    {
        $created = strtotime((string)($this->area['created_at'] ?? '') . ' UTC');

        return $created === false ? null : $created;
    }

    public function ensureNumbered(): void
    {
        $this->numbers->ensureArea($this->areaId);
    }

    public function bounds(): array
    {
        return $this->numbers->groupBounds($this->areaId);
    }

    public function range(int $lo, ?int $hi = null, ?int $limit = null): array
    {
        return $this->numbers->range($this->areaId, $lo, $hi, $limit);
    }

    public function article(int $number): ?array
    {
        $row = $this->rowForNumber($number);

        return $row === null ? null : $this->builder->build($row, $this->area, $this->group, $number);
    }

    public function overview(int $number): ?string
    {
        $row = $this->rowForNumber($number);

        return $row === null ? null : $this->builder->overviewLine($row, $this->area, $this->group, $number);
    }

    public function messageIdForNumber(int $number): ?string
    {
        $row = $this->rowForNumber($number);

        return $row === null ? null : $this->builder->messageIdFor($row, $this->group);
    }

    public function overviewBatch(array $numberToId): array
    {
        $rows = $this->loadRows(array_values($numberToId));
        $parentIds = $this->parentMessageIdMap($rows);

        $out = [];
        foreach ($numberToId as $number => $id) {
            if (isset($rows[$id])) {
                $out[$number] = $this->builder->overviewLine($rows[$id], $this->area, $this->group, $number, $parentIds);
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
                $out[$number] = $this->builder->build($rows[$id], $this->area, $this->group, $number, $parentIds);
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

        // Match the raw MSGID by its trailing serial within this area.
        $stmt = $this->db->prepare(
            "SELECT id FROM echomail
             WHERE echoarea_id = ?
               AND COALESCE(moderation_status,'approved') = 'approved'
               AND (message_id = ? OR message_id LIKE ?)
             ORDER BY id LIMIT 1"
        );
        $stmt->execute([$this->areaId, $parsed['serial'], '% ' . $parsed['serial']]);
        $emId = $stmt->fetchColumn();
        if ($emId === false) {
            return null;
        }

        return $this->numbers->numberFor($this->areaId, (int)$emId);
    }

    public function newMessageIdsSince(int $sinceUnix): array
    {
        $stmt = $this->db->prepare(
            "SELECT message_id, kludge_lines, message_text, from_address
             FROM echomail
             WHERE echoarea_id = ?
               AND COALESCE(moderation_status,'approved') = 'approved'
               AND date_received >= to_timestamp(?)"
        );
        $stmt->execute([$this->areaId, $sinceUnix]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $em) {
            $out[] = $this->builder->messageIdFor($em, $this->group);
        }

        return array_values(array_unique($out));
    }

    // ── internals ──────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    private function rowForNumber(int $number): ?array
    {
        $emId = $this->numbers->echomailIdFor($this->areaId, $number);
        if ($emId === null) {
            return null;
        }
        $rows = $this->loadRows([$emId]);

        return $rows[$emId] ?? null;
    }

    /**
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
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . " FROM echomail WHERE id IN ($placeholders)");
        $stmt->execute($ids);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['id']] = $row;
        }

        return $map;
    }

    /**
     * Constructed Message-ID for every distinct parent referenced by a batch of
     * rows — replies live in the same echoarea as their parent, so one group name
     * applies and `References:` needs no per-article walk.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,string>  parent echomail id => Message-ID
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
             FROM echomail WHERE id IN ($placeholders)"
        );
        $stmt->execute(array_keys($parentIds));

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $parent) {
            $map[(int)$parent['id']] = $this->builder->messageIdFor($parent, $this->group);
        }

        return $map;
    }
}
