#!/usr/bin/env php
<?php

class MigrationUtility
{
    private string $migrationsPath;

    public function __construct()
    {
        $this->migrationsPath = __DIR__ . '/../database/migrations/';
    }

    public function create(string $description, string $type = 'sql'): string
    {
        $type = strtolower($type);
        if (!in_array($type, ['sql', 'php'], true)) {
            throw new InvalidArgumentException("Migration type must be sql or php");
        }

        $version = gmdate('YmdHis');
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($description));
        $slug = trim($slug, '_');
        if ($slug === '') {
            throw new InvalidArgumentException("Description must contain at least one letter or number");
        }

        $filename = 'v' . $version . '_' . $slug . '.' . $type;
        $filepath = $this->migrationsPath . $filename;

        if (file_exists($filepath)) {
            throw new RuntimeException("Migration file already exists: $filename");
        }

        if (!is_dir($this->migrationsPath)) {
            mkdir($this->migrationsPath, 0755, true);
        }

        file_put_contents($filepath, $this->buildTemplate($version, $description, $type));

        return $filepath;
    }

    private function buildTemplate(string $version, string $description, string $type): string
    {
        if ($type === 'php') {
            return "<?php\n"
                . "// Migration: $version - $description\n"
                . "// Created: " . gmdate('Y-m-d H:i:s') . " UTC\n\n"
                . "return function(\\PDO \$db): bool {\n"
                . "    // Add migration code here.\n"
                . "    return true;\n"
                . "};\n";
        }

        return "-- Migration: $version - $description\n"
            . "-- Created: " . gmdate('Y-m-d H:i:s') . " UTC\n\n"
            . "-- Add your SQL statements here\n"
            . "-- Each statement should end with semicolon followed by newline\n\n"
            . "-- Example:\n"
            . "-- ALTER TABLE users ADD COLUMN new_field VARCHAR(100);\n\n"
            . "-- CREATE INDEX idx_new_field ON users(new_field);\n\n";
    }
}

function showUsage(): void
{
    echo "Usage: php scripts/migration.php <command> [options]\n\n";
    echo "Commands:\n";
    echo "  create <description> [sql|php]\n";
    echo "                       Create a new timestamped migration file\n";
    echo "  --help               Show this help message\n\n";
    echo "Examples:\n";
    echo "  php scripts/migration.php create 'add user roles'\n";
    echo "  php scripts/migration.php create 'backfill user data' php\n\n";
}

$command = $argv[1] ?? '--help';

if ($command === '--help' || $command === '-h') {
    showUsage();
    exit(0);
}

if ($command !== 'create') {
    echo "Unknown command: $command\n";
    showUsage();
    exit(1);
}

$description = $argv[2] ?? '';
$type = $argv[3] ?? 'sql';

if ($description === '') {
    echo "Error: create command requires a description\n";
    echo "Usage: php scripts/migration.php create <description> [sql|php]\n";
    exit(1);
}

try {
    $utility = new MigrationUtility();
    $filepath = $utility->create($description, $type);
    echo "Created migration file: " . basename($filepath) . "\n";
    echo "Edit the file to add your migration, then run php scripts/setup.php\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
