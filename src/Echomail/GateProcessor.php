<?php

namespace BinktermPHP\Echomail;

use BinktermPHP\Database;
use BinktermPHP\MessageHandler;
use PDO;

class GateProcessor
{
    private PDO $db;
    private MessageHandler $messageHandler;

    public function __construct(?PDO $db = null, ?MessageHandler $messageHandler = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->messageHandler = $messageHandler ?? new MessageHandler();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getGatesForArea(int $echoareaId): array
    {
        $stmt = $this->db->prepare("
            SELECT g.id,
                   g.source_area_id,
                   g.target_area_id,
                   g.bidirectional,
                   src.tag AS source_tag,
                   src.domain AS source_domain,
                   tgt.tag AS target_tag,
                   tgt.domain AS target_domain
            FROM echo_area_gates g
            JOIN echoareas src ON src.id = g.source_area_id
            JOIN echoareas tgt ON tgt.id = g.target_area_id
            WHERE g.source_area_id = ? OR (g.bidirectional = TRUE AND g.target_area_id = ?)
            ORDER BY g.id
        ");
        $stmt->execute([$echoareaId, $echoareaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<int,array<string,mixed>> $gates
     */
    public function replaceAreaGates(int $echoareaId, array $gates): void
    {
        $this->db->prepare("
            DELETE FROM echo_area_gates
            WHERE source_area_id = ?
               OR (bidirectional = TRUE AND target_area_id = ?)
        ")->execute([$echoareaId, $echoareaId]);

        if ($gates === []) {
            return;
        }

        $insertStmt = $this->db->prepare("
            INSERT INTO echo_area_gates (source_area_id, target_area_id, bidirectional, created_at)
            VALUES (?, ?, ?, NOW())
        ");

        foreach ($gates as $gate) {
            $targetId = (int)($gate['target_area_id'] ?? 0);
            $bidirectional = !empty($gate['bidirectional']);
            if ($targetId <= 0 || $targetId === $echoareaId) {
                throw new \InvalidArgumentException('Invalid gate target');
            }

            if ($bidirectional) {
                $sourceId = min($echoareaId, $targetId);
                $normalizedTargetId = max($echoareaId, $targetId);
                $insertStmt->execute([$sourceId, $normalizedTargetId, 'true']);
                continue;
            }

            $insertStmt->execute([$echoareaId, $targetId, 'false']);
        }
    }

    public function processMessageById(int $messageId): void
    {
        $message = $this->getMessage($messageId);
        if ($message === null) {
            return;
        }

        $routes = $this->resolveRoutes((int)$message['echoarea_id']);
        if ($routes === []) {
            return;
        }

        $sourceMsgId = trim((string)($message['source_msgid'] ?? ''));
        if ($sourceMsgId === '') {
            $sourceMsgId = trim((string)($message['message_id'] ?? ''));
        }
        if ($sourceMsgId === '') {
            $sourceMsgId = 'local:' . $messageId;
        }

        foreach ($routes as $targetAreaId) {
            if ($this->alreadyGated($targetAreaId, $sourceMsgId)) {
                continue;
            }

            $replyToId = $this->resolveTargetReplyToId($message, $targetAreaId);
            $this->messageHandler->importExternalEchomail([
                'echoarea_id' => $targetAreaId,
                'from_name' => (string)$message['from_name'],
                'to_name' => (string)$message['to_name'],
                'subject' => (string)$message['subject'],
                'message_text' => (string)$message['message_text'],
                'from_address' => $message['from_address'] !== '' ? (string)$message['from_address'] : null,
                'reply_to_id' => $replyToId,
                'source_msgid' => $sourceMsgId,
                'apply_gates' => false,
            ]);
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getMessage(int $messageId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT em.*, ea.tag, ea.domain
            FROM echomail em
            JOIN echoareas ea ON ea.id = em.echoarea_id
            WHERE em.id = ?
        ");
        $stmt->execute([$messageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<int>
     */
    private function resolveRoutes(int $echoareaId): array
    {
        $stmt = $this->db->prepare("
            SELECT source_area_id, target_area_id, bidirectional
            FROM echo_area_gates
            WHERE source_area_id = ? OR target_area_id = ?
        ");
        $stmt->execute([$echoareaId, $echoareaId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $targets = [];
        foreach ($rows as $row) {
            $source = (int)$row['source_area_id'];
            $target = (int)$row['target_area_id'];
            $bidirectional = !empty($row['bidirectional']);

            if ($source === $echoareaId) {
                $targets[] = $target;
            } elseif ($bidirectional && $target === $echoareaId) {
                $targets[] = $source;
            }
        }

        return array_values(array_unique($targets));
    }

    private function alreadyGated(int $targetAreaId, string $sourceMsgId): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1
            FROM echomail
            WHERE echoarea_id = ? AND source_msgid = ?
            LIMIT 1
        ");
        $stmt->execute([$targetAreaId, $sourceMsgId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * @param array<string,mixed> $message
     */
    private function resolveTargetReplyToId(array $message, int $targetAreaId): ?int
    {
        $replyToId = (int)($message['reply_to_id'] ?? 0);
        if ($replyToId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT source_msgid, message_id
            FROM echomail
            WHERE id = ?
        ");
        $stmt->execute([$replyToId]);
        $parent = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$parent) {
            return null;
        }

        $matchMsgId = trim((string)($parent['source_msgid'] ?: $parent['message_id']));
        if ($matchMsgId === '') {
            return null;
        }

        $lookup = $this->db->prepare("
            SELECT id
            FROM echomail
            WHERE echoarea_id = ?
              AND (message_id = ? OR source_msgid = ?)
            ORDER BY id ASC
            LIMIT 1
        ");
        $lookup->execute([$targetAreaId, $matchMsgId, $matchMsgId]);
        $id = $lookup->fetchColumn();
        return $id ? (int)$id : null;
    }
}
