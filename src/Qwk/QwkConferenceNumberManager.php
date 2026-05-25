<?php

namespace BinktermPHP\Qwk;

use BinktermPHP\Database;
use PDO;

/**
 * Maintains canonical BBS-wide QWK conference numbers for echo areas.
 *
 * Conference 0 is reserved for personal mail / netmail. Echo areas use the
 * qwk_conference_number stored on the echoareas table and new areas are
 * assigned the next available number the first time they are needed.
 */
class QwkConferenceNumberManager
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    /**
     * Ensure every supplied echo area has a canonical QWK conference number.
     *
     * @param array $echoareas
     * @return array<int,int> [echoarea_id => conference_number]
     */
    public function getOrCreateConferenceNumbers(array $echoareas): array
    {
        if (empty($echoareas)) {
            return [];
        }

        $numbersByArea = [];
        $missingAreaIds = [];

        foreach ($echoareas as $area) {
            $echoareaId = (int)($area['id'] ?? 0);
            if ($echoareaId <= 0) {
                continue;
            }

            $existingNumber = isset($area['qwk_conference_number']) && $area['qwk_conference_number'] !== null
                ? (int)$area['qwk_conference_number']
                : 0;

            if ($existingNumber > 0) {
                $numbersByArea[$echoareaId] = $existingNumber;
                continue;
            }

            $missingAreaIds[] = $echoareaId;
        }

        if (empty($missingAreaIds)) {
            return $numbersByArea;
        }

        $placeholders = implode(',', array_fill(0, count($missingAreaIds), '?'));
        $stmt = $this->db->prepare("
            SELECT id, qwk_conference_number
            FROM echoareas
            WHERE id IN ({$placeholders})
        ");
        $stmt->execute($missingAreaIds);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $echoareaId = (int)$row['id'];
            $conferenceNumber = (int)($row['qwk_conference_number'] ?? 0);
            if ($conferenceNumber > 0) {
                $numbersByArea[$echoareaId] = $conferenceNumber;
            }
        }

        $unassignedIds = [];
        foreach ($missingAreaIds as $echoareaId) {
            if (!isset($numbersByArea[$echoareaId])) {
                $unassignedIds[] = $echoareaId;
            }
        }

        if (empty($unassignedIds)) {
            return $numbersByArea;
        }

        $this->db->beginTransaction();
        try {
            $nextNumber = (int)$this->db->query("
                SELECT COALESCE(MAX(qwk_conference_number), 0)
                FROM echoareas
            ")->fetchColumn();

            $updateStmt = $this->db->prepare("
                UPDATE echoareas
                SET qwk_conference_number = ?
                WHERE id = ? AND qwk_conference_number IS NULL
            ");

            foreach ($echoareas as $area) {
                $echoareaId = (int)($area['id'] ?? 0);
                if (!in_array($echoareaId, $unassignedIds, true)) {
                    continue;
                }

                $nextNumber++;
                $updateStmt->execute([$nextNumber, $echoareaId]);
                $numbersByArea[$echoareaId] = $nextNumber;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $numbersByArea;
    }
}
