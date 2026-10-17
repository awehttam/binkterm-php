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
 */

namespace BinktermPHP;

/**
 * Door Category Helper
 *
 * Manages category normalization, standard genre definitions, network door detection,
 * and door categorization logic across CLI, admin, and front-end interfaces.
 */
class DoorCategoryHelper
{
    public const UNCATEGORIZED = '(uncategorized)';
    public const DOOR_SERVERS = 'Door Servers';
    public const NET_DOORPARTY = 'DoorParty';
    public const NET_BBSLINK = 'BBSLink';
    public const NET_ANET = 'A-Net';

    /**
     * Standard curated categories presented in admin dropdowns and front-end tabs.
     *
     * @return string[]
     */
    public static function getStandardCategories(): array
    {
        return [
            self::DOOR_SERVERS,
            self::NET_DOORPARTY,
            self::NET_BBSLINK,
            self::NET_ANET,
            'RPG',
            'Strategy',
            'Adventure',
            'Classic',
            'Multiplayer',
            'Arcade',
            'Sci-Fi',
            'Fantasy',
            'Simulation',
            'Space',
            'Action',
            'Casino',
            'Trivia',
            'Board Games',
            'Terminal',
            'Interactive Fiction',
            'Text',
            'MUD',
            'BBS',
            'Card Games',
            'Sports',
        ];
    }

    /**
     * Normalize a genre value (array or comma-separated string) to a list of trimmed strings.
     *
     * @param mixed $genre
     * @return string[]
     */
    public static function normalizeGenres($genre): array
    {
        if (is_string($genre)) {
            $genre = explode(',', $genre);
        }
        if (!is_array($genre)) {
            return [];
        }
        $out = [];
        foreach ($genre as $g) {
            $g = trim((string)$g);
            if ($g !== '') {
                $out[] = $g;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Check if a door is an external door server hub/gateway link rather than an individual game.
     *
     * @param string $doorId
     * @param string $doorName
     * @param string[] $genres
     * @return bool
     */
    public static function isDoorServer(string $doorId, string $doorName = '', array $genres = []): bool
    {
        $normalized = self::normalizeGenres($genres);
        foreach ($normalized as $g) {
            if (strcasecmp($g, self::DOOR_SERVERS) === 0) {
                return true;
            }
        }

        $idLower = strtolower(trim($doorId));
        if (in_array($idLower, ['doorparty', 'bbslinknative', 'anet'], true)) {
            return true;
        }

        $nameLower = strtolower(trim($doorName));
        if ($nameLower === 'doorparty' || $nameLower === 'bbslink door gateway' || $nameLower === 'a-net online game server') {
            return true;
        }

        return false;
    }

    /**
     * Detect if an individual door game belongs to an external door network (DoorParty, BBSLink, A-Net).
     * Returns null if not a network door or if the door is the server connector itself.
     *
     * @param string $doorId
     * @param string $doorName
     * @param string[] $genres
     * @return string|null
     */
    public static function detectNetwork(string $doorId, string $doorName = '', array $genres = []): ?string
    {
        if (self::isDoorServer($doorId, $doorName, $genres)) {
            return null;
        }

        $normalized = self::normalizeGenres($genres);
        foreach ($normalized as $g) {
            if (strcasecmp($g, self::NET_DOORPARTY) === 0) {
                return self::NET_DOORPARTY;
            }
            if (strcasecmp($g, self::NET_BBSLINK) === 0) {
                return self::NET_BBSLINK;
            }
            if (strcasecmp($g, self::NET_ANET) === 0 || strcasecmp($g, 'ANet') === 0) {
                return self::NET_ANET;
            }
        }

        $idLower = strtolower(trim($doorId));
        $nameLower = strtolower(trim($doorName));

        // DoorParty detection
        if (str_starts_with($idLower, 'dp-') || str_contains($nameLower, '(doorparty)')) {
            return self::NET_DOORPARTY;
        }

        // BBSLink detection
        if (str_starts_with($idLower, 'bbslink-') || str_contains($nameLower, '(bbslink)')) {
            return self::NET_BBSLINK;
        }

        // A-Net detection
        if (str_starts_with($idLower, 'anet-') || str_contains($nameLower, '(a-net)') || str_contains($nameLower, '(anet)')) {
            return self::NET_ANET;
        }

        return null;
    }

    /**
     * Get the effective display categories for a door based on sysop appearance preferences.
     *
     * @param array{id: string, name?: string, genre?: mixed} $door
     * @param bool $separateServers Whether to isolate DoorParty, BBSLink, and A-Net into their own tabs
     * @return string[]
     */
    public static function getEffectiveCategories(array $door, bool $separateServers = true): array
    {
        $doorId = (string)($door['id'] ?? '');
        $doorName = (string)($door['name'] ?? '');
        $genres = self::normalizeGenres($door['genre'] ?? ($door['categories'] ?? []));

        // Server hubs/gateways always belong to Door Servers
        if (self::isDoorServer($doorId, $doorName, $genres)) {
            return [self::DOOR_SERVERS];
        }

        // When network doors separation is enabled, keep them isolated on their own network tabs
        if ($separateServers) {
            $network = self::detectNetwork($doorId, $doorName, $genres);
            if ($network !== null) {
                return [$network];
            }
        }

        return !empty($genres) ? $genres : [self::UNCATEGORIZED];
    }

    /**
     * Scan and collect all installed doors across DOS, Native, JS-DOS, Web, and RLogin types.
     *
     * @return array<int, array{type: string, id: string, name: string, categories: string[], is_server: bool, network: ?string}>
     */
    public static function collectInstalledDoors(): array
    {
        $doors = [];

        try {
            foreach ((new DosBoxDoorManifest())->scanInstalledDoors() as $id => $m) {
                $categories = self::normalizeGenres($m['genre'] ?? []);
                $name = $m['name'] ?? $id;
                $doors[] = [
                    'type' => 'dos',
                    'id' => (string)$id,
                    'name' => $name,
                    'categories' => $categories,
                    'is_server' => self::isDoorServer((string)$id, $name, $categories),
                    'network' => self::detectNetwork((string)$id, $name, $categories),
                ];
            }
        } catch (\Throwable $e) {
            // DOS doors optional or uninitialized
        }

        try {
            foreach ((new NativeDoorManifest())->scanInstalledDoors() as $id => $m) {
                $categories = self::normalizeGenres($m['genre'] ?? []);
                $name = $m['name'] ?? $id;
                $doors[] = [
                    'type' => 'native',
                    'id' => (string)$id,
                    'name' => $name,
                    'categories' => $categories,
                    'is_server' => self::isDoorServer((string)$id, $name, $categories),
                    'network' => self::detectNetwork((string)$id, $name, $categories),
                ];
            }
        } catch (\Throwable $e) {
            // Native doors optional or uninitialized
        }

        try {
            foreach (JsdosDoorManifest::listManifests() as $entry) {
                $m = $entry['manifest'];
                $categories = self::normalizeGenres($m['genre'] ?? ($m['game']['genre'] ?? []));
                $name = $m['name'] ?? ($m['game']['name'] ?? $entry['id']);
                $doors[] = [
                    'type' => 'jsdos',
                    'id' => (string)$entry['id'],
                    'name' => $name,
                    'categories' => $categories,
                    'is_server' => self::isDoorServer((string)$entry['id'], $name, $categories),
                    'network' => self::detectNetwork((string)$entry['id'], $name, $categories),
                ];
            }
        } catch (\Throwable $e) {
            // JS-DOS doors optional or uninitialized
        }

        try {
            foreach (WebDoorManifest::listManifests() as $entry) {
                $m = $entry['manifest'];
                $game = $m['game'] ?? [];
                $categories = self::normalizeGenres($game['genre'] ?? ($game['category'] ?? []));
                $name = $game['name'] ?? $entry['id'];
                $doors[] = [
                    'type' => 'webdoor',
                    'id' => (string)$entry['id'],
                    'name' => $name,
                    'categories' => $categories,
                    'is_server' => self::isDoorServer((string)$entry['id'], $name, $categories),
                    'network' => self::detectNetwork((string)$entry['id'], $name, $categories),
                ];
            }
        } catch (\Throwable $e) {
            // Web doors optional or uninitialized
        }

        try {
            Database::getInstance()->getPdo();
            foreach ((new RLoginDoorManager())->getAllDoors() as $d) {
                $categories = self::normalizeGenres($d['genre'] ?? []);
                $name = $d['name'] ?? ($d['door_id'] ?? '');
                $id = (string)($d['door_id'] ?? '');
                $doors[] = [
                    'type' => 'rlogin',
                    'id' => $id,
                    'name' => $name,
                    'categories' => $categories,
                    'is_server' => self::isDoorServer($id, $name, $categories),
                    'network' => self::detectNetwork($id, $name, $categories),
                ];
            }
        } catch (\Throwable $e) {
            // RLogin doors optional or DB unreachable
        }

        return $doors;
    }

    /**
     * Get a combined list of standard categories and any custom categories present in installed doors.
     *
     * @return string[]
     */
    public static function getAllCategories(): array
    {
        $categories = self::getStandardCategories();
        $seen = array_fill_keys(array_map('strtolower', $categories), true);

        try {
            $doors = self::collectInstalledDoors();
            foreach ($doors as $door) {
                foreach ($door['categories'] as $cat) {
                    $catLower = strtolower($cat);
                    if (!isset($seen[$catLower]) && $cat !== self::UNCATEGORIZED) {
                        $seen[$catLower] = true;
                        $categories[] = $cat;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall back to standard categories if scan fails
        }

        return $categories;
    }
}
