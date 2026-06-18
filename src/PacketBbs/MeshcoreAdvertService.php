<?php

namespace BinktermPHP\PacketBbs;

use BinktermPHP\Database;

/**
 * Stores live MeshCore repeater adverts independently from the CWN manual-entry table.
 */
class MeshcoreAdvertService
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    /**
     * Upsert a live MeshCore advert keyed by full public key.
     *
     * @return array{id:int, action:string}
     */
    public function upsertAdvert(
        string $bridgeNodeId,
        string $publicKey,
        string $name,
        string $advType,
        float $latitude,
        float $longitude,
        ?int $hopCount,
        string $bbsName
    ): array {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO meshcore_node_adverts
                    (public_key, bridge_node_id, name, adv_type, latitude, longitude, hop_count, bbs_name, last_seen_at)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON CONFLICT (public_key) DO UPDATE SET
                    bridge_node_id = EXCLUDED.bridge_node_id,
                    name           = EXCLUDED.name,
                    adv_type       = EXCLUDED.adv_type,
                    latitude       = EXCLUDED.latitude,
                    longitude      = EXCLUDED.longitude,
                    hop_count      = EXCLUDED.hop_count,
                    bbs_name       = EXCLUDED.bbs_name,
                    last_seen_at   = NOW(),
                    updated_at     = NOW()
                 RETURNING id, (xmax = 0) AS was_inserted"
            );
            $stmt->execute([
                $publicKey,
                $bridgeNodeId !== '' ? $bridgeNodeId : null,
                $name,
                $advType,
                $latitude,
                $longitude,
                $hopCount,
                $bbsName,
            ]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['id' => 0, 'was_inserted' => false];

            // When a bridge advertises itself, bind the full public key to the registered node.
            if ($bridgeNodeId !== '' && substr($publicKey, 0, 12) === $bridgeNodeId) {
                $nodeStmt = $this->db->prepare(
                    'UPDATE packet_bbs_nodes
                        SET public_key = ?, last_seen_at = NOW()
                      WHERE interface_type = ? AND node_id = ?'
                );
                $nodeStmt->execute([$publicKey, 'meshcore', $bridgeNodeId]);
            }

            $this->db->commit();

            return [
                'id' => (int)$row['id'],
                'action' => (!empty($row['was_inserted']) && $row['was_inserted'] !== 'f') ? 'created' : 'updated',
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
