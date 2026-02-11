<?php
require __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

$db = Database::getInstance()->getPdo();

echo "=== DOSBox Door Tables ===\n\n";

$stmt = $db->query("
    SELECT table_name
    FROM information_schema.tables
    WHERE table_schema='public'
    AND (table_name LIKE 'dosbox%' OR table_name LIKE 'door%')
    ORDER BY table_name
");

$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($tables)) {
    echo "❌ No door tables found!\n";
    exit(1);
}

echo "✓ Found " . count($tables) . " door-related tables:\n";
foreach ($tables as $table) {
    echo "  - $table\n";
}

echo "\n=== Table Structures ===\n\n";

foreach ($tables as $table) {
    echo "Table: $table\n";
    $stmt = $db->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_name = '$table'
        ORDER BY ordinal_position
    ");

    while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $nullable = $col['is_nullable'] === 'YES' ? 'NULL' : 'NOT NULL';
        $default = $col['column_default'] ? " DEFAULT {$col['column_default']}" : '';
        echo "  {$col['column_name']}: {$col['data_type']} $nullable$default\n";
    }
    echo "\n";
}

echo "✓ All tables created successfully!\n";
