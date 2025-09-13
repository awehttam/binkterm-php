<?php

use BinktermPHP\AdminController;
use BinktermPHP\Auth;
use BinktermPHP\Template;
use Pecee\SimpleRouter\SimpleRouter;

SimpleRouter::group(['prefix' => '/admin'], function() {

    // Admin dashboard
    SimpleRouter::get('/', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        $adminController = new AdminController();
        $adminController->requireAdmin($user);

        $template = new Template();
        $stats = $adminController->getSystemStats();
        $template->renderResponse('admin/dashboard.twig', ['stats' => $stats]);
    });

    // Users management page
    SimpleRouter::get('/users', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        $adminController = new AdminController();
        $adminController->requireAdmin($user);

        $template = new Template();
        $template->renderResponse('admin/users.twig');
    });

    // API routes for admin
    SimpleRouter::group(['prefix' => '/api'], function() {

        // Get all users with pagination and search
        SimpleRouter::get('/users', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            $page = intval($_GET['page'] ?? 1);
            $limit = intval($_GET['limit'] ?? 25);
            $search = $_GET['search'] ?? '';

            header('Content-Type: application/json');
            $result = $adminController->getAllUsers($page, $limit, $search);
            echo json_encode($result);
        });

        // Get specific user
        SimpleRouter::get('/users/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $userData = $adminController->getUser($id);
            if ($userData) {
                $stats = $adminController->getUserStats($id);
                $userData['stats'] = $stats;
                echo json_encode(['user' => $userData]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
            }
        });

        // Create new user
        SimpleRouter::post('/users', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $userId = $adminController->createUser($input);
                echo json_encode(['success' => true, 'user_id' => $userId]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        // Update user
        SimpleRouter::put('/users/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $result = $adminController->updateUser($id, $input);
                echo json_encode(['success' => $result]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        // Delete user
        SimpleRouter::delete('/users/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $result = $adminController->deleteUser($id);
                echo json_encode(['success' => $result]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        // Get system stats
        SimpleRouter::get('/stats', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $stats = $adminController->getSystemStats();
            echo json_encode($stats);
        });
    });
});


// Admin subscription management page
SimpleRouter::get('/admin/subscriptions', function() {
    $auth = new Auth();
    $user = $auth->requireAuth();

    $adminController = new AdminController();
    $adminController->requireAdmin($user);

    $controller = new BinktermPHP\SubscriptionController();
    $data = $controller->renderAdminSubscriptionPage();

    // Only render template if we got data back (not redirected)
    if ($data !== null) {
        $template = new Template();
        $template->renderResponse('admin_subscriptions.twig', $data);
    }
});
