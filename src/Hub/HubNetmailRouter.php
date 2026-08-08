<?php

namespace BinktermPHP\Hub;

use PDO;
use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\BinkdProcessor;

/**
 * Routes netmail addressed to a registered hub node/point into
 * hub_node_outbound instead of the normal delivery/uplink-routing path,
 * per docs/proposals/HubPointSystemJuly2026.md ("Netmail Routing").
 * Covers both directions:
 *  - routeIfHubNode(): inbound transit mail not addressed to us.
 *  - routeOutboundIfHubNode(): netmail composed locally by our own users
 *    and addressed to a registered downlink, which would otherwise be
 *    misrouted through whatever uplink's network pattern happens to
 *    match the destination (see MessageHandler::spoolOutboundNetmail()).
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
     * caller falls through to the existing local-delivery logic.
     *
     * @param array $message Raw inbound message array as built by
     *   BinkdProcessor (destAddr, origAddr, fromName, toName, subject,
     *   text, dateTime, attributes).
     */
    public function routeIfHubNode(array $message): bool
    {
        if (Config::env('HUB_ROUTE_NETMAIL', 'false') !== 'true') {
            return false;
        }

        $destAddr = trim((string)($message['destAddr'] ?? ''));
        if ($destAddr === '') {
            return false;
        }

        $hubNode = $this->nodeManager->getByAddress($destAddr);
        if (!$hubNode || !$hubNode['enabled'] || $hubNode['hold_mail'] || !$hubNode['allow_inbound_netmail']) {
            return false;
        }

        [$bodyText, $kludgeText] = $this->splitKludges((string)($message['text'] ?? ''));

        $dateWritten = null;
        $parsed = strtotime((string)($message['dateTime'] ?? ''));
        if ($parsed !== false) {
            $dateWritten = date('Y-m-d H:i:s', $parsed);
        }

        $packetMessage = [
            'from_address' => $message['origAddr'] ?? '',
            'to_address' => $hubNode['node_address'],
            'from_name' => $message['fromName'] ?? '',
            'to_name' => $message['toName'] ?? '',
            'subject' => $message['subject'] ?? '',
            'message_text' => $bodyText,
            'kludge_lines' => $kludgeText,
            'date_written' => $dateWritten,
            'attributes' => $message['attributes'] ?? 0,
            'is_echomail' => false,
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

        return $this->buildAndEnqueue($packetMessage, $hubNode, $netmailId);
    }

    /**
     * Build a .pkt from $packetMessage addressed to $hubNode and insert it
     * into hub_node_outbound. Shared by both routing directions.
     *
     * @param array<string, mixed> $hubNode
     */
    private function buildAndEnqueue(array $packetMessage, array $hubNode, ?int $netmailId): bool
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
            INSERT INTO hub_node_outbound (hub_node_id, message_type, netmail_id, packet_data, size_bytes, status)
            VALUES (:hub_node_id, 'netmail', :netmail_id, :packet_data, :size_bytes, 'pending')
        ");
        $stmt->bindValue(':hub_node_id', $hubNode['id'], PDO::PARAM_INT);
        $stmt->bindValue(':netmail_id', $netmailId, $netmailId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':packet_data', $bytes, PDO::PARAM_LOB);
        $stmt->bindValue(':size_bytes', strlen($bytes), PDO::PARAM_INT);
        $stmt->execute();

        return true;
    }

    /**
     * Split raw FTN message text into [body, kludges], preserving original
     * kludge lines (MSGID, INTL, etc.) verbatim for relay rather than
     * regenerating them.
     *
     * @return array{0:string,1:string}
     */
    private function splitKludges(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $body = [];
        $kludges = [];

        foreach ($lines as $line) {
            if (strlen($line) > 0 && ord($line[0]) === 0x01) {
                $kludges[] = $line;
            } else {
                $body[] = $line;
            }
        }

        return [implode("\n", $body), implode("\n", $kludges)];
    }
}
