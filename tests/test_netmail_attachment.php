#!/usr/bin/env php
<?php

/**
 * Test script to create a netmail with file attachment
 * This creates a test file and netmail packet, then processes it
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\BinkdProcessor;

// Initialize database
Database::getInstance();

echo "Creating test netmail with file attachment...\n\n";

// Create test file in inbound directory
$inboundPath = __DIR__ . '/../data/inbound';
if (!is_dir($inboundPath)) {
    mkdir($inboundPath, 0755, true);
}

$testFilename = 'test_attachment.txt';
$testFilePath = $inboundPath . '/' . $testFilename;
$testContent = "This is a test file attachment sent via FidoNet netmail.\n\n";
$testContent .= "Timestamp: " . date('Y-m-d H:i:s') . "\n";
$testContent .= "From: awehttam@1:153/150\n";
$testContent .= "To: Matthew Asham@1:153/149.57599\n\n";
$testContent .= "This file should be automatically stored in the recipient's private file area.\n";

file_put_contents($testFilePath, $testContent);
echo "✓ Created test file: $testFilename (" . filesize($testFilePath) . " bytes)\n";

// Create netmail message data
$message = [
    'from_name' => 'Matt Henderson',
    'from_address' => '1:153/150',
    'to_name' => 'Matthew Asham',
    'to_address' => '1:153/149.57599',
    'subject' => $testFilename,  // FidoNet standard: subject contains filename
    'message_text' => "Hello Matthew,\n\nThis is a test netmail message with a file attachment.\n\nThe attached file should appear in your private file area and be linked to this message.\n\nPlease verify that the file was received correctly.\n\nRegards,\nMatt",
    'date_written' => date('Y-m-d H:i:s'),
    'attributes' => 0x0011,  // 0x0001 (Private) + 0x0010 (File Attach)
];

// Create packet using BinkdProcessor
$processor = new BinkdProcessor();

try {
    // Create packet in inbound directory (simulating incoming packet)
    $packetPath = $inboundPath . '/test_' . time() . '.pkt';
    $processor->createOutboundPacket([$message], '1:153/149.57599', $packetPath);
    echo "✓ Created test packet: " . basename($packetPath) . "\n\n";

    // Process the packet
    echo "Processing packet...\n";
    $result = $processor->processPacket($packetPath);

    if ($result) {
        echo "✓ Packet processed successfully\n\n";

        // Check if file was stored
        $db = Database::getInstance()->getPdo();
        $stmt = $db->prepare("
            SELECT n.id, n.subject, n.from_name, n.to_name, n.attributes,
                   f.id as file_id, f.filename, f.filesize, f.message_id
            FROM netmail n
            LEFT JOIN files f ON (f.message_id = n.id AND f.message_type = 'netmail')
            WHERE n.subject = ?
            ORDER BY n.id DESC
            LIMIT 1
        ");
        $stmt->execute([$testFilename]);
        $record = $stmt->fetch();

        if ($record) {
            echo "Netmail Record:\n";
            echo "  ID: {$record['id']}\n";
            echo "  From: {$record['from_name']}\n";
            echo "  To: {$record['to_name']}\n";
            echo "  Subject: {$record['subject']}\n";
            echo "  Attributes: 0x" . sprintf('%04X', $record['attributes']) . "\n";

            if ($record['file_id']) {
                echo "\n✓ File Attachment Found:\n";
                echo "  File ID: {$record['file_id']}\n";
                echo "  Filename: {$record['filename']}\n";
                echo "  Size: {$record['filesize']} bytes\n";
                echo "  Linked to Message ID: {$record['message_id']}\n";

                // Check if file exists in storage
                $fileStmt = $db->prepare("SELECT storage_path FROM files WHERE id = ?");
                $fileStmt->execute([$record['file_id']]);
                $fileRecord = $fileStmt->fetch();

                if ($fileRecord && file_exists($fileRecord['storage_path'])) {
                    echo "  Storage Path: {$fileRecord['storage_path']}\n";
                    echo "  ✓ File exists in storage\n";
                } else {
                    echo "  ✗ WARNING: File not found in storage\n";
                }
            } else {
                echo "\n✗ WARNING: No file attachment found in database\n";
                echo "  This may indicate the file attachment processing failed.\n";
            }
        } else {
            echo "✗ ERROR: Netmail record not found in database\n";
        }

        // Clean up packet file
        if (file_exists($packetPath)) {
            unlink($packetPath);
            echo "\n✓ Cleaned up test packet\n";
        }

    } else {
        echo "✗ ERROR: Packet processing failed\n";
    }

} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\nTest complete!\n";
