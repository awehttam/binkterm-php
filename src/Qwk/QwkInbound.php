<?php

namespace BinktermPHP\Qwk;

use BinktermPHP\Database;
use BinktermPHP\MessageHandler;
use PDO;

/**
 * Imports an inter-BBS mailbox `.QWK` packet into local echo areas.
 *
 * This is the inbound side of QWK networking between BBSes. It resolves
 * mailbox/conference mappings, creates placeholder subscriptions when needed,
 * performs deduplication, and stores imported messages with QWK source
 * metadata so they are not echoed straight back to the same mailbox.
 *
 * Used by: Inter-BBS
 */
class QwkInbound
{
    private PDO $db;
    private QwkPacketParser $parser;
    private QwkSubscriptionManager $subscriptions;
    private MessageHandler $messageHandler;
    private QwkMailboxManager $mailboxes;
    /** @var callable|null */
    private $logger = null;

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

    public function setLogger(?callable $logger): void
    {
        $this->logger = $logger;
        $this->parser->setLogger($logger);
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

        $this->log('INFO', sprintf(
            'Importing %d QWK message(s) for mailbox %d (%s)',
            count($parsed['messages']),
            $mailboxId,
            (string)($mailbox['name'] ?? $mailbox['bbs_id'] ?? 'unknown')
        ));

        foreach ($parsed['messages'] as $message) {
            $messageLabel = $this->describeMessage($message);
            $this->log('DEBUG', 'Processing ' . $messageLabel);

            if ($message->conferenceNumber <= 0) {
                $skipped++;
                $this->log('DEBUG', $messageLabel . ' skipped: conference number <= 0');
                continue;
            }

            $conferenceTag = trim((string)($conferenceMap[$message->conferenceNumber] ?? ''));
            $this->log('DEBUG', sprintf(
                '%s mapped to conference tag "%s"',
                $messageLabel,
                $conferenceTag !== '' ? $conferenceTag : '(blank)'
            ));
            $subscription = $this->subscriptions->getOrCreateSubscriptionForConference(
                $mailboxId,
                $message->conferenceNumber,
                $conferenceTag
            );
            if ($subscription === null) {
                $skipped++;
                $this->log('WARNING', sprintf(
                    '%s skipped: no QWK subscription/placeholder area could be resolved for conference %d ("%s")',
                    $messageLabel,
                    $message->conferenceNumber,
                    $conferenceTag !== '' ? $conferenceTag : '(blank)'
                ));
                continue;
            }

            $this->log('DEBUG', sprintf(
                '%s resolved to echoarea_id=%d tag="%s" domain="%s"',
                $messageLabel,
                (int)($subscription['echoarea_id'] ?? 0),
                (string)($subscription['tag'] ?? $subscription['conference_tag'] ?? ''),
                (string)($subscription['domain'] ?? '')
            ));

            if ($this->messageExists($mailboxId, $message->conferenceNumber, $message->messageNumber)) {
                $skipped++;
                $this->log('DEBUG', $messageLabel . ' skipped: duplicate QWK message already imported');
                continue;
            }

            $replyToId = $this->findReplyToId($mailboxId, $message->conferenceNumber, $message->replyToNumber);
            $sourceMsgId = $message->sourceMsgId ?: sprintf('qwk:%d:%d:%d', $mailboxId, $message->conferenceNumber, $message->messageNumber);
            $fromAddress = $this->resolveFromAddress($message, $mailboxId, $mailbox);

            $this->log('DEBUG', sprintf(
                '%s import context: reply_to_id=%s source_msgid="%s" from_address="%s" body_len=%d',
                $messageLabel,
                $replyToId !== null ? (string)$replyToId : 'null',
                $sourceMsgId,
                $fromAddress,
                strlen($message->body)
            ));

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
                'origin_type' => \BinktermPHP\Echomail\RelayPolicyManager::TRANSPORT_QWK,
                'use_relay_policy' => true,
                'exclude_qwk_mailbox_id' => $mailboxId,
                'apply_gates' => true,
            ]);

            if ($newId > 0) {
                $imported++;
                $this->log('INFO', $messageLabel . ' imported as echomail #' . $newId);
            } else {
                $skipped++;
                $this->log('WARNING', $messageLabel . ' skipped: importExternalEchomail returned 0');
            }
        }

        $this->log('INFO', sprintf(
            'QWK import summary for mailbox %d: %d imported, %d skipped',
            $mailboxId,
            $imported,
            $skipped
        ));

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

    private function describeMessage(QwkMessage $message): string
    {
        return sprintf(
            'QWK message #%d conf=%d reply=%d from="%s" to="%s" subject="%s"',
            $message->messageNumber,
            $message->conferenceNumber,
            $message->replyToNumber,
            $message->fromName,
            $message->toName,
            $message->subject
        );
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message);
        }
    }
}
