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
        $stmt = $this->db->query($this->buildEffectiveNodeQuery() . '
              ORDER BY (lat IS NOT NULL AND lon IS NOT NULL) DESC, id ASC');
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Nodes with lat/lon set, for map layers. */
    public function getMappableNodes(): array
    {
        $stmt = $this->db->query($this->buildEffectiveNodeQuery() . '
              WHERE lat IS NOT NULL AND lon IS NOT NULL');
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
            $this->buildEffectiveNodeQuery() . '
              WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * PacketBBS nodes with MeshCore live advert overlays when the bridge public key is known.
     */
    private function buildEffectiveNodeQuery(): string
    {
        return "SELECT n.id,
                       COALESCE(n.handle, a.name) AS handle,
                       n.interface_type,
                       n.location,
                       n.description,
                       COALESCE(a.latitude, n.lat) AS lat,
                       COALESCE(a.longitude, n.lon) AS lon,
                       COALESCE(a.last_seen_at, n.last_seen_at) AS last_seen_at,
                       n.node_id,
                       COALESCE(n.public_key, a.public_key) AS public_key,
                       left(n.node_id, 12) AS node_id_prefix
                  FROM packet_bbs_nodes n
             LEFT JOIN meshcore_node_adverts a
                    ON a.public_key = n.public_key
                   AND n.interface_type = 'meshcore'";
    }

    /**
     * SVG QR code for a node, encoding a MeshCore contact-add deep-link.
     *
     * meshcore://contact/add?name=<handle>&public_key=<public_key>&type=1
     */
    public function getQrCodeSvg(string $handle, string $publicKey): string
    {
        $url = sprintf(
            'meshcore://contact/add?name=%s&public_key=%s&type=1',
            rawurlencode($handle),
            rawurlencode($publicKey)
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
