<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
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

namespace BinktermPHP;

use BinktermPHP\Binkp\Config\BinkpConfig;

/**
 * Manages AreaFix and FileFix robot interactions for FTN hub uplinks.
 *
 * AreaFix and FileFix are Fidonet robot services that allow downlink nodes to
 * manage their echomail and file-area subscriptions by exchanging specially
 * formatted netmail messages with the hub.
 */
class AreaFixManager
{
    /** @var \PDO */
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    /**
     * Send AreaFix or FileFix commands to a hub uplink.
     *
     * The password is placed in the subject line (per AreaFix protocol) and the
     * commands are placed one per line in the message body. Subject masking in
     * MessageHandler::cleanMessageForJson() will obscure the password from display.
     *
     * @param string   $uplinkAddress  FTN address of the hub (e.g. "1:1/23")
     * @param string[] $commands       Command lines (e.g. ["%QUERY"], ["+SYSOP", "-FIDONEWS"])
     * @param string   $robot          "areafix" or "filefix"
     * @param int      $sysopUserId    User ID of the sysop account
     * @throws \RuntimeException If password is not configured or send fails
     */
    public function sendCommand(
        string $uplinkAddress,
        array $commands,
        string $robot,
        int $sysopUserId
    ): void {
        $binkpConfig = BinkpConfig::getInstance();

        if ($robot === 'filefix') {
            $password = $binkpConfig->getFilefixPassword($uplinkAddress);
            $toName = 'FileFix';
        } else {
            $password = $binkpConfig->getAreafixPassword($uplinkAddress);
            $toName = 'AreaFix';
        }

        if ($password === '') {
            throw new \RuntimeException(
                "No " . ucfirst($robot) . " password configured for uplink {$uplinkAddress}."
            );
        }

        $messageText = implode("\r\n", $commands);

        $messageHandler = new MessageHandler();
        $messageHandler->sendNetmail(
            $sysopUserId,
            $uplinkAddress,
            $toName,
            $password,
            $messageText
        );
    }

    /**
     * Parse a %QUERY, %LIST, or %UNLINKED reply body into an array of area records.
     *
     * Handles multiple hub software formats (Binkd/Husky, FrontDoor/InterMail,
     * Mystic BBS/MBSE). Returns an empty array if fewer than 2 valid areas are
     * found, which indicates the body is likely an error or status message rather
     * than an area list.
     *
     * @param string $body        Raw message body text
     * @param string $commandType Hint for parsing context (e.g. "list", "query", "unlinked")
     * @return array<int, array{name: string, description: string|null}> Parsed area records
     */
    public function parseResponseText(string $body, string $commandType): array
    {
        $areas = [];

        // Normalize line endings
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = explode("\n", $body);

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip blank lines
            if ($trimmed === '') {
                continue;
            }

            // Skip header/footer/decorative lines
            if ($this->isSkippableLine($trimmed)) {
                continue;
            }

            // Extract area tag (first whitespace-delimited token)
            $parts = preg_split('/\s+/', $trimmed, 2);
            if (!$parts || count($parts) === 0) {
                continue;
            }

            $tag = strtoupper($parts[0]);

            // Validate tag pattern: uppercase letters, digits, underscore, hyphen, dot; minimum 2 chars
            if (!preg_match('/^[A-Z0-9_\-\.]{2,}$/', $tag)) {
                continue;
            }

            // Extract description from remainder of line
            $description = null;
            if (isset($parts[1])) {
                $remainder = $parts[1];

                // Strip leading separators: " - ", tab, or two or more spaces
                $remainder = preg_replace('/^(\s*-\s+|\t+|\s{2,})/', '', $remainder);
                $remainder = trim($remainder);

                if ($remainder !== '') {
                    $description = $remainder;
                }
            }

            $areas[] = [
                'name'        => $tag,
                'description' => $description,
            ];
        }

        // If fewer than 2 valid areas parsed, assume this is not an area list response
        if (count($areas) < 2) {
            return [];
        }

        return $areas;
    }

    /**
     * Determine whether a line should be skipped during area list parsing.
     *
     * Skips header/footer lines, error lines, decorative separators, and lines
     * that are clearly not area tags.
     *
     * @param string $line Trimmed line to evaluate
     * @return bool True if the line should be skipped
     */
    private function isSkippableLine(string $line): bool
    {
        $lower = strtolower($line);

        // Lines starting with error or percent prefixes
        if (str_starts_with($line, '-ERR') || str_starts_with($line, '+ERR')) {
            return true;
        }
        if (str_starts_with($line, '%')) {
            return true;
        }

        // Known header patterns
        $headerPatterns = [
            'area list',
            'linked at',
            'areas linked',
            'echo areas',
            'file areas',
            'areafix',
            'filefix',
            'binkd',
            'mbse',
            'mystic',
            'intermail',
            'frontdoor',
            'husky',
            'fmail',
            'binkley',
            'available areas',
            'subscribed areas',
            'not linked',
            'unlinked areas',
            'areas available',
            'your subscriptions',
            'end of',
            'begin of',
        ];

        foreach ($headerPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        // Pure separator/decorative lines (---, ===, ***,  etc.)
        if (preg_match('/^[-=*#~\s]{3,}$/', $line)) {
            return true;
        }

        return false;
    }

    /**
     * Synchronize parsed area names into the local echoareas or file_areas table.
     *
     * For each area in $parsedAreas:
     * - If a matching row exists (same tag+domain): ensure is_active=true, update
     *   uplink_address and description if not already set.
     * - If no row exists: INSERT a new row with is_active=true.
     *
     * If $deactivateMissing is true, any rows for this uplink+domain that are NOT
     * in the parsed list will be set to is_active=false.
     *
     * For FileFix (robot = "filefix") the sync targets the file_areas table.
     * For AreaFix the sync targets the echoareas table.
     *
     * @param string $uplinkAddress     FTN address of the uplink hub
     * @param string $domain            Network domain (e.g. "fidonet")
     * @param array<int, array{name: string, description: string|null}> $parsedAreas
     * @param bool   $deactivateMissing If true, deactivate areas not in the list
     * @param string $robot             "areafix" or "filefix"
     * @return array{created: int, activated: int, deactivated: int}
     */
    public function syncSubscribedAreas(
        string $uplinkAddress,
        string $domain,
        array $parsedAreas,
        bool $deactivateMissing = false,
        string $robot = 'areafix'
    ): array {
        $created = 0;
        $activated = 0;
        $deactivated = 0;

        $table = ($robot === 'filefix') ? 'file_areas' : 'echoareas';
        $syncedTags = [];

        foreach ($parsedAreas as $area) {
            $tag = strtoupper(trim($area['name']));
            if ($tag === '') {
                continue;
            }

            $syncedTags[] = $tag;
            $description = $area['description'] ?? null;

            // Check if area already exists
            $stmt = $this->db->prepare(
                "SELECT id, is_active, description" .
                ($table === 'echoareas' ? ", uplink_address" : "") .
                " FROM {$table} WHERE UPPER(tag) = UPPER(?) AND domain = ?"
            );
            $stmt->execute([$tag, $domain]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($existing) {
                // Update existing: ensure active, set uplink/description if missing
                $updates = [];
                $params = [];

                if (!$existing['is_active']) {
                    $updates[] = 'is_active = TRUE';
                    $activated++;
                }

                // uplink_address only exists on echoareas, not file_areas
                if ($table === 'echoareas' && empty($existing['uplink_address'])) {
                    $updates[] = 'uplink_address = ?';
                    $params[] = $uplinkAddress;
                }

                if ($description !== null && empty($existing['description'])) {
                    $updates[] = 'description = ?';
                    $params[] = $description;
                }

                if (!empty($updates)) {
                    $params[] = $existing['id'];
                    $sql = "UPDATE {$table} SET " . implode(', ', $updates) . " WHERE id = ?";
                    $this->db->prepare($sql)->execute($params);
                }
            } else {
                // Insert new area
                if ($table === 'echoareas') {
                    $stmt = $this->db->prepare(
                        "INSERT INTO echoareas (tag, domain, uplink_address, description, is_active, color)
                         VALUES (?, ?, ?, ?, TRUE, '#28a745')
                         ON CONFLICT (tag, domain) DO UPDATE
                         SET is_active = TRUE,
                             uplink_address = COALESCE(NULLIF(echoareas.uplink_address, ''), EXCLUDED.uplink_address),
                             description   = COALESCE(NULLIF(echoareas.description, ''), EXCLUDED.description)"
                    );
                    $stmt->execute([$tag, $domain, $uplinkAddress, $description]);
                } else {
                    // file_areas uses domain to link to uplink — no uplink_address column
                    $stmt = $this->db->prepare(
                        "INSERT INTO file_areas (tag, domain, description, is_active)
                         VALUES (?, ?, ?, TRUE)
                         ON CONFLICT (tag, domain) DO UPDATE
                         SET is_active = TRUE,
                             description = COALESCE(NULLIF(file_areas.description, ''), EXCLUDED.description)"
                    );
                    $stmt->execute([$tag, $domain, $description]);
                }
                $created++;
            }
        }

        // Optionally deactivate areas that were not in the parsed list
        if ($deactivateMissing && !empty($syncedTags)) {
            $placeholders = implode(',', array_fill(0, count($syncedTags), '?'));
            // echoareas: filter by uplink_address; file_areas: filter by domain only
            if ($table === 'echoareas') {
                $params = array_merge([$uplinkAddress, $domain], array_map('strtoupper', $syncedTags));
                $whereClause = "uplink_address = ? AND domain = ? AND is_active = TRUE AND UPPER(tag) NOT IN ({$placeholders})";
            } else {
                $params = array_merge([$domain], array_map('strtoupper', $syncedTags));
                $whereClause = "domain = ? AND is_active = TRUE AND UPPER(tag) NOT IN ({$placeholders})";
            }
            $stmt = $this->db->prepare("UPDATE {$table} SET is_active = FALSE WHERE {$whereClause}");
            $stmt->execute($params);
            $deactivated = (int)$stmt->rowCount();
        } elseif ($deactivateMissing && empty($syncedTags)) {
            if ($table === 'echoareas') {
                $stmt = $this->db->prepare(
                    "UPDATE {$table} SET is_active = FALSE WHERE uplink_address = ? AND domain = ? AND is_active = TRUE"
                );
                $stmt->execute([$uplinkAddress, $domain]);
            } else {
                $stmt = $this->db->prepare(
                    "UPDATE {$table} SET is_active = FALSE WHERE domain = ? AND is_active = TRUE"
                );
                $stmt->execute([$domain]);
            }
            $deactivated = (int)$stmt->rowCount();
        }

        return [
            'created'     => $created,
            'activated'   => $activated,
            'deactivated' => $deactivated,
        ];
    }

    /**
     * Mark a local echo area as inactive (called after successful unsubscribe).
     *
     * @param string $areaTag Area tag to deactivate
     * @param string $domain  Network domain
     */
    public function deactivateArea(string $areaTag, string $domain): void
    {
        $stmt = $this->db->prepare(
            "UPDATE echoareas SET is_active = FALSE WHERE UPPER(tag) = UPPER(?) AND domain = ?"
        );
        $stmt->execute([$areaTag, $domain]);
    }

    /**
     * Return AreaFix/FileFix message history for a hub uplink.
     *
     * Delegates to MessageHandler::getLovlyNetRequests() which fetches both
     * outgoing requests (sent to AreaFix/FileFix at the hub) and incoming
     * responses (received from the hub's robot).
     *
     * @param string $uplinkAddress FTN address of the hub uplink
     * @param int    $sysopUserId   User ID of the sysop account
     * @return array<int, array<string, mixed>>
     */
    public function getHistory(string $uplinkAddress, int $sysopUserId): array
    {
        $messageHandler = new MessageHandler();
        return $messageHandler->getLovlyNetRequests($sysopUserId, $uplinkAddress);
    }

    /**
     * Return all enabled uplinks that have an areafix_password or filefix_password configured.
     *
     * @return array<int, array{address: string, domain: string, has_areafix: bool, has_filefix: bool}>
     */
    public function getConfiguredUplinks(): array
    {
        $binkpConfig = BinkpConfig::getInstance();
        $result = [];

        foreach ($binkpConfig->getEnabledUplinks() as $uplink) {
            $address = trim((string)($uplink['address'] ?? ''));
            if ($address === '') {
                continue;
            }

            $hasAreafix = !empty(trim((string)($uplink['areafix_password'] ?? '')));
            $hasFilefix = !empty(trim((string)($uplink['filefix_password'] ?? '')));

            if (!$hasAreafix && !$hasFilefix) {
                continue;
            }

            $result[] = [
                'address'     => $address,
                'domain'      => (string)($uplink['domain'] ?? 'unknown'),
                'has_areafix' => $hasAreafix,
                'has_filefix' => $hasFilefix,
            ];
        }

        return $result;
    }
}
