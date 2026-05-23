<?php

namespace BinktermPHP\Qwk;

use BinktermPHP\Database;
use PDO;

class QwkOutbound
{
    private PDO $db;
    private RepPacketBuilder $builder;

    public function __construct(?PDO $db = null, ?RepPacketBuilder $builder = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->builder = $builder ?? new RepPacketBuilder();
    }

    public function buildPendingRepPacket(array $uplink): ?string
    {
        $rows = $this->getPendingMessages((int)$uplink['id']);
        if ($rows === []) {
            return null;
        }

        $messages = [];
        foreach ($rows as $row) {
            $replyToNum = 0;
            if (!empty($row['reply_qwk_uplink_id'])
                && (int)$row['reply_qwk_uplink_id'] === (int)$uplink['id']
                && (int)$row['reply_qwk_conference_number'] === (int)$row['conference_number']
            ) {
                $replyToNum = (int)$row['reply_qwk_msg_number'];
            }

            $messages[] = [
                'conference_number' => (int)$row['conference_number'],
                'to_name' => $row['to_name'] ?: 'All',
                'from_name' => $row['from_name'] ?: 'Unknown',
                'subject' => $row['subject'] ?: '(no subject)',
                'body' => $row['message_text'] ?: '',
                'reply_to_num' => $replyToNum,
            ];
        }

        return $this->builder->build((string)$uplink['bbs_id'], $messages);
    }

    public function markUploaded(int $uplinkId): void
    {
        $stmt = $this->db->prepare("
            UPDATE qwk_outbound_messages
            SET sent_at = NOW()
            WHERE uplink_id = ? AND sent_at IS NULL
        ");
        $stmt->execute([$uplinkId]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getPendingMessages(int $uplinkId): array
    {
        $stmt = $this->db->prepare("
            SELECT qom.id AS queue_id,
                   em.id AS echomail_id,
                   em.to_name,
                   em.from_name,
                   em.subject,
                   em.message_text,
                   s.conference_number,
                   parent.qwk_uplink_id AS reply_qwk_uplink_id,
                   parent.qwk_conference_number AS reply_qwk_conference_number,
                   parent.qwk_msg_number AS reply_qwk_msg_number
            FROM qwk_outbound_messages qom
            JOIN echomail em ON em.id = qom.echomail_id
            JOIN echo_area_qwk_subscriptions s
              ON s.echoarea_id = em.echoarea_id
             AND s.uplink_id = qom.uplink_id
            LEFT JOIN echomail parent ON parent.id = em.reply_to_id
            WHERE qom.uplink_id = ?
              AND qom.sent_at IS NULL
            ORDER BY qom.id ASC
        ");
        $stmt->execute([$uplinkId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
