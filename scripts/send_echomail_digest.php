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
 *   php scripts/send_echomail_digest.php [--dry-run] [--verbose] [--user=ID]
 *
 * Options:
 *   --dry-run    Show what would be sent without actually sending or updating
 *                last-sent timestamps.
 *   --verbose    Print per-user status to stdout.
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

$dryRun  = false;
$verbose = false;
$onlyUser = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run')   { $dryRun  = true; continue; }
    if ($arg === '--verbose')   { $verbose = true; continue; }
    if (str_starts_with($arg, '--user=')) {
        $onlyUser = (int) substr($arg, 7);
        continue;
    }
    if ($arg === '--help') {
        echo "Usage: php scripts/send_echomail_digest.php [--dry-run] [--verbose] [--user=ID]\n";
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
 * @param array  $areas      Array of ['tag'=>string, 'name'=>string, 'messages'=>[...]]
 * @param string $systemName BBS system name
 * @param string $siteUrl    Base URL of the BBS
 * @return string
 */
function buildPlainDigest(array $areas, string $systemName, string $siteUrl): string
{
    $date  = date('l, F j Y');
    $lines = [];

    $lines[] = "$systemName — Echomail Digest";
    $lines[] = $date;
    $lines[] = str_repeat('=', 60);
    $lines[] = '';

    foreach ($areas as $area) {
        $count   = count($area['messages']);
        $lines[] = $area['tag'] . ' — ' . $count . ' new message' . ($count === 1 ? '' : 's');
        $lines[] = str_repeat('-', 40);

        foreach ($area['messages'] as $msg) {
            $lines[] = '  ' . $msg['subject'] . '  (from ' . $msg['from_name'] . ')';
        }

        $lines[] = '  ' . $siteUrl . '/echomail/' . $area['echoarea_id'];
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
 */
function buildHtmlDigest(array $areas, string $systemName, string $siteUrl): string
{
    $date         = date('l, F j Y');
    $safeSystem   = htmlspecialchars($systemName, ENT_QUOTES, 'UTF-8');
    $safeDate     = htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
    $safeSiteUrl  = htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8');

    $areaHtml = '';
    foreach ($areas as $area) {
        $count       = count($area['messages']);
        $safeTag     = htmlspecialchars($area['tag'], ENT_QUOTES, 'UTF-8');
        $areaUrl     = $siteUrl . '/echomail/' . (int) $area['echoarea_id'];
        $safeAreaUrl = htmlspecialchars($areaUrl, ENT_QUOTES, 'UTF-8');

        $areaHtml .= '<div class="area">';
        $areaHtml .= '<h3><a href="' . $safeAreaUrl . '">' . $safeTag . '</a></h3>';
        $areaHtml .= '<p class="count">' . $count . ' new message' . ($count === 1 ? '' : 's') . '</p>';
        $areaHtml .= '<ul>';

        foreach ($area['messages'] as $msg) {
            $safeSubject = htmlspecialchars($msg['subject'] ?: '(no subject)', ENT_QUOTES, 'UTF-8');
            $safeFrom    = htmlspecialchars($msg['from_name'], ENT_QUOTES, 'UTF-8');
            $areaHtml   .= '<li><strong>' . $safeSubject . '</strong> &mdash; ' . $safeFrom . '</li>';
        }

        $areaHtml .= '</ul>';
        $areaHtml .= '<p><a href="' . $safeAreaUrl . '">Read in ' . $safeTag . ' &rarr;</a></p>';
        $areaHtml .= '</div>';
    }

    return <<<HTML
    <html>
    <head>
        <title>Echomail Digest — {$safeSystem}</title>
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
        </style>
    </head>
    <body>
        <div class="header">
            <h1>{$safeSystem} — Echomail Digest</h1>
            <p>{$safeDate}</p>
        </div>
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
    SELECT u.id, u.email, u.username,
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

    if (!isDue($frequency, $lastSent)) {
        log_msg("User {$userId} ({$user['username']}): not due yet, skipping.", $verbose);
        $skipped++;
        continue;
    }

    // Determine the window: messages received since last digest (or all-time if never sent)
    $since = $lastSent ?? '1970-01-01 00:00:00';

    // Fetch new echomail in subscribed areas
    $msgStmt = $db->prepare("
        SELECT e.id AS echoarea_id, e.tag, e.description AS name,
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

    if (empty($rows)) {
        log_msg("User {$userId} ({$user['username']}): no new messages, skipping.", $verbose);
        // Still update last_sent so we don't keep checking every run
        if (!$dryRun) {
            $upd = $db->prepare("UPDATE user_settings SET echomail_digest_last_sent = NOW() WHERE user_id = ?");
            $upd->execute([$userId]);
        }
        $skipped++;
        continue;
    }

    // Group by echo area, cap per-area message list at 50 to keep emails manageable
    $areas = [];
    foreach ($rows as $row) {
        $eid = (int) $row['echoarea_id'];
        if (!isset($areas[$eid])) {
            $areas[$eid] = [
                'echoarea_id' => $eid,
                'tag'         => $row['tag'],
                'name'        => $row['name'],
                'messages'    => [],
            ];
        }
        if (count($areas[$eid]['messages']) < 50) {
            $areas[$eid]['messages'][] = [
                'subject'   => $row['subject'],
                'from_name' => $row['from_name'],
            ];
        }
    }

    $totalMessages = count($rows);
    $totalAreas    = count($areas);

    log_msg("User {$userId} ({$user['username']}): {$totalMessages} message(s) across {$totalAreas} area(s).", $verbose);

    if ($dryRun) {
        log_msg("  [dry-run] Would send digest to {$user['email']}", $verbose, true);
        $sent++;
        continue;
    }

    $subject   = "Echomail Digest — {$systemName} — " . date('Y-m-d');
    $plainText = buildPlainDigest(array_values($areas), $systemName, $siteUrl);
    $htmlBody  = buildHtmlDigest(array_values($areas), $systemName, $siteUrl);

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
