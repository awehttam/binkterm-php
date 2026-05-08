<?php

namespace BinktermPHP;

use BinktermPHP\Admin\AdminDaemonClient;
use BinktermPHP\Binkp\Config\BinkpConfig;
use PDO;

class NetworkDomainChangeService
{
    private PDO $db;
    private NetworkManager $networkManager;

    public function __construct(?PDO $db = null, ?NetworkManager $networkManager = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
        $this->networkManager = $networkManager ?? new NetworkManager($this->db);
    }

    public function changeDomain(int $networkId, string $newDomain): array
    {
        $network = $this->networkManager->getById($networkId);
        if (!$network) {
            throw new \InvalidArgumentException('Network not found');
        }

        $oldDomain = (string)$network['domain'];
        $normalizedNewDomain = NetworkManager::normalizeDomain($newDomain);
        if (strcasecmp($oldDomain, $normalizedNewDomain) === 0) {
            return [
                'network' => $network,
                'echoareas_updated' => 0,
                'fileareas_updated' => 0,
                'uplinks_updated' => 0,
            ];
        }

        $config = BinkpConfig::getInstance()->getFullConfig();
        $uplinksUpdated = 0;
        if (!empty($config['uplinks']) && is_array($config['uplinks'])) {
            foreach ($config['uplinks'] as &$uplink) {
                if (strcasecmp((string)($uplink['domain'] ?? ''), $oldDomain) === 0) {
                    $uplink['domain'] = $normalizedNewDomain;
                    $uplinksUpdated++;
                }
            }
            unset($uplink);
        }

        $this->db->beginTransaction();
        try {
            $updatedNetwork = $this->networkManager->renameDomain($networkId, $normalizedNewDomain);

            $stmt = $this->db->prepare("UPDATE echoareas SET domain = ? WHERE LOWER(domain) = LOWER(?)");
            $stmt->execute([$normalizedNewDomain, $oldDomain]);
            $echoareasUpdated = $stmt->rowCount();

            $stmt = $this->db->prepare("UPDATE file_areas SET domain = ? WHERE LOWER(domain) = LOWER(?)");
            $stmt->execute([$normalizedNewDomain, $oldDomain]);
            $fileareasUpdated = $stmt->rowCount();

            if ($uplinksUpdated > 0) {
                $client = new AdminDaemonClient();
                $client->setFullBinkpConfig($config);
            }

            $this->db->commit();

            return [
                'network' => $updatedNetwork,
                'echoareas_updated' => $echoareasUpdated,
                'fileareas_updated' => $fileareasUpdated,
                'uplinks_updated' => $uplinksUpdated,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
