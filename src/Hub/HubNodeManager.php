<?php

namespace BinktermPHP\Hub;

use PDO;
use BinktermPHP\Database;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Echomail\EchomailSeenBy;
use BinktermPHP\NetworkManager;

/**
 * CRUD for hub_nodes (subordinate FTN nodes/points BinktermPHP distributes
 * echomail/netmail to) and their per-echoarea subscriptions (hub_node_areas).
 * See docs/proposals/HubPointSystemJuly2026.md.
 */
class HubNodeManager
{
    public const TYPE_NODE = 'node';
    public const TYPE_POINT = 'point';

    private PDO $db;

    private const COLUMNS = "
        hn.id, hn.node_type, hn.node_address, hn.boss_address, hn.point_number, hn.name, hn.sysop_name,
        hn.session_password, hn.packet_password, hn.areafix_password, hn.filefix_password,
        hn.inet_host, hn.port, hn.enabled, hn.allow_inbound,
        hn.allow_outbound, hn.allow_inbound_echomail, hn.allow_inbound_netmail, hn.max_packet_kb,
        hn.hold_mail, hn.compress_outbound, hn.queue_retention_days, hn.capability_flags, hn.notes, hn.created_at, hn.last_session_at,
        hn.push_poll_interval_minutes, hn.last_push_at
    ";

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAll(?string $type = null): array
    {
        if ($type !== null) {
            $stmt = $this->db->prepare("SELECT " . self::COLUMNS . " FROM hub_nodes hn WHERE hn.node_type = ? ORDER BY hn.node_type, hn.node_address");
            $stmt->execute([$type]);
        } else {
            $stmt = $this->db->query("SELECT " . self::COLUMNS . " FROM hub_nodes hn ORDER BY hn.node_type, hn.node_address");
        }

        return array_map([$this, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT " . self::COLUMNS . " FROM hub_nodes hn WHERE hn.id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalizeRow($row) : null;
    }

    public function getByAddress(string $address): ?array
    {
        $stmt = $this->db->prepare("SELECT " . self::COLUMNS . " FROM hub_nodes hn WHERE hn.node_address = ? LIMIT 1");
        $stmt->execute([trim($address)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalizeRow($row) : null;
    }

    public function create(array $data): array
    {
        $prepared = $this->prepareFields($data);

        if ($this->getByAddress($prepared['node_address']) !== null) {
            throw new \InvalidArgumentException('A hub node with that address already exists');
        }

        $stmt = $this->db->prepare("
            INSERT INTO hub_nodes (
                node_type, node_address, boss_address, point_number, name, sysop_name,
                session_password, packet_password, areafix_password, filefix_password,
                inet_host, port, enabled, allow_inbound,
                allow_outbound, allow_inbound_echomail, allow_inbound_netmail, max_packet_kb,
                hold_mail, compress_outbound, queue_retention_days, capability_flags, notes,
                push_poll_interval_minutes
            ) VALUES (
                :node_type, :node_address, :boss_address, :point_number, :name, :sysop_name,
                :session_password, :packet_password, :areafix_password, :filefix_password,
                :inet_host, :port, :enabled, :allow_inbound,
                :allow_outbound, :allow_inbound_echomail, :allow_inbound_netmail, :max_packet_kb,
                :hold_mail, :compress_outbound, :queue_retention_days, :capability_flags, :notes,
                :push_poll_interval_minutes
            )
            RETURNING id
        ");
        $stmt->execute($this->bindable($prepared));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $this->getById((int)($row['id'] ?? 0)) ?? [];
    }

    public function update(int $id, array $data): array
    {
        $existing = $this->getById($id);
        if (!$existing) {
            throw new \InvalidArgumentException('Hub node not found');
        }

        $prepared = $this->prepareFields(array_merge($existing, $data));

        $conflict = $this->getByAddress($prepared['node_address']);
        if ($conflict !== null && (int)$conflict['id'] !== $id) {
            throw new \InvalidArgumentException('A hub node with that address already exists');
        }

        $stmt = $this->db->prepare("
            UPDATE hub_nodes SET
                node_type = :node_type,
                node_address = :node_address,
                boss_address = :boss_address,
                point_number = :point_number,
                name = :name,
                sysop_name = :sysop_name,
                session_password = :session_password,
                packet_password = :packet_password,
                areafix_password = :areafix_password,
                filefix_password = :filefix_password,
                inet_host = :inet_host,
                port = :port,
                enabled = :enabled,
                allow_inbound = :allow_inbound,
                allow_outbound = :allow_outbound,
                allow_inbound_echomail = :allow_inbound_echomail,
                allow_inbound_netmail = :allow_inbound_netmail,
                max_packet_kb = :max_packet_kb,
                hold_mail = :hold_mail,
                compress_outbound = :compress_outbound,
                queue_retention_days = :queue_retention_days,
                capability_flags = :capability_flags,
                notes = :notes,
                push_poll_interval_minutes = :push_poll_interval_minutes
            WHERE id = :id
        ");
        $bindable = $this->bindable($prepared);
        $bindable['id'] = $id;
        $stmt->execute($bindable);

        return $this->getById($id) ?? [];
    }

    public function delete(int $id): void
    {
        if (!$this->getById($id)) {
            throw new \InvalidArgumentException('Hub node not found');
        }

        $stmt = $this->db->prepare("DELETE FROM hub_nodes WHERE id = ?");
        $stmt->execute([$id]);
    }

    /**
     * All echoareas with the subscription status (and pause flag) for a given hub node,
     * scoped to areas in the node's own network domain (resolveDomain()) - local areas
     * (no domain, or a literal 'local'-ish domain value) are never offered since a hub
     * node/point only ever exchanges mail within one real network domain. Areas the
     * node is already subscribed to are always included regardless of domain, even if
     * that subscription predates domain scoping or was assigned cross-domain by an
     * admin - otherwise a save via bulkSetAreaSubscriptions() (a full replace of
     * whatever list this returns) would silently drop it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAreaSubscriptions(int $hubNodeId): array
    {
        $hubNode = $this->getById($hubNodeId);
        if (!$hubNode) {
            throw new \InvalidArgumentException('Hub node not found');
        }
        $domain = $this->resolveDomain($hubNode);

        $stmt = $this->db->prepare("
            SELECT ea.id AS echoarea_id, ea.tag, ea.domain, ea.description,
                   hna.id AS subscription_id, hna.paused, hna.subscribed_at
            FROM echoareas ea
            LEFT JOIN hub_node_areas hna ON hna.echoarea_id = ea.id AND hna.hub_node_id = ?
            WHERE ea.is_active = TRUE
              AND (
                    hna.id IS NOT NULL
                    OR (ea.domain IS NOT NULL AND ea.domain != '' AND LOWER(ea.domain) = LOWER(?))
                  )
            ORDER BY ea.domain, ea.tag
        ");
        $stmt->execute([$hubNodeId, $domain]);

        return array_map(function (array $row) {
            $row['echoarea_id'] = (int)$row['echoarea_id'];
            $row['subscribed'] = $row['subscription_id'] !== null;
            $row['paused'] = filter_var($row['paused'] ?? false, FILTER_VALIDATE_BOOLEAN);
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function setAreaSubscription(int $hubNodeId, int $echoareaId, bool $subscribed, bool $paused = false): void
    {
        if (!$this->getById($hubNodeId)) {
            throw new \InvalidArgumentException('Hub node not found');
        }

        if (!$subscribed) {
            $stmt = $this->db->prepare("DELETE FROM hub_node_areas WHERE hub_node_id = ? AND echoarea_id = ?");
            $stmt->execute([$hubNodeId, $echoareaId]);
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO hub_node_areas (hub_node_id, echoarea_id, paused)
            VALUES (?, ?, ?)
            ON CONFLICT (hub_node_id, echoarea_id) DO UPDATE SET paused = EXCLUDED.paused
        ");
        $stmt->execute([$hubNodeId, $echoareaId, $paused ? 'true' : 'false']);
    }

    /**
     * Replace the full subscription set for a hub node in one call.
     *
     * @param int[] $echoareaIds
     */
    public function bulkSetAreaSubscriptions(int $hubNodeId, array $echoareaIds): void
    {
        if (!$this->getById($hubNodeId)) {
            throw new \InvalidArgumentException('Hub node not found');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("DELETE FROM hub_node_areas WHERE hub_node_id = ?")->execute([$hubNodeId]);

            $stmt = $this->db->prepare("INSERT INTO hub_node_areas (hub_node_id, echoarea_id) VALUES (?, ?)");
            foreach (array_unique(array_map('intval', $echoareaIds)) as $echoareaId) {
                $stmt->execute([$hubNodeId, $echoareaId]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Active hub_nodes subscribed to a given echoarea (enabled, not held,
     * subscription not individually paused). Used by HubFanout.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSubscribersForArea(int $echoareaId): array
    {
        $stmt = $this->db->prepare("
            SELECT " . self::COLUMNS . "
            FROM hub_nodes hn
            JOIN hub_node_areas hna ON hna.hub_node_id = hn.id
            WHERE hna.echoarea_id = ?
              AND hn.enabled = TRUE
              AND hn.hold_mail = FALSE
              AND hna.paused = FALSE
        ");
        $stmt->execute([$echoareaId]);

        return array_map([$this, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * IDs of the active echoareas a hub node is currently subscribed to and
     * not paused on. Used by HubFanout::rescanForNode() for the AreaFix
     * %RESCAN command - deliberately ignores hn.enabled/hold_mail (unlike
     * getSubscribersForArea()) since rescanForNode() checks those itself.
     *
     * @return int[]
     */
    public function getSubscribedEchoareaIds(int $hubNodeId): array
    {
        $stmt = $this->db->prepare("
            SELECT hna.echoarea_id
            FROM hub_node_areas hna
            JOIN echoareas ea ON ea.id = hna.echoarea_id
            WHERE hna.hub_node_id = ? AND hna.paused = FALSE AND ea.is_active = TRUE
        ");
        $stmt->execute([$hubNodeId]);

        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'echoarea_id'));
    }

    /**
     * The active, non-paused echoarea a hub node is currently subscribed to
     * matching $tag, or null if it isn't subscribed to any such area. Used
     * by HubAreafixProcessor's %RESCAN <AREATAG> to scope a rescan to one
     * area - deliberately requires an existing subscription rather than any
     * matching area, so %RESCAN can't be used to peek at unsubscribed history.
     *
     * @return array{id: int, tag: string, domain: ?string}|null
     */
    public function findSubscribedEchoareaByTag(int $hubNodeId, string $tag): ?array
    {
        $stmt = $this->db->prepare("
            SELECT ea.id, ea.tag, ea.domain
            FROM hub_node_areas hna
            JOIN echoareas ea ON ea.id = hna.echoarea_id
            WHERE hna.hub_node_id = ? AND hna.paused = FALSE AND ea.is_active = TRUE
              AND UPPER(ea.tag) = UPPER(?)
            LIMIT 1
        ");
        $stmt->execute([$hubNodeId, $tag]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return ['id' => (int)$row['id'], 'tag' => $row['tag'], 'domain' => $row['domain']];
    }

    /**
     * All file areas with the subscription status (and pause flag) for a given hub node.
     * Mirrors getAreaSubscriptions(), including its domain scoping and the same
     * always-include-existing-subscriptions rule. See docs/proposals/HubPointSystemJuly2026.md Phase 4.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFileAreaSubscriptions(int $hubNodeId): array
    {
        $hubNode = $this->getById($hubNodeId);
        if (!$hubNode) {
            throw new \InvalidArgumentException('Hub node not found');
        }
        $domain = $this->resolveDomain($hubNode);

        $stmt = $this->db->prepare("
            SELECT fa.id AS file_area_id, fa.tag, fa.domain, fa.description,
                   hnf.id AS subscription_id, hnf.paused, hnf.subscribed_at
            FROM file_areas fa
            LEFT JOIN hub_node_fileareas hnf ON hnf.file_area_id = fa.id AND hnf.hub_node_id = ?
            WHERE fa.is_active = TRUE
              AND fa.is_private = FALSE
              AND (
                    hnf.id IS NOT NULL
                    OR (fa.domain IS NOT NULL AND fa.domain != '' AND LOWER(fa.domain) = LOWER(?))
                  )
            ORDER BY fa.domain, fa.tag
        ");
        $stmt->execute([$hubNodeId, $domain]);

        return array_map(function (array $row) {
            $row['file_area_id'] = (int)$row['file_area_id'];
            $row['subscribed'] = $row['subscription_id'] !== null;
            $row['paused'] = filter_var($row['paused'] ?? false, FILTER_VALIDATE_BOOLEAN);
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function setFileAreaSubscription(int $hubNodeId, int $fileAreaId, bool $subscribed, bool $paused = false): void
    {
        if (!$this->getById($hubNodeId)) {
            throw new \InvalidArgumentException('Hub node not found');
        }

        if (!$subscribed) {
            $stmt = $this->db->prepare("DELETE FROM hub_node_fileareas WHERE hub_node_id = ? AND file_area_id = ?");
            $stmt->execute([$hubNodeId, $fileAreaId]);
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO hub_node_fileareas (hub_node_id, file_area_id, paused)
            VALUES (?, ?, ?)
            ON CONFLICT (hub_node_id, file_area_id) DO UPDATE SET paused = EXCLUDED.paused
        ");
        $stmt->execute([$hubNodeId, $fileAreaId, $paused ? 'true' : 'false']);
    }

    /**
     * Replace the full file area subscription set for a hub node in one call.
     * Mirrors bulkSetAreaSubscriptions().
     *
     * @param int[] $fileAreaIds
     */
    public function bulkSetFileAreaSubscriptions(int $hubNodeId, array $fileAreaIds): void
    {
        if (!$this->getById($hubNodeId)) {
            throw new \InvalidArgumentException('Hub node not found');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare("DELETE FROM hub_node_fileareas WHERE hub_node_id = ?")->execute([$hubNodeId]);

            $stmt = $this->db->prepare("INSERT INTO hub_node_fileareas (hub_node_id, file_area_id) VALUES (?, ?)");
            foreach (array_unique(array_map('intval', $fileAreaIds)) as $fileAreaId) {
                $stmt->execute([$hubNodeId, $fileAreaId]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Active hub_nodes subscribed to a given file area (enabled, not held,
     * subscription not individually paused). Used by HubFanout::fanoutFile().
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSubscribersForFileArea(int $fileAreaId): array
    {
        $stmt = $this->db->prepare("
            SELECT " . self::COLUMNS . "
            FROM hub_nodes hn
            JOIN hub_node_fileareas hnf ON hnf.hub_node_id = hn.id
            WHERE hnf.file_area_id = ?
              AND hn.enabled = TRUE
              AND hn.hold_mail = FALSE
              AND hnf.paused = FALSE
        ");
        $stmt->execute([$fileAreaId]);

        return array_map([$this, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Suggest the next unused point number for a given boss AKA.
     */
    public function suggestNextPointNumber(string $bossAddress): int
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(MAX(point_number), 0) AS max_point
            FROM hub_nodes
            WHERE node_type = 'point' AND boss_address = ?
        ");
        $stmt->execute([trim($bossAddress)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['max_point'] ?? 0) + 1;
    }

    /**
     * The network domain a hub node/point belongs to, used to scope which
     * echoareas/fileareas it may self-subscribe to via AreaFix/FileFix. For
     * a point this is the domain of its boss AKA (points can only be
     * created against one of our own configured AKAs, so this always
     * resolves to exactly one domain); for a node it's resolved from its
     * own address the same way BinkdProcessor resolves domain for inbound
     * mail. Returns null if the domain can't be determined.
     */
    public function resolveDomain(array $hubNode): ?string
    {
        $config = BinkpConfig::getInstance();

        if (($hubNode['node_type'] ?? null) === self::TYPE_POINT) {
            $bossAddress = trim((string)($hubNode['boss_address'] ?? ''));
            foreach ($config->getMyAddressesWithDomains() as $entry) {
                if ($entry['address'] === $bossAddress) {
                    return $entry['domain'] ?: null;
                }
            }
            return null;
        }

        $domain = $config->getDomainByAddress(trim((string)($hubNode['node_address'] ?? '')));
        return $domain !== false && $domain !== '' ? $domain : null;
    }

    /**
     * The AKAs BinktermPHP itself holds, for the boss-address picker.
     *
     * @return string[]
     */
    public function getConfiguredAkas(): array
    {
        $config = BinkpConfig::getInstance();
        $akas = [];

        $systemAddress = $config->getSystemAddress();
        if (!empty($systemAddress)) {
            $akas[] = $systemAddress;
        }

        foreach ($config->getUplinks() as $uplink) {
            $me = trim((string)($uplink['me'] ?? ''));
            if ($me !== '') {
                $akas[] = $me;
            }
        }

        return array_values(array_unique($akas));
    }

    /**
     * Outbound queue counts per downlink (pending/failed/held/total), for the
     * Manage Downlinks page's queue-size column and its link into the
     * Binkp Status page's Downlink Queue tab.
     *
     * @return array<int, array{pending: int, failed: int, held: int, total: int}> keyed by hub_node_id
     */
    public function getQueueCounts(): array
    {
        $stmt = $this->db->query("
            SELECT hub_node_id, status, COUNT(*) AS cnt
            FROM hub_node_outbound
            GROUP BY hub_node_id, status
        ");

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int)$row['hub_node_id'];
            if (!isset($counts[$id])) {
                $counts[$id] = ['pending' => 0, 'failed' => 0, 'held' => 0, 'total' => 0];
            }
            $status = (string)$row['status'];
            $cnt = (int)$row['cnt'];
            if (array_key_exists($status, $counts[$id])) {
                $counts[$id][$status] = $cnt;
            }
            $counts[$id]['total'] += $cnt;
        }

        return $counts;
    }

    /**
     * The AKAs BinktermPHP itself holds, paired with the display name of the
     * network each belongs to (for the boss-address picker on the downlink
     * form, so a point's network is visible while choosing which AKA it
     * hangs off of).
     *
     * @return array<int, array{address: string, network_name: ?string}>
     */
    public function getConfiguredAkasWithNetworkNames(): array
    {
        $config = BinkpConfig::getInstance();
        $networkManager = new NetworkManager();

        $domainsByAddress = [];
        foreach ($config->getMyAddressesWithDomains() as $entry) {
            $domainsByAddress[$entry['address']] = $entry['domain'];
        }

        $result = [];
        foreach ($this->getConfiguredAkas() as $address) {
            $domain = $domainsByAddress[$address] ?? ($config->getDomainByAddress($address) ?: null);
            $networkName = null;
            if ($domain) {
                $network = $networkManager->getByDomain($domain);
                $networkName = $network['name'] ?? $domain;
            }
            $result[] = [
                'address' => $address,
                'network_name' => $networkName,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepareFields(array $data): array
    {
        $nodeType = (string)($data['node_type'] ?? self::TYPE_NODE);
        if (!in_array($nodeType, [self::TYPE_NODE, self::TYPE_POINT], true)) {
            throw new \InvalidArgumentException('Invalid node type');
        }

        if ($nodeType === self::TYPE_POINT) {
            $bossAddress = trim((string)($data['boss_address'] ?? ''));
            $pointNumber = (int)($data['point_number'] ?? 0);
            if ($bossAddress === '' || $pointNumber <= 0) {
                throw new \InvalidArgumentException('Boss address and point number are required for a point');
            }
            if (!self::isValidFtnAddress($bossAddress) || EchomailSeenBy::isPointAddress($bossAddress)) {
                throw new \InvalidArgumentException('Boss address must be one of our own AKAs in zone:net/node form, not itself a point');
            }
            if (!in_array($bossAddress, $this->getConfiguredAkas(), true)) {
                throw new \InvalidArgumentException('Boss address must be one of our own configured AKAs');
            }
            $nodeAddress = $bossAddress . '.' . $pointNumber;
        } else {
            $bossAddress = null;
            $pointNumber = null;
            $nodeAddress = trim((string)($data['node_address'] ?? ''));
            if ($nodeAddress === '' || !self::isValidFtnAddress($nodeAddress)) {
                throw new \InvalidArgumentException('A valid zone:net/node[.point] node address is required');
            }
        }

        return [
            'node_type' => $nodeType,
            'node_address' => $nodeAddress,
            'boss_address' => $bossAddress,
            'point_number' => $pointNumber,
            'name' => trim((string)($data['name'] ?? '')) ?: null,
            'sysop_name' => trim((string)($data['sysop_name'] ?? '')) ?: null,
            'session_password' => (string)($data['session_password'] ?? '') ?: null,
            'packet_password' => (string)($data['packet_password'] ?? '') ?: null,
            'areafix_password' => (string)($data['areafix_password'] ?? '') ?: null,
            'filefix_password' => (string)($data['filefix_password'] ?? '') ?: null,
            'inet_host' => trim((string)($data['inet_host'] ?? '')) ?: null,
            'port' => !empty($data['port']) ? (int)$data['port'] : null,
            'enabled' => filter_var($data['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'allow_inbound' => filter_var($data['allow_inbound'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'allow_outbound' => filter_var($data['allow_outbound'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'allow_inbound_echomail' => filter_var($data['allow_inbound_echomail'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'allow_inbound_netmail' => filter_var($data['allow_inbound_netmail'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'max_packet_kb' => (int)($data['max_packet_kb'] ?? 0),
            'hold_mail' => filter_var($data['hold_mail'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'compress_outbound' => filter_var($data['compress_outbound'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'queue_retention_days' => (int)($data['queue_retention_days'] ?? 30),
            'capability_flags' => trim((string)($data['capability_flags'] ?? '')) ?: null,
            'notes' => trim((string)($data['notes'] ?? '')) ?: null,
            'push_poll_interval_minutes' => max(5, (int)($data['push_poll_interval_minutes'] ?? 360)),
        ];
    }

    /**
     * Validates zone:net/node[.point] form (e.g. "1:153/150" or "1:153/150.1").
     */
    public static function isValidFtnAddress(string $address): bool
    {
        return (bool)preg_match('/^\d+:\d+\/\d+(\.\d+)?$/', trim($address));
    }

    /**
     * Convert prepareFields() output into PDO-bindable values (booleans as
     * 'true'/'false' strings per PostgreSQL prepared-statement convention).
     *
     * @param array<string, mixed> $prepared
     * @return array<string, mixed>
     */
    private function bindable(array $prepared): array
    {
        foreach (['enabled', 'allow_inbound', 'allow_outbound', 'allow_inbound_echomail', 'allow_inbound_netmail', 'hold_mail', 'compress_outbound'] as $field) {
            $prepared[$field] = $prepared[$field] ? 'true' : 'false';
        }
        return $prepared;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['point_number'] = $row['point_number'] !== null ? (int)$row['point_number'] : null;
        $row['port'] = $row['port'] !== null ? (int)$row['port'] : null;
        $row['max_packet_kb'] = (int)$row['max_packet_kb'];
        $row['queue_retention_days'] = (int)$row['queue_retention_days'];
        $row['push_poll_interval_minutes'] = (int)$row['push_poll_interval_minutes'];

        foreach (['enabled', 'allow_inbound', 'allow_outbound', 'allow_inbound_echomail', 'allow_inbound_netmail', 'hold_mail', 'compress_outbound'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = filter_var($row[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $row;
    }
}
