#!/usr/bin/env php
<?php
/**
 * Print the categories (genres) of every door installed on the system.
 *
 * Covers DOS doors, native doors, JS-DOS doors, WebDoors and RLogin doors.
 * Doors without any genre are listed under "(uncategorized)".
 *
 * Usage:
 *   php scripts/list_door_categories.php                      Categories with the doors in each
 *   php scripts/list_door_categories.php --separate-networks  Isolate DoorParty, BBSLink, ANET and Door Servers
 *   php scripts/list_door_categories.php --by-door            One line per door with its categories
 *   php scripts/list_door_categories.php --json               JSON output
 */

$autoload = file_exists(__DIR__ . '/../vendor/autoload.php')
    ? __DIR__ . '/../vendor/autoload.php'
    : __DIR__ . '/vendor/autoload.php';
require_once $autoload;
$functions = file_exists(__DIR__ . '/../src/functions.php')
    ? __DIR__ . '/../src/functions.php'
    : __DIR__ . '/src/functions.php';
require_once $functions;

use BinktermPHP\DoorCategoryHelper;

$byDoor = in_array('--by-door', $argv, true);
$asJson = in_array('--json', $argv, true);
$separateNetworks = in_array('--separate-networks', $argv, true);

$rawDoors = DoorCategoryHelper::collectInstalledDoors();
$doors = [];
foreach ($rawDoors as $door) {
    $categories = $separateNetworks
        ? DoorCategoryHelper::getEffectiveCategories($door, true)
        : ($door['categories'] ?: [DoorCategoryHelper::UNCATEGORIZED]);

    $doors[] = [
        'type' => $door['type'],
        'id' => $door['id'],
        'name' => $door['name'],
        'categories' => $categories,
        'is_server' => $door['is_server'],
        'network' => $door['network'],
    ];
}

$uncategorized = DoorCategoryHelper::UNCATEGORIZED;
$byCategory = [];
foreach ($doors as $door) {
    foreach ($door['categories'] as $cat) {
        $byCategory[$cat][] = $door;
    }
}

uksort($byCategory, static function ($a, $b) use ($uncategorized) {
    if ($a === DoorCategoryHelper::DOOR_SERVERS) {
        return -1;
    }
    if ($b === DoorCategoryHelper::DOOR_SERVERS) {
        return 1;
    }
    if ($a === $uncategorized) {
        return 1;
    }
    if ($b === $uncategorized) {
        return -1;
    }
    return strcasecmp($a, $b);
});

if ($asJson) {
    echo json_encode($byDoor ? $doors : $byCategory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

if ($byDoor) {
    foreach ($doors as $door) {
        printf("%-8s %-24s %s\n", $door['type'], $door['id'], implode(', ', $door['categories']) ?: $uncategorized);
    }
    exit(0);
}

foreach ($byCategory as $cat => $list) {
    printf("%s (%d)\n", $cat, count($list));
    foreach ($list as $door) {
        printf("  [%s] %s\n", $door['type'], $door['name']);
    }
}
printf("\n%d doors, %d categories\n", count($doors), count($byCategory) - (isset($byCategory[$uncategorized]) ? 1 : 0));
