<?php

namespace BinktermPHP\Qwk;

use BinktermPHP\Database;
use BinktermPHP\MessageHandler;
use PDO;

class QwkInbound
{
    private PDO $db;
    private QwkPacketParser $parser;
    private QwkSubscriptionManager $subscriptions;
    private MessageHandler $messageHandler;

    public function __construct(
        ?PDO $db = null,
        ?QwkPacketParser $parser = null,
        ?QwkSubscriptionManager $subscriptions = null,
        ?MessageHandler $messageHandler = null
    ) {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->parser = $parser ?? new QwkPacketParser();
        $this->subscriptions = $subscriptions ?? new QwkSubscriptionManager($this->db);
        $this->messageHandler = $messageHandler ?? new MessageHandler();
    }

    /**
     * @return array{imported:int,skipped:int}
     */
    public function importPacket(int $uplinkId, string $zipPath): array
    {
        $parsed = $this->parser->parsePacket($zipPath);
        $imported = 0;
        $skipped = 0;

        foreach ($parsed['messages'] as $message) {
            if ($message->conferenceNumber <= 0) {
                $skipped++;
                continue;
            }

            $subscription = $this->subscriptions->getSubscriptionForConference($uplinkId, $message->conferenceNumber);
            if ($subscription === null) {
                $skipped++;
                continue;
            }

            if ($this->messageExists($uplinkId, $message->conferenceNumber, $message->messageNumber)) {
                $skipped++;
                continue;
            }

            $replyToId = $this->findReplyToId($uplinkId, $message->conferenceNumber, $message->replyToNumber);
            $sourceMsgId = $message->sourceMsgId ?: sprintf('qwk:%d:%d:%d', $uplinkId, $message->conferenceNumber, $message->messageNumber);

            $newId = $this->messageHandler->importExternalEchomail([
                'echoarea_id' => (int)$subscription['echoarea_id'],
                'from_name' => $message->fromName,
                'to_name' => $message->toName !== '' ? $message->toName : 'All',
                'subject' => $message->subject !== '' ? $message->subject : '(no subject)',
                'message_text' => $message->body,
                'from_address' => null,
                'reply_to_id' => $replyToId,
                'source_msgid' => $sourceMsgId,
                'qwk_uplink_id' => $uplinkId,
                'qwk_conference_number' => $message->conferenceNumber,
                'qwk_msg_number' => $message->messageNumber,
                'exclude_qwk_uplink_id' => $uplinkId,
                'apply_gates' => true,
            ]);

            if ($newId > 0) {
                $imported++;
            } else {
                $skipped++;
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function messageExists(int $uplinkId, int $conferenceNumber, int $messageNumber): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1
            FROM echomail
            WHERE qwk_uplink_id = ? AND qwk_conference_number = ? AND qwk_msg_number = ?
            LIMIT 1
        ");
        $stmt->execute([$uplinkId, $conferenceNumber, $messageNumber]);
        return (bool)$stmt->fetchColumn();
    }

    private function findReplyToId(int $uplinkId, int $conferenceNumber, int $messageNumber): ?int
    {
        if ($messageNumber <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT id
            FROM echomail
            WHERE qwk_uplink_id = ? AND qwk_conference_number = ? AND qwk_msg_number = ?
            LIMIT 1
        ");
        $stmt->execute([$uplinkId, $conferenceNumber, $messageNumber]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }
}
