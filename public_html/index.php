<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\AdminController;
use BinktermPHP\AddressBookController;
use BinktermPHP\MessageHandler;
use BinktermPHP\SubscriptionController;


use BinktermPHP\Auth;
use BinktermPHP\Template;
use BinktermPHP\Database;
use BinktermPHP\Config;
use Pecee\SimpleRouter\SimpleRouter;

// Optional request profiling (logs slow requests)
$requestStart = microtime(true);
$profilingEnabled = Config::env('PERF_LOG_ENABLED', 'false') === 'true';
$slowThresholdMs = (int) Config::env('PERF_LOG_SLOW_MS', '500');
if ($profilingEnabled) {
    register_shutdown_function(function () use ($requestStart, $slowThresholdMs) {
        $durationMs = (microtime(true) - $requestStart) * 1000;
        if ($durationMs < $slowThresholdMs) {
            return;
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $status = http_response_code();
        $memoryMb = memory_get_peak_usage(true) / 1024 / 1024;
        error_log(sprintf(
            '[PERF] %s %s -> %d in %.1fms (%.1fMB peak)',
            $method,
            $uri,
            $status,
            $durationMs,
            $memoryMb
        ));
    });
}

// Initialize database
Database::getInstance();

// Start session for auth cookies
if (!headers_sent()) {
    session_start();
}

// Clean expired sessions periodically
if (rand(1, 100) <= 5) { // 5% chance
    $auth = new Auth();
    $auth->cleanExpiredSessions();
}

require_once __DIR__."/../src/functions.php";
require_once __DIR__."/../routes/web-routes.php";
require_once __DIR__."/../routes/webdoor-routes.php";
require_once __DIR__."/../routes/api-routes.php";
require_once __DIR__."/../routes/admin-routes.php";
require_once __DIR__."/../routes/nodelist-routes.php";
require_once __DIR__."/../routes/door-routes.php";


try {
// Start router
    SimpleRouter::start();
} catch (\Pecee\SimpleRouter\Exceptions\NotFoundHttpException $ex){
    http_response_code(404);
    
    // Use the pretty 404 template instead of plain text
    $template = new Template();
    $requestedUrl = $_SERVER['REQUEST_URI'] ?? '';
    $template->renderResponse('404.twig', [
        'requested_url' => htmlspecialchars($requestedUrl)
    ]);
}
