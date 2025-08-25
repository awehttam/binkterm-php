<?php

// Simple test for the delete API
$url = 'http://localhost:8080/api/messages/echomail/delete';

// Get a test message ID first
require_once __DIR__ . '/../vendor/autoload.php';
use BinktermPHP\Database;

Database::getInstance();
$db = Database::getInstance()->getPdo();
$stmt = $db->query("SELECT id FROM echomail LIMIT 1");
$message = $stmt->fetch();

if (!$message) {
    echo "No messages found to test with\n";
    exit(1);
}

$messageId = $message['id'];
echo "Testing with message ID: $messageId\n";

// Prepare test data
$data = json_encode([
    'messageIds' => [$messageId]
]);

echo "Request data: $data\n";

// Make the API call
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($data)
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_COOKIE, 'binktermphp_session=' . ($_COOKIE['binktermphp_session'] ?? 'none'));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";