#!/usr/bin/env php
<?php

/**
 * Audits docs/DATA_MODEL.md against the live PostgreSQL schema.
 *
 * Reports:
 *  - Tables present in the DB but absent from the doc
 *  - Tables named in the doc but absent from the DB
 *  - For tables whose columns are enumerated in the doc:
 *      - columns present in the DB but missing from the doc
 *      - columns named in the doc but absent from the DB
 *
 * Output is plain-text structured for AI ingestion.
 *
 * Usage:
 *   php scripts/audit_data_model_doc.php [--json]
 *
 *   --json   Emit JSON instead of human-readable text (useful for piping)
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;

$opts = getopt('', ['json', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php scripts/audit_data_model_doc.php [--json]\n";
    exit(0);
}
$jsonMode = isset($opts['json']);

// ---------------------------------------------------------------------------
// 1. Read the live schema from PostgreSQL
// ---------------------------------------------------------------------------

$db = Database::getInstance()->getPdo();

$dbTables = $db->query(
    "SELECT table_name
     FROM information_schema.tables
     WHERE table_schema = 'public'
       AND table_type = 'BASE TABLE'
     ORDER BY table_name"
)->fetchAll(\PDO::FETCH_COLUMN);

$dbColumns = [];
$rows = $db->query(
    "SELECT table_name, column_name, data_type, is_nullable, column_default
     FROM information_schema.columns
     WHERE table_schema = 'public'
     ORDER BY table_name, ordinal_position"
)->fetchAll(\PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $dbColumns[$row['table_name']][] = [
        'name'     => $row['column_name'],
        'type'     => $row['data_type'],
        'nullable' => $row['is_nullable'],
        'default'  => $row['column_default'],
    ];
}

// ---------------------------------------------------------------------------
// 2. Parse docs/DATA_MODEL.md
// ---------------------------------------------------------------------------

$docPath = __DIR__ . '/../docs/DATA_MODEL.md';
if (!is_readable($docPath)) {
    fwrite(STDERR, "ERROR: Cannot read $docPath\n");
    exit(1);
}

$docLines = file($docPath, FILE_IGNORE_NEW_LINES);

/**
 * Tables with full column listings in the doc.
 * Key = table name, value = array of column names.
 * @var array<string, string[]>
 */
$docDetailedColumns = [];

/**
 * All table names mentioned anywhere in the doc.
 * @var string[]
 */
$docMentionedTables = [];

// Parse: look for backtick-quoted identifiers that look like table names,
// and parse markdown column tables under ### `tablename` headings.

$currentTable = null;
$inColumnTable = false;

foreach ($docLines as $line) {
    // Detect ### `tablename` headings
    if (preg_match('/^###\s+`([a-z_][a-z0-9_]*)`/', $line, $m)) {
        $currentTable = $m[1];
        $inColumnTable = false;
        $docMentionedTables[] = $currentTable;
        $docDetailedColumns[$currentTable] = [];
        continue;
    }

    // Detect the start of a markdown table with a | `column` | pattern
    if ($currentTable !== null && preg_match('/^\|\s*`[a-z_]/', $line)) {
        $inColumnTable = true;
    }

    // Parse column rows: | `column_name` | ... |
    if ($inColumnTable && $currentTable !== null && preg_match('/^\|\s*`([a-z_][a-z0-9_]*)`\s*\|/', $line, $m)) {
        $docDetailedColumns[$currentTable][] = $m[1];
        continue;
    }

    // A blank line or a new heading ends the column table
    if ($inColumnTable && (trim($line) === '' || str_starts_with($line, '#'))) {
        $inColumnTable = false;
    }

    // Collect all backtick-quoted snake_case identifiers that plausibly name tables
    // (two or more words joined by underscores, or single common table keywords).
    // We also capture names from the Supporting Tables pipe-table rows.
    if (preg_match_all('/`([a-z][a-z0-9]*(?:_[a-z0-9]+)+)`/', $line, $matches)) {
        foreach ($matches[1] as $name) {
            $docMentionedTables[] = $name;
        }
    }
}

// Remove duplicates; remove obvious non-table names (short utility strings).
$docMentionedTables = array_unique($docMentionedTables);

// Filter: only keep identifiers that exist in the DB *or* look like real table
// names (at least one underscore or an exact DB table match). This removes
// things like `tag`, `domain`, `user_id`, `created_at` etc. that are column
// names masquerading as table names in narrative text.
$knownDbSet = array_flip($dbTables);
$docMentionedTables = array_filter($docMentionedTables, function (string $name) use ($knownDbSet): bool {
    // Always keep if it IS a known DB table.
    if (isset($knownDbSet[$name])) {
        return true;
    }
    // Keep if it looks like a table name: contains at least one underscore
    // and is not a typical column name fragment.
    $columnFragments = [
        'user_id', 'echoarea_id', 'message_id', 'reply_to_id', 'admin_only',
        'is_active', 'is_local', 'is_read', 'is_deleted', 'is_admin', 'is_approved',
        'is_sysop_only', 'is_nullable', 'column_name', 'table_name', 'data_type',
        'created_at', 'updated_at', 'date_written', 'date_received',
        'password_hash', 'credit_balance', 'last_login', 'referral_code',
        'from_name', 'from_address', 'to_name', 'to_address',
        'message_charset', 'kludge_lines', 'art_format', 'attachment_filename',
        'column_default',
    ];
    if (in_array($name, $columnFragments, true)) {
        return false;
    }
    // Keep multi-word identifiers (>1 underscore-separated segment) as likely tables
    return substr_count($name, '_') >= 1;
});

$docMentionedTables = array_values($docMentionedTables);

// ---------------------------------------------------------------------------
// 3. Compute gaps
// ---------------------------------------------------------------------------

$dbTableSet  = array_flip($dbTables);
$docTableSet = array_flip($docMentionedTables);

/** Tables in DB, not mentioned anywhere in the doc */
$tablesOnlyInDb = array_values(array_filter($dbTables, fn($t) => !isset($docTableSet[$t])));
sort($tablesOnlyInDb);

/** Tables mentioned in doc but not in DB */
$tablesOnlyInDoc = array_values(array_filter($docMentionedTables, fn($t) => !isset($dbTableSet[$t])));
sort($tablesOnlyInDoc);

/** Column-level gaps for tables that have detailed columns in the doc */
$columnGaps = [];
foreach ($docDetailedColumns as $table => $docCols) {
    if (empty($docCols)) {
        continue; // doc heading with no column table
    }
    $dbColNames  = array_column($dbColumns[$table] ?? [], 'name');
    $docColSet   = array_flip($docCols);
    $dbColSet    = array_flip($dbColNames);

    $onlyInDb  = array_values(array_filter($dbColNames, fn($c) => !isset($docColSet[$c])));
    $onlyInDoc = array_values(array_filter($docCols,    fn($c) => !isset($dbColSet[$c])));

    if ($onlyInDb || $onlyInDoc) {
        $columnGaps[$table] = [
            'in_db_not_doc' => $onlyInDb,
            'in_doc_not_db' => $onlyInDoc,
        ];
    }
}

// ---------------------------------------------------------------------------
// 4. Emit report
// ---------------------------------------------------------------------------

if ($jsonMode) {
    echo json_encode([
        'generated_at' => gmdate('c'),
        'doc_path'     => 'docs/DATA_MODEL.md',
        'summary' => [
            'db_table_count'            => count($dbTables),
            'doc_mentioned_table_count' => count($docMentionedTables),
            'tables_undocumented'       => count($tablesOnlyInDb),
            'tables_missing_from_db'    => count($tablesOnlyInDoc),
            'tables_with_column_gaps'   => count($columnGaps),
        ],
        'tables_in_db_not_in_doc' => $tablesOnlyInDb,
        'tables_in_doc_not_in_db' => $tablesOnlyInDoc,
        'column_gaps'             => $columnGaps,
        'all_db_tables_with_columns' => $dbColumns,
    ], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

// Human-readable / AI-ingestible text format
$sep = str_repeat('=', 72);
$sub = str_repeat('-', 72);

echo "$sep\n";
echo "DATA_MODEL.md AUDIT REPORT\n";
echo "Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n";
echo "Source doc: docs/DATA_MODEL.md\n";
echo "$sep\n\n";

echo "SUMMARY\n";
echo "$sub\n";
echo "  Tables in live database:           " . count($dbTables) . "\n";
echo "  Tables mentioned in doc:           " . count($docMentionedTables) . "\n";
echo "  Undocumented DB tables:            " . count($tablesOnlyInDb) . "\n";
echo "  Doc tables absent from DB:         " . count($tablesOnlyInDoc) . "\n";
echo "  Tables with column-level gaps:     " . count($columnGaps) . "\n";
echo "\n";

// --- Section A: undocumented DB tables ---
echo "SECTION A — TABLES IN DATABASE BUT NOT IN docs/DATA_MODEL.md\n";
echo "$sub\n";
echo "These tables exist in PostgreSQL but are not mentioned anywhere in the doc.\n";
echo "They need either a description entry or at minimum a row in the Supporting\n";
echo "Tables table.\n\n";

if (empty($tablesOnlyInDb)) {
    echo "  (none — all DB tables are documented)\n\n";
} else {
    foreach ($tablesOnlyInDb as $table) {
        $colNames = array_column($dbColumns[$table] ?? [], 'name');
        $colList  = implode(', ', $colNames);
        echo "  TABLE: $table\n";
        echo "  COLUMNS: $colList\n";
        echo "\n";
    }
}

// --- Section B: phantom doc tables ---
echo "SECTION B — TABLES MENTIONED IN DOC BUT ABSENT FROM DATABASE\n";
echo "$sub\n";
echo "These names appear in docs/DATA_MODEL.md but have no corresponding table\n";
echo "in PostgreSQL. They may be planned, renamed, or typos.\n\n";

if (empty($tablesOnlyInDoc)) {
    echo "  (none — all doc table names exist in the DB)\n\n";
} else {
    foreach ($tablesOnlyInDoc as $table) {
        echo "  TABLE: $table\n";
    }
    echo "\n";
}

// --- Section C: column-level gaps ---
echo "SECTION C — COLUMN GAPS IN TABLES WITH DOCUMENTED COLUMN LISTS\n";
echo "$sub\n";
echo "Only tables that have explicit column tables in DATA_MODEL.md are checked here.\n\n";

if (empty($columnGaps)) {
    echo "  (none — all documented columns match the live schema)\n\n";
} else {
    foreach ($columnGaps as $table => $gap) {
        echo "  TABLE: $table\n";
        if ($gap['in_db_not_doc']) {
            echo "    IN DB, MISSING FROM DOC:\n";
            foreach ($gap['in_db_not_doc'] as $col) {
                $meta = null;
                foreach (($dbColumns[$table] ?? []) as $c) {
                    if ($c['name'] === $col) { $meta = $c; break; }
                }
                $typeStr = $meta ? " ({$meta['type']}" . ($meta['nullable'] === 'NO' ? ', NOT NULL' : '') . ")" : '';
                echo "      - $col$typeStr\n";
            }
        }
        if ($gap['in_doc_not_db']) {
            echo "    IN DOC, MISSING FROM DB (may be renamed or removed):\n";
            foreach ($gap['in_doc_not_db'] as $col) {
                echo "      - $col\n";
            }
        }
        echo "\n";
    }
}

// --- Section D: full schema dump for AI reference ---
echo "SECTION D — FULL LIVE SCHEMA (for AI reference)\n";
echo "$sub\n";
echo "Complete column listing for every table in the database.\n\n";

foreach ($dbTables as $table) {
    echo "  TABLE: $table\n";
    foreach (($dbColumns[$table] ?? []) as $col) {
        $nullStr    = $col['nullable'] === 'NO' ? ' NOT NULL' : '';
        $defaultStr = $col['default'] !== null ? " DEFAULT {$col['default']}" : '';
        echo "    - {$col['name']}  {$col['type']}{$nullStr}{$defaultStr}\n";
    }
    echo "\n";
}

echo "$sep\n";
echo "END OF REPORT\n";
echo "$sep\n";
