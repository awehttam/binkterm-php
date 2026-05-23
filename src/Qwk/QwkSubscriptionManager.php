<?php

namespace BinktermPHP\Qwk;

use BinktermPHP\Database;
use PDO;

class QwkSubscriptionManager
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getSubscriptionsForArea(int $echoareaId): array
    {
        $stmt = $this->db->prepare("
            SELECT s.id, s.echoarea_id, s.uplink_id, s.conference_tag, s.conference_number,
                   u.name AS uplink_name, u.bbs_id AS uplink_bbs_id, u.enabled AS uplink_enabled
            FROM echo_area_qwk_subscriptions s
            JOIN qwk_uplinks u ON u.id = s.uplink_id
            WHERE s.echoarea_id = ?
            ORDER BY LOWER(u.name), s.conference_number
        ");
        $stmt->execute([$echoareaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getSubscriptionsForUplink(int $uplinkId): array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, e.tag, e.domain, e.is_local, e.uplink_address
            FROM echo_area_qwk_subscriptions s
            JOIN echoareas e ON e.id = s.echoarea_id
            WHERE s.uplink_id = ?
            ORDER BY s.conference_number, e.id
        ");
        $stmt->execute([$uplinkId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getSubscriptionForConference(int $uplinkId, int $conferenceNumber): ?array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, e.tag, e.domain, e.is_local, e.uplink_address
            FROM echo_area_qwk_subscriptions s
            JOIN echoareas e ON e.id = s.echoarea_id
            WHERE s.uplink_id = ? AND s.conference_number = ?
            LIMIT 1
        ");
        $stmt->execute([$uplinkId, $conferenceNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
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
                (echoarea_id, uplink_id, conference_tag, conference_number, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");

        foreach ($subscriptions as $subscription) {
            $uplinkId = (int)($subscription['uplink_id'] ?? 0);
            $conferenceNumber = (int)($subscription['conference_number'] ?? 0);
            $conferenceTag = trim((string)($subscription['conference_tag'] ?? ''));
            if ($uplinkId <= 0 || $conferenceNumber < 0 || $conferenceTag === '') {
                throw new \InvalidArgumentException('Invalid QWK subscription payload');
            }

            $insertStmt->execute([$echoareaId, $uplinkId, $conferenceTag, $conferenceNumber]);
        }
    }
}
