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

use BinktermPHP\NetworkManager;
use PDO;

/**
 * Maps FTN echoareas to NNTP newsgroup names and back.
 *
 * Newsgroup name = `<NetworkPrefix>.<AreaTag>` following Synchronet's convention
 * (docs/proposals/NNTPServer.md — "Newsgroup Name Translation"). The prefix is the
 * network's display name (from the `networks` table) with non-alphanumerics stripped,
 * or the raw domain slug when `newsgroup_prefix_mode` is "domain", or `Local` for
 * local areas with no domain.
 *
 * One instance per connection is fine — it snapshots the active-area list once.
 */
class NntpNewsgroups
{
    private const TAG_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._+-]*$/';

    private PDO $db;
    private NntpConfig $config;
    private NetworkManager $networks;

    /** @var array<string,array<string,mixed>>|null  lowercased group name => echoarea row */
    private ?array $groupMap = null;

    /** @var array<string,string>  cached domain => sanitized display prefix */
    private array $prefixCache = [];

    public function __construct(PDO $db, ?NntpConfig $config = null, ?NetworkManager $networks = null)
    {
        $this->db = $db;
        $this->config = $config ?? NntpConfig::getInstance();
        $this->networks = $networks ?? new NetworkManager($db);
    }

    /**
     * Newsgroup name for an echoarea row, or null when its tag cannot form a legal
     * newsgroup component.
     *
     * @param array<string,mixed> $area  Row from `echoareas` (needs tag, domain, is_local).
     */
    public function groupNameForArea(array $area): ?string
    {
        $tag = trim((string)($area['tag'] ?? ''));
        if ($tag === '' || !preg_match(self::TAG_PATTERN, $tag)) {
            return null;
        }

        return $this->prefixForArea($area) . '.' . $tag;
    }

    /**
     * The hierarchy prefix for an area (network display name, domain slug, or Local).
     *
     * @param array<string,mixed> $area
     */
    public function prefixForArea(array $area): string
    {
        $domain = strtolower(trim((string)($area['domain'] ?? '')));

        if ($domain === '') {
            return 'Local';
        }

        if (isset($this->prefixCache[$domain])) {
            return $this->prefixCache[$domain];
        }

        $prefix = $domain;
        if ($this->config->getNewsgroupPrefixMode() === 'network_name') {
            $network = $this->networks->getByDomain($domain);
            if ($network !== null && trim((string)($network['name'] ?? '')) !== '') {
                $prefix = (string)$network['name'];
            }
        }

        $sanitized = preg_replace('/[^A-Za-z0-9]+/', '', $prefix) ?? '';
        if ($sanitized === '') {
            $sanitized = preg_replace('/[^A-Za-z0-9]+/', '', $domain) ?: 'Net';
        }

        return $this->prefixCache[$domain] = $sanitized;
    }

    /**
     * All active echoareas keyed by lowercased newsgroup name. Areas whose tag is not
     * a legal newsgroup component are skipped (collect them with skippedAreas()).
     *
     * @return array<string,array<string,mixed>>
     */
    public function groupMap(): array
    {
        if ($this->groupMap !== null) {
            return $this->groupMap;
        }

        $rows = $this->db->query(
            'SELECT id, tag, domain, description, is_local, is_sysop_only, created_at
             FROM echoareas
             WHERE is_active = TRUE
             ORDER BY domain NULLS FIRST, tag'
        )->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $group = $this->groupNameForArea($row);
            if ($group === null) {
                continue;
            }
            $key = strtolower($group);
            if (isset($map[$key])) {
                // Collision — keep the first, record nothing here (detectCollisions() reports).
                continue;
            }
            $row['nntp_group'] = $group;
            $map[$key] = $row;
        }

        return $this->groupMap = $map;
    }

    /**
     * Resolve a client-supplied newsgroup name (case-insensitive) to an echoarea row.
     *
     * @return array<string,mixed>|null
     */
    public function resolveGroup(string $name): ?array
    {
        return $this->groupMap()[strtolower(trim($name))] ?? null;
    }

    /**
     * Group names that collide (two or more (domain,tag) pairs producing the same
     * newsgroup name). Logged at daemon startup.
     *
     * @return array<string,string[]>  lowercased group name => list of "domain/tag"
     */
    public function detectCollisions(): array
    {
        $rows = $this->db->query(
            'SELECT tag, domain FROM echoareas WHERE is_active = TRUE'
        )->fetchAll(PDO::FETCH_ASSOC);

        $seen = [];
        foreach ($rows as $row) {
            $group = $this->groupNameForArea($row);
            if ($group === null) {
                continue;
            }
            $seen[strtolower($group)][] = (trim((string)($row['domain'] ?? '')) ?: 'local') . '/' . $row['tag'];
        }

        return array_filter($seen, static fn (array $srcs): bool => count($srcs) > 1);
    }

    /**
     * Active echoareas whose tag cannot form a legal newsgroup component.
     *
     * @return array<int,string>  "domain/tag"
     */
    public function skippedAreas(): array
    {
        $rows = $this->db->query(
            'SELECT tag, domain FROM echoareas WHERE is_active = TRUE'
        )->fetchAll(PDO::FETCH_ASSOC);

        $skipped = [];
        foreach ($rows as $row) {
            $tag = trim((string)($row['tag'] ?? ''));
            if ($tag === '' || !preg_match(self::TAG_PATTERN, $tag)) {
                $skipped[] = (trim((string)($row['domain'] ?? '')) ?: 'local') . '/' . $tag;
            }
        }

        return $skipped;
    }
}
