#!/usr/bin/env php
<?php
/**
 * Diagnostic: dump decoded ibbslastcall-data messages to inspect actual line format
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;

$db = Database::getInstance()->getPdo();

$stmt = $db->prepare("
    SELECT e.id, e.subject, e.from_name, e.message_text
    FROM echomail e
    JOIN echoareas ea ON ea.id = e.echoarea_id
    WHERE ea.tag ILIKE 'fsx_dat'
      AND e.subject ILIKE '%ibbslastcall-data%'
    ORDER BY e.id DESC
    LIMIT 5
");
$stmt->execute();
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($messages)) {
    echo "No ibbslastcall-data messages found in FSX_DAT.\n";
    exit(0);
}

function applyRot47(string $text): string {
    $result = '';
    $len = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $ord = ord($text[$i]);
        if ($ord >= 33 && $ord <= 126) {
            $result .= chr((($ord - 33 + 47) % 94) + 33);
        } else {
            $result .= $text[$i];
        }
    }
    return $result;
}

foreach ($messages as $msg) {
    echo "=== Message ID {$msg['id']} | From: {$msg['from_name']} ===\n";
    echo "--- RAW BODY (first 300 chars, hex for non-printable) ---\n";
    $raw = substr($msg['message_text'], 0, 300);
    $display = preg_replace_callback('/[\x00-\x1f\x7f]/', function($m) {
        return sprintf('[^%02X]', ord($m[0]));
    }, $raw);
    echo $display . "\n\n";

    echo "--- DECODED LINES ---\n";
    $decoded = applyRot47($msg['message_text']);
    $lines = explode("\n", $decoded);
    foreach ($lines as $i => $line) {
        $line = rtrim($line, "\r");
        $lineDisplay = preg_replace_callback('/[\x00-\x1f\x7f]/', function($m) {
            return sprintf('[^%02X]', ord($m[0]));
        }, $line);
        echo "  [{$i}]: {$lineDisplay}\n";
    }
    echo "\n";
}
