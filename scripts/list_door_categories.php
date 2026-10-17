#!/usr/bin/env php
<?php
/**
 * Print the categories (genres) of every door installed on the system.
 *
 * Covers DOS doors, native doors, JS-DOS doors, WebDoors and RLogin doors.
 * Doors without any genre are listed under "(uncategorized)".
 *
 * Usage:
 *   php scripts/list_door_categories.php             Categories with the doors in each
 *   php scripts/list_door_categories.php --by-door   One line per door with its categories
 *   php scripts/list_door_categories.php --json      JSON output
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\DosBoxDoorManifest;
use BinktermPHP\JsdosDoorManifest;
use BinktermPHP\NativeDoorManifest;
use BinktermPHP\RLoginDoorManager;
use BinktermPHP\WebDoorManifest;

/**
 * Normalize a genre value (array or comma-separated string) to a list of strings.
 *
 * @param mixed $genre
 * @return string[]
 */
function normalizeGenres($genre): array
{
    if (is_string($genre)) {
        $genre = explode(',', $genre);
    }
    if (!is_array($genre)) {
        return [];
    }
    $out = [];
    foreach ($genre as $g) {
        $g = trim((string)$g);
        if ($g !== '') {
            $out[] = $g;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Collect every installed door as [type, id, name, categories].
 *
 * @return array<int, array{type: string, id: string, name: string, categories: string[]}>
 */
function collectDoors(): array
{
    $doors = [];

    foreach ((new DosBoxDoorManifest())->scanInstalledDoors() as $id => $m) {
        $doors[] = ['type' => 'dos', 'id' => (string)$id, 'name' => $m['name'] ?? $id, 'categories' => normalizeGenres($m['genre'] ?? [])];
    }

    foreach ((new NativeDoorManifest())->scanInstalledDoors() as $id => $m) {
        $doors[] = ['type' => 'native', 'id' => (string)$id, 'name' => $m['name'] ?? $id, 'categories' => normalizeGenres($m['genre'] ?? [])];
    }

    foreach (JsdosDoorManifest::listManifests() as $entry) {
        $m = $entry['manifest'];
        $genre = $m['genre'] ?? ($m['game']['genre'] ?? []);
        $doors[] = ['type' => 'jsdos', 'id' => (string)$entry['id'], 'name' => $m['name'] ?? ($m['game']['name'] ?? $entry['id']), 'categories' => normalizeGenres($genre)];
    }

    foreach (WebDoorManifest::listManifests() as $entry) {
        $m = $entry['manifest'];
        $game = $m['game'] ?? [];
        $doors[] = ['type' => 'webdoor', 'id' => (string)$entry['id'], 'name' => $game['name'] ?? $entry['id'], 'categories' => normalizeGenres($game['genre'] ?? ($game['category'] ?? []))];
    }

    try {
        Database::getInstance()->getPdo();
        foreach ((new RLoginDoorManager())->getAllDoors() as $d) {
            $doors[] = ['type' => 'rlogin', 'id' => (string)($d['door_id'] ?? ''), 'name' => $d['name'] ?? ($d['door_id'] ?? ''), 'categories' => normalizeGenres($d['genre'] ?? [])];
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "Warning: RLogin doors skipped (" . $e->getMessage() . ")\n");
    }

    return $doors;
}

$byDoor = in_array('--by-door', $argv, true);
$asJson = in_array('--json', $argv, true);

$doors = collectDoors();
$uncategorized = '(uncategorized)';

$byCategory = [];
foreach ($doors as $door) {
    foreach ($door['categories'] ?: [$uncategorized] as $cat) {
        $byCategory[$cat][] = $door;
    }
}
uksort($byCategory, static function ($a, $b) use ($uncategorized) {
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
