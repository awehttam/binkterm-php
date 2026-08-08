<?php

namespace BinktermPHP\Hub;

use PDO;
use BinktermPHP\Database;
use BinktermPHP\BinkdProcessor;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Echomail\EchomailSeenBy;

/**
 * Fans a newly-stored echomail message out to subscribed hub_nodes
 * (subordinate nodes and points), enqueueing one packet per subscriber into
 * hub_node_outbound. This is Phase 1 of docs/proposals/HubPointSystemJuly2026.md:
 * enqueue only, no delivery (Phase 2).
 */
class HubFanout
{
    private PDO $db;
    private HubNodeManager $nodeManager;

    public function __construct(?PDO $db = null, ?HubNodeManager $nodeManager = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->nodeManager = $nodeManager ?? new HubNodeManager($this->db);
    }

    /**
     * @param int $echomailId The stored echomail row to fan out.
     */
    public function fanout(int $echomailId): void
    {
        $message = $this->loadMessage($echomailId);
        if (!$message) {
            return;
        }

        $subscribers = $this->nodeManager->getSubscribersForArea((int)$message['echoarea_id']);
        if (empty($subscribers)) {
            return;
        }

        $rawSeenBy = EchomailSeenBy::parseSeenBy($message['bottom_kludges']);
        $rawPath = EchomailSeenBy::parsePath($message['bottom_kludges']);
        $ourAddress = (string)BinkpConfig::getInstance()->getSystemAddress();

        $processor = new BinkdProcessor();

        foreach ($subscribers as $subscriber) {
            $this->queueForSubscriber($processor, $message, $subscriber, $rawSeenBy, $rawPath, $ourAddress);
        }
    }

    private function loadMessage(int $echomailId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT em.*, ea.tag AS echoarea_tag, ea.domain AS echoarea_domain
            FROM echomail em
            JOIN echoareas ea ON ea.id = em.echoarea_id
            WHERE em.id = ?
        ");
        $stmt->execute([$echomailId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $subscriber
     * @param array<int, int[]> $rawSeenBy
     * @param string[] $rawPath
     */
    private function queueForSubscriber(
        BinkdProcessor $processor,
        array $message,
        array $subscriber,
        array $rawSeenBy,
        array $rawPath,
        string $ourAddress
    ): void {
        $isPoint = $subscriber['node_type'] === HubNodeManager::TYPE_POINT;

        if ($isPoint) {
            // Points are never skipped based on SEEN-BY (they never legitimately
            // appear there) and never get SEEN-BY/PATH mutation - subscription
            // state alone governs delivery. Pass bottom_kludges through untouched;
            // echomail hop history is tracked via PATH, not Via (Via is netmail-only,
            // FSC-0043 - real tossers like HPT never stamp it onto echomail).
            $bottomKludges = (string)$message['bottom_kludges'];
        } else {
            $subscriberAddress = $subscriber['node_address'];

            // Loop guard: our own address already appears more than once in PATH.
            if (EchomailSeenBy::isLoop($rawPath, $ourAddress)) {
                return;
            }

            // Already has it.
            if (EchomailSeenBy::seenByContains($rawSeenBy, $subscriberAddress)) {
                return;
            }

            $seenBy = EchomailSeenBy::addToSeenBy($rawSeenBy, $ourAddress);
            $seenBy = EchomailSeenBy::addToSeenBy($seenBy, $subscriberAddress);
            $path = EchomailSeenBy::addToPath($rawPath, $ourAddress);

            $bottomKludges = trim(EchomailSeenBy::formatSeenBy($seenBy) . "\r" . EchomailSeenBy::formatPath($path));
        }

        $packetMessage = [
            'from_address' => $message['from_address'],
            'to_address' => $subscriber['node_address'],
            'from_name' => $message['from_name'],
            'to_name' => $message['to_name'] ?? 'All',
            'subject' => $message['subject'],
            'message_text' => $message['message_text'],
            'date_written' => $message['date_written'],
            'attributes' => 0x0000,
            'is_echomail' => true,
            'echoarea_tag' => $message['echoarea_tag'],
            'echoarea_domain' => $message['echoarea_domain'],
            'kludge_lines' => $message['kludge_lines'],
            'bottom_kludges' => $bottomKludges,
            'tearline_component' => $message['tearline_component'] ?? null,
            'reply_to_id' => $message['reply_to_id'] ?? null,
            // Already merged the full SEEN-BY/PATH set above; suppress
            // BinkdProcessor::writeMessage()'s single-hop auto-synthesis.
            'skip_default_seenby_path' => true,
            // If this message already carries a PID kludge (i.e. it arrived
            // via inbound packet processing and kludge_lines is the real
            // author's, not empty), it also already has that author's
            // tearline embedded in message_text - suppress writeMessage()'s
            // unconditional fresh PID+tearline so we don't duplicate both.
            // Locally-composed posts have no PID yet and should still get
            // one generated fresh (this system is the originating tosser).
            'skip_default_pid_tearline' => strpos((string)($message['kludge_lines'] ?? ''), "\x01PID:") !== false,
        ];

        $packetPath = $this->tempPacketPath();
        try {
            $processor->createOutboundPacket([$packetMessage], $subscriber['node_address'], $packetPath);
            $bytes = file_get_contents($packetPath);
        } finally {
            @unlink($packetPath);
        }

        if ($bytes === false) {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO hub_node_outbound (hub_node_id, message_type, echoarea_id, echomail_id, packet_data, size_bytes, status)
            VALUES (:hub_node_id, 'echomail', :echoarea_id, :echomail_id, :packet_data, :size_bytes, 'pending')
        ");
        $stmt->bindValue(':hub_node_id', $subscriber['id'], PDO::PARAM_INT);
        $stmt->bindValue(':echoarea_id', $message['echoarea_id'], PDO::PARAM_INT);
        $stmt->bindValue(':echomail_id', $message['id'], PDO::PARAM_INT);
        $stmt->bindValue(':packet_data', $bytes, PDO::PARAM_LOB);
        $stmt->bindValue(':size_bytes', strlen($bytes), PDO::PARAM_INT);
        $stmt->execute();
    }

    private function tempPacketPath(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hubfanout_' . uniqid('', true) . '.pkt';
    }
}
