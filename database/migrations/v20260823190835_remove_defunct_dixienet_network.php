<?php
// Migration: 20260823190835 - remove defunct dixienet network
// Created: 2026-08-23 19:08:35 UTC

return function(\PDO $db): bool {
    $domain = 'dixienet';

    $stmt = $db->prepare("SELECT id FROM networks WHERE LOWER(domain) = LOWER(?)");
    $stmt->execute([$domain]);
    $network = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$network) {
        return true;
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM echoareas WHERE LOWER(domain) = LOWER(?)");
    $stmt->execute([$domain]);
    if ((int)$stmt->fetchColumn() > 0) {
        echo "Skipping removal of network '$domain': in use by echoareas\n";
        return true;
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM file_areas WHERE LOWER(domain) = LOWER(?)");
    $stmt->execute([$domain]);
    if ((int)$stmt->fetchColumn() > 0) {
        echo "Skipping removal of network '$domain': in use by file_areas\n";
        return true;
    }

    if (class_exists(\BinktermPHP\Binkp\Config\BinkpConfig::class)) {
        $config = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        foreach ($config->getUplinks() as $uplink) {
            if (strcasecmp((string)($uplink['domain'] ?? ''), $domain) === 0) {
                echo "Skipping removal of network '$domain': in use by uplink " . ($uplink['address'] ?? '') . "\n";
                return true;
            }
        }
    }

    $stmt = $db->prepare("DELETE FROM networks WHERE id = ?");
    $stmt->execute([$network['id']]);

    return true;
};
