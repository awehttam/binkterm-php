<?php
/**
 * Echomail query performance analysis.
 *
 * Validates the performance assertions made in the echomail optimization proposal
 * by timing current queries vs proposed alternatives and checking index coverage.
 * Read-only — safe to run on production.
 *
 * Usage:
 *   php tests/perf_echomail_analysis.php [--user=<user_id>] [--explain] [--verbose]
 *
 *   --user=N    User ID to use for unread-count tests (default: first active user)
 *   --explain   Print EXPLAIN ANALYZE output for each query
 *   --verbose   Print extra detail including EXPLAIN for proposed alternatives
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;

$db = Database::getInstance()->getPdo();

// ---------------------------------------------------------------------------
// Parse args
// ---------------------------------------------------------------------------

$userId   = null;
$explain  = false;
$verbose  = false;

foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--user=(\d+)$/', $arg, $m)) {
        $userId = (int)$m[1];
    } elseif ($arg === '--explain') {
        $explain = true;
    } elseif ($arg === '--verbose') {
        $verbose  = true;
        $explain  = true;
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

$pass  = 0;
$warn  = 0;
$fail  = 0;
$total = 0;

function assertion(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $warn, $fail, $total;
    $total++;
    if ($ok) {
        $pass++;
        echo "  [PASS] $label\n";
    } else {
        $fail++;
        echo "  [FAIL] $label\n";
    }
    if ($detail !== '') {
        echo "         $detail\n";
    }
}

function warning(string $label, string $detail = ''): void
{
    global $warn;
    $warn++;
    echo "  [WARN] $label\n";
    if ($detail !== '') {
        echo "         $detail\n";
    }
}

function section(string $title): void
{
    echo "\n" . str_repeat('=', 70) . "\n";
    echo "  $title\n";
    echo str_repeat('=', 70) . "\n";
}

/**
 * Run a query N times, return [avg_ms, min_ms, max_ms, row_count].
 */
function timeQuery(PDO $db, string $sql, array $params = [], int $runs = 3): array
{
    $times    = [];
    $rowCount = 0;
    for ($i = 0; $i < $runs; $i++) {
        $t0   = microtime(true);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows     = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $times[]  = (microtime(true) - $t0) * 1000;
        $rowCount = count($rows);
    }
    return [
        'avg' => array_sum($times) / count($times),
        'min' => min($times),
        'max' => max($times),
        'rows' => $rowCount,
    ];
}

function fmtMs(float $ms): string
{
    return number_format($ms, 1) . ' ms';
}

function explainQuery(PDO $db, string $sql, array $params = []): string
{
    $explainSql = "EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT) $sql";
    $stmt       = $db->prepare($explainSql);
    $stmt->execute($params);
    $lines = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    return implode("\n", $lines);
}

// ---------------------------------------------------------------------------
// Resolve user for tests that need one
// ---------------------------------------------------------------------------

if ($userId === null) {
    $row = $db->query("SELECT id FROM users WHERE is_active = TRUE ORDER BY id LIMIT 1")->fetch();
    if ($row) {
        $userId = (int)$row['id'];
    }
}

if ($userId === null) {
    echo "ERROR: no active users found in the database. Use --user=N.\n";
    exit(1);
}

$userRow = $db->prepare("SELECT username, real_name FROM users WHERE id = ?");
$userRow->execute([$userId]);
$userInfo = $userRow->fetch(PDO::FETCH_ASSOC);

echo "\nEchomail Performance Analysis\n";
echo "User: $userId ({$userInfo['username']})\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "\nNOTE: Timing assertions are calibrated for a production database with 80K+\n";
echo "      echomail rows. On a small dev database all queries will appear fast and\n";
echo "      the [FAIL] markers will reflect that the problem is not present — that is\n";
echo "      expected. What matters on a small DB is the EXPLAIN plan (use --explain)\n";
echo "      and whether indexes exist, not the raw millisecond numbers.\n";

// ---------------------------------------------------------------------------
// SECTION 1: Table sizes
// ---------------------------------------------------------------------------

section("1. Table Sizes");

$sizes = $db->query("
    SELECT
        relname AS table_name,
        pg_size_pretty(pg_total_relation_size(oid)) AS total_size,
        pg_size_pretty(pg_relation_size(oid))       AS table_size,
        reltuples::bigint                            AS estimated_rows
    FROM pg_class
    WHERE relname IN ('echomail', 'echoareas', 'message_read_status', 'user_echoarea_subscriptions')
      AND relkind = 'r'
    ORDER BY pg_total_relation_size(oid) DESC
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($sizes as $row) {
    printf("  %-40s  rows ~%-8s  total %s\n",
        $row['table_name'],
        number_format((int)$row['estimated_rows']),
        $row['total_size']
    );
}

$echomailCount = (int)$db->query("SELECT COUNT(*) FROM echomail")->fetchColumn();
$echoareaCount = (int)$db->query("SELECT COUNT(*) FROM echoareas")->fetchColumn();
$mrsCount      = (int)$db->query("SELECT COUNT(*) FROM message_read_status WHERE message_type = 'echomail'")->fetchColumn();

echo "\n  Exact counts:\n";
echo "    echomail rows:          " . number_format($echomailCount) . "\n";
echo "    echoareas rows:         $echoareaCount\n";
echo "    message_read_status (echomail): " . number_format($mrsCount) . "\n";

// ---------------------------------------------------------------------------
// SECTION 2: Index inventory
// ---------------------------------------------------------------------------

section("2. Index Inventory");

$indexes = $db->query("
    SELECT
        i.relname                                   AS index_name,
        t.relname                                   AS table_name,
        pg_size_pretty(pg_relation_size(i.oid))     AS index_size,
        ix.indisunique                              AS is_unique,
        array_to_string(
            ARRAY(
                SELECT pg_get_indexdef(ix.indexrelid, k + 1, TRUE)
                FROM generate_subscripts(ix.indkey, 1) AS k
                ORDER BY k
            ), ', '
        ) AS columns
    FROM pg_index ix
    JOIN pg_class t ON t.oid = ix.indrelid
    JOIN pg_class i ON i.oid = ix.indexrelid
    WHERE t.relname IN ('echomail', 'echoareas', 'message_read_status', 'user_echoarea_subscriptions')
      AND t.relkind = 'r'
    ORDER BY t.relname, i.relname
")->fetchAll(PDO::FETCH_ASSOC);

$existingIndexes = [];
// Also build a map of table -> list of column signatures for fuzzy matching
$indexesByTableCols = []; // "table:col1,col2" => index_name
foreach ($indexes as $idx) {
    $existingIndexes[$idx['index_name']] = $idx;
    $sig = $idx['table_name'] . ':' . strtolower($idx['columns']);
    $indexesByTableCols[$sig] = $idx['index_name'];
    $unique = $idx['is_unique'] ? ' [UNIQUE]' : '';
    printf("  %-50s  %-40s  %s%s\n",
        $idx['index_name'],
        $idx['table_name'] . '(' . $idx['columns'] . ')',
        $idx['index_size'],
        $unique
    );
}

/**
 * Check whether a proposed index is covered by any existing index on the same table
 * and the same leading columns (ignoring DESC and partial-index predicates for matching).
 * Returns [bool $covered, string $coveredBy].
 */
function indexCovered(array $existingIndexes, array $indexesByTableCols, string $table, string $colSpec): array
{
    // Normalise: strip DESC/ASC, strip WHERE clause, lowercase, trim spaces
    $normalize = fn(string $s) => trim(preg_replace('/\s+(desc|asc)\b/i', '', strtolower(preg_replace('/\s+WHERE\s+.*/i', '', $s))));
    $wantCols  = $normalize($colSpec);

    foreach ($existingIndexes as $idx) {
        if ($idx['table_name'] !== $table) {
            continue;
        }
        $haveCols = $normalize($idx['columns']);
        // Exact match, or the existing index's columns start with the desired columns
        if ($haveCols === $wantCols || str_starts_with($haveCols, $wantCols)) {
            return [true, $idx['index_name']];
        }
    }
    return [false, ''];
}

// Check for proposed indexes (by column semantics, not just name)
echo "\n  Proposed indexes — present?\n";

$proposed = [
    'idx_echomail_echoarea_date_received' => [
        'table'   => 'echomail',
        'columns' => 'echoarea_id, date_received DESC',
        'reason'  => 'DISTINCT ON last-post subquery and ORDER BY date_received in message list',
        'note'    => 'PostgreSQL can use an (echoarea_id, date_received) index via backward scan for DESC ordering.',
    ],
    'idx_echoareas_is_active' => [
        'table'   => 'echoareas',
        'columns' => 'is_active',
        'reason'  => 'Active-area filter applied in every echolist query',
        'note'    => 'echoareas is small (~40 rows) so this is low priority.',
    ],
    'idx_user_subs_area_active' => [
        'table'   => 'user_echoarea_subscriptions',
        'columns' => 'echoarea_id, is_active',
        'reason'  => 'Scoping unread-count subquery to subscribed areas only',
        'note'    => '',
    ],
];

$indexCheckResults = [];
foreach ($proposed as $name => $info) {
    [$covered, $coveredBy] = indexCovered($existingIndexes, $indexesByTableCols, $info['table'], $info['columns']);
    $indexCheckResults[$name] = $covered;
    if ($covered) {
        $status = '[COVERED]';
        $detail = "covered by existing index: $coveredBy";
    } elseif (isset($existingIndexes[$name])) {
        $status = '[EXISTS ]';
        $detail = '';
    } else {
        $status = '[MISSING]';
        $detail = 'not present';
    }
    echo "  $status  $name  ON {$info['table']}({$info['columns']})\n";
    if ($detail) {
        echo "           $detail\n";
    }
    echo "           Reason: {$info['reason']}\n";
    if ($info['note']) {
        echo "           Note:   {$info['note']}\n";
    }
}
$hasDistinctOnIndex = $indexCheckResults['idx_echomail_echoarea_date_received'] || isset($existingIndexes['idx_echomail_echoarea_date_received']);

// ---------------------------------------------------------------------------
// SECTION 3: Assertion — echoareas.message_count accuracy
// ---------------------------------------------------------------------------

section("3. Assertion: echoareas.message_count Is Accurate");

echo "  Comparing cached e.message_count vs live COUNT(*) per area...\n\n";

$drift = $db->query("
    SELECT
        e.id,
        e.tag,
        e.message_count                              AS cached_count,
        COUNT(em.id)                                 AS live_count,
        e.message_count - COUNT(em.id)               AS drift
    FROM echoareas e
    LEFT JOIN echomail em ON em.echoarea_id = e.id
    GROUP BY e.id, e.tag, e.message_count
    HAVING e.message_count != COUNT(em.id)
    ORDER BY ABS(e.message_count - COUNT(em.id)) DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($drift)) {
    assertion(
        "echoareas.message_count matches live COUNT(*) for all areas",
        true,
        "The cached column is reliable — safe to replace the total_counts subquery."
    );
} else {
    assertion(
        "echoareas.message_count matches live COUNT(*) for all areas",
        false,
        count($drift) . " area(s) have drift. Top offenders:"
    );
    foreach (array_slice($drift, 0, 5) as $row) {
        printf("    id=%-4d  %-30s  cached=%-6d  live=%-6d  drift=%d\n",
            $row['id'], $row['tag'], $row['cached_count'], $row['live_count'], $row['drift']
        );
    }
    echo "\n  NOTE: If drift exists, a one-time recalibration migration is needed before\n";
    echo "        switching to e.message_count in the echolist query.\n";
}

// Also check total deviation
$totalRow = $db->query("
    SELECT
        SUM(ABS(e.message_count - live.c)) AS total_drift,
        COUNT(CASE WHEN e.message_count != live.c THEN 1 END) AS drifted_areas
    FROM echoareas e
    JOIN (SELECT echoarea_id, COUNT(*) AS c FROM echomail GROUP BY echoarea_id) live
      ON live.echoarea_id = e.id
")->fetch(PDO::FETCH_ASSOC);

if ($totalRow) {
    echo "  Total drift across all areas: {$totalRow['total_drift']} messages in {$totalRow['drifted_areas']} areas\n";
}

// ---------------------------------------------------------------------------
// SECTION 4: Timing — total_counts subquery vs e.message_count
// ---------------------------------------------------------------------------

section("4. Timing: total_counts Subquery vs e.message_count");

// Current approach: COUNT(*) GROUP BY subquery
$sqlCurrentTotal = "
    SELECT em.echoarea_id, COUNT(*) AS message_count
    FROM echomail em
    WHERE em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC')
    GROUP BY em.echoarea_id
";

// Proposed approach: just read the column
$sqlProposedTotal = "SELECT id, message_count FROM echoareas";

echo "  Running each query 3 times...\n\n";

$currentT  = timeQuery($db, $sqlCurrentTotal);
$proposedT = timeQuery($db, $sqlProposedTotal);

printf("  Current  (COUNT subquery):     avg %s  min %s  max %s  rows=%d\n",
    fmtMs($currentT['avg']),  fmtMs($currentT['min']),  fmtMs($currentT['max']),  $currentT['rows']);
printf("  Proposed (e.message_count):    avg %s  min %s  max %s  rows=%d\n",
    fmtMs($proposedT['avg']), fmtMs($proposedT['min']), fmtMs($proposedT['max']), $proposedT['rows']);

$speedup = $currentT['avg'] > 0 ? $currentT['avg'] / max($proposedT['avg'], 0.01) : 0;
printf("\n  Speedup: %.1fx faster with cached column\n", $speedup);

assertion(
    "Cached e.message_count is significantly faster than live COUNT subquery",
    $speedup >= 5.0,
    sprintf("%.1fx speedup (threshold: 5x). Current avg: %s, proposed avg: %s",
        $speedup, fmtMs($currentT['avg']), fmtMs($proposedT['avg']))
);

if ($explain) {
    echo "\n  EXPLAIN ANALYZE — current total_counts subquery:\n";
    echo "  " . str_replace("\n", "\n  ", explainQuery($db, $sqlCurrentTotal)) . "\n";
}

// ---------------------------------------------------------------------------
// SECTION 5: Timing — last_posts DISTINCT ON subquery
// ---------------------------------------------------------------------------

section("5. Timing: last_posts DISTINCT ON Subquery");

$sqlDistinctOn = "
    SELECT DISTINCT ON (em.echoarea_id)
        em.echoarea_id,
        em.subject   AS last_subject,
        em.from_name AS last_author,
        em.date_received AS last_date
    FROM echomail em
    ORDER BY em.echoarea_id, em.date_received DESC
";

echo "  Running 3 times...\n\n";
$t = timeQuery($db, $sqlDistinctOn);

printf("  DISTINCT ON subquery:  avg %s  min %s  max %s  rows=%d\n",
    fmtMs($t['avg']), fmtMs($t['min']), fmtMs($t['max']), $t['rows']);

$isSlowDistinct = $t['avg'] > 100;
assertion(
    "DISTINCT ON last-posts subquery is a performance concern (>100 ms)",
    $isSlowDistinct,
    sprintf("avg %s — %s",
        fmtMs($t['avg']),
        $isSlowDistinct
            ? "CONFIRMED slow. Caching last_post columns on echoareas would eliminate this scan."
            : "currently fast but will worsen linearly with row count.")
);

// Check if the covering index exists
$hasIndex = isset($existingIndexes['idx_echomail_echoarea_date_received']);
assertion(
    "Index covering (echoarea_id, date_received) exists for DISTINCT ON",
    $hasDistinctOnIndex,
    $hasDistinctOnIndex
        ? "Index present — DISTINCT ON can use index scan instead of seq scan + sort."
        : "No covering index found — PostgreSQL must seq-scan + sort all " . number_format($echomailCount) . " rows."
);

if ($explain) {
    echo "\n  EXPLAIN ANALYZE — DISTINCT ON:\n";
    echo "  " . str_replace("\n", "\n  ", explainQuery($db, $sqlDistinctOn)) . "\n";
}

// ---------------------------------------------------------------------------
// SECTION 6: Timing — unread_counts subquery
// ---------------------------------------------------------------------------

section("6. Timing: unread_counts Subquery (user $userId)");

// Current approach: scan ALL echomail, left join MRS for this user
$sqlUnreadCurrent = "
    SELECT em.echoarea_id, COUNT(*) AS unread_count
    FROM echomail em
    LEFT JOIN message_read_status mrs
        ON mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?
    WHERE mrs.read_at IS NULL
      AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC'))
    GROUP BY em.echoarea_id
";

// Proposed approach: start from subscriptions (only the user's areas)
$sqlUnreadProposed = "
    SELECT ues.echoarea_id, COUNT(em.id) AS unread_count
    FROM user_echoarea_subscriptions ues
    JOIN echomail em
        ON em.echoarea_id = ues.echoarea_id
        AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC'))
    LEFT JOIN message_read_status mrs
        ON mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?
    WHERE ues.user_id = ? AND ues.is_active = TRUE
      AND mrs.read_at IS NULL
    GROUP BY ues.echoarea_id
";

echo "  Running each query 3 times...\n\n";

$currentUnreadT  = timeQuery($db, $sqlUnreadCurrent,  [$userId]);
$proposedUnreadT = timeQuery($db, $sqlUnreadProposed, [$userId, $userId]);

printf("  Current  (all areas, left join): avg %s  min %s  max %s\n",
    fmtMs($currentUnreadT['avg']), fmtMs($currentUnreadT['min']), fmtMs($currentUnreadT['max']));
printf("  Proposed (subscribed only):      avg %s  min %s  max %s\n",
    fmtMs($proposedUnreadT['avg']), fmtMs($proposedUnreadT['min']), fmtMs($proposedUnreadT['max']));

// How many areas is this user subscribed to?
$subCount = (int)$db->prepare("
    SELECT COUNT(*) FROM user_echoarea_subscriptions WHERE user_id = ? AND is_active = TRUE
")->execute([$userId]) ? $db->prepare("
    SELECT COUNT(*) FROM user_echoarea_subscriptions WHERE user_id = ? AND is_active = TRUE
")->execute([$userId]) : 0;

$subCountStmt = $db->prepare("SELECT COUNT(*) FROM user_echoarea_subscriptions WHERE user_id = ? AND is_active = TRUE");
$subCountStmt->execute([$userId]);
$subCount = (int)$subCountStmt->fetchColumn();

echo "\n  User $userId is subscribed to $subCount / $echoareaCount echoareas.\n";

$unreadSpeedup = $currentUnreadT['avg'] > 0
    ? $currentUnreadT['avg'] / max($proposedUnreadT['avg'], 0.01)
    : 0;
printf("  Speedup with subscription-scoped query: %.1fx\n", $unreadSpeedup);

$unreadSlow = $currentUnreadT['avg'] > 200;
assertion(
    "Unread-count subquery is a performance concern (>200 ms)",
    $unreadSlow,
    sprintf("avg %s", fmtMs($currentUnreadT['avg']))
);

assertion(
    "Subscription-scoped unread query is faster than full-scan variant",
    $proposedUnreadT['avg'] < $currentUnreadT['avg'],
    sprintf("%.1fx speedup", $unreadSpeedup)
);

if ($explain) {
    echo "\n  EXPLAIN ANALYZE — current unread subquery:\n";
    echo "  " . str_replace("\n", "\n  ", explainQuery($db, $sqlUnreadCurrent, [$userId])) . "\n";

    if ($verbose) {
        echo "\n  EXPLAIN ANALYZE — proposed subscription-scoped unread query:\n";
        echo "  " . str_replace("\n", "\n  ", explainQuery($db, $sqlUnreadProposed, [$userId, $userId])) . "\n";
    }
}

// ---------------------------------------------------------------------------
// SECTION 7: Timing — full echolist query (current)
// ---------------------------------------------------------------------------

section("7. Timing: Full Echolist Query (Current)");

// Replicate the actual query from api-routes.php (no ignore filter for simplicity)
$sqlEcholistFull = "
    SELECT
        e.id, e.tag, e.description, e.color, e.is_active, e.domain, e.is_local,
        COALESCE(total_counts.message_count, 0) AS message_count,
        COALESCE(unread_counts.unread_count, 0) AS unread_count,
        COALESCE(sub_counts.subscriber_count, 0) AS subscriber_count,
        last_posts.last_subject,
        last_posts.last_author,
        last_posts.last_date
    FROM echoareas e
    LEFT JOIN (
        SELECT em.echoarea_id, COUNT(*) AS message_count
        FROM echomail em
        WHERE em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC')
        GROUP BY em.echoarea_id
    ) total_counts ON e.id = total_counts.echoarea_id
    LEFT JOIN (
        SELECT em.echoarea_id, COUNT(*) AS unread_count
        FROM echomail em
        LEFT JOIN message_read_status mrs
            ON mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?
        WHERE mrs.read_at IS NULL
          AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC'))
        GROUP BY em.echoarea_id
    ) unread_counts ON e.id = unread_counts.echoarea_id
    LEFT JOIN (
        SELECT DISTINCT ON (em.echoarea_id)
            em.echoarea_id,
            em.subject   AS last_subject,
            em.from_name AS last_author,
            em.date_received AS last_date
        FROM echomail em
        ORDER BY em.echoarea_id, em.date_received DESC
    ) last_posts ON e.id = last_posts.echoarea_id
    LEFT JOIN (
        SELECT echoarea_id, COUNT(*) AS subscriber_count
        FROM user_echoarea_subscriptions
        WHERE is_active = TRUE
        GROUP BY echoarea_id
    ) sub_counts ON e.id = sub_counts.echoarea_id
";

// Proposed query: use e.message_count and skip total_counts subquery;
// last_posts still uses DISTINCT ON (caching it requires schema change)
$sqlEcholistProposed = "
    SELECT
        e.id, e.tag, e.description, e.color, e.is_active, e.domain, e.is_local,
        e.message_count,
        COALESCE(unread_counts.unread_count, 0) AS unread_count,
        COALESCE(sub_counts.subscriber_count, 0) AS subscriber_count,
        last_posts.last_subject,
        last_posts.last_author,
        last_posts.last_date
    FROM echoareas e
    LEFT JOIN (
        SELECT ues.echoarea_id, COUNT(em.id) AS unread_count
        FROM user_echoarea_subscriptions ues
        JOIN echomail em
            ON em.echoarea_id = ues.echoarea_id
            AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC'))
        LEFT JOIN message_read_status mrs
            ON mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?
        WHERE ues.user_id = ? AND ues.is_active = TRUE
          AND mrs.read_at IS NULL
        GROUP BY ues.echoarea_id
    ) unread_counts ON e.id = unread_counts.echoarea_id
    LEFT JOIN (
        SELECT DISTINCT ON (em.echoarea_id)
            em.echoarea_id,
            em.subject   AS last_subject,
            em.from_name AS last_author,
            em.date_received AS last_date
        FROM echomail em
        ORDER BY em.echoarea_id, em.date_received DESC
    ) last_posts ON e.id = last_posts.echoarea_id
    LEFT JOIN (
        SELECT echoarea_id, COUNT(*) AS subscriber_count
        FROM user_echoarea_subscriptions
        WHERE is_active = TRUE
        GROUP BY echoarea_id
    ) sub_counts ON e.id = sub_counts.echoarea_id
";

echo "  Running each query 3 times...\n\n";

$fullCurrent  = timeQuery($db, $sqlEcholistFull,     [$userId]);
$fullProposed = timeQuery($db, $sqlEcholistProposed, [$userId, $userId]);

printf("  Current  (3 echomail subqueries):  avg %s  min %s  max %s  rows=%d\n",
    fmtMs($fullCurrent['avg']),  fmtMs($fullCurrent['min']),  fmtMs($fullCurrent['max']),  $fullCurrent['rows']);
printf("  Proposed (1 echomail subquery):    avg %s  min %s  max %s  rows=%d\n",
    fmtMs($fullProposed['avg']), fmtMs($fullProposed['min']), fmtMs($fullProposed['max']), $fullProposed['rows']);

$fullSpeedup = $fullCurrent['avg'] > 0
    ? $fullCurrent['avg'] / max($fullProposed['avg'], 0.01)
    : 0;
printf("\n  Speedup from partial optimisation (fixes 1+3 only): %.1fx\n", $fullSpeedup);

assertion(
    "Full echolist query takes more than 500 ms (production concern)",
    $fullCurrent['avg'] > 500,
    sprintf("avg %s", fmtMs($fullCurrent['avg']))
);
assertion(
    "Partial fix (drop total_counts + scope unread) is faster than current",
    $fullProposed['avg'] < $fullCurrent['avg'],
    sprintf("%.1fx speedup — current %s vs proposed %s",
        $fullSpeedup, fmtMs($fullCurrent['avg']), fmtMs($fullProposed['avg']))
);

if ($explain) {
    echo "\n  EXPLAIN ANALYZE — full current echolist query:\n";
    echo "  " . str_replace("\n", "\n  ", explainQuery($db, $sqlEcholistFull, [$userId])) . "\n";

    if ($verbose) {
        echo "\n  EXPLAIN ANALYZE — proposed partial-fix echolist query:\n";
        echo "  " . str_replace("\n", "\n  ", explainQuery($db, $sqlEcholistProposed, [$userId, $userId])) . "\n";
    }
}

// ---------------------------------------------------------------------------
// SECTION 8: Dashboard unread echomail query
// ---------------------------------------------------------------------------

section("8. Timing: Dashboard Unread Echomail Count");

$sqlDashUnread = "
    SELECT COUNT(*) AS count
    FROM echomail em
    INNER JOIN echoareas e ON em.echoarea_id = e.id
    INNER JOIN user_echoarea_subscriptions ues ON e.id = ues.echoarea_id AND ues.user_id = ?
    LEFT JOIN message_read_status mrs
        ON mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?
    WHERE mrs.read_at IS NULL
      AND e.is_active = TRUE
      AND ues.is_active = TRUE
";

echo "  Running 3 times...\n\n";
$dashT = timeQuery($db, $sqlDashUnread, [$userId, $userId]);

printf("  Dashboard unread query:  avg %s  min %s  max %s  count=%d\n",
    fmtMs($dashT['avg']), fmtMs($dashT['min']), fmtMs($dashT['max']),
    empty($dashT['rows']) ? 0 : 0); // fetchAll returns 1 row with count

// Re-run to get the actual unread count value
$stmt = $db->prepare($sqlDashUnread);
$stmt->execute([$userId, $userId]);
$unreadValue = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
echo "  Unread count for user $userId: $unreadValue messages\n";

assertion(
    "Dashboard unread echomail query takes more than 200 ms",
    $dashT['avg'] > 200,
    sprintf("avg %s — %s",
        fmtMs($dashT['avg']),
        $dashT['avg'] > 200
            ? "CONFIRMED slow. Consider a last_read_id high-watermark per subscribed area."
            : "currently acceptable but scales linearly with echomail volume.")
);

if ($explain) {
    echo "\n  EXPLAIN ANALYZE — dashboard unread query:\n";
    echo "  " . str_replace("\n", "\n  ", explainQuery($db, $sqlDashUnread, [$userId, $userId])) . "\n";
}

// ---------------------------------------------------------------------------
// SECTION 9: Index impact simulation (disable seqscan to force index usage)
// ---------------------------------------------------------------------------

section("9. Index Simulation: (echoarea_id, date_received DESC)");

echo "  Testing DISTINCT ON query with seq-scan disabled to measure index benefit...\n";
echo "  (This simulates having idx_echomail_echoarea_date_received)\n\n";

$db->exec("SET enable_seqscan = OFF");
$tWithIndex = timeQuery($db, $sqlDistinctOn);
$db->exec("SET enable_seqscan = ON");

$tWithout = timeQuery($db, $sqlDistinctOn);

printf("  DISTINCT ON with  index (seqscan off): avg %s  min %s  max %s\n",
    fmtMs($tWithIndex['avg']), fmtMs($tWithIndex['min']), fmtMs($tWithIndex['max']));
printf("  DISTINCT ON without index (seqscan on): avg %s  min %s  max %s\n",
    fmtMs($tWithout['avg']),  fmtMs($tWithout['min']),  fmtMs($tWithout['max']));

$indexSpeedup = $tWithout['avg'] > 0
    ? $tWithout['avg'] / max($tWithIndex['avg'], 0.01)
    : 0;
printf("\n  Estimated index speedup: %.1fx\n", $indexSpeedup);

// Note: disable seqscan forces an index scan only if the index exists.
// If there is no suitable index, PostgreSQL may still pick a different plan.
$note = $hasDistinctOnIndex
    ? "A covering index on (echoarea_id, date_received) EXISTS — result is a real index-scan measurement."
    : "No covering index on (echoarea_id, date_received) found — PostgreSQL used its best fallback;\n  create the index and re-run to see the real gain.";
echo "\n  NOTE: $note\n";

assertion(
    "Adding (echoarea_id, date_received DESC) index improves DISTINCT ON by >= 2x",
    $indexSpeedup >= 2.0,
    sprintf("%.1fx speedup estimated", $indexSpeedup)
);

if ($explain && $verbose) {
    $db->exec("SET enable_seqscan = OFF");
    echo "\n  EXPLAIN ANALYZE — DISTINCT ON with seqscan disabled:\n";
    echo "  " . str_replace("\n", "\n  ", explainQuery($db, $sqlDistinctOn)) . "\n";
    $db->exec("SET enable_seqscan = ON");
}

// ---------------------------------------------------------------------------
// SECTION 10: Summary
// ---------------------------------------------------------------------------

section("10. Summary");

echo "\n  Assertions: $pass passed, $fail failed, $warn warnings (of $total total)\n\n";

echo "  What this means:\n\n";
echo "  Fix 1 (use e.message_count):  ";
echo isset($existingIndexes['_dummy']) ? '' : '';
if ($currentT['avg'] > 10) {
    printf("saves ~%s per echolist load (eliminates COUNT subquery).\n", fmtMs($currentT['avg']));
} else {
    echo "minimal gain at current scale.\n";
}

echo "  Fix 2 (cache last_post cols): ";
if ($t['avg'] > 50) {
    printf("saves ~%s per echolist load (eliminates DISTINCT ON scan).\n", fmtMs($t['avg']));
} else {
    echo "currently fast, but will worsen as message base grows.\n";
}

echo "  Fix 3 (scope unread query):   ";
if ($currentUnreadT['avg'] > $proposedUnreadT['avg']) {
    printf("saves ~%s per echolist load (%.1fx speedup on unread count).\n",
        fmtMs($currentUnreadT['avg'] - $proposedUnreadT['avg']), $unreadSpeedup);
} else {
    echo "no measured benefit for this user's subscription set.\n";
}

echo "  Fix 4 (add index):            ";
if ($indexSpeedup >= 2) {
    printf("~%.1fx speedup on DISTINCT ON query.\n", $indexSpeedup);
} else {
    echo "index benefit unclear at this scale — re-run after creating the index.\n";
}

echo "\n  Run with --explain to see EXPLAIN ANALYZE plans for all queries.\n";
echo   "  Run with --verbose to also see plans for proposed alternatives.\n";
echo   "  Run with --user=N to test a user with more/fewer subscriptions.\n\n";
