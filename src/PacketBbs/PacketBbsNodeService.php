<?php

namespace BinktermPHP\PacketBbs;

use BinktermPHP\Database;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Shared data service for public-facing PacketBBS node queries.
 */
class PacketBbsNodeService
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    /** All nodes ordered: mapped first, then unmapped. */
    public function getPublicNodes(): array
    {
        $stmt = $this->db->query(
            'SELECT id, handle, interface_type, location, lat, lon, last_seen_at
               FROM packet_bbs_nodes
              ORDER BY (lat IS NOT NULL AND lon IS NOT NULL) DESC, id ASC'
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Nodes with lat/lon set, for map layers. */
    public function getMappableNodes(): array
    {
        $stmt = $this->db->query(
            'SELECT id, handle, interface_type, location, lat, lon, last_seen_at,
                    left(node_id, 12) AS node_id_prefix
               FROM packet_bbs_nodes
              WHERE lat IS NOT NULL AND lon IS NOT NULL'
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Count of registered nodes, for conditional nav/dashboard. */
    public function getNodeCount(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM packet_bbs_nodes')->fetchColumn();
    }

    /** Single node row by id, for the detail panel. */
    public function getNodeById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, handle, interface_type, location, description, lat, lon, last_seen_at, node_id
               FROM packet_bbs_nodes
              WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * SVG QR code for a node, encoding a MeshCore contact-add deep-link.
     *
     * meshcore://contact/add?name=<handle>&public_key=<node_id>&type=1
     */
    public function getQrCodeSvg(string $handle, string $nodeId): string
    {
        $url = sprintf(
            'meshcore://contact/add?name=%s&public_key=%s&type=1',
            rawurlencode($handle),
            rawurlencode($nodeId)
        );

        $options = new QROptions();
        $options->outputType    = QRCode::OUTPUT_MARKUP_SVG;
        $options->eccLevel      = QRCode::ECC_M;
        $options->scale         = 6;
        $options->imageBase64   = false;
        $options->markupDark    = '#000000';
        $options->markupLight   = '#ffffff';
        $options->quietzoneSize = 4;

        return (new QRCode($options))->render($url);
    }
}
