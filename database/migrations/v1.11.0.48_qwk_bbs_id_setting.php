<?php
/**
 * Migration: v1.11.0.48 - Populate qwk.bbs_id in bbs.json from system name
 *
 * Derives the BBSID using the same logic as QwkBuilder::getBbsId() and writes
 * it into config/bbs.json so future changes to the system name don't affect
 * the packet filename or CONTROL.DAT identifier.
 */

return function ($db) {
    $config = \BinktermPHP\BbsConfig::getConfig();

    // Only set if not already configured
    if (!empty($config['qwk']['bbs_id'])) {
        echo "qwk.bbs_id already set to \"{$config['qwk']['bbs_id']}\" — skipping\n";
        return true;
    }

    // Derive BBSID from system name using same logic as QwkBuilder::getBbsId()
    $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
    $name  = $binkpConfig->getSystemName();
    $bbsId = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name));
    $bbsId = substr($bbsId, 0, 8);
    if ($bbsId === '') {
        $bbsId = 'BINKTERM';
    }

    $config['qwk']['bbs_id'] = $bbsId;
    \BinktermPHP\BbsConfig::saveConfig($config);

    echo "Set qwk.bbs_id to \"{$bbsId}\" (derived from system name \"{$name}\")\n";
    return true;
};
