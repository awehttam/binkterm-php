<?php

namespace BinktermPHP\Qwk;

use BinktermPHP\Database;
use BinktermPHP\EchoareaManager;
use PDO;

class QwkSubscriptionManager
{
    private PDO $db;
    private EchoareaManager $echoareaManager;

    public function __construct(?PDO $db = null, ?EchoareaManager $echoareaManager = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->echoareaManager = $echoareaManager ?? new EchoareaManager($this->db);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getSubscriptionsForArea(int $echoareaId): array
    {
        $stmt = $this->db->prepare("
            SELECT s.id, s.echoarea_id, s.mailbox_id, s.conference_tag, s.conference_number, s.auto_created,
                   m.name AS mailbox_name, m.bbs_id AS mailbox_bbs_id, m.enabled AS mailbox_enabled
            FROM echo_area_qwk_subscriptions s
            JOIN qwk_mailboxes m ON m.id = s.mailbox_id
            WHERE s.echoarea_id = ?
            ORDER BY LOWER(m.name), s.conference_number
        ");
        $stmt->execute([$echoareaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getSubscriptionsForMailbox(int $mailboxId): array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, e.tag, e.domain, e.is_local, e.uplink_address
            FROM echo_area_qwk_subscriptions s
            JOIN echoareas e ON e.id = s.echoarea_id
            WHERE s.mailbox_id = ?
            ORDER BY s.conference_number, e.id
        ");
        $stmt->execute([$mailboxId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getSubscriptionForConference(int $mailboxId, int $conferenceNumber): ?array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, e.tag, e.domain, e.is_local, e.uplink_address
            FROM echo_area_qwk_subscriptions s
            JOIN echoareas e ON e.id = s.echoarea_id
            WHERE s.mailbox_id = ? AND s.conference_number = ?
            LIMIT 1
        ");
        $stmt->execute([$mailboxId, $conferenceNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getOrCreateSubscriptionForConference(int $mailboxId, int $conferenceNumber, string $conferenceTag): ?array
    {
        $existing = $this->getSubscriptionForConference($mailboxId, $conferenceNumber);
        if ($existing !== null) {
            return $existing;
        }

        $echoareaId = $this->echoareaManager->createQwkPlaceholderArea($conferenceTag, $conferenceNumber);
        if ($echoareaId <= 0) {
            return null;
        }

        $insertStmt = $this->db->prepare("
            INSERT INTO echo_area_qwk_subscriptions
                (echoarea_id, mailbox_id, conference_tag, conference_number, auto_created, created_at)
            VALUES (?, ?, ?, ?, 'true', NOW())
            ON CONFLICT (mailbox_id, conference_number) DO NOTHING
        ");
        $insertStmt->execute([
            $echoareaId,
            $mailboxId,
            trim($conferenceTag) !== '' ? trim($conferenceTag) : ('Conference ' . $conferenceNumber),
            $conferenceNumber,
        ]);

        return $this->getSubscriptionForConference($mailboxId, $conferenceNumber);
    }

    /**
     * @param array<int,array<string,mixed>> $subscriptions
     */
    public function replaceAreaSubscriptions(int $echoareaId, array $subscriptions): void
    {
        $deleteStmt = $this->db->prepare("DELETE FROM echo_area_qwk_subscriptions WHERE echoarea_id = ?");
        $deleteStmt->execute([$echoareaId]);

        if ($subscriptions === []) {
            return;
        }

        $insertStmt = $this->db->prepare("
            INSERT INTO echo_area_qwk_subscriptions
                (echoarea_id, mailbox_id, conference_tag, conference_number, auto_created, created_at)
            VALUES (?, ?, ?, ?, 'false', NOW())
        ");

        foreach ($subscriptions as $subscription) {
            $mailboxId = (int)($subscription['mailbox_id'] ?? 0);
            $conferenceNumber = (int)($subscription['conference_number'] ?? 0);
            $conferenceTag = trim((string)($subscription['conference_tag'] ?? ''));
            if ($mailboxId <= 0 || $conferenceNumber < 0 || $conferenceTag === '') {
                throw new \InvalidArgumentException('Invalid QWK subscription payload');
            }

            $insertStmt->execute([$echoareaId, $mailboxId, $conferenceTag, $conferenceNumber]);
        }
    }
}
