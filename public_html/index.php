<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\AdminController;
use BinktermPHP\AddressBookController;
use BinktermPHP\MessageHandler;
use BinktermPHP\SubscriptionController;


use BinktermPHP\Auth;
use BinktermPHP\Template;
use BinktermPHP\Database;
use Pecee\SimpleRouter\SimpleRouter;

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