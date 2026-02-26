<?php

use BinktermPHP\AdminActionLogger;
use BinktermPHP\AdminController;
use BinktermPHP\Auth;
use BinktermPHP\DoorConfig;
use BinktermPHP\DoorManager;
use BinktermPHP\RouteHelper;
use BinktermPHP\Template;
use BinktermPHP\UserMeta;
use BinktermPHP\WebDoorManifest;
use Pecee\SimpleRouter\SimpleRouter;

SimpleRouter::group(['prefix' => '/admin'], function() {

    // Admin dashboard
    SimpleRouter::get('/', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $adminController = new AdminController();
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
            'git_branch' => \BinktermPHP\SystemStatus::getGitBranch(),
            'system_addresses' => $systemAddresses
        ]);
    });

    // Users management page
    SimpleRouter::get('/users', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/users.twig');
    });

    // Chat rooms management page
    SimpleRouter::get('/chat-rooms', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/chat_rooms.twig');
    });

    // Polls management page
    SimpleRouter::get('/polls', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/polls.twig');
    });

    // Shoutbox moderation page
    SimpleRouter::get('/shoutbox', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/shoutbox.twig');
    });

    // Binkp configuration page
    SimpleRouter::get('/binkp-config', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/binkp_config.twig', [
            'timezone_list' => \DateTimeZone::listIdentifiers()
        ]);
    });

    // Webdoors config page
    SimpleRouter::get('/webdoors', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/webdoors_config.twig');
    });

    // DOSDoors config page
    SimpleRouter::get('/dosdoors', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/dosdoors_config.twig');
    });

    // File area rules page
    SimpleRouter::get('/filearea-rules', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/filearea_rules.twig');
    });

    // Advertisements management page
    SimpleRouter::get('/ads', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/ads.twig');
    });

    // BBS settings page
    SimpleRouter::get('/bbs-settings', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/bbs_settings.twig', [
            'timezone_list' => \DateTimeZone::listIdentifiers(),
        ]);
    });

    // Appearance & Content settings page
    SimpleRouter::get('/appearance', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/appearance.twig', [
            'available_themes' => \BinktermPHP\Config::getThemes(),
        ]);
    });

    // MRC Chat settings page
    SimpleRouter::get('/mrc-settings', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/mrc_settings.twig');
    });

    // Custom template editor page
    SimpleRouter::get('/template-editor', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/template_editor.twig');
    });

    // Upgrade notes viewer
    SimpleRouter::get('/upgrade-notes', function() {
        RouteHelper::requireAdmin();

        $version = \BinktermPHP\Version::getVersion();
        $docPath = __DIR__ . '/../docs/UPGRADING_' . $version . '.md';

        if (!file_exists($docPath)) {
            http_response_code(404);
            $template = new Template();
            $template->renderResponse('admin/upgrade_notes.twig', [
                'version'  => $version,
                'content'  => null,
            ]);
            return;
        }

        $raw = file_get_contents($docPath);
        $html = \BinktermPHP\MarkdownRenderer::toHtml($raw);

        $template = new Template();
        $template->renderResponse('admin/upgrade_notes.twig', [
            'version' => $version,
            'content' => $html,
        ]);
    });

    // Activity statistics page
    SimpleRouter::get('/activity-stats', function() {
        $user = RouteHelper::requireAdmin();
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        $timezone = 'UTC';
        if ($userId) {
            $handler = new \BinktermPHP\MessageHandler();
            $settings = $handler->getUserSettings((int)$userId);
            $timezone = $settings['timezone'] ?? 'UTC';
        }

        $template = new Template();
        $template->renderResponse('admin/activity_stats.twig', ['user_timezone' => $timezone]);
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
                       u.username as created_by_username,
                       COUNT(v.id) as vote_count
                FROM polls p
                LEFT JOIN users u ON u.id = p.created_by
                LEFT JOIN poll_votes v ON v.poll_id = p.id
                GROUP BY p.id, u.username
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
                    'created_by_username' => $poll['created_by_username'] ?? 'Unknown',
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
                    if (mb_strlen($symbol) > 5) {
                        throw new Exception('Currency symbol must be 0-5 characters');
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
                    if (!is_numeric($credits['netmail_cost'] ?? null) || (int)$credits['netmail_cost'] < 0) {
                        throw new Exception('Netmail cost must be a non-negative integer');
                    }
                    if (!is_numeric($credits['echomail_reward'] ?? null) || (int)$credits['echomail_reward'] < 0) {
                        throw new Exception('Echomail reward must be a non-negative integer');
                    }
                    if (!is_numeric($credits['crashmail_cost'] ?? null) || (int)$credits['crashmail_cost'] < 0) {
                        throw new Exception('Crashmail cost must be a non-negative integer');
                    }
                    if (!is_numeric($credits['poll_creation_cost'] ?? null) || (int)$credits['poll_creation_cost'] < 0) {
                        throw new Exception('Poll creation cost must be a non-negative integer');
                    }
                    if (!is_numeric($credits['return_14days'] ?? null) || (int)$credits['return_14days'] < 0) {
                        throw new Exception('14-day return bonus must be a non-negative integer');
                    }
                    if (!is_numeric($credits['transfer_fee_percent'] ?? null) || (float)$credits['transfer_fee_percent'] < 0 || (float)$credits['transfer_fee_percent'] > 1) {
                        throw new Exception('Transfer fee must be between 0 and 1 (0% to 100%)');
                    }
                    if (isset($credits['referral_bonus']) && (!is_numeric($credits['referral_bonus']) || (int)$credits['referral_bonus'] < 0)) {
                        throw new Exception('Referral bonus must be a non-negative integer');
                    }
                    $config['credits'] = [
                        'enabled' => !empty($credits['enabled']),
                        'symbol' => $symbol,
                        'daily_amount' => (int)$credits['daily_amount'],
                        'daily_login_delay_minutes' => (int)$credits['daily_login_delay_minutes'],
                        'approval_bonus' => (int)$credits['approval_bonus'],
                        'netmail_cost' => (int)$credits['netmail_cost'],
                        'echomail_reward' => (int)$credits['echomail_reward'],
                        'crashmail_cost' => (int)$credits['crashmail_cost'],
                        'poll_creation_cost' => (int)$credits['poll_creation_cost'],
                        'return_14days' => (int)$credits['return_14days'],
                        'transfer_fee_percent' => (float)$credits['transfer_fee_percent'],
                        'referral_enabled' => !empty($credits['referral_enabled']),
                        'referral_bonus' => isset($credits['referral_bonus']) ? (int)$credits['referral_bonus'] : 25
                    ];
                }

                // Validate max_cross_post_areas if provided
                if (array_key_exists('max_cross_post_areas', $config)) {
                    $maxCrossPost = (int)$config['max_cross_post_areas'];
                    if ($maxCrossPost < 2 || $maxCrossPost > 20) {
                        throw new Exception('Max cross-post areas must be between 2 and 20');
                    }
                    $config['max_cross_post_areas'] = $maxCrossPost;
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

        // ---------------------------------------------------------------
        // Appearance API
        // ---------------------------------------------------------------

        SimpleRouter::get('/appearance', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $data = $client->getAppearanceConfig();
                echo json_encode(['success' => true, 'data' => $data]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/appearance/branding', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true) ?? [];
                $branding = $payload['branding'] ?? [];

                $accentColor = trim((string)($branding['accent_color'] ?? ''));
                if ($accentColor !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $accentColor)) {
                    throw new Exception('Invalid accent color format');
                }

                $logoUrl = trim((string)($branding['logo_url'] ?? ''));
                $footerText = trim((string)($branding['footer_text'] ?? ''));
                if (mb_strlen($footerText) > 500) {
                    throw new Exception('Footer text must be 500 characters or less');
                }

                $config = \BinktermPHP\AppearanceConfig::getConfig();
                $config['branding']['accent_color'] = $accentColor;
                $config['branding']['default_theme'] = trim((string)($branding['default_theme'] ?? ''));
                $config['branding']['lock_theme'] = !empty($branding['lock_theme']);
                $config['branding']['logo_url'] = $logoUrl;
                $config['branding']['footer_text'] = $footerText;

                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $client->setAppearanceConfig($config);
                \BinktermPHP\AppearanceConfig::reload();

                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/appearance/content', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true) ?? [];

                $client = new \BinktermPHP\Admin\AdminDaemonClient();

                // Save system news markdown
                if (array_key_exists('system_news', $payload)) {
                    $client->setSystemNews((string)$payload['system_news']);
                }

                // Save house rules markdown
                if (array_key_exists('house_rules', $payload)) {
                    $client->setHouseRules((string)$payload['house_rules']);
                }

                // Save announcement config
                if (array_key_exists('announcement', $payload)) {
                    $ann = $payload['announcement'];
                    $allowedTypes = ['info', 'warning', 'danger', 'success', 'primary'];
                    $annType = in_array($ann['type'] ?? '', $allowedTypes, true) ? $ann['type'] : 'info';

                    $config = \BinktermPHP\AppearanceConfig::getConfig();
                    $config['content']['announcement'] = [
                        'enabled' => !empty($ann['enabled']),
                        'text' => substr(strip_tags((string)($ann['text'] ?? '')), 0, 1000),
                        'type' => $annType,
                        'expires_at' => ($ann['expires_at'] ?? '') !== '' ? (string)$ann['expires_at'] : null,
                        'dismissible' => !empty($ann['dismissible']),
                    ];
                    $client->setAppearanceConfig($config);
                }

                \BinktermPHP\AppearanceConfig::reload();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/appearance/navigation', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true) ?? [];
                $links = $payload['custom_links'] ?? [];

                if (!is_array($links)) {
                    throw new Exception('Invalid custom_links payload');
                }

                $sanitized = [];
                foreach ($links as $link) {
                    $label = trim((string)($link['label'] ?? ''));
                    $url = trim((string)($link['url'] ?? ''));
                    if ($label === '' || $url === '') {
                        continue;
                    }
                    if (!preg_match('#^https?://#i', $url) && strpos($url, '/') !== 0) {
                        continue; // Only relative paths starting with / or absolute https? URLs
                    }
                    $sanitized[] = [
                        'label' => substr($label, 0, 100),
                        'url' => substr($url, 0, 500),
                        'new_tab' => !empty($link['new_tab']),
                    ];
                }

                $config = \BinktermPHP\AppearanceConfig::getConfig();
                $config['navigation']['custom_links'] = $sanitized;

                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $client->setAppearanceConfig($config);
                \BinktermPHP\AppearanceConfig::reload();

                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/appearance/seo', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true) ?? [];
                $seo = $payload['seo'] ?? [];

                $description = substr(trim((string)($seo['description'] ?? '')), 0, 300);
                $ogImage = trim((string)($seo['og_image_url'] ?? ''));

                $config = \BinktermPHP\AppearanceConfig::getConfig();
                $config['seo']['description'] = $description;
                $config['seo']['og_image_url'] = $ogImage;
                $config['seo']['about_page_enabled'] = !empty($seo['about_page_enabled']);

                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $client->setAppearanceConfig($config);
                \BinktermPHP\AppearanceConfig::reload();

                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/appearance/shell', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true) ?? [];
                $shell = $payload['shell'] ?? [];

                $activeShell = (string)($shell['active'] ?? 'web');
                if (!in_array($activeShell, ['web', 'bbs-menu'], true)) {
                    $activeShell = 'web';
                }

                $bbsMenu = $shell['bbs_menu'] ?? [];
                $variant = (string)($bbsMenu['variant'] ?? 'cards');
                if (!in_array($variant, ['cards', 'ansi', 'text'], true)) {
                    $variant = 'cards';
                }

                $menuItems = $bbsMenu['menu_items'] ?? [];
                $sanitizedItems = [];
                if (is_array($menuItems)) {
                    foreach ($menuItems as $item) {
                        $key = strtoupper(trim((string)($item['key'] ?? '')));
                        $label = trim((string)($item['label'] ?? ''));
                        $url = trim((string)($item['url'] ?? ''));
                        $icon = trim((string)($item['icon'] ?? 'circle'));
                        if (strlen($key) !== 1 || $label === '' || $url === '') {
                            continue;
                        }
                        $sanitizedItems[] = [
                            'key' => $key,
                            'label' => substr($label, 0, 100),
                            'icon' => preg_replace('/[^a-z0-9-]/', '', strtolower($icon)),
                            'url' => substr($url, 0, 500),
                        ];
                    }
                }

                $config = \BinktermPHP\AppearanceConfig::getConfig();
                $config['shell']['active'] = $activeShell;
                $config['shell']['lock_shell'] = !empty($shell['lock_shell']);
                $config['shell']['bbs_menu']['variant'] = $variant;
                $config['shell']['bbs_menu']['ansi_file'] = basename(trim((string)($bbsMenu['ansi_file'] ?? '')));
                if (!empty($sanitizedItems)) {
                    $config['shell']['bbs_menu']['menu_items'] = $sanitizedItems;
                }

                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $client->setAppearanceConfig($config);
                \BinktermPHP\AppearanceConfig::reload();

                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/appearance/message-reader', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true) ?? [];
                $mr = $payload['message_reader'] ?? [];

                $config = \BinktermPHP\AppearanceConfig::getConfig();
                $config['message_reader']['scrollable_body'] = !empty($mr['scrollable_body']);

                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $client->setAppearanceConfig($config);
                \BinktermPHP\AppearanceConfig::reload();

                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/appearance/preview-markdown', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true) ?? [];
                $markdown = (string)($payload['markdown'] ?? '');
                $html = \BinktermPHP\MarkdownRenderer::toHtml($markdown);
                echo json_encode(['success' => true, 'html' => $html]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        // Shell art management
        SimpleRouter::get('/shell-art', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');
            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $files = $client->listShellArt();
                echo json_encode(['success' => true, 'files' => $files]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/shell-art/upload', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');
            try {
                if (empty($_FILES['file'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'No file uploaded']);
                    return;
                }
                $file = $_FILES['file'];
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Upload error: ' . $file['error']]);
                    return;
                }
                // Max 512 KB for ANSI art
                if ($file['size'] > 524288) {
                    http_response_code(400);
                    echo json_encode(['error' => 'File too large (max 512 KB)']);
                    return;
                }
                $originalName = basename($file['name']);
                $contentBase64 = base64_encode(file_get_contents($file['tmp_name']));
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->uploadShellArt($contentBase64, '', $originalName);
                echo json_encode(['success' => true, 'name' => $result['name'] ?? $originalName]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::delete('/shell-art/{name}', function(string $name) {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');
            try {
                $name = basename($name);
                if (!preg_match('/^[a-zA-Z0-9_\-]+\.(ans|asc|txt)$/i', $name)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid filename']);
                    return;
                }
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $client->deleteShellArt($name);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::get('/taglines', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->getTaglines();
                echo json_encode(['success' => true, 'taglines' => $result['text'] ?? '']);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/taglines', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                $text = (string)($payload['taglines'] ?? '');
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->saveTaglines($text);
                echo json_encode(['success' => true, 'taglines' => $result['text'] ?? '']);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        // MRC settings
        SimpleRouter::get('/mrc-settings', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->send(['cmd' => 'get_mrc_config']);

                if (!$result['ok']) {
                    throw new Exception($result['error'] ?? 'Failed to get MRC config');
                }

                echo json_encode(['success' => true, 'config' => $result['result']]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/mrc-settings', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                $config = $payload['config'] ?? [];

                if (!is_array($config)) {
                    throw new Exception('Invalid configuration payload');
                }

                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->send([
                    'cmd' => 'set_mrc_config',
                    'data' => ['config' => $config]
                ]);

                if (!$result['ok']) {
                    throw new Exception($result['error'] ?? 'Failed to save MRC config');
                }

                $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
                if ($userId) {
                    AdminActionLogger::logAction($userId, 'mrc_settings_updated', [
                        'enabled' => $config['enabled'] ?? null
                    ]);
                }

                echo json_encode(['success' => true, 'config' => $result['result']]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/mrc-restart', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->send(['cmd' => 'restart_mrc_daemon']);

                if (!$result['ok']) {
                    throw new Exception($result['error'] ?? 'Failed to restart MRC daemon');
                }

                $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
                if ($userId) {
                    AdminActionLogger::logAction($userId, 'mrc_daemon_restarted');
                }

                echo json_encode(['success' => true, 'message' => 'MRC daemon restart initiated']);
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

        SimpleRouter::post('/binkp-reload', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->reloadBinkpConfig();
                echo json_encode(['success' => true, 'message' => $result]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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

        SimpleRouter::get('/webdoors-available', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            $doors = [];
            foreach (WebDoorManifest::listManifests() as $entry) {
                $manifest = $entry['manifest'];
                $game = $manifest['game'] ?? [];
                $gameId = $entry['id'];
                $doors[] = [
                    'id' => $gameId,
                    'name' => $game['name'] ?? $gameId,
                    'path' => $entry['path'],
                    'config' => is_array($manifest['config'] ?? null) ? $manifest['config'] : null
                ];
            }

            echo json_encode(['doors' => $doors]);
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

        // DOSDoors API endpoints
        SimpleRouter::get('/dosdoors-config', function() {
            $user = RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->getDosdoorsConfig();

                $configData = null;
                if (!empty($result['config_json'])) {
                    $configData = json_decode($result['config_json'], true);
                }

                echo json_encode([
                    'success' => true,
                    'config' => $configData ?? [],
                    'exists' => $result['active'] ?? false
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        });

        SimpleRouter::get('/dosdoors-available', function() {
            $user = RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            $doorManager = new DoorManager();
            $allDoors = $doorManager->getAllDoors();

            $doors = [];
            foreach ($allDoors as $doorId => $door) {
                $doors[] = [
                    'id' => $doorId,
                    'name' => $door['name'],
                    'short_name' => $door['short_name'] ?? $door['name'],
                    'author' => $door['author'] ?? 'Unknown',
                    'description' => $door['description'] ?? '',
                    'config' => $door['config'] ?? []
                ];
            }

            echo json_encode(['success' => true, 'doors' => $doors]);
        });

        SimpleRouter::post('/dosdoors-config', function() {
            $user = RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                $config = $payload['config'] ?? null;

                if (!is_array($config)) {
                    throw new Exception('Invalid config data');
                }

                $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    throw new Exception('Failed to encode config as JSON');
                }

                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->saveDosdoorsConfig($json);

                // Reload config class cache
                DoorConfig::reload();

                // Sync enabled doors to database
                $doorManager = new DoorManager();
                $syncResult = $doorManager->syncDoorsToDatabase();

                echo json_encode([
                    'success' => true,
                    'config' => $config,
                    'synced' => $syncResult['synced'],
                    'sync_errors' => $syncResult['errors']
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        });

        SimpleRouter::get('/filearea-rules', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $config = $client->getFileAreaRulesConfig();
                echo json_encode(['success' => true, 'config' => $config]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/filearea-rules', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                $json = $payload['json'] ?? '';
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $updated = $client->saveFileAreaRulesConfig((string)$json);
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

        // ========================================
        // Custom Template Editor
        // ========================================

        SimpleRouter::get('/custom-templates', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $templates = $client->listCustomTemplates();
                echo json_encode(['templates' => $templates]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::get('/custom-templates/file', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            $path = $_GET['path'] ?? '';
            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $template = $client->getCustomTemplate($path);
                echo json_encode($template);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/custom-templates/file', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            $input = json_decode(file_get_contents('php://input'), true);
            $path = trim($input['path'] ?? '');
            $content = (string)($input['content'] ?? '');
            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->saveCustomTemplate($path, $content);
                echo json_encode($result);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::delete('/custom-templates/file', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            $path = $_GET['path'] ?? '';
            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->deleteCustomTemplate($path);
                echo json_encode($result);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });

        SimpleRouter::post('/custom-templates/install', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            $input = json_decode(file_get_contents('php://input'), true);
            $source = trim($input['source'] ?? '');
            $overwrite = !empty($input['overwrite']);
            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->installCustomTemplate($source, $overwrite);
                echo json_encode($result);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['error' => $e->getMessage()]);
            }
        });
    });

    // Auto Feed page
    SimpleRouter::get('/auto-feed', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/auto_feed.twig');
    });

    // Auto Feed API - Get all feeds
    SimpleRouter::get('/api/auto-feed/feeds', function() {
        $user = RouteHelper::requireAdmin();
        $db = \BinktermPHP\Database::getInstance()->getPdo();

        header('Content-Type: application/json');

        $stmt = $db->query("
            SELECT f.*, u.username, e.tag as echoarea_tag, e.domain as echoarea_domain
            FROM auto_feed_sources f
            LEFT JOIN users u ON u.id = f.post_as_user_id
            LEFT JOIN echoareas e ON e.id = f.echoarea_id
            ORDER BY f.id DESC
        ");
        $feeds = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['feeds' => $feeds]);
    });

    // Auto Feed API - Get single feed
    SimpleRouter::get('/api/auto-feed/feeds/{id}', function($id) {
        $user = RouteHelper::requireAdmin();
        $db = \BinktermPHP\Database::getInstance()->getPdo();

        header('Content-Type: application/json');

        $stmt = $db->prepare("SELECT * FROM auto_feed_sources WHERE id = ?");
        $stmt->execute([$id]);
        $feed = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$feed) {
            http_response_code(404);
            echo json_encode(['error' => 'Feed not found']);
            return;
        }

        echo json_encode(['feed' => $feed]);
    });

    // Auto Feed API - Create feed
    SimpleRouter::post('/api/auto-feed/feeds', function() {
        $user = RouteHelper::requireAdmin();
        $db = \BinktermPHP\Database::getInstance()->getPdo();

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);

        // Validate required fields
        if (empty($input['feed_url']) || empty($input['echoarea_id']) || empty($input['post_as_user_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            return;
        }

        // Validate URL
        if (!filter_var($input['feed_url'], FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid feed URL']);
            return;
        }

        // Validate echoarea exists
        $stmt = $db->prepare("SELECT id FROM echoareas WHERE id = ?");
        $stmt->execute([$input['echoarea_id']]);
        if (!$stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid echo area']);
            return;
        }

        // Validate user exists
        $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$input['post_as_user_id']]);
        if (!$stmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid user ID']);
            return;
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO auto_feed_sources
                (feed_url, feed_name, echoarea_id, post_as_user_id,
                 max_articles_per_check, active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $input['feed_url'],
                $input['feed_name'] ?? null,
                $input['echoarea_id'],
                $input['post_as_user_id'],
                $input['max_articles_per_check'] ?? 10,
                $input['active'] ?? true
            ]);

            $feedId = $db->lastInsertId();

            // Log action
            $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
            AdminActionLogger::logAction($userId, 'auto_feed_created', [
                'feed_id' => $feedId,
                'feed_url' => $input['feed_url'],
                'echoarea_id' => $input['echoarea_id']
            ]);

            echo json_encode(['success' => true, 'id' => $feedId]);
        } catch (PDOException $e) {
            http_response_code(400);
            if (strpos($e->getMessage(), 'duplicate key') !== false) {
                echo json_encode(['error' => 'This feed URL already exists']);
            } else {
                echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
            }
        }
    });

    // Auto Feed API - Update feed
    SimpleRouter::put('/api/auto-feed/feeds/{id}', function($id) {
        $user = RouteHelper::requireAdmin();
        $db = \BinktermPHP\Database::getInstance()->getPdo();

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);

        // Validate feed exists
        $stmt = $db->prepare("SELECT * FROM auto_feed_sources WHERE id = ?");
        $stmt->execute([$id]);
        $existingFeed = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingFeed) {
            http_response_code(404);
            echo json_encode(['error' => 'Feed not found']);
            return;
        }

        // Validate required fields
        if (empty($input['feed_url']) || empty($input['echoarea_id']) || empty($input['post_as_user_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            return;
        }

        try {
            $stmt = $db->prepare("
                UPDATE auto_feed_sources
                SET feed_url = ?,
                    feed_name = ?,
                    echoarea_id = ?,
                    post_as_user_id = ?,
                    max_articles_per_check = ?,
                    active = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $input['feed_url'],
                $input['feed_name'] ?? null,
                $input['echoarea_id'],
                $input['post_as_user_id'],
                $input['max_articles_per_check'] ?? 10,
                $input['active'] ?? true,
                $id
            ]);

            // Log action
            $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
            AdminActionLogger::logAction($userId, 'auto_feed_updated', [
                'feed_id' => $id,
                'feed_url' => $input['feed_url'],
                'echoarea_id' => $input['echoarea_id']
            ]);

            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(400);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
    });

    // Auto Feed API - Delete feed
    SimpleRouter::delete('/api/auto-feed/feeds/{id}', function($id) {
        $user = RouteHelper::requireAdmin();
        $db = \BinktermPHP\Database::getInstance()->getPdo();

        header('Content-Type: application/json');

        // Get feed info for logging
        $stmt = $db->prepare("SELECT feed_url FROM auto_feed_sources WHERE id = ?");
        $stmt->execute([$id]);
        $feed = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$feed) {
            http_response_code(404);
            echo json_encode(['error' => 'Feed not found']);
            return;
        }

        $stmt = $db->prepare("DELETE FROM auto_feed_sources WHERE id = ?");
        $stmt->execute([$id]);

        // Log action
        $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
        AdminActionLogger::logAction($userId, 'auto_feed_deleted', [
            'feed_id' => $id,
            'feed_url' => $feed['feed_url']
        ]);

        echo json_encode(['success' => true]);
    });

    // Auto Feed API - Check feed now
    SimpleRouter::post('/api/auto-feed/check/{id}', function($id) {
        $user = RouteHelper::requireAdmin();
        $db = \BinktermPHP\Database::getInstance()->getPdo();

        header('Content-Type: application/json');

        // Verify feed exists and is active
        $stmt = $db->prepare("SELECT * FROM auto_feed_sources WHERE id = ?");
        $stmt->execute([$id]);
        $feed = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$feed) {
            http_response_code(404);
            echo json_encode(['error' => 'Feed not found']);
            return;
        }

        // Execute rss_poster.php script for this feed
        $scriptPath = __DIR__ . '/../scripts/rss_poster.php';
        $command = PHP_BINARY . ' ' . escapeshellarg($scriptPath) . ' --feed-id=' . (int)$id . ' --verbose 2>&1';

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            http_response_code(500);
            echo json_encode(['error' => 'Feed check failed', 'output' => implode("\n", $output)]);
            return;
        }

        // Reload feed to get updated stats
        $stmt->execute([$id]);
        $updatedFeed = $stmt->fetch(PDO::FETCH_ASSOC);

        // Count articles posted
        $articlesPosted = $updatedFeed['articles_posted'] - $feed['articles_posted'];

        echo json_encode([
            'success' => true,
            'articles_posted' => max(0, $articlesPosted),
            'output' => implode("\n", $output)
        ]);
    });

    // Auto Feed API - Get statistics
    SimpleRouter::get('/api/auto-feed/stats', function() {
        $user = RouteHelper::requireAdmin();
        $db = \BinktermPHP\Database::getInstance()->getPdo();

        header('Content-Type: application/json');

        $stmt = $db->query("
            SELECT
                COUNT(*) as total_feeds,
                COUNT(CASE WHEN active THEN 1 END) as active_feeds,
                COALESCE(SUM(articles_posted), 0) as total_articles
            FROM auto_feed_sources
        ");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode($stats);
    });

    // Activity statistics API
    SimpleRouter::get('/api/activity-stats', function() {
        RouteHelper::requireAdmin();

        header('Content-Type: application/json');

        $db = \BinktermPHP\Database::getInstance()->getPdo();
        $period        = $_GET['period'] ?? '30d';
        $excludeAdmins = !empty($_GET['exclude_admins']);

        // Validate and sanitize user timezone
        $requestedTz = $_GET['timezone'] ?? 'UTC';
        try {
            new \DateTimeZone($requestedTz);
            $timezone = $requestedTz;
        } catch (\Exception $e) {
            $timezone = 'UTC';
        }

        // Build date filter condition
        switch ($period) {
            case '7d':
                $dateFilter = "AND ual.created_at >= NOW() - INTERVAL '7 days'";
                break;
            case '90d':
                $dateFilter = "AND ual.created_at >= NOW() - INTERVAL '90 days'";
                break;
            case 'all':
                $dateFilter = '';
                break;
            case '30d':
            default:
                $dateFilter = "AND ual.created_at >= NOW() - INTERVAL '30 days'";
                break;
        }

        // Optionally exclude activity from admin users
        $adminFilter = $excludeAdmins
            ? "AND (ual.user_id IS NULL OR ual.user_id NOT IN (SELECT id FROM users WHERE is_admin = TRUE))"
            : '';

        // Check that user_activity_log table exists
        try {
            $db->query("SELECT 1 FROM user_activity_log LIMIT 1");
        } catch (\Exception $e) {
            echo json_encode(['error' => 'Activity log table not yet available. Run setup.php to apply migrations.']);
            return;
        }

        // Summary: total + by category
        $summaryStmt = $db->query("
            SELECT ac.name AS category, COUNT(*) AS cnt
            FROM user_activity_log ual
            JOIN activity_types at2 ON ual.activity_type_id = at2.id
            JOIN activity_categories ac ON at2.category_id = ac.id
            WHERE 1=1 {$dateFilter}{$adminFilter}
            GROUP BY ac.name
        ");
        $categoryRows = $summaryStmt->fetchAll(\PDO::FETCH_ASSOC);
        $byCategory = [];
        $totalEvents = 0;
        foreach ($categoryRows as $row) {
            $byCategory[$row['category']] = (int)$row['cnt'];
            $totalEvents += (int)$row['cnt'];
        }

        // Summary: per activity type (for netmail/echomail breakdown)
        $typeStmt = $db->query("
            SELECT activity_type_id, COUNT(*) AS cnt
            FROM user_activity_log ual
            WHERE 1=1 {$dateFilter}{$adminFilter}
            GROUP BY activity_type_id
        ");
        $byType = [];
        foreach ($typeStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byType[(int)$row['activity_type_id']] = (int)$row['cnt'];
        }

        // Popular echoareas (views and posts)
        $echoAreasStmt = $db->query("
            SELECT object_name AS name,
                   SUM(CASE WHEN activity_type_id = 1 THEN 1 ELSE 0 END) AS views,
                   SUM(CASE WHEN activity_type_id = 2 THEN 1 ELSE 0 END) AS posts
            FROM user_activity_log ual
            WHERE activity_type_id IN (1, 2) {$dateFilter}{$adminFilter}
              AND object_name IS NOT NULL
            GROUP BY object_name
            ORDER BY views DESC
            LIMIT 20
        ");
        $popularEchoareas = $echoAreasStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($popularEchoareas as &$row) {
            $row['views'] = (int)$row['views'];
            $row['posts'] = (int)$row['posts'];
        }
        unset($row);

        // Popular WebDoors
        $webdoorsStmt = $db->query("
            SELECT object_name AS name, COUNT(*) AS count
            FROM user_activity_log ual
            WHERE activity_type_id = 8 {$dateFilter}{$adminFilter}
              AND object_name IS NOT NULL
            GROUP BY object_name
            ORDER BY count DESC
            LIMIT 10
        ");
        $popularWebdoors = $webdoorsStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($popularWebdoors as &$row) { $row['count'] = (int)$row['count']; }
        unset($row);

        // Popular DOS Doors
        $dosdoorsStmt = $db->query("
            SELECT object_name AS name, COUNT(*) AS count
            FROM user_activity_log ual
            WHERE activity_type_id = 9 {$dateFilter}{$adminFilter}
              AND object_name IS NOT NULL
            GROUP BY object_name
            ORDER BY count DESC
            LIMIT 10
        ");
        $popularDosdoors = $dosdoorsStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($popularDosdoors as &$row) { $row['count'] = (int)$row['count']; }
        unset($row);

        // Top downloaded files
        $topFilesStmt = $db->query("
            SELECT object_name AS name, COUNT(*) AS count
            FROM user_activity_log ual
            WHERE activity_type_id = 6 {$dateFilter}{$adminFilter}
              AND object_name IS NOT NULL
            GROUP BY object_name
            ORDER BY count DESC
            LIMIT 15
        ");
        $topFiles = $topFilesStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($topFiles as &$row) { $row['count'] = (int)$row['count']; }
        unset($row);

        // Most browsed file areas
        $fileAreasStmt = $db->query("
            SELECT ual.object_id AS area_id, fa.tag AS area_name, COUNT(*) AS count
            FROM user_activity_log ual
            LEFT JOIN file_areas fa ON fa.id = ual.object_id
            WHERE ual.activity_type_id = 5 {$dateFilter}{$adminFilter}
              AND ual.object_id IS NOT NULL
            GROUP BY ual.object_id, fa.tag
            ORDER BY count DESC
            LIMIT 10
        ");
        $topFileareas = $fileAreasStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($topFileareas as &$row) {
            $row['area_id']   = (int)$row['area_id'];
            $row['area_name'] = $row['area_name'] ?? 'Area #' . $row['area_id'];  // deleted area fallback
            $row['count']     = (int)$row['count'];
        }
        unset($row);

        // Nodelist searches
        $nodelistSearchStmt = $db->query("
            SELECT object_name AS name, COUNT(*) AS count
            FROM user_activity_log ual
            WHERE activity_type_id = 10 {$dateFilter}{$adminFilter}
              AND object_name IS NOT NULL
            GROUP BY object_name
            ORDER BY count DESC
            LIMIT 10
        ");
        $topNodelistSearches = $nodelistSearchStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($topNodelistSearches as &$row) { $row['count'] = (int)$row['count']; }
        unset($row);

        // Most viewed nodes
        $topNodesStmt = $db->query("
            SELECT object_name AS name, COUNT(*) AS count
            FROM user_activity_log ual
            WHERE activity_type_id = 11 {$dateFilter}{$adminFilter}
              AND object_name IS NOT NULL
            GROUP BY object_name
            ORDER BY count DESC
            LIMIT 10
        ");
        $topNodes = $topNodesStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($topNodes as &$row) { $row['count'] = (int)$row['count']; }
        unset($row);

        // Top users
        $topUsersStmt = $db->query("
            SELECT u.username, COUNT(*) AS count
            FROM user_activity_log ual
            LEFT JOIN users u ON ual.user_id = u.id
            WHERE 1=1 {$dateFilter}{$adminFilter}
              AND ual.user_id IS NOT NULL
            GROUP BY ual.user_id, u.username
            ORDER BY count DESC
            LIMIT 15
        ");
        $topUsers = $topUsersStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($topUsers as &$row) { $row['count'] = (int)$row['count']; }
        unset($row);

        // Hourly distribution in user's timezone
        $hourlyStmt = $db->prepare("
            SELECT EXTRACT(HOUR FROM created_at AT TIME ZONE :tz)::int AS hour, COUNT(*) AS count
            FROM user_activity_log ual
            WHERE 1=1 {$dateFilter}{$adminFilter}
            GROUP BY hour
            ORDER BY hour
        ");
        $hourlyStmt->execute([':tz' => $timezone]);
        $hourlyRaw = $hourlyStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Fill all 24 hours even if no data
        $hourly = [];
        $hourlyByHour = [];
        foreach ($hourlyRaw as $row) {
            $hourlyByHour[(int)$row['hour']] = (int)$row['count'];
        }
        for ($h = 0; $h < 24; $h++) {
            $hourly[] = ['hour' => $h, 'count' => $hourlyByHour[$h] ?? 0];
        }

        // Daily activity (last 30 days always, regardless of period for the overview chart)
        $dailyAdminFilter = $excludeAdmins
            ? "AND (user_id IS NULL OR user_id NOT IN (SELECT id FROM users WHERE is_admin = TRUE))"
            : '';
        $dailyStmt = $db->prepare("
            SELECT DATE(created_at AT TIME ZONE :tz) AS date, COUNT(*) AS count
            FROM user_activity_log
            WHERE created_at >= NOW() - INTERVAL '30 days'
            {$dailyAdminFilter}
            GROUP BY date
            ORDER BY date
        ");
        $dailyStmt->execute([':tz' => $timezone]);
        $daily = $dailyStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($daily as &$row) { $row['count'] = (int)$row['count']; }
        unset($row);

        echo json_encode([
            'period'   => $period,
            'timezone' => $timezone,
            'summary' => [
                'total'       => $totalEvents,
                'by_category' => $byCategory,
                'by_type'     => [
                    'echomail_views' => $byType[1] ?? 0,
                    'echomail_sends' => $byType[2] ?? 0,
                    'netmail_reads'  => $byType[3] ?? 0,
                    'netmail_sends'  => $byType[4] ?? 0,
                ],
            ],
            'popular_echoareas'     => $popularEchoareas,
            'popular_webdoors'      => $popularWebdoors,
            'popular_dosdoors'      => $popularDosdoors,
            'top_files'             => $topFiles,
            'top_fileareas'         => $topFileareas,
            'top_nodelist_searches' => $topNodelistSearches,
            'top_nodes'             => $topNodes,
            'top_users'             => $topUsers,
            'hourly'                => $hourly,
            'daily'                 => $daily,
        ]);
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
