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

use BinktermPHP\AreaFix\AreaFixParser;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Binkp\Logger;

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
    private Logger $logger;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
        $this->logger = new Logger(Config::getLogPath('server.log'), Logger::LEVEL_INFO, false);
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

        $this->logger->info(ucfirst($robot) . " command sent to {$uplinkAddress} by user #{$sysopUserId}: " . implode(' | ', $commands));
    }

    /**
     * Parse an AreaFix or FileFix reply body into an array of area records.
     *
     * Delegates to the structural AreaFixParser, which handles Mystic BBS / MBSE
     * command blocks, delimited colon/pipe tables, and columnar/dotted-leader tables.
     *
     * @param string $body        Raw message body text
     * @param string $commandType Hint for parsing context (e.g. "%LIST", "%QUERY", "%UNLINKED")
     * @return array<int, array{name: string, description: string|null, action: string, is_subscribed: bool}> Parsed area records
     */
    public function parseResponseText(string $body, string $commandType = '%LIST'): array
    {
        $parser = new AreaFixParser();
        return $parser->parse($body);
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
        string $robot = 'areafix',
        bool $activateAll = false
    ): array {
        $created = 0;
        $activated = 0;
        $deactivated = 0;

        $table = ($robot === 'filefix') ? 'file_areas' : 'echoareas';
        $syncedTags = [];

        foreach ($parsedAreas as $area) {
            $tag = strtoupper(trim((string)($area['name'] ?? '')));
            if ($tag === '') {
                continue;
            }

            $syncedTags[] = $tag;
            $description = $area['description'] ?? null;
            $action = $area['action'] ?? ($activateAll ? AreaFixParser::ACTION_SUBSCRIBE : ($area['is_subscribed'] ?? true ? AreaFixParser::ACTION_SUBSCRIBE : AreaFixParser::ACTION_AVAILABLE));

            // If action is unsubscribe, deactivate the area if it exists
            if ($action === AreaFixParser::ACTION_UNSUBSCRIBE) {
                $stmt = $this->db->prepare(
                    "UPDATE {$table} SET is_active = FALSE WHERE UPPER(tag) = UPPER(?) AND domain = ? AND is_active = TRUE"
                );
                $stmt->execute([$tag, $domain]);
                if ($stmt->rowCount() > 0) {
                    $deactivated++;
                }
                continue;
            }

            // Check if area already exists
            $stmt = $this->db->prepare(
                "SELECT id, is_active, description" .
                ($table === 'echoareas' ? ", uplink_address" : "") .
                " FROM {$table} WHERE UPPER(tag) = UPPER(?) AND domain = ?"
            );
            $stmt->execute([$tag, $domain]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($existing) {
                // Update existing
                $updates = [];
                $params = [];

                if ($action === AreaFixParser::ACTION_SUBSCRIBE && !$existing['is_active']) {
                    $updates[] = 'is_active = TRUE';
                    $activated++;
                }

                // uplink_address only exists on echoareas, not file_areas
                if ($table === 'echoareas' && empty($existing['uplink_address'])) {
                    $updates[] = 'uplink_address = ?';
                    $params[] = $uplinkAddress;
                }

                if ($description !== null && !self::isPlaceholderDescription($description) && self::isPlaceholderDescription($existing['description'] ?? null)) {
                    $updates[] = 'description = ?';
                    $params[] = $description;
                }

                if (!empty($updates)) {
                    $params[] = $existing['id'];
                    $sql = "UPDATE {$table} SET " . implode(', ', $updates) . " WHERE id = ?";
                    $this->db->prepare($sql)->execute($params);
                }
            } else {
                // Determine whether new area should be active (only if confirmed subscribed or explicitly requested)
                $isActive = ($action === AreaFixParser::ACTION_SUBSCRIBE || $activateAll);

                // Insert new area
                if ($table === 'echoareas') {
                    $stmt = $this->db->prepare(
                        "INSERT INTO echoareas (tag, domain, uplink_address, description, is_active, color)
                         VALUES (?, ?, ?, ?, ?, '#28a745')
                         ON CONFLICT (tag, domain) DO UPDATE
                         SET is_active = EXCLUDED.is_active,
                             uplink_address = COALESCE(NULLIF(echoareas.uplink_address, ''), EXCLUDED.uplink_address),
                             description   = COALESCE(NULLIF(echoareas.description, ''), EXCLUDED.description)"
                    );
                    $stmt->execute([$tag, $domain, $uplinkAddress, $description, $isActive ? 'true' : 'false']);
                } else {
                    // file_areas uses domain to link to uplink — no uplink_address column
                    $stmt = $this->db->prepare(
                        "INSERT INTO file_areas (tag, domain, description, is_active)
                         VALUES (?, ?, ?, ?)
                         ON CONFLICT (tag, domain) DO UPDATE
                         SET is_active = EXCLUDED.is_active,
                             description = COALESCE(NULLIF(file_areas.description, ''), EXCLUDED.description)"
                    );
                    $stmt->execute([$tag, $domain, $description, $isActive ? 'true' : 'false']);
                }
                $created++;
                if ($isActive) {
                    $activated++;
                }
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
     * Find the newest incoming AreaFix/FileFix reply for an uplink that contains
     * actionable, parseable area data (skipping result receipts, error notices, and help text).
     *
     * Shared by the preview and apply code paths so that "this reply is actionable"
     * means exactly the same thing in both places.
     *
     * @param string $uplinkAddress FTN address of the hub uplink
     * @param int    $sysopUserId   User ID of the sysop account
     * @return array{message: array<string, mixed>, areas: array<int, array{name: string, description: string|null, action: string, is_subscribed: bool}>}|null
     */
    public function findLatestActionableReply(string $uplinkAddress, int $sysopUserId): ?array
    {
        foreach ($this->getIncomingMessages($uplinkAddress, $sysopUserId) as $m) {
            $parsed = $this->toActionableReply($m);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Find a specific incoming AreaFix/FileFix reply for an uplink by its netmail id
     * and confirm it contains actionable, parseable area data.
     *
     * @param string $uplinkAddress FTN address of the hub uplink
     * @param int    $sysopUserId   User ID of the sysop account
     * @param int    $messageId     netmail.id of the incoming reply to inspect
     * @return array{message: array<string, mixed>, areas: array<int, array{name: string, description: string|null, action: string, is_subscribed: bool}>}|null
     */
    public function findActionableReplyById(string $uplinkAddress, int $sysopUserId, int $messageId): ?array
    {
        foreach ($this->getIncomingMessages($uplinkAddress, $sysopUserId) as $m) {
            if ((int)($m['id'] ?? 0) !== $messageId) {
                continue;
            }
            return $this->toActionableReply($m);
        }

        return null;
    }

    /**
     * Return the incoming (hub-to-us) messages from an uplink's AreaFix/FileFix history.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getIncomingMessages(string $uplinkAddress, int $sysopUserId): array
    {
        $historyData = $this->getHistory($uplinkAddress, $sysopUserId);
        $messages = ($historyData['messages'] ?? $historyData);
        if (!is_array($messages)) {
            return [];
        }

        return array_values(array_filter($messages, static fn($m) => ($m['direction'] ?? '') === 'incoming'));
    }

    /**
     * Check whether a single incoming message is an actionable AreaFix/FileFix reply
     * and, if so, return it paired with its parsed areas.
     *
     * @param array<string, mixed> $message
     * @return array{message: array<string, mixed>, areas: array<int, array{name: string, description: string|null, action: string, is_subscribed: bool}>}|null
     */
    private function toActionableReply(array $message): ?array
    {
        $subj = (string)($message['subject'] ?? '');
        $bodyText = (string)($message['message_text'] ?? '');

        if (!$this->isAreaListResponse($subj, $bodyText)) {
            return null;
        }

        $areas = $this->parseResponseText($bodyText, '%LIST');
        if (empty($areas)) {
            return null;
        }

        return ['message' => $message, 'areas' => $areas];
    }

    /**
     * Compute what syncSubscribedAreas() would do for the given parsed areas,
     * without writing anything to the database.
     *
     * Each parsed area is classified against current local state as one of:
     * - "new": area does not exist locally yet and will be created (active or not,
     *   depending on action).
     * - "reactivate": area exists but is currently inactive and will be turned on.
     * - "deactivate": area is currently active and the parsed action is unsubscribe
     *   (or, when $deactivateMissing is true, the area is active locally but missing
     *   from the parsed list).
     * - "unchanged": area already matches the state the sync would produce.
     *
     * @param string $uplinkAddress     FTN address of the uplink hub
     * @param string $domain            Network domain (e.g. "fidonet")
     * @param array<int, array{name: string, description: string|null, action?: string, is_subscribed?: bool}> $parsedAreas
     * @param bool   $deactivateMissing If true, also list locally-active areas missing from the parsed list as deactivation candidates
     * @param string $robot             "areafix" or "filefix"
     * @return array<int, array{name: string, description: string|null, action: string, is_subscribed: bool, status: string, currently_active: bool}>
     */
    public function previewSync(
        string $uplinkAddress,
        string $domain,
        array $parsedAreas,
        bool $deactivateMissing = false,
        string $robot = 'areafix'
    ): array {
        $table = ($robot === 'filefix') ? 'file_areas' : 'echoareas';
        $items = [];
        $seenTags = [];

        foreach ($parsedAreas as $area) {
            $tag = strtoupper(trim((string)($area['name'] ?? '')));
            if ($tag === '' || isset($seenTags[$tag])) {
                continue;
            }
            $seenTags[$tag] = true;

            $description = $area['description'] ?? null;
            $isSubscribed = (bool)($area['is_subscribed'] ?? true);
            $action = $area['action'] ?? ($isSubscribed ? AreaFixParser::ACTION_SUBSCRIBE : AreaFixParser::ACTION_AVAILABLE);

            $stmt = $this->db->prepare(
                "SELECT is_active FROM {$table} WHERE UPPER(tag) = UPPER(?) AND domain = ?"
            );
            $stmt->execute([$tag, $domain]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
            $currentlyActive = $existing ? (bool)$existing['is_active'] : false;

            if ($action === AreaFixParser::ACTION_UNSUBSCRIBE) {
                $status = $currentlyActive ? 'deactivate' : 'unchanged';
            } elseif (!$existing) {
                $status = 'new';
            } elseif (!$currentlyActive && $action === AreaFixParser::ACTION_SUBSCRIBE) {
                $status = 'reactivate';
            } else {
                $status = 'unchanged';
            }

            $items[] = [
                'name'             => $tag,
                'description'      => $description,
                'action'           => $action,
                'is_subscribed'    => $isSubscribed,
                'status'           => $status,
                'currently_active' => $currentlyActive,
            ];
        }

        if ($deactivateMissing) {
            $sql = "SELECT tag, description FROM {$table} WHERE domain = ? AND is_active = TRUE";
            $params = [$domain];
            if ($table === 'echoareas') {
                $sql .= " AND uplink_address = ?";
                $params[] = $uplinkAddress;
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $tag = strtoupper(trim((string)$row['tag']));
                if ($tag === '' || isset($seenTags[$tag])) {
                    continue;
                }
                $seenTags[$tag] = true;

                $items[] = [
                    'name'             => $tag,
                    'description'      => $row['description'] ?? null,
                    'action'           => AreaFixParser::ACTION_UNSUBSCRIBE,
                    'is_subscribed'    => false,
                    'status'           => 'deactivate',
                    'currently_active' => true,
                ];
            }
        }

        return $items;
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
     * Check if an area description is considered an auto-generated placeholder
     * or contains ANSI box-drawing/block corruption.
     */
    public static function isPlaceholderDescription(?string $desc): bool
    {
        if ($desc === null || trim($desc) === '') {
            return true;
        }
        $trimmed = trim($desc);
        // Starts with "Auto-created" (e.g. "Auto-created from TIC file", "Auto-created: ...")
        if (preg_match('/^Auto-created\b/i', $trimmed)) {
            return true;
        }
        // Contains ANSI box drawing / block art characters (e.g. ▄▄▄)
        if (preg_match('/[▄█▀▌▐░▒▓─│┌┐└┘├┤┬┴┼═║]/u', $trimmed) || preg_match('/[\xB0-\xDF]/', $trimmed)) {
            return true;
        }
        return false;
    }

    /**
     * Check whether a message subject and body represent an AreaFix/FileFix area list
     * or actionable reply rather than an error or help notification.
     */
    public function isAreaListResponse(string $subject, string $body): bool
    {
        if (preg_match('/\b(invalid password|password error)\b/i', $subject)) {
            return false;
        }

        $parser = new AreaFixParser();
        return $parser->hasActionableContent($body, $subject);
    }

    /**
     * Check an incoming netmail message to see if it is an AreaFix or FileFix reply from a configured uplink.
     * If so, automatically parses the response and synchronizes the areas to the database.
     *
     * @param array $message Raw netmail array containing from_address, to_address, from_name, subject, message_text
     * @return array{matched: bool, uplink: string, domain: string, robot: string, count: int, summary: array, areas: array}|null
     */
    public function processIncomingReply(array $message): ?array
    {
        if (!empty($message['is_insecure'])) {
            return null;
        }

        $fromAddr = trim((string)($message['from_address'] ?? $message['origAddr'] ?? ''));
        $subject  = trim((string)($message['subject'] ?? ''));
        $fromName = trim((string)($message['from_name'] ?? $message['fromName'] ?? ''));
        $body     = (string)($message['message_text'] ?? $message['text'] ?? '');

        if ($fromAddr === '' || $body === '') {
            return null;
        }

        // Check if sender matches any configured uplink
        $binkpConfig = BinkpConfig::getInstance();
        $targetUplink = null;
        $normFrom = preg_replace('/\.0$/', '', $fromAddr);

        foreach ($binkpConfig->getUplinks() as $uplink) {
            $uAddr = preg_replace('/\.0$/', '', trim((string)($uplink['address'] ?? '')));
            if ($uAddr !== '' && $uAddr === $normFrom) {
                $targetUplink = $uplink;
                break;
            }
        }

        if (!$targetUplink) {
            return null;
        }

        // If the packet originating address is provided, ensure it matches the uplink
        if (!empty($message['packet_orig_addr'])) {
            $pktOrig = preg_replace('/\.0$/', '', trim((string)$message['packet_orig_addr']));
            if ($pktOrig !== '' && $pktOrig !== $normFrom) {
                return null;
            }
        }

        // Do not process non-actionable receipts or error notifications
        if (!$this->isAreaListResponse($subject, $body)) {
            return null;
        }

        // Verify that this is an AreaFix or FileFix reply
        $isAreafix = (bool)preg_match('/areafix/i', $subject . ' ' . $fromName);
        $isFilefix = (bool)preg_match('/filefix/i', $subject . ' ' . $fromName);

        if (!$isAreafix && !$isFilefix) {
            if (preg_match('/(?:available echoareas|area list for|areas linked at|here are the list of available|echoarea|\: AREA \: DESCRIPTION)/i', $body)) {
                $isAreafix = true;
            } elseif (preg_match('/(?:available fileareas|file area list|fileareas)/i', $body)) {
                $isFilefix = true;
            } else {
                return null;
            }
        }

        $robot = $isFilefix ? 'filefix' : 'areafix';
        $parsedAreas = $this->parseResponseText($body, '%LIST');

        if (empty($parsedAreas)) {
            return null;
        }

        $uplinkAddress = (string)$targetUplink['address'];
        $domain = (string)($targetUplink['domain'] ?? 'fidonet');

        $summary = $this->syncSubscribedAreas($uplinkAddress, $domain, $parsedAreas, false, $robot);

        $this->logger->info("[AreaFixManager] Auto-imported " . count($parsedAreas) . " areas for domain '{$domain}' from {$uplinkAddress}: created={$summary['created']}, activated={$summary['activated']}, deactivated={$summary['deactivated']}");

        return [
            'matched' => true,
            'uplink'  => $uplinkAddress,
            'domain'  => $domain,
            'robot'   => $robot,
            'count'   => count($parsedAreas),
            'summary' => $summary,
            'areas'   => $parsedAreas,
        ];
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
