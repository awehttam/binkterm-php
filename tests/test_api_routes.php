<?php

echo "Testing API Routes via HTTP\n";
echo "===========================\n\n";

// Test routes by making internal HTTP requests
function testRoute($url) {
    $fullUrl = "http://localhost:8000" . $url; // Adjust port as needed
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\n",
            'timeout' => 10
        ]
    ]);
    
    echo "Testing: $url\n";
    
    $response = @file_get_contents($fullUrl, false, $context);
    
    if ($response === false) {
        $error = error_get_last();
        echo "  ✗ Failed: " . ($error['message'] ?? 'Unknown error') . "\n\n";
        return false;
    }
    
    $httpCode = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $header, $matches)) {
                $httpCode = intval($matches[1]);
                break;
            }
        }
    }
    
    echo "  Status Code: $httpCode\n";
    echo "  Response: " . substr($response, 0, 200) . "\n\n";
    
    return $httpCode === 200;
}

// Test the statistics endpoints
echo "1. Testing netmail stats endpoint:\n";
testRoute('/api/messages/netmail/stats');

echo "2. Testing echomail stats endpoint:\n";
testRoute('/api/messages/echomail/stats');

echo "3. Testing basic API endpoint:\n";
testRoute('/api/messages/netmail');

echo "4. Testing non-API route:\n";
testRoute('/login');

echo "Test completed.\n";