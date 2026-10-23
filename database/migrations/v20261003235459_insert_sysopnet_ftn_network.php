<?php
// Migration: 20261003235459 - insert sysopnet ftn network
// Created: 2026-10-03 23:54:59 UTC

return function (\PDO $db): bool {
    $nm = new \BinktermPHP\NetworkManager($db);
    $existing = $nm->getByDomain('sysopnet');
    if ($existing) {
        echo "Network already exists, skipping: SysopNet (sysopnet)\n";
        return true;
    }
    $data = [
        'domain'              => 'sysopnet',
        'name'                => 'SysopNet',
        'description'         => 'The FTN for SysOps, by SysOps',
        'website'             => 'https://sysopnet.com',
        'network_type'        => \BinktermPHP\NetworkManager::NETWORK_TYPE_FIDONET,
        'posting_name_policy' => 'real_name',
        'default_charset'     => 'CP437',
    ];
    $nm->create($data);
    echo "Created new network: SysopNet (sysopnet)\n";
    return true;
};
