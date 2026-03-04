<?php
/**
 * Migration: 1.10.17 - Rename allow_markdown to allow_markup in data/binkp.json
 *
 * The uplink config key was renamed from allow_markdown to allow_markup to reflect
 * that the setting controls all markup formats, not just Markdown.
 */

return function ($db) {
    $configPath = __DIR__ . '/../../data/binkp.json';

    if (!file_exists($configPath)) {
        echo "No data/binkp.json found — skipping.\n";
        return true;
    }

    $raw = file_get_contents($configPath);
    $config = json_decode($raw, true);

    if (!is_array($config)) {
        echo "data/binkp.json is not valid JSON — skipping.\n";
        return true;
    }

    $updated = 0;

    if (!empty($config['uplinks']) && is_array($config['uplinks'])) {
        foreach ($config['uplinks'] as &$uplink) {
            if (array_key_exists('allow_markdown', $uplink)) {
                $uplink['allow_markup'] = $uplink['allow_markdown'];
                unset($uplink['allow_markdown']);
                $updated++;
            }
        }
        unset($uplink);
    }

    if ($updated === 0) {
        echo "No allow_markdown keys found in data/binkp.json — nothing to do.\n";
        return true;
    }

    file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "Renamed allow_markdown to allow_markup in {$updated} uplink(s) in data/binkp.json.\n";

    return true;
};
