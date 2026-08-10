<?php

namespace BinktermPHP\Hub;

use PDO;
use BinktermPHP\Database;

/**
 * Server-side Areafix/Filefix robot: intercepts netmail addressed to
 * "AreaFix" or "FileFix" at one of our own AKAs, authenticates the sender
 * against a registered hub_nodes row's areafix_password/filefix_password,
 * and processes +TAG/-TAG subscribe/unsubscribe plus %LIST/%QUERY/%HELP/
 * %PAUSE/%RESUME commands against hub_node_areas/hub_node_fileareas. This
 * is the server-side counterpart to the existing client-side
 * AreaFixManager, which sends these same commands *to* our own uplinks.
 * See docs/proposals/HubPointSystemJuly2026.md Phase 5.
 */
class HubAreafixProcessor
{
    private PDO $db;
    private HubNodeManager $nodeManager;
    private HubNetmailRouter $netmailRouter;

    public function __construct(?PDO $db = null, ?HubNodeManager $nodeManager = null, ?HubNetmailRouter $netmailRouter = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->nodeManager = $nodeManager ?? new HubNodeManager($this->db);
        $this->netmailRouter = $netmailRouter ?? new HubNetmailRouter($this->db, $this->nodeManager);
    }

    /**
     * If $message is netmail addressed to "AreaFix" or "FileFix" at one of
     * our own AKAs, process it as a robot command and return true - the
     * caller must not deliver or otherwise process this message further,
     * whether or not authentication/commands actually succeeded. Returns
     * false for anything else so the caller falls through to normal
     * delivery handling.
     *
     * @param array $message Raw inbound message array as built by
     *   BinkdProcessor (destAddr, origAddr, fromName, toName, subject,
     *   text, dateTime, attributes).
     */
    public function processIncoming(array $message): bool
    {
        $toName = trim((string)($message['toName'] ?? ''));
        if (strcasecmp($toName, 'AreaFix') === 0) {
            $robot = 'areafix';
        } elseif (strcasecmp($toName, 'FileFix') === 0) {
            $robot = 'filefix';
        } else {
            return false;
        }

        $destAddr = trim((string)($message['destAddr'] ?? ''));
        if ($destAddr === '' || !in_array($destAddr, $this->nodeManager->getConfiguredAkas(), true)) {
            // Addressed to "AreaFix"/"FileFix" but not at one of our own
            // AKAs - not for us to handle (e.g. transit mail for a
            // downlink's own robot).
            return false;
        }

        $origAddr = trim((string)($message['origAddr'] ?? ''));
        $hubNode = $origAddr !== '' ? $this->nodeManager->getByAddress($origAddr) : null;
        if (!$hubNode || !$hubNode['enabled']) {
            // Not a registered subordinate - swallow without replying, so
            // this can't be used as a backscatter oracle for unsolicited
            // "AreaFix"-addressed netmail from arbitrary senders.
            return true;
        }

        $passwordField = $robot === 'filefix' ? 'filefix_password' : 'areafix_password';
        $expectedPassword = (string)($hubNode[$passwordField] ?? '');
        $providedPassword = (string)($message['subject'] ?? '');

        if ($expectedPassword === '' || !hash_equals($expectedPassword, $providedPassword)) {
            $this->sendReply($message, $hubNode, $robot, ['Password incorrect.']);
            return true;
        }

        $replyLines = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)($message['text'] ?? '')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ord($line[0]) === 0x01) {
                continue; // blank or kludge line
            }
            if (!empty($replyLines)) {
                $replyLines[] = '';
            }
            $replyLines[] = "> {$line}";
            $replyLines[] = '';
            $replyLines = array_merge($replyLines, $this->processCommand($line, $hubNode, $robot));
        }

        if (empty($replyLines)) {
            $replyLines[] = 'No commands found.';
        }

        $this->sendReply($message, $hubNode, $robot, $replyLines);

        return true;
    }

    /**
     * @param array<string, mixed> $hubNode
     * @return string[] Reply lines produced by this single command.
     */
    private function processCommand(string $line, array $hubNode, string $robot): array
    {
        $upper = strtoupper($line);

        if ($upper === '%HELP') {
            return $this->helpLines($robot);
        }
        if ($upper === '%LIST') {
            return $this->listLines($hubNode, $robot);
        }
        if ($upper === '%QUERY') {
            return $this->queryLines($hubNode, $robot);
        }
        if ($upper === '%PAUSE') {
            $this->nodeManager->update((int)$hubNode['id'], ['hold_mail' => true]);
            return ['All areas paused (hold mail enabled).'];
        }
        if ($upper === '%RESUME') {
            $this->nodeManager->update((int)$hubNode['id'], ['hold_mail' => false]);
            return ['Mail resumed (hold mail disabled).'];
        }
        if ($upper === '%RESCAN' || str_starts_with($upper, '%RESCAN ')) {
            return $this->rescan($hubNode, $robot, trim(substr($line, 7)));
        }

        if ($line[0] === '+' || $line[0] === '-') {
            $subscribe = $line[0] === '+';
            $tag = strtoupper(trim(substr($line, 1)));
            if ($tag === '') {
                return ["Invalid command: {$line}"];
            }

            return $robot === 'filefix'
                ? $this->toggleFilearea($hubNode, $tag, $subscribe)
                : $this->toggleEchoarea($hubNode, $tag, $subscribe);
        }

        return ["Unknown command: {$line}"];
    }

    /**
     * %RESCAN [AREATAG] [days] - re-queue historical echomail, going back
     * $days days (default/max per HubFanout::RESCAN_DEFAULT_DAYS /
     * RESCAN_MAX_DAYS). With no area tag, rescans all of the caller's
     * currently subscribed areas; with one, only that area (which must be
     * one the caller is currently subscribed to). Tag and day count may
     * appear in either order (e.g. "%RESCAN GENERAL 30" or "%RESCAN 30
     * GENERAL"); a purely-numeric token is always taken as the day count.
     * Matches common areafix conventions (Mystic et al). Echomail only -
     * not meaningful for FileFix, which has no per-message history to replay.
     *
     * @param array<string, mixed> $hubNode
     * @return string[]
     */
    private function rescan(array $hubNode, string $robot, string $args): array
    {
        if ($robot === 'filefix') {
            return ['%RESCAN is not supported for FileFix.'];
        }

        $days = null;
        $areaTag = null;
        foreach (preg_split('/\s+/', trim($args), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match('/^\d{1,4}$/', $token)) {
                $days = (int)$token;
            } elseif ($areaTag === null) {
                $areaTag = strtoupper($token);
            } else {
                return ["Invalid command: %RESCAN {$args}"];
            }
        }

        $usedDays = $days !== null ? max(1, min($days, HubFanout::RESCAN_MAX_DAYS)) : HubFanout::RESCAN_DEFAULT_DAYS;
        $fanout = new HubFanout($this->db, $this->nodeManager);

        if ($areaTag !== null) {
            $area = $this->nodeManager->findSubscribedEchoareaByTag((int)$hubNode['id'], $areaTag);
            if (!$area) {
                return ["{$areaTag}: not currently subscribed, nothing to rescan"];
            }
            $queued = $fanout->rescanForNode((int)$hubNode['id'], $days, (int)$area['id']);
            return ["Rescan queued {$queued} message(s) from {$areaTag} (last {$usedDays} day(s))."];
        }

        $queued = $fanout->rescanForNode((int)$hubNode['id'], $days);
        return ["Rescan queued {$queued} message(s) from the last {$usedDays} day(s)."];
    }

    /**
     * @param array<string, mixed> $hubNode
     * @return string[]
     */
    private function toggleEchoarea(array $hubNode, string $tag, bool $subscribe): array
    {
        $domain = $this->nodeManager->resolveDomain($hubNode);
        $area = $this->findEligibleEchoarea($tag, $domain);
        if (!$area) {
            return ["{$tag}: area not found"];
        }

        $this->nodeManager->setAreaSubscription((int)$hubNode['id'], (int)$area['id'], $subscribe);

        return [$subscribe ? "{$tag}: added" : "{$tag}: removed"];
    }

    /**
     * @param array<string, mixed> $hubNode
     * @return string[]
     */
    private function toggleFilearea(array $hubNode, string $tag, bool $subscribe): array
    {
        $domain = $this->nodeManager->resolveDomain($hubNode);
        $area = $this->findEligibleFilearea($tag, $domain);
        if (!$area) {
            return ["{$tag}: area not found"];
        }

        $this->nodeManager->setFileAreaSubscription((int)$hubNode['id'], (int)$area['id'], $subscribe);

        return [$subscribe ? "{$tag}: added" : "{$tag}: removed"];
    }

    /**
     * Areas a subordinate may self-subscribe to via Areafix: active,
     * non-local, non-sysop-only echoareas in the subordinate's own network
     * domain (resolved from its boss AKA for points, or its own address for
     * nodes). A null $domain (couldn't be resolved) matches nothing, so an
     * unresolvable node/point is locked out rather than granted broad access.
     */
    private function findEligibleEchoarea(string $tag, ?string $domain): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, tag, domain, description
            FROM echoareas
            WHERE UPPER(tag) = UPPER(?) AND is_active = TRUE AND COALESCE(is_local, FALSE) = FALSE
              AND COALESCE(is_sysop_only, FALSE) = FALSE
              AND domain IS NOT NULL AND LOWER(domain) = LOWER(?)
        ");
        $stmt->execute([$tag, $domain]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Areas a subordinate may self-subscribe to via Filefix: active,
     * non-local, non-private file areas in the subordinate's own network
     * domain - same domain scoping as findEligibleEchoarea().
     */
    private function findEligibleFilearea(string $tag, ?string $domain): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, tag, domain, description
            FROM file_areas
            WHERE UPPER(tag) = UPPER(?) AND is_active = TRUE
              AND COALESCE(is_local, FALSE) = FALSE AND COALESCE(is_private, FALSE) = FALSE
              AND domain IS NOT NULL AND LOWER(domain) = LOWER(?)
        ");
        $stmt->execute([$tag, $domain]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return string[]
     */
    private function helpLines(string $robot): array
    {
        $verb = $robot === 'filefix' ? 'file area' : 'echo area';

        $lines = [
            'AreaFix/FileFix command reference:',
            "+TAG        Subscribe to a {$verb}",
            "-TAG        Unsubscribe from a {$verb}",
            '%LIST       List available areas',
            '%QUERY      List your current subscriptions',
            '%PAUSE      Pause all areas (hold mail)',
            '%RESUME     Resume all areas',
        ];

        if ($robot !== 'filefix') {
            $lines[] = '%RESCAN [AREATAG] [days]  Re-queue echomail history (all subscribed areas, or one)';
            $lines[] = '            (default/max ' . HubFanout::RESCAN_DEFAULT_DAYS . '/' . HubFanout::RESCAN_MAX_DAYS . ' days)';
        }

        $lines[] = '%HELP       This help text';

        return $lines;
    }

    /**
     * @param array<string, mixed> $hubNode
     * @return string[]
     */
    private function listLines(array $hubNode, string $robot): array
    {
        $domain = $this->nodeManager->resolveDomain($hubNode);

        if ($robot === 'filefix') {
            $stmt = $this->db->prepare("
                SELECT tag, description FROM file_areas
                WHERE is_active = TRUE AND COALESCE(is_local, FALSE) = FALSE AND COALESCE(is_private, FALSE) = FALSE
                  AND domain IS NOT NULL AND LOWER(domain) = LOWER(?)
                ORDER BY tag
            ");
        } else {
            $stmt = $this->db->prepare("
                SELECT tag, description FROM echoareas
                WHERE is_active = TRUE AND COALESCE(is_local, FALSE) = FALSE AND COALESCE(is_sysop_only, FALSE) = FALSE
                  AND domain IS NOT NULL AND LOWER(domain) = LOWER(?)
                ORDER BY tag
            ");
        }
        $stmt->execute([$domain]);

        return $this->formatAreaRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'No areas available.');
    }

    /**
     * @param array<string, mixed> $hubNode
     * @return string[]
     */
    private function queryLines(array $hubNode, string $robot): array
    {
        if ($robot === 'filefix') {
            $stmt = $this->db->prepare("
                SELECT fa.tag, fa.description
                FROM file_areas fa
                JOIN hub_node_fileareas hnf ON hnf.file_area_id = fa.id
                WHERE hnf.hub_node_id = ?
                ORDER BY fa.tag
            ");
        } else {
            $stmt = $this->db->prepare("
                SELECT ea.tag, ea.description
                FROM echoareas ea
                JOIN hub_node_areas hna ON hna.echoarea_id = ea.id
                WHERE hna.hub_node_id = ?
                ORDER BY ea.tag
            ");
        }
        $stmt->execute([$hubNode['id']]);

        return $this->formatAreaRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'You are not subscribed to any areas.');
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return string[]
     */
    private function formatAreaRows(array $rows, string $emptyMessage): array
    {
        if (empty($rows)) {
            return [$emptyMessage];
        }

        return array_map(
            static fn(array $row) => str_pad((string)$row['tag'], 20) . (string)($row['description'] ?? ''),
            $rows
        );
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $hubNode
     * @param string[] $bodyLines
     */
    private function sendReply(array $message, array $hubNode, string $robot, array $bodyLines): void
    {
        $fromName = $robot === 'filefix' ? 'FileFix' : 'AreaFix';
        $destAddr = trim((string)($message['destAddr'] ?? ''));

        $packetMessage = [
            'from_address' => $destAddr,
            'to_address' => $hubNode['node_address'],
            'from_name' => $fromName,
            'to_name' => (string)($message['fromName'] ?? 'Sysop'),
            'subject' => $fromName . ' reply',
            'message_text' => implode("\r\n", $bodyLines),
            'date_written' => date('Y-m-d H:i:s'),
            'attributes' => 0x0001, // Private
            'is_echomail' => false,
        ];

        $this->netmailRouter->buildAndEnqueue($packetMessage, $hubNode, null);
    }
}
