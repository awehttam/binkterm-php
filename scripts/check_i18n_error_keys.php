#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

$routeFiles = [
    __DIR__ . '/../routes/api-routes.php',
    __DIR__ . '/../routes/admin-routes.php',
];

$catalogFiles = [
    __DIR__ . '/../config/i18n/en/errors.php',
    __DIR__ . '/../config/i18n/en/common.php',
];

$routeKeys = [];

foreach ($routeFiles as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Missing route file: {$file}\n");
        exit(2);
    }

    $content = file_get_contents($file);
    if ($content === false) {
        fwrite(STDERR, "Failed to read route file: {$file}\n");
        exit(2);
    }

    if (preg_match_all('/apiError\(\s*[\'"](errors\.[A-Za-z0-9_.]+)[\'"]/', $content, $matches) !== false) {
        foreach ($matches[1] as $key) {
            $routeKeys[$key] = true;
        }
    }
}

$catalogKeys = [];

foreach ($catalogFiles as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Missing catalog file: {$file}\n");
        exit(2);
    }

    $catalog = require $file;
    if (!is_array($catalog)) {
        fwrite(STDERR, "Catalog file did not return an array: {$file}\n");
        exit(2);
    }

    foreach ($catalog as $key => $_message) {
        if (is_string($key) && str_starts_with($key, 'errors.')) {
            $catalogKeys[$key] = true;
        }
    }
}

$missing = array_diff_key($routeKeys, $catalogKeys);
ksort($missing);

echo 'Route error keys: ' . count($routeKeys) . PHP_EOL;
echo 'Catalog error keys: ' . count($catalogKeys) . PHP_EOL;
echo 'Missing keys: ' . count($missing) . PHP_EOL;

if (!empty($missing)) {
    echo PHP_EOL . 'Missing catalog keys:' . PHP_EOL;
    foreach (array_keys($missing) as $key) {
        echo '- ' . $key . PHP_EOL;
    }
    exit(1);
}

echo 'i18n error key check passed.' . PHP_EOL;
exit(0);
