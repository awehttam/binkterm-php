<?php
/**
 * Migration: 1.11.0.37 - Set LovlyNet TIC password from Areafix password
 *
 * Derives the LovlyNet TIC password from the first 8 characters of the
 * Areafix password, uppercased, and stores it in both config/lovlynet.json
 * and the LovlyNet uplink entry in config/binkp.json.
 */

return function ($db) {
    $projectRoot = dirname(__DIR__, 2);
    $lovlyNetPath = $projectRoot . '/config/lovlynet.json';
    $binkpPath = $projectRoot . '/config/binkp.json';

    if (!file_exists($lovlyNetPath)) {
        echo "No config/lovlynet.json found - skipping.\n";
        return true;
    }

    $lovlyNetRaw = file_get_contents($lovlyNetPath);
    $lovlyNetConfig = json_decode($lovlyNetRaw, true);
    if (!is_array($lovlyNetConfig)) {
        echo "config/lovlynet.json is not valid JSON - skipping.\n";
        return true;
    }

    $areafixPassword = (string)($lovlyNetConfig['areafix_password'] ?? '');
    if ($areafixPassword === '') {
        echo "LovlyNet areafix_password is empty - skipping.\n";
        return true;
    }

    $ticPassword = strtoupper(substr($areafixPassword, 0, 8));
    $lovlyNetConfig['tic_password'] = $ticPassword;

    file_put_contents(
        $lovlyNetPath,
        json_encode($lovlyNetConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
    echo "Updated config/lovlynet.json TIC password.\n";

    if (!file_exists($binkpPath)) {
        echo "No config/binkp.json found - skipping uplink update.\n";
        return true;
    }

    $binkpRaw = file_get_contents($binkpPath);
    $binkpConfig = json_decode($binkpRaw, true);
    if (!is_array($binkpConfig)) {
        echo "config/binkp.json is not valid JSON - skipping uplink update.\n";
        return true;
    }

    $updatedUplinks = 0;
    if (!empty($binkpConfig['uplinks']) && is_array($binkpConfig['uplinks'])) {
        foreach ($binkpConfig['uplinks'] as &$uplink) {
            if (strtolower((string)($uplink['domain'] ?? '')) !== 'lovlynet') {
                continue;
            }

            $uplink['tic_password'] = $ticPassword;
            $updatedUplinks++;
        }
        unset($uplink);
    }

    if ($updatedUplinks === 0) {
        echo "No LovlyNet uplinks found in config/binkp.json.\n";
        return true;
    }

    file_put_contents(
        $binkpPath,
        json_encode($binkpConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );
    echo "Updated {$updatedUplinks} LovlyNet uplink(s) in config/binkp.json.\n";

    return true;
};
