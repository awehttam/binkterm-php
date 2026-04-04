#!/usr/bin/env php
<?php

/**
 * echomail_summary.php - Summarize echomail traffic over the past N days,
 * optionally using the AI provider to generate a narrative digest.
 *
 * Usage: php scripts/echomail_summary.php [options]
 *   --days=N        Days to look back (default: 7)
 *   --top=N         Rows in ranked lists (default: 10)
 *   --area=TAG      Limit to a single echo area
 *   --no-ai         Print raw stats table only, skip AI summary
 *   --output=FILE   Write AI summary to FILE instead of stdout
 *   --help
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\AI\AiService;
use BinktermPHP\AI\AiRequest;

// ── Argument parsing ──────────────────────────────────────────────────────────

$days     = 7;
$top      = 10;
$area     = null;
$useAi    = true;
$output   = null;

foreach ($argv as $i => $arg) {
    if ($i === 0) continue;
    if (preg_match('/^--days=(\d+)$/', $arg, $m))   { $days   = max(1, (int) $m[1]); }
    elseif (preg_match('/^--top=(\d+)$/', $arg, $m)) { $top    = max(1, (int) $m[1]); }
    elseif (preg_match('/^--area=(.+)$/', $arg, $m)) { $area   = strtoupper(trim($m[1])); }
    elseif (preg_match('/^--output=(.+)$/', $arg, $m)){ $output = trim($m[1]); }
    elseif ($arg === '--no-ai')                        { $useAi  = false; }
    elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php scripts/echomail_summary.php [options]\n\n";
        echo "  --days=N      Days to look back (default: 7)\n";
        echo "  --top=N       Rows in ranked lists (default: 10)\n";
        echo "  --area=TAG    Limit to a single echo area tag\n";
        echo "  --no-ai       Print raw stats table only, skip AI summary\n";
        echo "  --output=FILE Write AI summary to FILE instead of stdout\n";
        exit(0);
    }
}

try {
    $db = Database::getInstance()->getPdo();
} catch (Exception $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$since       = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                   ->modify("-{$days} days")
                   ->format('Y-m-d H:i:s');
$areaFilter  = 'AND ea.is_sysop_only = FALSE' . ($area !== null ? ' AND ea.tag = :area' : '');

// ── Helper: bind common params ────────────────────────────────────────────────

$bind = function (PDOStatement $stmt) use ($since, $area): void {
    $stmt->bindValue(':since', $since);
    if ($area !== null) $stmt->bindValue(':area', $area);
};

// ── Query: totals ─────────────────────────────────────────────────────────────

$stmt = $db->prepare("
    SELECT COUNT(*)                        AS total,
           COUNT(DISTINCT e.echoarea_id)   AS active_areas,
           COUNT(DISTINCT ea.domain)       AS networks,
           COUNT(DISTINCT e.from_address)  AS unique_nodes,
           COUNT(DISTINCT e.from_name)     AS unique_names
    FROM echomail e
    JOIN echoareas ea ON ea.id = e.echoarea_id
    WHERE e.date_received >= :since {$areaFilter}
");
$bind($stmt); $stmt->execute();
$totals = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Query: per-network message counts ─────────────────────────────────────────

$stmt = $db->prepare("
    SELECT COALESCE(ea.domain, 'unknown') AS network,
           COUNT(*) AS msg_count,
           COUNT(DISTINCT e.echoarea_id) AS area_count
    FROM echomail e
    JOIN echoareas ea ON ea.id = e.echoarea_id
    WHERE e.date_received >= :since {$areaFilter}
    GROUP BY ea.domain
    ORDER BY msg_count DESC
");
$bind($stmt); $stmt->execute();
$networks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Query: per-area counts ────────────────────────────────────────────────────

$stmt = $db->prepare("
    SELECT ea.tag,
           COALESCE(ea.domain, 'unknown') AS network,
           COALESCE(ea.description, '')   AS description,
           COUNT(*) AS msg_count
    FROM echomail e
    JOIN echoareas ea ON ea.id = e.echoarea_id
    WHERE e.date_received >= :since {$areaFilter}
    GROUP BY ea.id, ea.tag, ea.domain, ea.description
    ORDER BY msg_count DESC
    LIMIT :top
");
$bind($stmt); $stmt->bindValue(':top', $top, PDO::PARAM_INT); $stmt->execute();
$areaCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Query: top senders (volume) ───────────────────────────────────────────────

$stmt = $db->prepare("
    SELECT e.from_name, e.from_address,
           COALESCE(ea.domain, 'unknown') AS network,
           COUNT(*) AS msg_count
    FROM echomail e
    JOIN echoareas ea ON ea.id = e.echoarea_id
    WHERE e.date_received >= :since {$areaFilter}
    GROUP BY e.from_name, e.from_address, ea.domain
    ORDER BY msg_count DESC
    LIMIT :top
");
$bind($stmt); $stmt->bindValue(':top', $top, PDO::PARAM_INT); $stmt->execute();
$topSenders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Query: most replied-to senders ───────────────────────────────────────────
// Counts how many messages in the window are replies to each sender.

$stmt = $db->prepare("
    SELECT orig.from_name, orig.from_address,
           COUNT(*) AS reply_count
    FROM echomail reply
    JOIN echomail orig ON orig.id = reply.reply_to_id
    JOIN echoareas ea  ON ea.id  = reply.echoarea_id
    WHERE reply.date_received >= :since
      AND reply.reply_to_id IS NOT NULL
      {$areaFilter}
    GROUP BY orig.from_name, orig.from_address
    ORDER BY reply_count DESC
    LIMIT :top
");
$bind($stmt); $stmt->bindValue(':top', $top, PDO::PARAM_INT); $stmt->execute();
$mostRepliedTo = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Query: most active threads (by subject, bot-filtered) ─────────────────────

$stmt = $db->prepare("
    SELECT e.subject,
           COUNT(DISTINCT ea.domain)  AS network_count,
           STRING_AGG(DISTINCT ea.tag, ', ' ORDER BY ea.tag) AS areas,
           COUNT(*) AS post_count
    FROM echomail e
    JOIN echoareas ea ON ea.id = e.echoarea_id
    WHERE e.date_received >= :since
      AND e.subject IS NOT NULL AND e.subject <> ''
      AND e.from_name NOT ILIKE '%bot%'
      AND e.from_name NOT ILIKE '%feeder%'
      AND e.from_name NOT ILIKE '%news%'
      {$areaFilter}
    GROUP BY e.subject
    ORDER BY post_count DESC
    LIMIT :top
");
$bind($stmt); $stmt->bindValue(':top', $top, PDO::PARAM_INT); $stmt->execute();
$threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Query: daily breakdown ────────────────────────────────────────────────────

$stmt = $db->prepare("
    SELECT DATE(e.date_received AT TIME ZONE 'UTC') AS day,
           COUNT(*) AS msg_count
    FROM echomail e
    JOIN echoareas ea ON ea.id = e.echoarea_id
    WHERE e.date_received >= :since {$areaFilter}
    GROUP BY day ORDER BY day ASC
");
$bind($stmt); $stmt->execute();
$daily = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Query: cross-network threads (same subject in 2+ networks) ────────────────

$stmt = $db->prepare("
    SELECT e.subject, COUNT(DISTINCT ea.domain) AS network_count,
           STRING_AGG(DISTINCT COALESCE(ea.domain,'unknown'), ', ' ORDER BY COALESCE(ea.domain,'unknown')) AS networks_list,
           COUNT(*) AS post_count
    FROM echomail e
    JOIN echoareas ea ON ea.id = e.echoarea_id
    WHERE e.date_received >= :since
      AND e.subject IS NOT NULL AND e.subject <> ''
      AND e.from_name NOT ILIKE '%bot%'
      AND e.from_name NOT ILIKE '%feeder%'
      {$areaFilter}
    GROUP BY e.subject
    HAVING COUNT(DISTINCT ea.domain) > 1
    ORDER BY network_count DESC, post_count DESC
    LIMIT 10
");
$bind($stmt); $stmt->execute();
$crossNetwork = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Raw stats table ───────────────────────────────────────────────────────────

$hr  = str_repeat('─', 72);
$hr2 = str_repeat('═', 72);

$printRawStats = function () use (
    $days, $area, $totals, $networks, $areaCounts,
    $topSenders, $mostRepliedTo, $threads, $daily, $crossNetwork,
    $hr, $hr2, $top
): void {
    $title = $area !== null
        ? "Echomail Summary — {$area} — past {$days} day(s)"
        : "Echomail Summary — past {$days} day(s)";

    echo "\n{$hr2}\n {$title}\n Generated: " . gmdate('Y-m-d H:i') . " UTC\n{$hr2}\n\n";

    echo "OVERVIEW\n{$hr}\n";
    echo sprintf("  Total messages   : %d\n", (int)$totals['total']);
    echo sprintf("  Active areas     : %d\n", (int)$totals['active_areas']);
    echo sprintf("  Networks         : %d\n", (int)$totals['networks']);
    echo sprintf("  Unique nodes     : %d\n", (int)$totals['unique_nodes']);
    echo sprintf("  Unique names     : %d\n", (int)$totals['unique_names']);
    echo "\n";

    if (!empty($networks)) {
        echo "BY NETWORK\n{$hr}\n";
        echo sprintf("  %-20s %8s %8s\n", 'Network', 'Messages', 'Areas');
        echo "  " . str_repeat('-', 40) . "\n";
        foreach ($networks as $row) {
            echo sprintf("  %-20s %8d %8d\n", $row['network'], $row['msg_count'], $row['area_count']);
        }
        echo "\n";
    }

    if (!empty($daily)) {
        echo "DAILY TRAFFIC\n{$hr}\n";
        $maxDay = max(array_column($daily, 'msg_count'));
        foreach ($daily as $row) {
            $barLen = $maxDay > 0 ? (int) round($row['msg_count'] / $maxDay * 40) : 0;
            echo sprintf("  %s  %s %d\n", $row['day'], str_pad(str_repeat('█', $barLen), 40), $row['msg_count']);
        }
        echo "\n";
    }

    if (!empty($areaCounts)) {
        echo "TOP AREAS\n{$hr}\n";
        echo sprintf("  %-20s %-12s %-30s %s\n", 'Area', 'Network', 'Description', 'Msgs');
        echo "  " . str_repeat('-', 70) . "\n";
        foreach ($areaCounts as $row) {
            echo sprintf("  %-20s %-12s %-30s %d\n",
                $row['tag'], $row['network'],
                mb_strimwidth($row['description'], 0, 29, '…'),
                $row['msg_count']);
        }
        echo "\n";
    }

    if (!empty($topSenders)) {
        echo "TOP SENDERS (VOLUME)\n{$hr}\n";
        echo sprintf("  %-28s %-18s %-14s %s\n", 'Name', 'Address', 'Network', 'Msgs');
        echo "  " . str_repeat('-', 70) . "\n";
        foreach ($topSenders as $row) {
            echo sprintf("  %-28s %-18s %-14s %d\n",
                mb_strimwidth($row['from_name'], 0, 27, '…'),
                $row['from_address'], $row['network'], $row['msg_count']);
        }
        echo "\n";
    }

    if (!empty($mostRepliedTo)) {
        echo "MOST REPLIED-TO\n{$hr}\n";
        echo sprintf("  %-28s %-20s %s\n", 'Name', 'Address', 'Replies');
        echo "  " . str_repeat('-', 60) . "\n";
        foreach ($mostRepliedTo as $row) {
            echo sprintf("  %-28s %-20s %d\n",
                mb_strimwidth($row['from_name'], 0, 27, '…'),
                $row['from_address'], $row['reply_count']);
        }
        echo "\n";
    }

    if (!empty($threads)) {
        echo "MOST ACTIVE THREADS\n{$hr}\n";
        echo sprintf("  %-38s %-20s %s\n", 'Subject', 'Areas', 'Posts');
        echo "  " . str_repeat('-', 68) . "\n";
        foreach ($threads as $row) {
            echo sprintf("  %-38s %-20s %d\n",
                mb_strimwidth($row['subject'], 0, 37, '…'),
                mb_strimwidth($row['areas'], 0, 19, '…'),
                $row['post_count']);
        }
        echo "\n";
    }

    if (!empty($crossNetwork)) {
        echo "CROSS-NETWORK THREADS\n{$hr}\n";
        echo sprintf("  %-38s %-24s %s\n", 'Subject', 'Networks', 'Posts');
        echo "  " . str_repeat('-', 68) . "\n";
        foreach ($crossNetwork as $row) {
            echo sprintf("  %-38s %-24s %d\n",
                mb_strimwidth($row['subject'], 0, 37, '…'),
                mb_strimwidth($row['networks_list'], 0, 23, '…'),
                $row['post_count']);
        }
        echo "\n";
    }

    echo "{$hr2}\n\n";
};

// Always print the raw stats
$printRawStats();

if (!$useAi) {
    exit(0);
}

// ── Build AI prompt ───────────────────────────────────────────────────────────

$promptLines = [];
$promptLines[] = "You are analyzing echomail traffic on a multi-network FidoNet-style BBS system.";
$promptLines[] = "The data below covers the past {$days} day(s). Generate a concise, engaging plain-text";
$promptLines[] = "digest in the style of a weekly newsletter. Include:";
$promptLines[] = "- A headline summary (totals, networks)";
$promptLines[] = "- Volume leaders by area and network (note which areas are dominated by bots/feeders)";
$promptLines[] = "- Who the most engaged *human* participants are this week";
$promptLines[] = "- Hot topics and notable threads, especially any cross-network ones";
$promptLines[] = "- Any interesting patterns or observations";
$promptLines[] = "Keep it conversational and concise. Use plain text with simple section headers (no markdown).";
$promptLines[] = "";

$promptLines[] = "=== OVERVIEW ===";
$promptLines[] = "Period: past {$days} days";
$promptLines[] = "Total messages: " . $totals['total'];
$promptLines[] = "Active areas: "   . $totals['active_areas'];
$promptLines[] = "Networks: "       . $totals['networks'];
$promptLines[] = "Unique sending nodes: " . $totals['unique_nodes'];
$promptLines[] = "";

$promptLines[] = "=== BY NETWORK ===";
foreach ($networks as $row) {
    $promptLines[] = "{$row['network']}: {$row['msg_count']} messages across {$row['area_count']} areas";
}
$promptLines[] = "";

$promptLines[] = "=== TOP AREAS ===";
foreach ($areaCounts as $row) {
    $promptLines[] = "{$row['tag']} ({$row['network']}): {$row['msg_count']} messages — {$row['description']}";
}
$promptLines[] = "";

$promptLines[] = "=== TOP SENDERS BY VOLUME ===";
foreach ($topSenders as $row) {
    $promptLines[] = "{$row['from_name']} <{$row['from_address']}> [{$row['network']}]: {$row['msg_count']} messages";
}
$promptLines[] = "";

if (!empty($mostRepliedTo)) {
    $promptLines[] = "=== MOST REPLIED-TO (indicates human engagement) ===";
    foreach ($mostRepliedTo as $row) {
        $promptLines[] = "{$row['from_name']} <{$row['from_address']}>: {$row['reply_count']} replies received";
    }
    $promptLines[] = "";
}

if (!empty($threads)) {
    $promptLines[] = "=== MOST ACTIVE THREADS ===";
    foreach ($threads as $row) {
        $promptLines[] = "\"{$row['subject']}\" — {$row['areas']} ({$row['post_count']} posts)";
    }
    $promptLines[] = "";
}

if (!empty($crossNetwork)) {
    $promptLines[] = "=== CROSS-NETWORK THREADS (same subject active in multiple networks) ===";
    foreach ($crossNetwork as $row) {
        $promptLines[] = "\"{$row['subject']}\" — networks: {$row['networks_list']} ({$row['post_count']} posts)";
    }
    $promptLines[] = "";
}

$promptLines[] = "=== DAILY TRAFFIC ===";
foreach ($daily as $row) {
    $promptLines[] = "{$row['day']}: {$row['msg_count']} messages";
}

$userPrompt = implode("\n", $promptLines);

// ── Call AI provider ──────────────────────────────────────────────────────────

$systemPrompt = "You are a weekly digest writer for a FidoNet/BBS community. "
    . "You write concise, insightful summaries of echomail network activity. "
    . "You understand FTN addressing, echo areas, bots/feeders vs human posters, "
    . "and the culture of the BBS/FidoNet community. "
    . "Write in plain text with clear section headers. No markdown formatting.";

try {
    $aiService = AiService::create();

    $configured = $aiService->getConfiguredProviders();
    if (empty($configured)) {
        fwrite(STDERR, "No AI provider configured. Set ANTHROPIC_API_KEY or OPENAI_API_KEY in .env.\n");
        fwrite(STDERR, "Run with --no-ai to skip AI summary.\n");
        exit(1);
    }

    fwrite(STDERR, "Generating AI summary (provider: " . implode(', ', $configured) . ")...\n");

    $response = $aiService->generateText(new AiRequest(
        'echomail_summary',
        $systemPrompt,
        $userPrompt,
        null,   // provider — use AI_ECHOMAIL_SUMMARY_PROVIDER or AI_DEFAULT_PROVIDER
        null,   // model   — use AI_ECHOMAIL_SUMMARY_MODEL or AI_DEFAULT_MODEL
        0.5,    // temperature — a little creative
        2048,   // max output tokens
        90      // timeout
    ));

    $summary = $response->getContent();
    $usage   = $response->getUsage();

} catch (\Throwable $e) {
    fwrite(STDERR, "AI summary failed: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Output ────────────────────────────────────────────────────────────────────

$header = str_repeat('═', 72) . "\n AI-GENERATED DIGEST\n" . str_repeat('═', 72) . "\n\n";
$footer = "\n" . str_repeat('─', 72) . "\n"
    . sprintf(" Tokens: %d in / %d out  |  Model: %s\n",
        $usage->getInputTokens(), $usage->getOutputTokens(), $response->getModel())
    . str_repeat('─', 72) . "\n";

if ($output !== null) {
    file_put_contents($output, $header . $summary . $footer);
    fwrite(STDERR, "AI summary written to {$output}\n");
} else {
    echo $header . $summary . $footer;
}
