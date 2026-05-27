<?php

namespace BinktermPHP\Echomail;

use BinktermPHP\Database;
use BinktermPHP\NetworkManager;
use PDO;

/**
 * Manages echoarea-level relay policy for transport-imported messages.
 */
class RelayPolicyManager
{
    public const MODE_NONE = 'none';
    public const MODE_AUTO = 'auto';
    public const MODE_MANUAL = 'manual';

    public const TRANSPORT_FTN = 'ftn';
    public const TRANSPORT_QWK = 'qwk';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    public function getModeForArea(int $echoareaId): string
    {
        $stmt = $this->db->prepare("SELECT relay_mode FROM echoareas WHERE id = ? LIMIT 1");
        $stmt->execute([$echoareaId]);
        $mode = $stmt->fetchColumn();

        return $this->normalizeMode(is_string($mode) ? $mode : self::MODE_AUTO);
    }

    public function setModeForArea(int $echoareaId, string $mode): void
    {
        $stmt = $this->db->prepare("UPDATE echoareas SET relay_mode = ? WHERE id = ?");
        $stmt->execute([$this->normalizeMode($mode), $echoareaId]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getRulesForArea(int $echoareaId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, echoarea_id, origin_type, target_type, is_allowed, created_at
            FROM echo_area_relay_rules
            WHERE echoarea_id = ?
            ORDER BY LOWER(origin_type), LOWER(target_type), id
        ");
        $stmt->execute([$echoareaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<int,array<string,mixed>> $rules
     */
    public function replaceRulesForArea(int $echoareaId, array $rules): void
    {
        $this->db->prepare("DELETE FROM echo_area_relay_rules WHERE echoarea_id = ?")
            ->execute([$echoareaId]);

        if ($rules === []) {
            return;
        }

        $insertStmt = $this->db->prepare("
            INSERT INTO echo_area_relay_rules
                (echoarea_id, origin_type, target_type, is_allowed)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($rules as $rule) {
            $originType = $this->normalizeTransportType((string)($rule['origin_type'] ?? ''));
            $targetType = $this->normalizeTransportType((string)($rule['target_type'] ?? ''));
            if ($originType === '' || $targetType === '' || $originType === $targetType) {
                throw new \InvalidArgumentException('Invalid relay rule payload');
            }

            $isAllowed = !array_key_exists('is_allowed', $rule) || !empty($rule['is_allowed']);
            $insertStmt->execute([
                $echoareaId,
                $originType,
                $targetType,
                $isAllowed ? 'true' : 'false',
            ]);
        }
    }

    public function shouldRelayImportedMessage(int $echoareaId, string $originType, string $targetType): bool
    {
        $originType = $this->normalizeTransportType($originType);
        $targetType = $this->normalizeTransportType($targetType);
        if ($originType === '' || $targetType === '' || $originType === $targetType) {
            return false;
        }

        $mode = $this->getModeForArea($echoareaId);
        if ($mode === self::MODE_NONE) {
            return false;
        }
        if ($mode === self::MODE_AUTO) {
            return true;
        }

        $stmt = $this->db->prepare("
            SELECT is_allowed
            FROM echo_area_relay_rules
            WHERE echoarea_id = ?
              AND origin_type = ?
              AND target_type = ?
            LIMIT 1
        ");
        $stmt->execute([$echoareaId, $originType, $targetType]);
        $allowed = $stmt->fetchColumn();

        return $allowed !== false && filter_var($allowed, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<int,string>
     */
    public function getAvailableTransportTypesForArea(int $echoareaId): array
    {
        $stmt = $this->db->prepare("
            SELECT e.domain,
                   e.is_local,
                   EXISTS (
                       SELECT 1
                       FROM echo_area_qwk_subscriptions s
                       WHERE s.echoarea_id = e.id
                   ) AS has_qwk
            FROM echoareas e
            WHERE e.id = ?
            LIMIT 1
        ");
        $stmt->execute([$echoareaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [];
        }

        $types = [];
        if ($this->isFtnRoutableArea($row)) {
            $types[] = self::TRANSPORT_FTN;
        }
        if (!empty($row['has_qwk']) && empty($row['is_local'])) {
            $types[] = self::TRANSPORT_QWK;
        }

        return $types;
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (in_array($mode, [self::MODE_NONE, self::MODE_AUTO, self::MODE_MANUAL], true)) {
            return $mode;
        }

        return self::MODE_AUTO;
    }

    private function normalizeTransportType(string $type): string
    {
        $type = strtolower(trim($type));
        if ($type === '' || !preg_match('/^[a-z0-9_]+$/', $type)) {
            return '';
        }

        return $type;
    }

    /**
     * @param array<string,mixed> $echoarea
     */
    private function isFtnRoutableArea(array $echoarea): bool
    {
        if (!empty($echoarea['is_local'])) {
            return false;
        }

        $domain = strtolower(trim((string)($echoarea['domain'] ?? '')));
        if ($domain === '') {
            return false;
        }

        $network = (new NetworkManager($this->db))->getByDomain($domain);

        return (int)($network['network_type'] ?? 0) === NetworkManager::NETWORK_TYPE_FIDONET;
    }
}
