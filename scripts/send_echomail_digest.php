#!/usr/bin/env php
<?php

/**
 * Echomail Digest Mailer
 *
 * Sends per-user echomail digest emails summarising new messages in subscribed
 * echo areas since the last digest was sent.  Run this script periodically via
 * cron — once per hour is a good cadence; the script enforces the per-user
 * frequency (daily / weekly) internally.
 *
 * Usage:
 *   php scripts/send_echomail_digest.php [--dry-run] [--verbose] [--resend] [--user=ID]
 *
 * Options:
 *   --dry-run    Show what would be sent without actually sending or updating
 *                last-sent timestamps.
 *   --verbose    Print per-user status to stdout.
 *   --resend     Force send even if the frequency window has not elapsed yet.
 *                Lookback window is still based on last_sent (or the frequency
 *                period if never sent). Useful for support and testing.
 *   --user=ID    Process only the specified user ID (useful for testing).
 *
 * Requirements:
 *   - Valid BinktermPHP license (License::isValid())
 *   - SMTP configured and enabled in .env
 *   - User must have an email address and echomail_digest set to 'daily' or 'weekly'
 *   - User must be subscribed to at least one echo area that has new messages
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\License;
use BinktermPHP\Mail;
use BinktermPHP\Binkp\Config\BinkpConfig;

// ── Argument parsing ────────────────────────────────────────────────────────

$dryRun   = false;
$verbose  = false;
$resend   = false;
$onlyUser = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run')   { $dryRun  = true; continue; }
    if ($arg === '--verbose')   { $verbose = true; continue; }
    if ($arg === '--resend')    { $resend  = true; continue; }
    if (str_starts_with($arg, '--user=')) {
        $onlyUser = (int) substr($arg, 7);
        continue;
    }
    if ($arg === '--help') {
        echo "Usage: php scripts/send_echomail_digest.php [--dry-run] [--verbose] [--resend] [--user=ID]\n";
        echo "\n";
        echo "  --resend   Force send even if the frequency window has not elapsed.\n";
        echo "             Lookback window is still based on last_sent (or the\n";
        echo "             frequency period if never sent). Useful for support/testing.\n";
        exit(0);
    }
}

// ── License check ────────────────────────────────────────────────────────────

if (!License::isValid()) {
    fwrite(STDERR, "Echomail digest requires a valid BinktermPHP license.\n");
    exit(1);
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function log_msg(string $msg, bool $verbose, bool $force = false): void
{
    if ($verbose || $force) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    }
}

function buildEchomailMessageUrl(string $siteUrl, string $areaSlug, int $messageId): string
{
    return $siteUrl . '/echomail/' . rawurlencode($areaSlug) . '?message=' . $messageId;
}

function buildNetmailMessageUrl(string $siteUrl, int $messageId): string
{
    return $siteUrl . '/netmail?message=' . $messageId;
}

/**
 * Determine whether enough time has passed since the last digest for the given
 * frequency.
 *
 * @param string      $frequency  'daily' or 'weekly'
 * @param string|null $lastSent   ISO timestamp or null if never sent
 */
function isDue(string $frequency, ?string $lastSent): bool
{
    if ($lastSent === null) {
        return true; // Never sent — always due
    }

    $sentAt  = new DateTimeImmutable($lastSent);
    $now     = new DateTimeImmutable('now');
    $elapsed = $now->getTimestamp() - $sentAt->getTimestamp();

    return match ($frequency) {
        'daily'  => $elapsed >= 86400,
        'weekly' => $elapsed >= 604800,
        default  => false,
    };
}

/**
 * Build a plain-text digest body for one user.
 *
 * @param array  $netmail    New netmail messages:
 *                           [['subject','from_name','id'], ...]
 * @param array  $addressed  Messages personally addressed to the user:
 *                           [['subject','from_name','area_tag','area_slug','id'], ...]
 * @param array  $areas      Array of ['tag'=>string, 'name'=>string, 'messages'=>[...]]
 * @param string $systemName BBS system name
 * @param string $siteUrl    Base URL of the BBS
 * @return string
 */
function buildPlainDigest(array $netmail, array $addressed, array $areas, string $systemName, string $siteUrl): string
{
    $date  = date('l, F j Y');
    $lines = [];

    $lines[] = "$systemName — Mail Digest";
    $lines[] = $date;
    $lines[] = str_repeat('=', 60);
    $lines[] = '';

    if (!empty($netmail)) {
        $count   = count($netmail);
        $lines[] = '*** NEW NETMAIL (' . $count . ') ***';
        $lines[] = str_repeat('-', 40);
        foreach ($netmail as $msg) {
            $lines[] = '  ' . $msg['subject'] . '  (from ' . $msg['from_name'] . ')';
            $lines[] = '  ' . buildNetmailMessageUrl($siteUrl, (int)$msg['id']);
        }
        $lines[] = '';
    }

    // Personal messages section — shown first if any exist
    if (!empty($addressed)) {
        $count   = count($addressed);
        $lines[] = '*** MESSAGES ADDRESSED TO YOU (' . $count . ') ***';
        $lines[] = str_repeat('-', 40);
        foreach ($addressed as $msg) {
            $lines[] = '  ' . $msg['subject'] . '  (from ' . $msg['from_name'] . ' in ' . $msg['area_tag'] . ')';
            $lines[] = '  ' . buildEchomailMessageUrl($siteUrl, $msg['area_slug'], (int)$msg['id']);
        }
        $lines[] = '';
    }

    foreach ($areas as $area) {
        $count   = count($area['messages']);
        $lines[] = $area['tag'] . ' — ' . $count . ' new message' . ($count === 1 ? '' : 's');
        $lines[] = str_repeat('-', 40);

        foreach ($area['messages'] as $msg) {
            $lines[] = '  ' . $msg['subject'] . '  (from ' . $msg['from_name'] . ')';
            if (!empty($msg['id'])) {
                $lines[] = '  ' . buildEchomailMessageUrl($siteUrl, $area['slug'], (int)$msg['id']);
            }
        }

        $lines[] = '  ' . $siteUrl . '/echomail/' . rawurlencode($area['slug']);
        $lines[] = '';
    }

    $lines[] = str_repeat('=', 60);
    $lines[] = 'To unsubscribe from digest emails, visit:';
    $lines[] = $siteUrl . '/settings';
    $lines[] = '';

    return implode("\n", $lines);
}

/**
 * Build an HTML digest body for one user.
 *
 * @param array  $netmail    New netmail messages.
 * @param array  $addressed  Messages personally addressed to the user.
 * @param array  $areas      Echo area summaries.
 * @param string $systemName BBS system name.
 * @param string $siteUrl    Base URL of the BBS.
 */
function buildHtmlDigest(array $netmail, array $addressed, array $areas, string $systemName, string $siteUrl): string
{
    $date         = date('l, F j Y');
    $safeSystem   = htmlspecialchars($systemName, ENT_QUOTES, 'UTF-8');
    $safeDate     = htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
    $safeSiteUrl  = htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8');

    $netmailHtml = '';
    if (!empty($netmail)) {
        $count       = count($netmail);
        $netmailHtml .= '<div class="netmail">';
        $netmailHtml .= '<h2>New Netmail (' . $count . ')</h2>';
        $netmailHtml .= '<ul>';
        foreach ($netmail as $msg) {
            $safeSubject = htmlspecialchars($msg['subject'] ?: '(no subject)', ENT_QUOTES, 'UTF-8');
            $safeFrom    = htmlspecialchars($msg['from_name'], ENT_QUOTES, 'UTF-8');
            $messageUrl  = buildNetmailMessageUrl($siteUrl, (int)$msg['id']);
            $safeMessageUrl = htmlspecialchars($messageUrl, ENT_QUOTES, 'UTF-8');
            $netmailHtml .= '<li><a href="' . $safeMessageUrl . '"><strong>' . $safeSubject . '</strong></a> &mdash; from ' . $safeFrom . '</li>';
        }
        $netmailHtml .= '</ul></div>';
    }

    // Personal messages block
    $addressedHtml = '';
    if (!empty($addressed)) {
        $count          = count($addressed);
        $addressedHtml .= '<div class="addressed">';
        $addressedHtml .= '<h2>Messages Addressed to You (' . $count . ')</h2>';
        $addressedHtml .= '<ul>';
        foreach ($addressed as $msg) {
            $safeSubject  = htmlspecialchars($msg['subject'] ?: '(no subject)', ENT_QUOTES, 'UTF-8');
            $safeFrom     = htmlspecialchars($msg['from_name'], ENT_QUOTES, 'UTF-8');
            $safeAreaTag  = htmlspecialchars($msg['area_tag'], ENT_QUOTES, 'UTF-8');
            $messageUrl   = buildEchomailMessageUrl($siteUrl, $msg['area_slug'], (int)$msg['id']);
            $safeMessageUrl = htmlspecialchars($messageUrl, ENT_QUOTES, 'UTF-8');
            $addressedHtml .= '<li><a href="' . $safeMessageUrl . '"><strong>' . $safeSubject . '</strong></a> &mdash; from '
                . $safeFrom . ' in ' . $safeAreaTag . '</li>';
        }
        $addressedHtml .= '</ul></div>';
    }

    $areaHtml = '';
    foreach ($areas as $area) {
        $count       = count($area['messages']);
        $safeTag     = htmlspecialchars($area['tag'], ENT_QUOTES, 'UTF-8');
        $areaUrl     = $siteUrl . '/echomail/' . rawurlencode($area['slug']);
        $safeAreaUrl = htmlspecialchars($areaUrl, ENT_QUOTES, 'UTF-8');

        $areaHtml .= '<div class="area">';
        $areaHtml .= '<h3><a href="' . $safeAreaUrl . '">' . $safeTag . '</a></h3>';
        $areaHtml .= '<p class="count">' . $count . ' new message' . ($count === 1 ? '' : 's') . '</p>';
        $areaHtml .= '<ul>';

        foreach ($area['messages'] as $msg) {
            $safeSubject = htmlspecialchars($msg['subject'] ?: '(no subject)', ENT_QUOTES, 'UTF-8');
            $safeFrom    = htmlspecialchars($msg['from_name'], ENT_QUOTES, 'UTF-8');
            if (!empty($msg['id'])) {
                $messageUrl = buildEchomailMessageUrl($siteUrl, $area['slug'], (int)$msg['id']);
                $safeMessageUrl = htmlspecialchars($messageUrl, ENT_QUOTES, 'UTF-8');
                $areaHtml   .= '<li><a href="' . $safeMessageUrl . '"><strong>' . $safeSubject . '</strong></a> &mdash; ' . $safeFrom . '</li>';
            } else {
                $areaHtml   .= '<li><strong>' . $safeSubject . '</strong> &mdash; ' . $safeFrom . '</li>';
            }
        }

        $areaHtml .= '</ul>';
        $areaHtml .= '<p><a href="' . $safeAreaUrl . '">Read in ' . $safeTag . ' &rarr;</a></p>';
        $areaHtml .= '</div>';
    }

    return <<<HTML
    <html>
    <head>
        <title>Mail Digest — {$safeSystem}</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.5; color: #333; max-width: 640px; margin: 0 auto; }
            .header { background: #0066cc; color: #fff; padding: 16px 20px; margin-bottom: 20px; }
            .header h1 { margin: 0; font-size: 1.2em; }
            .header p  { margin: 4px 0 0; opacity: 0.85; font-size: 0.9em; }
            .area { border-left: 4px solid #0066cc; padding: 10px 16px; margin-bottom: 20px; background: #f9f9f9; }
            .area h3  { margin: 0 0 4px; font-size: 1em; }
            .area h3 a { color: #0066cc; text-decoration: none; }
            .area .count { margin: 0 0 8px; color: #666; font-size: 0.85em; }
            .area ul  { margin: 0 0 8px; padding-left: 1.2em; }
            .area ul li { margin-bottom: 2px; font-size: 0.9em; }
            .footer { font-size: 0.8em; color: #999; border-top: 1px solid #ddd; padding-top: 12px; margin-top: 20px; }
            .addressed { border-left: 4px solid #cc6600; padding: 10px 16px; margin-bottom: 24px; background: #fff8f0; }
            .addressed h2 { margin: 0 0 8px; font-size: 1em; color: #cc6600; }
            .addressed ul { margin: 0; padding-left: 1.2em; }
            .addressed ul li { margin-bottom: 4px; font-size: 0.9em; }
            .addressed ul li a { color: #cc6600; }
            .netmail { border-left: 4px solid #198754; padding: 10px 16px; margin-bottom: 24px; background: #f4fff7; }
            .netmail h2 { margin: 0 0 8px; font-size: 1em; color: #198754; }
            .netmail ul { margin: 0; padding-left: 1.2em; }
            .netmail ul li { margin-bottom: 4px; font-size: 0.9em; }
            .netmail ul li a { color: #198754; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>{$safeSystem} — Mail Digest</h1>
            <p>{$safeDate}</p>
        </div>
        {$netmailHtml}
        {$addressedHtml}
        {$areaHtml}
        <div class="footer">
            To change digest frequency or unsubscribe, visit
            <a href="{$safeSiteUrl}/settings">{$safeSiteUrl}/settings</a>.
        </div>
    </body>
    </html>
    HTML;
}

// ── Main ─────────────────────────────────────────────────────────────────────

$db = Database::getInstance()->getPdo();

try {
    $binkpConfig = BinkpConfig::getInstance();
    $systemName  = $binkpConfig->getSystemName();
} catch (Exception $e) {
    $systemName = 'BinktermPHP System';
}

$siteUrl = \BinktermPHP\Config::getSiteUrl();

// Fetch candidate users
$sql = "
    SELECT u.id, u.email, u.username, u.real_name,
           us.echomail_digest,
           us.echomail_digest_last_sent
    FROM users u
    JOIN user_settings us ON us.user_id = u.id
    WHERE u.is_active = TRUE
      AND u.email IS NOT NULL
      AND u.email <> ''
      AND us.echomail_digest IN ('daily', 'weekly')
";
$params = [];

if ($onlyUser !== null) {
    $sql    .= ' AND u.id = ?';
    $params[] = $onlyUser;
}

$stmt  = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

log_msg('Found ' . count($users) . ' candidate user(s).', $verbose, true);

$sent    = 0;
$skipped = 0;
$failed  = 0;

foreach ($users as $user) {
    $userId    = (int) $user['id'];
    $frequency = $user['echomail_digest'];
    $lastSent  = $user['echomail_digest_last_sent'];

    if (!$resend && !isDue($frequency, $lastSent)) {
        log_msg("User {$userId} ({$user['username']}): not due yet, skipping.", $verbose);
        $skipped++;
        continue;
    }

    // Limits to keep digest emails manageable
    $maxAreas   = 20; // Show at most this many echo areas
    $maxPerArea = 20; // Show at most this many messages per area

    // Determine the lookback window.
    // --resend always uses the full frequency window so there are messages to show.
    // Normal runs use last_sent when available; first run falls back to the frequency window.
    $lookback = $frequency === 'weekly' ? '7 days' : '24 hours';
    if (!$resend && $lastSent !== null) {
        $since = $lastSent;
    } else {
        $since = (new DateTimeImmutable("-{$lookback}"))->format('Y-m-d H:i:s');
    }

    // Fetch new inbound netmail for this user.
    $netmail = [];
    $netmailStmt = $db->prepare("
        SELECT n.id, n.subject, n.from_name, n.date_received
        FROM netmail n
        WHERE n.user_id = ?
          AND n.date_received > ?
          AND COALESCE(n.deleted_by_recipient, FALSE) = FALSE
          AND LOWER(n.from_name) <> LOWER(?)
          AND LOWER(n.from_name) <> LOWER(?)
        ORDER BY n.date_received ASC
        LIMIT 50
    ");
    $netmailStmt->execute([$userId, $since, $user['username'], $user['real_name']]);
    foreach ($netmailStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $netmail[] = [
            'id'        => (int)$row['id'],
            'subject'   => $row['subject'],
            'from_name' => $row['from_name'],
        ];
    }

    // Fetch echomail personally addressed to this user (across all active areas).
    // Uses real_name for matching since that's what FTN correspondents use.
    $addressed = [];
    $realName  = trim((string)($user['real_name'] ?? ''));
    if ($realName !== '') {
        $addrStmt = $db->prepare("
            SELECT em.id, em.subject, em.from_name, e.tag, e.domain,
                   em.date_received
            FROM echomail em
            JOIN echoareas e ON em.echoarea_id = e.id
            WHERE LOWER(em.to_name) = LOWER(?)
              AND em.date_received > ?
              AND e.is_active = TRUE
            ORDER BY em.date_received ASC
            LIMIT 50
        ");
        $addrStmt->execute([$realName, $since]);
        foreach ($addrStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $slug = !empty($row['domain'])
                ? $row['tag'] . '@' . $row['domain']
                : $row['tag'];
            $addressed[] = [
                'id'        => (int)$row['id'],
                'subject'   => $row['subject'],
                'from_name' => $row['from_name'],
                'area_tag'  => $row['tag'],
                'area_slug' => $slug,
            ];
        }
    }

    // Fetch new echomail in subscribed areas, ordered so the most active areas
    // bubble up first (we'll truncate to $maxAreas after grouping).
    $msgStmt = $db->prepare("
        SELECT e.id AS echoarea_id, e.tag, e.domain, e.description AS name,
               em.id,
               em.subject, em.from_name, em.date_received
        FROM echomail em
        JOIN echoareas e ON em.echoarea_id = e.id
        JOIN user_echoarea_subscriptions s
             ON s.echoarea_id = e.id AND s.user_id = ? AND s.is_active = TRUE
        WHERE em.date_received > ?
          AND e.is_active = TRUE
        ORDER BY e.tag, em.date_received ASC
    ");
    $msgStmt->execute([$userId, $since]);
    $rows = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows) && empty($addressed) && empty($netmail)) {
        log_msg("User {$userId} ({$user['username']}): no new messages, skipping.", $verbose);
        // Still update last_sent so we don't keep checking every run
        if (!$dryRun) {
            $upd = $db->prepare("UPDATE user_settings SET echomail_digest_last_sent = NOW() WHERE user_id = ?");
            $upd->execute([$userId]);
        }
        $skipped++;
        continue;
    }

    // Group by echo area, cap per-area message list at $maxPerArea
    $areas = [];
    foreach ($rows as $row) {
        $eid = (int) $row['echoarea_id'];
        if (!isset($areas[$eid])) {
            $slug = !empty($row['domain'])
                ? $row['tag'] . '@' . $row['domain']
                : $row['tag'];
            $areas[$eid] = [
                'echoarea_id' => $eid,
                'tag'         => $row['tag'],
                'slug'        => $slug,
                'name'        => $row['name'],
                'messages'    => [],
                'total'       => 0,
            ];
        }
        $areas[$eid]['total']++;
        if (count($areas[$eid]['messages']) < $maxPerArea) {
            $areas[$eid]['messages'][] = [
                'id'        => (int)$row['id'],
                'subject'   => $row['subject'],
                'from_name' => $row['from_name'],
            ];
        }
    }

    // Sort by total message count descending so the busiest areas appear first,
    // then truncate to $maxAreas
    uasort($areas, fn($a, $b) => $b['total'] <=> $a['total']);
    $totalAreas = count($areas);
    if ($totalAreas > $maxAreas) {
        $areas = array_slice($areas, 0, $maxAreas, true);
    }

    $totalMessages = count($rows);

    $addrCount = count($addressed);
    $netmailCount = count($netmail);
    log_msg("User {$userId} ({$user['username']}): {$totalMessages} message(s) across {$totalAreas} area(s)" .
        ($totalAreas > $maxAreas ? " (showing top {$maxAreas})" : '') .
        ($addrCount > 0 ? ", {$addrCount} addressed to user" : '') .
        ($netmailCount > 0 ? ", {$netmailCount} new netmail" : '') . '.', $verbose);

    if ($dryRun) {
        $shownAreas = count($areas);
        log_msg("  [dry-run] Would send digest to {$user['email']} ({$shownAreas} area(s) shown)", $verbose, true);
        $sent++;
        continue;
    }

    $subject   = "Mail Digest — {$systemName} — " . date('Y-m-d');
    $plainText = buildPlainDigest($netmail, $addressed, array_values($areas), $systemName, $siteUrl);
    $htmlBody  = buildHtmlDigest($netmail, $addressed, array_values($areas), $systemName, $siteUrl);

    $mail   = new Mail();
    $result = $mail->sendMail($user['email'], $subject, $htmlBody, $plainText);

    if ($result) {
        $upd = $db->prepare("UPDATE user_settings SET echomail_digest_last_sent = NOW() WHERE user_id = ?");
        $upd->execute([$userId]);
        log_msg("  Sent digest to {$user['email']}.", $verbose);
        $sent++;
    } else {
        log_msg("  FAILED to send digest to {$user['email']}.", $verbose, true);
        $failed++;
    }
}

log_msg("Done. Sent: {$sent}, Skipped: {$skipped}, Failed: {$failed}", $verbose, true);
exit($failed > 0 ? 1 : 0);
