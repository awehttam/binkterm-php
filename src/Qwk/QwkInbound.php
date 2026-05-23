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
    private QwkMailboxManager $mailboxes;

    public function __construct(
        ?PDO $db = null,
        ?QwkPacketParser $parser = null,
        ?QwkSubscriptionManager $subscriptions = null,
        ?MessageHandler $messageHandler = null,
        ?QwkMailboxManager $mailboxes = null
    ) {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->parser = $parser ?? new QwkPacketParser();
        $this->subscriptions = $subscriptions ?? new QwkSubscriptionManager($this->db);
        $this->messageHandler = $messageHandler ?? new MessageHandler();
        $this->mailboxes = $mailboxes ?? new QwkMailboxManager($this->db);
    }

    /**
     * @return array{imported:int,skipped:int}
     */
    public function importPacket(int $mailboxId, string $zipPath): array
    {
        $parsed = $this->parser->parsePacket($zipPath);
        $imported = 0;
        $skipped = 0;
        $mailbox = $this->mailboxes->getById($mailboxId, true);
        $conferenceMap = is_array($parsed['control']['conferences'] ?? null)
            ? $parsed['control']['conferences']
            : [];

        foreach ($parsed['messages'] as $message) {
            if ($message->conferenceNumber <= 0) {
                $skipped++;
                continue;
            }

            $conferenceTag = trim((string)($conferenceMap[$message->conferenceNumber] ?? ''));
            $subscription = $this->subscriptions->getOrCreateSubscriptionForConference(
                $mailboxId,
                $message->conferenceNumber,
                $conferenceTag
            );
            if ($subscription === null) {
                $skipped++;
                continue;
            }

            if ($this->messageExists($mailboxId, $message->conferenceNumber, $message->messageNumber)) {
                $skipped++;
                continue;
            }

            $replyToId = $this->findReplyToId($mailboxId, $message->conferenceNumber, $message->replyToNumber);
            $sourceMsgId = $message->sourceMsgId ?: sprintf('qwk:%d:%d:%d', $mailboxId, $message->conferenceNumber, $message->messageNumber);
            $fromAddress = $this->resolveFromAddress($message, $mailboxId, $mailbox);

            $newId = $this->messageHandler->importExternalEchomail([
                'echoarea_id' => (int)$subscription['echoarea_id'],
                'from_name' => $message->fromName,
                'to_name' => $message->toName !== '' ? $message->toName : 'All',
                'subject' => $message->subject !== '' ? $message->subject : '(no subject)',
                'message_text' => $message->body,
                'from_address' => $fromAddress,
                'reply_to_id' => $replyToId,
                'source_msgid' => $sourceMsgId,
                'qwk_mailbox_id' => $mailboxId,
                'qwk_conference_number' => $message->conferenceNumber,
                'qwk_msg_number' => $message->messageNumber,
                'exclude_qwk_mailbox_id' => $mailboxId,
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

    /**
     * @param array<string,mixed>|null $mailbox
     */
    private function buildSyntheticFromAddress(int $mailboxId, ?array $mailbox): string
    {
        $bbsId = strtoupper(trim((string)($mailbox['bbs_id'] ?? '')));
        if ($bbsId !== '') {
            return substr('qwk:' . $bbsId, 0, 50);
        }

        return substr('qwk:mailbox-' . $mailboxId, 0, 50);
    }

    /**
     * @param array<string,mixed>|null $mailbox
     */
    private function resolveFromAddress(QwkMessage $message, int $mailboxId, ?array $mailbox): string
    {
        $kludgeAddress = $this->extractAddressFromKludges($message->kludgeLines);
        if ($kludgeAddress !== null) {
            return $kludgeAddress;
        }

        return $this->buildSyntheticFromAddress($mailboxId, $mailbox);
    }

    private function extractAddressFromKludges(string $kludgeLines): ?string
    {
        $replyAddr = $this->extractFtnAddress('/^\x01REPLYADDR\s+(.+)$/im', $kludgeLines);
        if ($replyAddr !== null) {
            return $replyAddr;
        }

        $msgIdAddress = $this->extractAddressFromMsgId($this->extractKludgeValue('MSGID', $kludgeLines));
        if ($msgIdAddress !== null) {
            return $msgIdAddress;
        }

        $replyTo = $this->extractFtnAddress('/^\x01REPLYTO\s+(.+)$/im', $kludgeLines);
        if ($replyTo !== null) {
            return $replyTo;
        }

        return null;
    }

    private function extractKludgeValue(string $name, string $kludgeLines): ?string
    {
        $pattern = '/^\x01' . preg_quote($name, '/') . ':\s*(.+)$/im';
        if (preg_match($pattern, $kludgeLines, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractFtnAddress(string $pattern, string $text): ?string
    {
        if (!preg_match($pattern, $text, $matches)) {
            return null;
        }

        $candidate = trim($matches[1]);
        if (preg_match('/(\d+:\d+\/\d+(?:\.\d+)?(?:@[A-Za-z0-9_-]+)?)/', $candidate, $addressMatches)) {
            return $addressMatches[1];
        }

        return null;
    }

    private function extractAddressFromMsgId(?string $sourceMsgId): ?string
    {
        $sourceMsgId = trim((string)$sourceMsgId);
        if ($sourceMsgId === '') {
            return null;
        }

        if (preg_match('/^(\d+:\d+\/\d+(?:\.\d+)?(?:@[A-Za-z0-9_-]+)?)(?:\s|$)/', $sourceMsgId, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function messageExists(int $mailboxId, int $conferenceNumber, int $messageNumber): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1
            FROM echomail
            WHERE qwk_mailbox_id = ? AND qwk_conference_number = ? AND qwk_msg_number = ?
            LIMIT 1
        ");
        $stmt->execute([$mailboxId, $conferenceNumber, $messageNumber]);
        return (bool)$stmt->fetchColumn();
    }

    private function findReplyToId(int $mailboxId, int $conferenceNumber, int $messageNumber): ?int
    {
        if ($messageNumber <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT id
            FROM echomail
            WHERE qwk_mailbox_id = ? AND qwk_conference_number = ? AND qwk_msg_number = ?
            LIMIT 1
        ");
        $stmt->execute([$mailboxId, $conferenceNumber, $messageNumber]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }
}
