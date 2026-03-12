#!/usr/bin/env php
<?php

/**
 * regenerate_packet.php — Regenerate an outbound echomail packet from the database.
 *
 * Useful for re-sending a message that was lost or misrouted by an upstream.
 * The regenerated packet is written to data/outbound/regenerated/ and is NOT
 * automatically polled — review it first, then move it to data/outbound/ or
 * send it manually.
 *
 * Usage:
 *   php scripts/regenerate_packet.php --id=<echomail_id>
 *   php scripts/regenerate_packet.php --msgid=<MSGID>
 *
 * Options:
 *   --id=N       Database row ID from the echomail table
 *   --msgid=STR  Full or partial MSGID kludge value (e.g. "1:154/10 AB12CD34")
 *   --dry-run    Print message details without writing a packet
 */

chdir(__DIR__);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\BinkdProcessor;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Database;

// ---------------------------------------------------------------------------
// Parse arguments
// ---------------------------------------------------------------------------

$opts = getopt('', ['id:', 'msgid:', 'dry-run']);

$echoId  = isset($opts['id'])    ? (int)$opts['id']        : null;
$msgid   = isset($opts['msgid']) ? trim($opts['msgid'])     : null;
$dryRun  = array_key_exists('dry-run', $opts);

if (!$echoId && !$msgid) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php scripts/regenerate_packet.php --id=<echomail_id>\n");
    fwrite(STDERR, "  php scripts/regenerate_packet.php --msgid=<MSGID>\n");
    fwrite(STDERR, "  Add --dry-run to inspect without writing a packet.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Database lookup
// ---------------------------------------------------------------------------

$db = Database::getInstance()->getPdo();

if ($echoId) {
    $stmt = $db->prepare("
        SELECT em.*, ea.tag AS echoarea_tag, ea.domain AS echoarea_domain,
               ea.uplink_address, ea.is_local
        FROM echomail em
        JOIN echoareas ea ON em.echoarea_id = ea.id
        WHERE em.id = ?
    ");
    $stmt->execute([$echoId]);
} else {
    // MSGID is stored as the full value after ^AMSGID: (e.g. "1:154/10 AB12CD34")
    // Allow partial match so the user can pass just the hash portion
    $stmt = $db->prepare("
        SELECT em.*, ea.tag AS echoarea_tag, ea.domain AS echoarea_domain,
               ea.uplink_address, ea.is_local
        FROM echomail em
        JOIN echoareas ea ON em.echoarea_id = ea.id
        WHERE em.message_id LIKE ?
        ORDER BY em.id DESC
        LIMIT 5
    ");
    $stmt->execute(['%' . $msgid . '%']);
}

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    fwrite(STDERR, "No echomail message found.\n");
    exit(1);
}

if (count($rows) > 1) {
    echo "Multiple messages matched — please refine your query or use --id:\n\n";
    foreach ($rows as $r) {
        printf("  id=%-6d  area=%-20s  from=%-20s  subject=%s\n",
            $r['id'],
            $r['echoarea_tag'],
            $r['from_name'] . ' <' . $r['from_address'] . '>',
            $r['subject']
        );
    }
    exit(1);
}

$message = $rows[0];

// ---------------------------------------------------------------------------
// Print message details
// ---------------------------------------------------------------------------

echo "\n";
echo "Message details:\n";
echo "  ID          : " . $message['id'] . "\n";
echo "  Area        : " . $message['echoarea_tag'] . "@" . $message['echoarea_domain'] . "\n";
echo "  From        : " . $message['from_name'] . " <" . $message['from_address'] . ">\n";
echo "  To          : " . $message['to_name'] . "\n";
echo "  Subject     : " . $message['subject'] . "\n";
echo "  Date written: " . $message['date_written'] . "\n";
echo "  MSGID       : " . $message['message_id'] . "\n";
echo "  Local area  : " . ($message['is_local'] ? 'yes' : 'no') . "\n";
echo "\n";

if ($message['is_local']) {
    fwrite(STDERR, "Error: This message is in a local-only echo area and was never sent to any uplink.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Resolve uplink address
// ---------------------------------------------------------------------------

$domain      = $message['echoarea_domain'] ?? '';
$uplinkAddress = $message['uplink_address'] ?? null;

if (!$uplinkAddress && $domain) {
    try {
        $binkpConfig   = BinkpConfig::getInstance();
        $uplinkAddress = $binkpConfig->getUplinkAddressForDomain($domain);
    } catch (\Exception $e) {
        // fall through
    }
}

if (!$uplinkAddress) {
    fwrite(STDERR, "Error: Cannot determine uplink address for area " . $message['echoarea_tag'] . "@{$domain}.\n");
    fwrite(STDERR, "Check that the domain is configured in BinkP settings.\n");
    exit(1);
}

echo "  Uplink      : " . $uplinkAddress . "\n\n";

if ($dryRun) {
    echo "[dry-run] No packet written.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Build output path
// ---------------------------------------------------------------------------

$regeneratedDir = __DIR__ . '/../data/outbound/regenerated';
if (!is_dir($regeneratedDir) && !mkdir($regeneratedDir, 0755, true)) {
    fwrite(STDERR, "Error: Cannot create directory: {$regeneratedDir}\n");
    exit(1);
}

$packetFilename = 'regen_' . $message['id'] . '_' . substr(uniqid(), -6) . '.pkt';
$outputPath     = $regeneratedDir . '/' . $packetFilename;

// ---------------------------------------------------------------------------
// Assemble message array for createOutboundPacket
// ---------------------------------------------------------------------------

$message['attributes']      = 0x0000;
$message['is_echomail']     = true;
$message['to_address']      = $uplinkAddress;
// echoarea_tag and echoarea_domain are already set from the JOIN

// ---------------------------------------------------------------------------
// Write packet
// ---------------------------------------------------------------------------

try {
    $processor = new BinkdProcessor();
    $processor->createOutboundPacket([$message], $uplinkAddress, $outputPath);
} catch (\Exception $e) {
    fwrite(STDERR, "Error creating packet: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Packet written to: data/outbound/regenerated/{$packetFilename}\n";
echo "\n";
echo "To send it, move it to data/outbound/ and trigger a poll:\n";
echo "  mv data/outbound/regenerated/{$packetFilename} data/outbound/\n";
echo "  php scripts/binkp_poll.php\n";
echo "\n";
