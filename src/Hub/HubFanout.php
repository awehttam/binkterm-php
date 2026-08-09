<?php

namespace BinktermPHP\Hub;

use PDO;
use BinktermPHP\Database;
use BinktermPHP\BinkdProcessor;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Echomail\EchomailSeenBy;
use BinktermPHP\TicFileGenerator;

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

        $this->buildAndQueuePacket($processor, $message, $subscriber, $bottomKludges);
    }

    /**
     * Build a one-message outbound packet for $subscriber and insert it into
     * hub_node_outbound. Shared by queueForSubscriber() (live fanout, which
     * computes $bottomKludges via the SEEN-BY loop-guard/mutation above) and
     * rescanForNode() (which passes the message's original bottom_kludges
     * through unchanged - a rescan is a deliberate resend, not a fresh toss,
     * so it must not be blocked by the "subscriber already in SEEN-BY" check).
     *
     * @param array<string, mixed> $message
     * @param array<string, mixed> $subscriber
     */
    private function buildAndQueuePacket(
        BinkdProcessor $processor,
        array $message,
        array $subscriber,
        string $bottomKludges
    ): void {
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

    /**
     * Default lookback window for %RESCAN when no day count is given,
     * matching Mystic's areafix RESCAN default of roughly 6 months.
     */
    public const RESCAN_DEFAULT_DAYS = 182;

    /** Upper bound on %RESCAN's day count, to keep a mistyped huge number from queueing years of mail. */
    public const RESCAN_MAX_DAYS = 3650;

    /**
     * Re-queue historical echomail into hub_node_outbound for a single hub
     * node, going back $days days (server AreaFix %RESCAN command). With
     * $echoareaId null, covers all of the node's currently subscribed
     * (active, non-paused) echoareas; with it set, only that one area
     * (caller must have already verified the node is subscribed to it -
     * see HubNodeManager::findSubscribedEchoareaByTag()). Unlike fanout(),
     * this always resends every matching message regardless of SEEN-BY -
     * it's an explicit request to receive again, not a fresh toss, and
     * points are already exempt from SEEN-BY entirely (see queueForSubscriber()).
     *
     * @return int Number of messages queued.
     */
    public function rescanForNode(int $hubNodeId, ?int $days = null, ?int $echoareaId = null): int
    {
        $days = $days !== null ? max(1, min($days, self::RESCAN_MAX_DAYS)) : self::RESCAN_DEFAULT_DAYS;

        $subscriber = $this->nodeManager->getById($hubNodeId);
        if (!$subscriber || !$subscriber['enabled']) {
            return 0;
        }

        $echoareaIds = $echoareaId !== null ? [$echoareaId] : $this->nodeManager->getSubscribedEchoareaIds($hubNodeId);
        if (empty($echoareaIds)) {
            return 0;
        }

        $processor = new BinkdProcessor();
        $queued = 0;

        foreach ($echoareaIds as $echoareaId) {
            foreach ($this->loadRecentMessages($echoareaId, $days) as $message) {
                $this->buildAndQueuePacket($processor, $message, $subscriber, (string)$message['bottom_kludges']);
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRecentMessages(int $echoareaId, int $days): array
    {
        $stmt = $this->db->prepare("
            SELECT em.*, ea.tag AS echoarea_tag, ea.domain AS echoarea_domain
            FROM echomail em
            JOIN echoareas ea ON ea.id = em.echoarea_id
            WHERE em.echoarea_id = ?
              AND em.date_received >= (NOW() AT TIME ZONE 'UTC') - make_interval(days => ?)
            ORDER BY em.date_received ASC
        ");
        $stmt->execute([$echoareaId, $days]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function tempPacketPath(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hubfanout_' . uniqid('', true) . '.pkt';
    }

    /**
     * Fan a stored file (upload, or inbound TIC storage) out to subscribed
     * hub_nodes, enqueueing one TIC control-file + data-file pair per
     * subscriber into hub_node_outbound (message_type='tic'). This is
     * Phase 4 of docs/proposals/HubPointSystemJuly2026.md.
     *
     * @param int $fileId The stored files.id row to fan out.
     */
    public function fanoutFile(int $fileId): void
    {
        $file = $this->loadFile($fileId);
        if (!$file) {
            return;
        }

        $fileArea = $this->loadFileArea((int)$file['file_area_id']);
        if (!$fileArea) {
            return;
        }

        $subscribers = $this->nodeManager->getSubscribersForFileArea((int)$file['file_area_id']);
        if (empty($subscribers)) {
            return;
        }

        if (!file_exists($file['storage_path'])) {
            return;
        }

        $existingSeenBy = $this->splitAddressList($file['tic_seenby'] ?? '');
        $existingPath = $this->splitAddressList($file['tic_path'] ?? '');
        $ourAddress = (string)BinkpConfig::getInstance()->getSystemAddress();

        $ticGenerator = new TicFileGenerator();

        foreach ($subscribers as $subscriber) {
            $this->queueFileForSubscriber($ticGenerator, $file, $fileArea, $subscriber, $existingSeenBy, $existingPath, $ourAddress);
        }
    }

    private function loadFile(int $fileId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM files WHERE id = ? AND status = 'approved'");
        $stmt->execute([$fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function loadFileArea(int $fileAreaId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM file_areas WHERE id = ?");
        $stmt->execute([$fileAreaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed> $fileArea
     * @param array<string, mixed> $subscriber
     * @param string[] $existingSeenBy
     * @param string[] $existingPath
     */
    private function queueFileForSubscriber(
        TicFileGenerator $ticGenerator,
        array $file,
        array $fileArea,
        array $subscriber,
        array $existingSeenBy,
        array $existingPath,
        string $ourAddress
    ): void {
        $subscriberAddress = $subscriber['node_address'];

        // Loop guard: skip if the subscriber has already seen this file
        // (mirrors SEEN-BY loop prevention for echomail).
        if ($this->addressListContains($existingSeenBy, $subscriberAddress)) {
            return;
        }

        $seenBy = $this->addAddressToList($existingSeenBy, $ourAddress);
        $seenBy = $this->addAddressToList($seenBy, $subscriberAddress);
        $path = $this->addAddressToList($existingPath, $ourAddress);

        $ticContent = $ticGenerator->buildTicContentForDownlink(
            $file,
            $fileArea,
            $file['filename'],
            $ourAddress,
            $subscriberAddress,
            $path,
            $seenBy,
            (string)($fileArea['password'] ?? '')
        );

        $dataBytes = @file_get_contents($file['storage_path']);
        if ($dataBytes === false) {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO hub_node_outbound (hub_node_id, message_type, file_id, packet_data, tic_file_data, tic_filename, size_bytes, status)
            VALUES (:hub_node_id, 'tic', :file_id, :packet_data, :tic_file_data, :tic_filename, :size_bytes, 'pending')
        ");
        $stmt->bindValue(':hub_node_id', $subscriber['id'], PDO::PARAM_INT);
        $stmt->bindValue(':file_id', $file['id'], PDO::PARAM_INT);
        $stmt->bindValue(':packet_data', $ticContent, PDO::PARAM_LOB);
        $stmt->bindValue(':tic_file_data', $dataBytes, PDO::PARAM_LOB);
        $stmt->bindValue(':tic_filename', $file['filename']);
        $stmt->bindValue(':size_bytes', strlen($dataBytes) + strlen($ticContent), PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * @return string[] Lowercase-normalized, deduplicated FTN addresses.
     */
    private function splitAddressList(?string $value): array
    {
        if (empty($value)) {
            return [];
        }
        $parts = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_map('strtolower', $parts)));
    }

    /**
     * @param string[] $list
     */
    private function addressListContains(array $list, string $address): bool
    {
        return in_array(strtolower(trim($address)), $list, true);
    }

    /**
     * @param string[] $list
     * @return string[]
     */
    private function addAddressToList(array $list, string $address): array
    {
        $normalized = strtolower(trim($address));
        if (!in_array($normalized, $list, true)) {
            $list[] = $normalized;
        }

        return $list;
    }
}
