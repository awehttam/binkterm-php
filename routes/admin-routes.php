<?php

use BinktermPHP\AdminActionLogger;
use BinktermPHP\AdminController;
use BinktermPHP\Auth;
use BinktermPHP\Template;
use BinktermPHP\UserMeta;
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
        $dbVersion = $adminController->getDatabaseVersion();
        $config = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $systemAddresses = [$config->getSystemAddress()];
        foreach ($config->getUplinks() as $uplink) {
            if (!empty($uplink['me'])) {
                $systemAddresses[] = $uplink['me'];
            }
        }
        $systemAddresses = array_values(array_unique(array_filter($systemAddresses)));
        $template->renderResponse('admin/dashboard.twig', [
            'stats' => $stats,
            'db_version' => $dbVersion,
            'daemon_status' => \BinktermPHP\SystemStatus::getDaemonStatus(),
            'git_commit' => \BinktermPHP\SystemStatus::getGitCommitHash(),
            'system_addresses' => $systemAddresses
        ]);
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

    // Chat rooms management page
    SimpleRouter::get('/chat-rooms', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        $adminController = new AdminController();
        $adminController->requireAdmin($user);

        $template = new Template();
        $template->renderResponse('admin/chat_rooms.twig');
    });

    // Polls management page
    SimpleRouter::get('/polls', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        $adminController = new AdminController();
        $adminController->requireAdmin($user);

        $template = new Template();
        $template->renderResponse('admin/polls.twig');
    });

    // Shoutbox moderation page
    SimpleRouter::get('/shoutbox', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        $adminController = new AdminController();
        $adminController->requireAdmin($user);

        $template = new Template();
        $template->renderResponse('admin/shoutbox.twig');
    });

    // Binkp configuration page
    SimpleRouter::get('/binkp-config', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        $adminController = new AdminController();
        $adminController->requireAdmin($user);

        $template = new Template();
        $template->renderResponse('admin/binkp_config.twig', [
            'timezone_list' => \DateTimeZone::listIdentifiers()
        ]);
    });

    // Webdoors config page
    SimpleRouter::get('/webdoors', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        $adminController = new AdminController();
        $adminController->requireAdmin($user);

        $template = new Template();
        $template->renderResponse('admin/webdoors_config.twig');
    });

    // Advertisements management page
    SimpleRouter::get('/ads', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        $adminController = new AdminController();
        $adminController->requireAdmin($user);

        $template = new Template();
        $template->renderResponse('admin/ads.twig');
    });

    // BBS settings page
    SimpleRouter::get('/bbs-settings', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        $adminController = new AdminController();
        $adminController->requireAdmin($user);

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $csrfToken = bin2hex(random_bytes(32));
        if ($userId) {
            $meta = new UserMeta();
            $meta->setValue((int)$userId, 'csrf_bbs_settings', $csrfToken);
        }

        $template = new Template();
        $template->renderResponse('admin/bbs_settings.twig', [
            'timezone_list' => \DateTimeZone::listIdentifiers(),
            'bbs_settings_csrf' => $csrfToken
        ]);
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

        SimpleRouter::get('/admin-users', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            $db = \BinktermPHP\Database::getInstance()->getPdo();
            $stmt = $db->prepare("SELECT real_name FROM users WHERE is_admin = TRUE AND real_name IS NOT NULL ORDER BY real_name");
            $stmt->execute();
            $admins = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            echo json_encode(['admins' => $admins]);
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

        // Chat rooms
        SimpleRouter::get('/chat-rooms', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            $db = \BinktermPHP\Database::getInstance()->getPdo();
            $stmt = $db->prepare("
                SELECT id, name, description, is_active, created_at
                FROM chat_rooms
                ORDER BY name
            ");
            $stmt->execute();
            $rooms = $stmt->fetchAll();

            echo json_encode(['rooms' => $rooms]);
        });

        // Polls
        SimpleRouter::get('/polls', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $db = \BinktermPHP\Database::getInstance()->getPdo();

            $pollStmt = $db->prepare("
                SELECT p.id, p.question, p.is_active, p.created_at, p.updated_at,
                       COUNT(v.id) as vote_count
                FROM polls p
                LEFT JOIN poll_votes v ON v.poll_id = p.id
                GROUP BY p.id
                ORDER BY p.created_at DESC
            ");
            $pollStmt->execute();
            $polls = $pollStmt->fetchAll();

            $optionsStmt = $db->prepare("
                SELECT id, poll_id, option_text, sort_order
                FROM poll_options
                ORDER BY sort_order, id
            ");
            $optionsStmt->execute();
            $options = $optionsStmt->fetchAll();
            $optionsByPoll = [];
            foreach ($options as $opt) {
                $optionsByPoll[$opt['poll_id']][] = [
                    'id' => (int)$opt['id'],
                    'option_text' => $opt['option_text'],
                    'sort_order' => (int)$opt['sort_order']
                ];
            }

            $payload = [];
            foreach ($polls as $poll) {
                $payload[] = [
                    'id' => (int)$poll['id'],
                    'question' => $poll['question'],
                    'is_active' => (bool)$poll['is_active'],
                    'created_at' => $poll['created_at'],
                    'updated_at' => $poll['updated_at'],
                    'vote_count' => (int)$poll['vote_count'],
                    'options' => $optionsByPoll[$poll['id']] ?? []
                ];
            }

            echo json_encode(['polls' => $payload]);
        });

        SimpleRouter::post('/polls', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $question = trim($input['question'] ?? '');
                $options = $input['options'] ?? [];
                $isActive = !empty($input['is_active']);

                if ($question === '') {
                    throw new Exception('Question is required');
                }
                if (!is_array($options) || count($options) < 2) {
                    throw new Exception('At least two options are required');
                }

                $db = \BinktermPHP\Database::getInstance()->getPdo();
                $db->beginTransaction();

                $pollStmt = $db->prepare("
                    INSERT INTO polls (question, is_active, created_by)
                    VALUES (?, ?, ?)
                    RETURNING id
                ");
                $pollStmt->execute([$question, $isActive ? 1 : 0, $user['id'] ?? $user['user_id']]);
                $pollId = $pollStmt->fetchColumn();

                $optStmt = $db->prepare("
                    INSERT INTO poll_options (poll_id, option_text, sort_order)
                    VALUES (?, ?, ?)
                ");
                $order = 0;
                foreach ($options as $optionText) {
                    $optionText = trim($optionText);
                    if ($optionText === '') {
                        continue;
                    }
                    $optStmt->execute([$pollId, $optionText, $order++]);
                }
                if ($order < 2) {
                    throw new Exception('At least two valid options are required');
                }

                $db->commit();
                echo json_encode(['success' => true, 'id' => (int)$pollId]);
            } catch (Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::put('/polls/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $question = trim($input['question'] ?? '');
                $options = $input['options'] ?? [];
                $isActive = !empty($input['is_active']);

                if ($question === '') {
                    throw new Exception('Question is required');
                }
                if (!is_array($options) || count($options) < 2) {
                    throw new Exception('At least two options are required');
                }

                $db = \BinktermPHP\Database::getInstance()->getPdo();
                $db->beginTransaction();

                $existingStmt = $db->prepare("SELECT id FROM polls WHERE id = ?");
                $existingStmt->execute([$id]);
                if (!$existingStmt->fetch()) {
                    throw new Exception('Poll not found');
                }

                $updateStmt = $db->prepare("
                    UPDATE polls
                    SET question = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$question, $isActive ? 1 : 0, $id]);

                $db->prepare("DELETE FROM poll_votes WHERE poll_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM poll_options WHERE poll_id = ?")->execute([$id]);

                $optStmt = $db->prepare("
                    INSERT INTO poll_options (poll_id, option_text, sort_order)
                    VALUES (?, ?, ?)
                ");
                $order = 0;
                foreach ($options as $optionText) {
                    $optionText = trim($optionText);
                    if ($optionText === '') {
                        continue;
                    }
                    $optStmt->execute([$id, $optionText, $order++]);
                }
                if ($order < 2) {
                    throw new Exception('At least two valid options are required');
                }

                $db->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::delete('/polls/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $db = \BinktermPHP\Database::getInstance()->getPdo();
                $stmt = $db->prepare("DELETE FROM polls WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        // Shoutbox moderation
        SimpleRouter::get('/shoutbox', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $limit = intval($_GET['limit'] ?? 100);
            $messages = $adminController->getShoutboxMessages($limit);
            echo json_encode(['messages' => $messages]);
        });

        SimpleRouter::post('/shoutbox/{id}/hide', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $result = $adminController->setShoutboxHidden((int)$id, true);
            echo json_encode(['success' => $result]);
        });

        SimpleRouter::post('/shoutbox/{id}/unhide', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $result = $adminController->setShoutboxHidden((int)$id, false);
            echo json_encode(['success' => $result]);
        });

        SimpleRouter::delete('/shoutbox/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $result = $adminController->deleteShoutboxMessage((int)$id);
            echo json_encode(['success' => $result]);
        });

        // BBS settings
        SimpleRouter::get('/bbs-settings', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $config = $client->getBbsConfig();
                echo json_encode(['success' => true, 'config' => $config]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/bbs-settings', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
                $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                $meta = new UserMeta();
                $expectedToken = $userId ? $meta->getValue($userId, 'csrf_bbs_settings') : null;
                if (!$expectedToken || !hash_equals($expectedToken, (string)$csrfToken)) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Invalid CSRF token']);
                    return;
                }

                $payload = json_decode(file_get_contents('php://input'), true);
                $config = $payload['config'] ?? [];
                if (!is_array($config)) {
                    throw new Exception('Invalid configuration payload');
                }

                if (array_key_exists('credits', $config)) {
                    if (!is_array($config['credits'])) {
                        throw new Exception('Invalid credits configuration');
                    }
                    $credits = $config['credits'];
                    $symbol = trim((string)($credits['symbol'] ?? ''));
                    if ($symbol === '' || mb_strlen($symbol) > 5) {
                        throw new Exception('Currency symbol must be 1-5 characters');
                    }
                    if (!is_numeric($credits['daily_amount'] ?? null) || (int)$credits['daily_amount'] < 0) {
                        throw new Exception('Daily login amount must be a non-negative integer');
                    }
                    if (!is_numeric($credits['daily_login_delay_minutes'] ?? null) || (int)$credits['daily_login_delay_minutes'] < 0) {
                        throw new Exception('Daily login delay must be a non-negative integer');
                    }
                    if (!is_numeric($credits['approval_bonus'] ?? null) || (int)$credits['approval_bonus'] < 0) {
                        throw new Exception('Approval bonus must be a non-negative integer');
                    }
                    $config['credits'] = [
                        'enabled' => !empty($credits['enabled']),
                        'symbol' => $symbol,
                        'daily_amount' => (int)$credits['daily_amount'],
                        'daily_login_delay_minutes' => (int)$credits['daily_login_delay_minutes'],
                        'approval_bonus' => (int)$credits['approval_bonus']
                    ];
                }

                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $updated = $client->setBbsConfig($config);
                if ($userId) {
                    AdminActionLogger::logAction($userId, 'bbs_settings_updated', [
                        'credits' => $config['credits'] ?? null
                    ]);
                }
                echo json_encode(['success' => true, 'config' => $updated]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::get('/bbs-system', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $config = $client->getSystemConfig();
                echo json_encode(['success' => true, 'config' => $config]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/bbs-system', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                $config = $payload['config'] ?? [];
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $updated = $client->setSystemConfig($config);
                echo json_encode(['success' => true, 'config' => $updated]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::get('/binkp-config', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $config = $client->getFullBinkpConfig();
                echo json_encode(['success' => true, 'config' => $config]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/binkp-config', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                $config = $payload['config'] ?? [];
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $updated = $client->setFullBinkpConfig($config);
                echo json_encode(['success' => true, 'config' => $updated]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::get('/webdoors-config', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $config = $client->getWebdoorsConfig();
                echo json_encode(['success' => true, 'config' => $config]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/webdoors-config', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                $json = $payload['json'] ?? '';
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $updated = $client->saveWebdoorsConfig((string)$json);
                echo json_encode(['success' => true, 'config' => $updated]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/webdoors-activate', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $updated = $client->activateWebdoorsConfig();
                echo json_encode(['success' => true, 'config' => $updated]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        // Advertisements
        SimpleRouter::get('/ads', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $ads = $client->listAds();
                echo json_encode(['ads' => $ads]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/ads/upload', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            if (!isset($_FILES['ad_file'])) {
                http_response_code(400);
                echo json_encode(['error' => 'No file uploaded']);
                return;
            }

            $file = $_FILES['ad_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['error' => 'Upload failed']);
                return;
            }

            $maxSize = 1024 * 1024;
            if (!empty($file['size']) && $file['size'] > $maxSize) {
                http_response_code(400);
                echo json_encode(['error' => 'File is too large (max 1MB)']);
                return;
            }

            $content = @file_get_contents($file['tmp_name']);
            if ($content === false) {
                http_response_code(400);
                echo json_encode(['error' => 'Failed to read upload']);
                return;
            }

            $name = trim((string)($_POST['name'] ?? ''));

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $ad = $client->uploadAd(base64_encode($content), $name, $file['name'] ?? '');
                echo json_encode(['success' => true, 'ad' => $ad]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::delete('/ads/{name}', function($name) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $client->deleteAd($name);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        })->where(['name' => '[A-Za-z0-9._-]+']);


        SimpleRouter::post('/chat-rooms', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $name = trim($input['name'] ?? '');
                $description = trim($input['description'] ?? '');
                $isActive = !empty($input['is_active']);

                if ($name === '' || strlen($name) > 64) {
                    throw new Exception('Room name must be 1-64 characters');
                }

                $db = \BinktermPHP\Database::getInstance()->getPdo();
                $stmt = $db->prepare("
                    INSERT INTO chat_rooms (name, description, is_active)
                    VALUES (?, ?, ?)
                    RETURNING id
                ");
                $stmt->execute([$name, $description ?: null, $isActive ? 1 : 0]);
                $roomId = $stmt->fetchColumn();

                echo json_encode(['success' => true, 'id' => (int)$roomId]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::put('/chat-rooms/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $name = trim($input['name'] ?? '');
                $description = trim($input['description'] ?? '');
                $isActive = !empty($input['is_active']);

                $db = \BinktermPHP\Database::getInstance()->getPdo();
                $existingStmt = $db->prepare("SELECT name FROM chat_rooms WHERE id = ?");
                $existingStmt->execute([$id]);
                $existingName = $existingStmt->fetchColumn();

                if (!$existingName) {
                    throw new Exception('Chat room not found');
                }

                if ($existingName === 'Lobby' && $name !== '' && $name !== 'Lobby') {
                    throw new Exception('Lobby name cannot be changed');
                }

                $finalName = $name !== '' ? $name : $existingName;
                if (strlen($finalName) > 64) {
                    throw new Exception('Room name must be 1-64 characters');
                }

                $stmt = $db->prepare("
                    UPDATE chat_rooms
                    SET name = ?, description = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$finalName, $description ?: null, $isActive ? 1 : 0, $id]);

                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::delete('/chat-rooms/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $db = \BinktermPHP\Database::getInstance()->getPdo();
                $existingStmt = $db->prepare("SELECT name FROM chat_rooms WHERE id = ?");
                $existingStmt->execute([$id]);
                $existingName = $existingStmt->fetchColumn();

                if (!$existingName) {
                    throw new Exception('Chat room not found');
                }

                if ($existingName === 'Lobby') {
                    throw new Exception('Lobby cannot be deleted');
                }

                $stmt = $db->prepare("DELETE FROM chat_rooms WHERE id = ?");
                $stmt->execute([$id]);

                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        // ========================================
        // Insecure Nodes Management
        // ========================================

        // List insecure nodes
        SimpleRouter::get('/insecure-nodes', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $nodes = $adminController->getInsecureNodes();
            echo json_encode(['nodes' => $nodes]);
        });

        // Add insecure node
        SimpleRouter::post('/insecure-nodes', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $nodeId = $adminController->addInsecureNode($input);
                echo json_encode(['success' => true, 'id' => $nodeId]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        // Update insecure node
        SimpleRouter::put('/insecure-nodes/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $result = $adminController->updateInsecureNode($id, $input);
                echo json_encode(['success' => $result]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        // Delete insecure node
        SimpleRouter::delete('/insecure-nodes/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $result = $adminController->deleteInsecureNode($id);
                echo json_encode(['success' => $result]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        // ========================================
        // Crashmail Queue Management
        // ========================================

        // Get crashmail queue stats
        SimpleRouter::get('/crashmail/stats', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $stats = $adminController->getCrashmailStats();
            echo json_encode($stats);
        });

        // Get crashmail queue items
        SimpleRouter::get('/crashmail/queue', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $status = $_GET['status'] ?? null;
            $limit = intval($_GET['limit'] ?? 50);
            $items = $adminController->getCrashmailQueue($status, $limit);
            echo json_encode(['items' => $items]);
        });

        // Retry failed crashmail
        SimpleRouter::post('/crashmail/{id}/retry', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $result = $adminController->retryCrashmail($id);
                echo json_encode(['success' => $result]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        // Cancel queued crashmail
        SimpleRouter::delete('/crashmail/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $result = $adminController->cancelCrashmail($id);
                echo json_encode(['success' => $result]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        // Attempt crashmail delivery (runs crashmail_poll)
        SimpleRouter::post('/crashmail/poll', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->crashmailPoll();
                echo json_encode(['success' => true, 'result' => $result]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        });

        // ========================================
        // Binkp Session Log
        // ========================================

        // Get session log
        SimpleRouter::get('/binkp-sessions', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $filters = [
                'session_type' => $_GET['session_type'] ?? null,
                'status' => $_GET['status'] ?? null,
                'remote_address' => $_GET['remote_address'] ?? null,
                'is_inbound' => $_GET['is_inbound'] ?? null,
            ];
            $limit = intval($_GET['limit'] ?? 50);
            $sessions = $adminController->getBinkpSessions($filters, $limit);
            echo json_encode(['sessions' => $sessions]);
        });

        // Get session stats
        SimpleRouter::get('/binkp-sessions/stats', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $period = $_GET['period'] ?? 'day';
            $stats = $adminController->getBinkpSessionStats($period);
            echo json_encode($stats);
        });
    });
});


// Crashmail Queue page
SimpleRouter::get('/admin/crashmail', function() {
    $auth = new Auth();
    $user = $auth->requireAuth();

    $adminController = new AdminController();
    $adminController->requireAdmin($user);

    $template = new Template();
    $template->renderResponse('admin/crashmail_queue.twig');
});

// Insecure Nodes page
SimpleRouter::get('/admin/insecure-nodes', function() {
    $auth = new Auth();
    $user = $auth->requireAuth();

    $adminController = new AdminController();
    $adminController->requireAdmin($user);

    $template = new Template();
    $template->renderResponse('admin/insecure_nodes.twig');
});

// Binkp Sessions page
SimpleRouter::get('/admin/binkp-sessions', function() {
    $auth = new Auth();
    $user = $auth->requireAuth();

    $adminController = new AdminController();
    $adminController->requireAdmin($user);

    $template = new Template();
    $template->renderResponse('admin/binkp_sessions.twig');
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
