#!/usr/bin/env php
<?php

declare(strict_types=1);

use BinktermPHP\Database;
use BinktermPHP\MessageCharsetConverter;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

function usage(): void
{
    echo <<<TXT
Usage:
  php scripts/rebuild_echomail_message_text.php --domain=<domain>
  php scripts/rebuild_echomail_message_text.php --echoarea=<TAG[@domain]>
  php scripts/rebuild_echomail_message_text.php --echoarea=<TAG> --domain=<domain>

Options:
  --domain=<domain>       Limit to one network domain.
  --echoarea=<tag[@dom]>  Limit to one echo area tag, optionally with @domain.
  --charset=<charset>     Force one charset for all selected rows.
  --dry-run               Show what would change without writing.
  --help                  Show this help text.
TXT;
}

function extractChrsCharset(?string $kludgeLines): ?string
{
    if (!is_string($kludgeLines) || $kludgeLines === '') {
        return null;
    }

    if (preg_match('/(?:^|\n)\x01CHRS:\s*([A-Za-z0-9_\-]+)/i', $kludgeLines, $matches)) {
        return MessageCharsetConverter::normalizeDecodableCharset($matches[1]);
    }

    return null;
}

$options = getopt('', ['domain:', 'echoarea:', 'charset:', 'dry-run', 'help']);
if (isset($options['help'])) {
    usage();
    exit(0);
}

$domainFilter = isset($options['domain']) ? strtolower(trim((string)$options['domain'])) : '';
$echoareaInput = isset($options['echoarea']) ? trim((string)$options['echoarea']) : '';
$forcedCharset = MessageCharsetConverter::normalizeSupportedCharset($options['charset'] ?? null);
$dryRun = array_key_exists('dry-run', $options);

if (isset($options['charset']) && trim((string)$options['charset']) !== '' && $forcedCharset === null) {
    fwrite(STDERR, "Invalid charset.\n");
    exit(1);
}

$echoareaTag = '';
if ($echoareaInput !== '') {
    $parts = explode('@', $echoareaInput, 2);
    $echoareaTag = strtoupper(trim($parts[0] ?? ''));
    if ($echoareaTag === '') {
        fwrite(STDERR, "Invalid echo area selector.\n");
        exit(1);
    }
    if ($domainFilter === '' && isset($parts[1])) {
        $domainFilter = strtolower(trim($parts[1]));
    }
}

if ($domainFilter === '' && $echoareaTag === '') {
    fwrite(STDERR, "Specify --domain or --echoarea.\n");
    usage();
    exit(1);
}

$db = Database::getInstance()->getPdo();
$sql = "
    SELECT em.id,
           em.message_text,
           em.raw_message_bytes,
           em.message_charset,
           em.kludge_lines,
           ea.tag,
           ea.domain,
           ea.missing_chrs_charset AS area_missing_chrs_charset,
           n.missing_chrs_charset AS network_missing_chrs_charset
    FROM echomail em
    INNER JOIN echoareas ea ON ea.id = em.echoarea_id
    LEFT JOIN networks n ON LOWER(n.domain) = LOWER(ea.domain)
    WHERE 1=1
";
$params = [];

if ($domainFilter !== '') {
    $sql .= " AND LOWER(COALESCE(ea.domain, '')) = LOWER(?)";
    $params[] = $domainFilter;
}
if ($echoareaTag !== '') {
    $sql .= " AND UPPER(ea.tag) = UPPER(?)";
    $params[] = $echoareaTag;
}

$sql .= " ORDER BY LOWER(COALESCE(ea.domain, '')), UPPER(ea.tag), em.id";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($rows === []) {
    echo "No matching echomail rows found.\n";
    exit(0);
}

$updateStmt = $db->prepare('UPDATE echomail SET message_text = ?, message_charset = ? WHERE id = ?');
$updated = 0;
$unchanged = 0;
$skipped = 0;

foreach ($rows as $row) {
    $rowId = (int)$row['id'];
    $rawBytes = MessageCharsetConverter::normalizeRawBytes($row['raw_message_bytes'] ?? null);
    if ($rawBytes === '') {
        $skipped++;
        echo "[skip] id={$rowId} {$row['tag']}@" . ($row['domain'] ?? '') . " has no raw_message_bytes\n";
        continue;
    }

    $targetCharset = $forcedCharset
        ?? extractChrsCharset($row['kludge_lines'] ?? null)
        ?? MessageCharsetConverter::normalizeSupportedCharset($row['area_missing_chrs_charset'] ?? null)
        ?? MessageCharsetConverter::normalizeSupportedCharset($row['network_missing_chrs_charset'] ?? null)
        ?? MessageCharsetConverter::normalizeSupportedCharset($row['message_charset'] ?? null);

    if ($targetCharset === null) {
        $skipped++;
        echo "[skip] id={$rowId} {$row['tag']}@" . ($row['domain'] ?? '') . " has no resolvable charset\n";
        continue;
    }

    $decodedText = MessageCharsetConverter::decodeStoredMessageBytes($rawBytes, $targetCharset);
    if ($decodedText === null) {
        $skipped++;
        echo "[skip] id={$rowId} {$row['tag']}@" . ($row['domain'] ?? '') . " could not be decoded\n";
        continue;
    }

    $currentCharset = MessageCharsetConverter::normalizeSupportedCharset($row['message_charset'] ?? null);
    $currentText = (string)($row['message_text'] ?? '');
    if ($currentText === $decodedText && $currentCharset === $targetCharset) {
        $unchanged++;
        continue;
    }

    echo sprintf(
        "[%s] id=%d %s@%s charset=%s\n",
        $dryRun ? 'dry-run' : 'update',
        $rowId,
        $row['tag'],
        $row['domain'] ?? '',
        $targetCharset
    );

    if (!$dryRun) {
        $updateStmt->execute([$decodedText, $targetCharset, $rowId]);
    }
    $updated++;
}

echo "\nSummary:\n";
echo "  matched:   " . count($rows) . "\n";
echo "  updated:   {$updated}\n";
echo "  unchanged: {$unchanged}\n";
echo "  skipped:   {$skipped}\n";
