<?php

namespace BinktermPHP\Hub;

use PDO;
use BinktermPHP\Database;
use BinktermPHP\BinkdProcessor;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Echomail\EchomailSeenBy;

/**
 * Routes netmail addressed to, or received from, a registered hub
 * node/point, per docs/proposals/HubPointSystemJuly2026.md ("Netmail
 * Routing"). Three directions:
 *  - routeIfHubNode(): inbound transit mail addressed to one of our own
 *    hub nodes/points, not to us - enqueued into hub_node_outbound.
 *  - routeOutboundIfHubNode(): netmail composed locally by our own users
 *    and addressed to a registered downlink, which would otherwise be
 *    misrouted through whatever uplink's network pattern happens to
 *    match the destination (see MessageHandler::spoolOutboundNetmail()).
 *  - relayIfFromHubNode(): netmail received FROM a registered hub
 *    node/point, addressed to neither us nor another registered hub
 *    node - i.e. a point using us as its boss to relay mail onward to
 *    the wider network. Queued into the normal flat-file outbound queue
 *    via the standard uplink-routing path, exactly like web-composed
 *    netmail. Without this, such mail was silently dropped as
 *    undeliverable - a registered point could never send anywhere but
 *    back to us.
 */
class HubNetmailRouter
{
    private PDO $db;
    private HubNodeManager $nodeManager;

    public function __construct(?PDO $db = null, ?HubNodeManager $nodeManager = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->nodeManager = $nodeManager ?? new HubNodeManager($this->db);
    }

    /**
     * If $message's destination address belongs to a registered, enabled
     * hub node/point with allow_inbound_netmail, enqueue it for delivery
     * and return true. Returns false (no side effects) otherwise, so the
     * caller falls through to the existing local-delivery logic. Gated
     * solely by the per-node allow_inbound_netmail flag - this is core
     * "deliver mail addressed to a registered downlink" functionality, not
     * an opt-in relay feature, so (unlike an earlier version of this
     * method) there is no separate global flag to also remember to enable.
     *
     * @param array $message Raw inbound message array as built by
     *   BinkdProcessor (destAddr, origAddr, fromName, toName, subject,
     *   text, dateTime, attributes).
     */
    public function routeIfHubNode(array $message): bool
    {
        $destAddr = trim((string)($message['destAddr'] ?? ''));
        if ($destAddr === '') {
            return false;
        }

        $hubNode = $this->nodeManager->getByAddress($destAddr);
        if (!$hubNode || !$hubNode['enabled'] || $hubNode['hold_mail'] || !$hubNode['allow_inbound_netmail']) {
            return false;
        }

        [$bodyText, $kludgeText, $bottomKludgeText] = $this->splitKludges((string)($message['text'] ?? ''));

        $dateWritten = null;
        $parsed = strtotime((string)($message['dateTime'] ?? ''));
        if ($parsed !== false) {
            $dateWritten = date('Y-m-d H:i:s', $parsed);
        }

        // Record this hop, same as HubFanout does for echomail (FTS-4009).
        $ourAddress = (string)(BinkpConfig::getInstance()->getOriginAddressByDestination($hubNode['node_address'])
            ?: BinkpConfig::getInstance()->getSystemAddress());
        $viaLines = EchomailSeenBy::parseViaLines($bottomKludgeText);
        $viaLines[] = \generateViaLine($ourAddress);

        $packetMessage = [
            'from_address' => $message['origAddr'] ?? '',
            'to_address' => $hubNode['node_address'],
            'from_name' => $message['fromName'] ?? '',
            'to_name' => $message['toName'] ?? '',
            'subject' => $message['subject'] ?? '',
            'message_text' => $bodyText,
            'kludge_lines' => $kludgeText,
            'bottom_kludges' => EchomailSeenBy::formatViaLines($viaLines),
            'date_written' => $dateWritten,
            'attributes' => $message['attributes'] ?? 0,
            'is_echomail' => false,
            // The preserved kludges already carry the true originator's PID,
            // and message_text already has their tearline embedded - don't
            // let writeMessage() add a second set on top (see HubFanout.php
            // for the same reasoning on the echomail side).
            'skip_default_pid_tearline' => strpos($kludgeText, "\x01PID:") !== false,
        ];

        return $this->buildAndEnqueue($packetMessage, $hubNode, null);
    }

    /**
     * If $message's to_address (already shaped for
     * BinkdProcessor::createOutboundPacket(), i.e. a netmail table row)
     * belongs to a registered, enabled hub node/point with allow_outbound,
     * enqueue it directly into hub_node_outbound and return true - instead
     * of letting it fall into uplink network-pattern routing, which has no
     * knowledge of registered downlinks and would send it to whatever
     * uplink's pattern happens to match the destination's zone/net.
     *
     * @param array $message A netmail table row (or equivalently-shaped
     *   array) with to_address/from_address/from_name/to_name/subject/
     *   message_text/attributes/date_written/kludge_lines.
     */
    public function routeOutboundIfHubNode(array $message, int $netmailId): bool
    {
        $toAddr = trim((string)($message['to_address'] ?? ''));
        if ($toAddr === '') {
            return false;
        }

        $hubNode = $this->nodeManager->getByAddress($toAddr);
        if (!$hubNode || !$hubNode['enabled'] || $hubNode['hold_mail'] || !$hubNode['allow_outbound']) {
            return false;
        }

        $packetMessage = $message;
        $packetMessage['to_address'] = $hubNode['node_address'];
        $packetMessage['is_echomail'] = false;
        // Usually a no-op (locally-composed mail has no PID yet, so this
        // stays false and writeMessage() generates one fresh as normal) -
        // but guards the same duplication if this row's kludge_lines
        // somehow already carries one (see routeIfHubNode()).
        $packetMessage['skip_default_pid_tearline'] = strpos((string)($message['kludge_lines'] ?? ''), "\x01PID:") !== false;

        // Delivering to a downlink is a hop from its perspective even when
        // we originated the message ourselves - mirrors HubFanout treating
        // locally-posted echomail the same way for SEEN-BY/PATH.
        $ourAddress = (string)(BinkpConfig::getInstance()->getOriginAddressByDestination($hubNode['node_address'])
            ?: BinkpConfig::getInstance()->getSystemAddress());
        $viaLines = EchomailSeenBy::parseViaLines((string)($message['bottom_kludges'] ?? ''));
        $viaLines[] = \generateViaLine($ourAddress);
        $packetMessage['bottom_kludges'] = EchomailSeenBy::formatViaLines($viaLines);

        return $this->buildAndEnqueue($packetMessage, $hubNode, $netmailId);
    }

    /**
     * If $message's origin address belongs to a registered, enabled hub
     * node/point with allow_inbound_netmail, and the destination is neither
     * us nor another registered hub node (i.e. it fell through to
     * BinkdProcessor::storeNetmail()'s undeliverable path), relay it onward
     * via the normal uplink-routing outbound queue and return true.
     *
     * @param array $message Raw inbound message array as built by
     *   BinkdProcessor (destAddr, origAddr, fromName, toName, subject,
     *   text, dateTime, attributes).
     */
    public function relayIfFromHubNode(array $message): bool
    {
        $origAddr = trim((string)($message['origAddr'] ?? ''));
        $destAddr = trim((string)($message['destAddr'] ?? ''));
        if ($origAddr === '' || $destAddr === '') {
            return false;
        }

        $hubNode = $this->nodeManager->getByAddress($origAddr);
        if (!$hubNode || !$hubNode['enabled'] || $hubNode['hold_mail'] || !$hubNode['allow_inbound_netmail']) {
            return false;
        }

        // Refuse to relay mail addressed to one of our own AKAs (host address,
        // ignoring any point suffix) upstream. It got here because
        // routeIfHubNode() didn't recognize the destination as a registered
        // downlink/point - but "upstream" can never deliver a message whose
        // destination resolves back to us. Standard FTN netmail routing
        // strips the point suffix and delivers by node number, so an uplink
        // receiving this would just send it straight back down to us,
        // creating an unbounded relay loop between us and the uplink. Fall
        // through to the normal undeliverable/drop path instead.
        if ($this->isOwnHostAddress($destAddr)) {
            return false;
        }

        [$bodyText, $kludgeText, $bottomKludgeText] = $this->splitKludges((string)($message['text'] ?? ''));

        $dateWritten = null;
        $parsed = strtotime((string)($message['dateTime'] ?? ''));
        if ($parsed !== false) {
            $dateWritten = date('Y-m-d H:i:s', $parsed);
        }

        // Route via the same uplink-network-pattern lookup used for locally
        // composed netmail - the packet header addresses the uplink hop,
        // while the message envelope below keeps the true orig/dest so the
        // uplink forwards it onward correctly.
        $uplink = BinkpConfig::getInstance()->getUplinkForDestination($destAddr);
        $routeAddress = $uplink ? $uplink['address'] : $destAddr;

        // Record this hop (FTS-4009), same as routeIfHubNode().
        $ourAddress = (string)(BinkpConfig::getInstance()->getOriginAddressByDestination($routeAddress)
            ?: BinkpConfig::getInstance()->getSystemAddress());
        $viaLines = EchomailSeenBy::parseViaLines($bottomKludgeText);
        $viaLines[] = \generateViaLine($ourAddress);

        $packetMessage = [
            'from_address' => $origAddr,
            'to_address' => $destAddr,
            'from_name' => $message['fromName'] ?? '',
            'to_name' => $message['toName'] ?? '',
            'subject' => $message['subject'] ?? '',
            'message_text' => $bodyText,
            'kludge_lines' => $kludgeText,
            'bottom_kludges' => EchomailSeenBy::formatViaLines($viaLines),
            'date_written' => $dateWritten,
            'attributes' => $message['attributes'] ?? 0,
            'is_echomail' => false,
            // See routeIfHubNode() - preserved kludges/text already carry the
            // true originator's PID and tearline.
            'skip_default_pid_tearline' => strpos($kludgeText, "\x01PID:") !== false,
        ];

        (new BinkdProcessor())->createOutboundPacket([$packetMessage], $routeAddress);

        return true;
    }

    /**
     * Build a .pkt from $packetMessage addressed to $hubNode and insert it
     * into hub_node_outbound. Shared by both routing directions, and reused
     * by HubAreafixProcessor to deliver Areafix/Filefix robot replies
     * (Phase 5 of docs/proposals/HubPointSystemJuly2026.md) - hence public.
     *
     * @param array<string, mixed> $hubNode
     */
    public function buildAndEnqueue(array $packetMessage, array $hubNode, ?int $netmailId): bool
    {
        $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hubnetmail_' . uniqid('', true) . '.pkt';
        try {
            (new BinkdProcessor())->createOutboundPacket([$packetMessage], $hubNode['node_address'], $tmpPath);
            $bytes = file_get_contents($tmpPath);
        } finally {
            @unlink($tmpPath);
        }

        if ($bytes === false) {
            return false;
        }

        $stmt = $this->db->prepare("
            INSERT INTO hub_node_outbound (hub_node_id, message_type, netmail_id, packet_data, message_payload, size_bytes, status)
            VALUES (:hub_node_id, 'netmail', :netmail_id, :packet_data, :message_payload, :size_bytes, 'pending')
        ");
        $stmt->bindValue(':hub_node_id', $hubNode['id'], PDO::PARAM_INT);
        $stmt->bindValue(':netmail_id', $netmailId, $netmailId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':packet_data', $bytes, PDO::PARAM_LOB);
        $stmt->bindValue(':message_payload', json_encode($packetMessage), PDO::PARAM_STR);
        $stmt->bindValue(':size_bytes', strlen($bytes), PDO::PARAM_INT);
        $stmt->execute();

        return true;
    }

    /**
     * True if $addr matches one of our own configured AKAs (system address
     * or an uplink's "me" address), comparing at the host (net/node) level
     * so a point suffix doesn't prevent the match - mirrors
     * BinkdProcessor::isOwnAddress().
     */
    private function isOwnHostAddress(string $addr): bool
    {
        $addr = trim($addr);
        if ($addr === '') {
            return false;
        }

        $hostAddr = strpos($addr, '.') !== false ? explode('.', $addr, 2)[0] : $addr;

        foreach ($this->nodeManager->getConfiguredAkas() as $aka) {
            $akaHost = strpos($aka, '.') !== false ? explode('.', $aka, 2)[0] : $aka;
            if ($addr === $aka || $hostAddr === $akaHost) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split raw FTN message text into [body, top kludges, bottom kludges],
     * preserving original kludge lines (MSGID, INTL, Via, etc.) verbatim for
     * relay rather than regenerating them. Via and PATH lines are separated
     * into the third slot to match BinkdProcessor::storeNetmail()'s own
     * bottom_kludges classification (FTS-4009 - Via/PATH go after the
     * message text, everything else stays at the top).
     *
     * @return array{0:string,1:string,2:string}
     */
    private function splitKludges(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $body = [];
        $kludges = [];
        $bottomKludges = [];

        foreach ($lines as $line) {
            if (strlen($line) > 0 && ord($line[0]) === 0x01) {
                if (preg_match('/^\x01Via[:\s]/i', $line) || preg_match('/^\x01PATH:/i', $line)) {
                    $bottomKludges[] = $line;
                } else {
                    $kludges[] = $line;
                }
            } else {
                $body[] = $line;
            }
        }

        return [implode("\n", $body), implode("\n", $kludges), implode("\n", $bottomKludges)];
    }
}
