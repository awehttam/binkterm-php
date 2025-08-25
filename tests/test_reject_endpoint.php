<?php

require_once __DIR__ . '/../vendor/autoload.php';

echo "Testing Reject API Endpoint\n";
echo "===========================\n\n";

// Test the exact API call that's failing
$url = 'http://localhost:1244/api/admin/pending-users/2/reject';
$postData = ['notes' => 'Test rejection from API'];

echo "Testing POST to: $url\n";
echo "Post data: " . json_encode($postData) . "\n\n";

// Initialize cURL
$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded'
]);

// Add cookie if we have a session (you'd need to get this from browser)
// curl_setopt($ch, CURLOPT_COOKIE, 'binktermphp_session=your_session_id_here');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

$headers = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);

echo "HTTP Status Code: $httpCode\n";
echo "Response Headers:\n" . $headers . "\n";
echo "Response Body:\n" . $body . "\n";

curl_close($ch);

echo "Test completed.\n";