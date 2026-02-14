<?php

// Nodelist routes
use BinktermPHP\ActivityTracker;
use BinktermPHP\Auth;
use Pecee\SimpleRouter\SimpleRouter;

SimpleRouter::get('/nodelist', function() {
    $controller = new BinktermPHP\Web\NodelistController();
    echo $controller->index($_GET['search'] ?? '', $_GET['zone'] ?? '', $_GET['net'] ?? '', (int)($_GET['page'] ?? 1));
});

SimpleRouter::get('/nodelist/view', function() {
    $controller = new BinktermPHP\Web\NodelistController();
    $address = $_GET['address'] ?? '';
    echo $controller->view($address);
});

SimpleRouter::get('/nodelist/import', function() {
    $controller = new BinktermPHP\Web\NodelistController();
    echo $controller->import();
});

SimpleRouter::post('/nodelist/import', function() {
    $controller = new BinktermPHP\Web\NodelistController();
    echo $controller->import();
});

// Nodelist API routes
SimpleRouter::group(['prefix' => '/api/nodelist'], function() {
    SimpleRouter::get('/search', function() {
        $controller = new BinktermPHP\Web\NodelistController();
        $controller->api('search');
        $auth = new Auth();
        $user = $auth->getCurrentUser();
        if ($user) {
            $userId = $user['user_id'] ?? $user['id'] ?? null;
            ActivityTracker::track($userId, ActivityTracker::TYPE_NODELIST_VIEW, null, $_GET['q'] ?? null);
        }
    });

    SimpleRouter::get('/node', function() {
        $controller = new BinktermPHP\Web\NodelistController();
        $controller->api('node');
        $auth = new Auth();
        $user = $auth->getCurrentUser();
        if ($user) {
            $userId = $user['user_id'] ?? $user['id'] ?? null;
            ActivityTracker::track($userId, ActivityTracker::TYPE_NODE_VIEW, null, $_GET['address'] ?? null);
        }
    });

    SimpleRouter::get('/zones', function() {
        $controller = new BinktermPHP\Web\NodelistController();
        $controller->api('zones');
    });

    SimpleRouter::get('/nets', function() {
        $controller = new BinktermPHP\Web\NodelistController();
        $controller->api('nets');
    });

    SimpleRouter::get('/stats', function() {
        $controller = new BinktermPHP\Web\NodelistController();
        $controller->api('stats');
    });
});
