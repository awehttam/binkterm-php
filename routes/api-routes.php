<?php

use BinktermPHP\AddressBookController;
use BinktermPHP\AdminController;
use BinktermPHP\Auth;
use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\MessageHandler;
use BinktermPHP\UserMeta;
use Pecee\SimpleRouter\SimpleRouter;

function sanitizeFilenameForWindows(string $name, string $fallback = 'message'): string
{
    $safe = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/', '_', $name);
    $safe = preg_replace('/\s+/', ' ', (string)$safe);
    $safe = trim($safe);
    $safe = rtrim($safe, '. ');

    if ($safe === '') {
        $safe = $fallback;
    }

    $upper = strtoupper($safe);
    $reserved = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9'
    ];
    if (in_array($upper, $reserved, true)) {
        $safe = '_' . $safe;
    }

    if (strlen($safe) > 120) {
        $safe = substr($safe, 0, 120);
    }

    return $safe;
}

SimpleRouter::group(['prefix' => '/api'], function() {

    SimpleRouter::post('/auth/login', function() {
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';

        if (empty($username) || empty($password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Username and password required']);
            return;
        }

        $auth = new Auth();
        $sessionId = $auth->login($username, $password);

        if ($sessionId) {
            setcookie('binktermphp_session', $sessionId, time() + 86400 * 30, '/', '', false, true);
            echo json_encode(['success' => true]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
        }
    });

    SimpleRouter::post('/auth/logout', function() {
        header('Content-Type: application/json');

        $sessionId = $_COOKIE['binktermphp_session'] ?? null;
        if ($sessionId) {
            $auth = new Auth();
            $auth->logout($sessionId);
            setcookie('binktermphp_session', '', time() - 3600, '/');
        }

        echo json_encode(['success' => true]);
    });

    // Gateway token verification endpoint for external services (bbslinkgateway, etc.)
    SimpleRouter::post('/auth/verify-gateway-token', function() {
        header('Content-Type: application/json');

        // Verify API key
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        $expectedKey = Config::env('BBSLINK_API_KEY');

        if (empty($expectedKey) || $apiKey !== $expectedKey) {
            //error_log($expectedKey." != ".$apiKey);
            http_response_code(401);
            echo json_encode(['valid' => false, 'error' => 'Invalid API key']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $userId = $input['userid'] ?? $input['user_id'] ?? null;
        $token = $input['token'] ?? '';

        if (empty($userId) || empty($token)) {
            http_response_code(400);
            echo json_encode(['valid' => false, 'error' => 'userid and token are required']);
            return;
        }

        $auth = new Auth();
        $userInfo = $auth->verifyGatewayToken((int)$userId, $token);

        if ($userInfo) {
            //error_log("Verified gateway token succesfully");
            echo json_encode([
                'valid' => true,
                'userInfo' => $userInfo
            ]);
        } else {
            //error_log("Invalid or expired token userId=$userId, token=$token" );
            echo json_encode([
                'valid' => false,
                'error' => 'Invalid or expired token'
            ]);
        }
    });

    // Generate gateway token for authenticated user
    SimpleRouter::post('/auth/gateway-token', function() {
        header('Content-Type: application/json');

        $auth = new Auth();
        $user = $auth->requireAuth();

        $input = json_decode(file_get_contents('php://input'), true);
        $door = $input['door'] ?? null;
        $ttl = $input['ttl'] ?? 300; // Default 5 minutes

        // Cap TTL at 10 minutes for security
        $ttl = min((int)$ttl, 600);

        $token = $auth->generateGatewayToken($user['user_id'], $door, $ttl);

        echo json_encode([
            'success' => true,
            'userid' => $user['user_id'],
            'token' => $token,
            'expires_in' => $ttl
        ]);
    });

    SimpleRouter::post('/auth/forgot-password', function() {
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $usernameOrEmail = $input['usernameOrEmail'] ?? '';

        if (empty($usernameOrEmail)) {
            http_response_code(400);
            echo json_encode(['error' => 'Username or email is required']);
            return;
        }

        $controller = new \BinktermPHP\PasswordResetController();
        $result = $controller->requestPasswordReset($usernameOrEmail);

        echo json_encode($result);
    });

    SimpleRouter::post('/auth/validate-reset-token', function() {
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['token'] ?? '';

        if (empty($token)) {
            http_response_code(400);
            echo json_encode(['valid' => false, 'error' => 'Token is required']);
            return;
        }

        $controller = new \BinktermPHP\PasswordResetController();
        $tokenData = $controller->validateToken($token);

        if ($tokenData) {
            echo json_encode(['valid' => true]);
        } else {
            echo json_encode(['valid' => false, 'error' => 'Invalid or expired token']);
        }
    });

    SimpleRouter::post('/auth/reset-password', function() {
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['token'] ?? '';
        $newPassword = $input['newPassword'] ?? '';

        if (empty($token) || empty($newPassword)) {
            http_response_code(400);
            echo json_encode(['error' => 'Token and new password are required']);
            return;
        }

        $controller = new \BinktermPHP\PasswordResetController();
        $result = $controller->resetPassword($token, $newPassword);

        if (!$result['success']) {
            http_response_code(400);
        }

        echo json_encode($result);
    });

    SimpleRouter::post('/register', function() {
        header('Content-Type: application/json');

        // Get form data (not JSON for form submission)
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $email = $_POST['email'] ?? '';
        $realName = $_POST['real_name'] ?? '';
        $location = $_POST['location'] ?? '';
        $reason = $_POST['reason'] ?? '';

        // Validate required fields
        if (empty($username) || empty($password) || empty($realName)) {
            http_response_code(400);
            echo json_encode(['error' => 'Username, password, and real name are required']);
            return;
        }

        // Validate username format
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            http_response_code(400);
            echo json_encode(['error' => 'Username must be 3-20 characters, letters, numbers, and underscores only']);
            return;
        }

        // Validate password length
        if (strlen($password) < 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Password must be at least 8 characters long']);
            return;
        }

        try {
            $db = Database::getInstance()->getPdo();

            // Check if username or real_name already exists in users or pending_users (case-insensitive)
            $checkStmt = $db->prepare("
                SELECT 1 FROM users WHERE username = ? OR LOWER(real_name) = LOWER(?) OR LOWER(real_name) = LOWER(?)
                UNION
                SELECT 1 FROM pending_users WHERE (username = ? OR LOWER(real_name) = LOWER(?) OR LOWER(real_name) = LOWER(?)) AND status = 'pending'
            ");
            $checkStmt->execute([$username, $username, $realName, $username, $username, $realName]);

            if ($checkStmt->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'A user with this username or name already exists. Please try logging in or contact the sysop for assistance.']);
                return;
            }

            // Hash password
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            // Get client info
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            // Insert pending user
            $insertStmt = $db->prepare("
                INSERT INTO pending_users (username, password_hash, email, real_name, location, reason, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $insertStmt->execute([
                $username,
                $passwordHash,
                $email ?: null,
                $realName,
                $location ?: null,
                $reason ?: null,
                $ipAddress,
                $userAgent
            ]);

            $pendingUserId = $db->lastInsertId();

            // Send notification to sysop
            try {
                $handler = new MessageHandler();
                $handler->sendRegistrationNotification($pendingUserId, $username, $realName, $email, $reason, $ipAddress);
            } catch (Exception $e) {
                // Log error but don't fail registration
                error_log("Failed to send registration notification: " . $e->getMessage());
            }

            echo json_encode(['success' => true]);

        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Registration failed. Please try again later.']);
        }
    });

    SimpleRouter::post('/account/reminder', function() {
        header('Content-Type: application/json');

        // Get form data
        $username = $_POST['username'] ?? '';

        // Validate required fields
        if (empty($username)) {
            http_response_code(400);
            echo json_encode(['error' => 'Username is required']);
            return;
        }

        try {
            $handler = new MessageHandler();

            // Check if user exists and hasn't logged in
            if (!$handler->canSendReminder($username)) {
                http_response_code(404);
                echo json_encode(['error' => 'User not found or already logged in']);
                return;
            }

            // Send reminder
            $result = $handler->sendAccountReminder($username);

            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Account reminder sent successfully',
                    'email_sent' => $result['email_sent'] ?? false
                ]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => $result['error']]);
            }

        } catch (Exception $e) {
            error_log("Account reminder error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to send reminder. Please try again later.']);
        }
    });

    SimpleRouter::get('/notify/state', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid user']);
            return;
        }

        $defaults = [
            'mailLastCounts' => ['netmail' => 0, 'echomail' => 0],
            'mailUnread' => ['netmail' => false, 'echomail' => false],
            'chatLastTotal' => 0,
            'chatUnread' => false
        ];

        $meta = new UserMeta();
        $raw = $meta->getValue((int)$userId, 'notify_state');
        $state = null;
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $state = $decoded;
            }
        }

        echo json_encode(['state' => $state ?? $defaults]);
    });

    SimpleRouter::post('/notify/state', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid user']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $state = $input['state'] ?? null;
        if (!is_array($state)) {
            http_response_code(400);
            echo json_encode(['error' => 'State payload required']);
            return;
        }

        $normalized = [
            'mailLastCounts' => [
                'netmail' => max(0, (int)($state['mailLastCounts']['netmail'] ?? 0)),
                'echomail' => max(0, (int)($state['mailLastCounts']['echomail'] ?? 0))
            ],
            'mailUnread' => [
                'netmail' => !empty($state['mailUnread']['netmail']),
                'echomail' => !empty($state['mailUnread']['echomail'])
            ],
            'chatLastTotal' => max(0, (int)($state['chatLastTotal'] ?? 0)),
            'chatUnread' => !empty($state['chatUnread'])
        ];

        $meta = new UserMeta();
        $meta->setValue((int)$userId, 'notify_state', json_encode($normalized));

        echo json_encode(['success' => true, 'state' => $normalized]);
    });

    SimpleRouter::post('/notify/seen', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $isAdmin = !empty($user['is_admin']);
        if (!$userId) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid user']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $target = strtolower((string)($input['target'] ?? ''));
        if (!in_array($target, ['netmail', 'echomail', 'chat'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid target']);
            return;
        }

        $meta = new UserMeta();
        $now = gmdate('Y-m-d H:i:s');
        $meta->setValue((int)$userId, 'notify_seen_' . $target, $now);

        echo json_encode(['success' => true, 'target' => $target, 'seen_at' => $now]);
    });

    SimpleRouter::get('/dashboard/stats', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $isAdmin = !empty($user['is_admin']);
        $meta = new UserMeta();
        $now = gmdate('Y-m-d H:i:s');

        $seenNetmail = $meta->getValue((int)$userId, 'notify_seen_netmail');
        if (!$seenNetmail) {
            // Initialize to a date in the past so existing unread messages are counted
            $seenNetmail = '2020-01-01 00:00:00';
            $meta->setValue((int)$userId, 'notify_seen_netmail', $seenNetmail);
        }

        $seenEchomail = $meta->getValue((int)$userId, 'notify_seen_echomail');
        if (!$seenEchomail) {
            // Initialize to a date in the past so existing unread messages are counted
            $seenEchomail = '2020-01-01 00:00:00';
            $meta->setValue((int)$userId, 'notify_seen_echomail', $seenEchomail);
        }

        $seenChat = $meta->getValue((int)$userId, 'notify_seen_chat');
        if (!$seenChat) {
            // Initialize to a date in the past so existing chat messages are counted
            $seenChat = '2020-01-01 00:00:00';
            $meta->setValue((int)$userId, 'notify_seen_chat', $seenChat);
        }

        // Unread netmail using message_read_status table (sent + received)
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $myAddresses = $binkpConfig->getMyAddresses();
            $myAddresses[] = $binkpConfig->getSystemAddress();
        } catch (\Exception $e) {
            $myAddresses = [];
        }

        if (!empty($myAddresses)) {
            $addressPlaceholders = implode(',', array_fill(0, count($myAddresses), '?'));
            $unreadStmt = $db->prepare("
                SELECT COUNT(*) as count
                FROM netmail n
                LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
                WHERE mrs.read_at IS NULL
                  AND (
                    n.user_id = ?
                    OR ((LOWER(n.to_name) = LOWER(?) OR LOWER(n.to_name) = LOWER(?)) AND n.to_address IN ($addressPlaceholders))
                  )
            ");
            $params = [$userId, $userId, $user['username'], $user['real_name']];
            $params = array_merge($params, $myAddresses);
            $unreadStmt->execute($params);
        } else {
            $unreadStmt = $db->prepare("
                SELECT COUNT(*) as count
                FROM netmail n
                LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
                WHERE n.user_id = ? AND mrs.read_at IS NULL
            ");
            $unreadStmt->execute([$userId, $userId]);
        }
        $unreadNetmail = $unreadStmt->fetch()['count'] ?? 0;

        // Unread echomail using message_read_status table (only from subscribed echoareas)
        $sysopUnreadFilter = $isAdmin ? "" : " AND COALESCE(e.is_sysop_only, FALSE) = FALSE";
        $unreadEchomailStmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM echomail em
            INNER JOIN echoareas e ON em.echoarea_id = e.id
            INNER JOIN user_echoarea_subscriptions ues ON e.id = ues.echoarea_id AND ues.user_id = ?
            LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
            WHERE mrs.read_at IS NULL AND e.is_active = TRUE{$sysopUnreadFilter}
        ");
        $unreadEchomailStmt->execute([$userId, $userId]);
        $unreadEchomail = $unreadEchomailStmt->fetch()['count'] ?? 0;

        $chatTotalStmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM chat_messages m
            LEFT JOIN chat_rooms r ON m.room_id = r.id
            WHERE ((m.room_id IS NOT NULL AND r.is_active = TRUE)
               OR m.to_user_id = ?
               OR m.from_user_id = ?)
              AND m.created_at > ?
        ");
        $chatTotalStmt->execute([$userId, $userId, $seenChat]);
        $chatTotal = $chatTotalStmt->fetch()['count'] ?? 0;

        echo json_encode([
            'unread_netmail' => $unreadNetmail,
            'new_echomail' => $unreadEchomail,
            'chat_total' => (int)$chatTotal
        ]);
    });

    SimpleRouter::get('/polls/active', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();
        $pollStmt = $db->prepare("
            SELECT id, question
            FROM polls
            WHERE is_active = TRUE
            ORDER BY created_at ASC
        ");
        $pollStmt->execute();
        $polls = $pollStmt->fetchAll();

        if (!$polls) {
            echo json_encode(['polls' => []]);
            return;
        }

        $pollIds = array_map(function($row) {
            return (int)$row['id'];
        }, $polls);

        $placeholders = implode(',', array_fill(0, count($pollIds), '?'));
        $optionsStmt = $db->prepare("
            SELECT id, poll_id, option_text
            FROM poll_options
            WHERE poll_id IN ($placeholders)
            ORDER BY sort_order, id
        ");
        $optionsStmt->execute($pollIds);
        $options = $optionsStmt->fetchAll();
        $optionsByPoll = [];
        foreach ($options as $opt) {
            $optionsByPoll[$opt['poll_id']][] = [
                'id' => (int)$opt['id'],
                'option_text' => $opt['option_text']
            ];
        }

        $voteStmt = $db->prepare("
            SELECT poll_id
            FROM poll_votes
            WHERE user_id = ? AND poll_id IN ($placeholders)
            GROUP BY poll_id
        ");
        $voteStmt->execute(array_merge([$userId], $pollIds));
        $votedPolls = $voteStmt->fetchAll();
        $votedPollIds = array_map(function($row) {
            return (int)$row['poll_id'];
        }, $votedPolls);
        $votedLookup = array_flip($votedPollIds);

        $resultsByPoll = [];
        $totalVotesByPoll = [];
        if (!empty($votedPollIds)) {
            $resultPlaceholders = implode(',', array_fill(0, count($votedPollIds), '?'));
            $resultsStmt = $db->prepare("
                SELECT o.poll_id, o.id, o.option_text, COUNT(v.id) as votes
                FROM poll_options o
                LEFT JOIN poll_votes v ON v.option_id = o.id
                WHERE o.poll_id IN ($resultPlaceholders)
                GROUP BY o.poll_id, o.id, o.option_text
                ORDER BY o.sort_order, o.id
            ");
            $resultsStmt->execute($votedPollIds);
            $results = $resultsStmt->fetchAll();
            foreach ($results as $row) {
                $pollId = (int)$row['poll_id'];
                $resultsByPoll[$pollId][] = [
                    'option_id' => (int)$row['id'],
                    'option_text' => $row['option_text'],
                    'votes' => (int)$row['votes']
                ];
                $totalVotesByPoll[$pollId] = ($totalVotesByPoll[$pollId] ?? 0) + (int)$row['votes'];
            }
        }

        $payload = [];
        foreach ($polls as $poll) {
            $pollId = (int)$poll['id'];
            $hasVoted = isset($votedLookup[$pollId]);
            $entry = [
                'id' => $pollId,
                'question' => $poll['question'],
                'options' => $optionsByPoll[$pollId] ?? [],
                'has_voted' => $hasVoted
            ];
            if ($hasVoted) {
                $entry['results'] = $resultsByPoll[$pollId] ?? [];
                $entry['total_votes'] = $totalVotesByPoll[$pollId] ?? 0;
            }
            $payload[] = $entry;
        }

        echo json_encode(['polls' => $payload]);
    });

    SimpleRouter::post('/polls/{id}/vote', function($id) {
        $auth = new Auth();
        $user = $auth->requireAuth();
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        header('Content-Type: application/json');

        $payload = json_decode(file_get_contents('php://input'), true);
        $optionId = isset($payload['option_id']) ? (int)$payload['option_id'] : 0;
        if ($optionId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Option is required']);
            return;
        }

        $db = Database::getInstance()->getPdo();
        $pollStmt = $db->prepare("SELECT id FROM polls WHERE id = ? AND is_active = TRUE");
        $pollStmt->execute([$id]);
        $poll = $pollStmt->fetch();
        if (!$poll) {
            http_response_code(404);
            echo json_encode(['error' => 'Poll not found']);
            return;
        }

        $optionStmt = $db->prepare("SELECT id FROM poll_options WHERE id = ? AND poll_id = ?");
        $optionStmt->execute([$optionId, $id]);
        if (!$optionStmt->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid option']);
            return;
        }

        try {
            $insertStmt = $db->prepare("
                INSERT INTO poll_votes (poll_id, option_id, user_id)
                VALUES (?, ?, ?)
            ");
            $insertStmt->execute([$id, $optionId, $userId]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => 'You have already voted in this poll.']);
            return;
        }

        echo json_encode(['success' => true]);
    });

    SimpleRouter::get('/shoutbox', function() {
        $auth = new Auth();
        $auth->requireAuth();

        header('Content-Type: application/json');
        $db = Database::getInstance()->getPdo();
        $limit = intval($_GET['limit'] ?? 20);
        $offset = intval($_GET['offset'] ?? 0);
        if ($limit <= 0) {
            $limit = 20;
        }
        if ($limit > 100) {
            $limit = 100;
        }
        if ($offset < 0) {
            $offset = 0;
        }
        $stmt = $db->prepare("
            SELECT s.id, s.message, s.created_at, u.username
            FROM shoutbox_messages s
            INNER JOIN users u ON s.user_id = u.id
            WHERE s.is_hidden = FALSE
            ORDER BY s.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $messages = $stmt->fetchAll();
        echo json_encode(['messages' => $messages]);
    });

    SimpleRouter::post('/shoutbox', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');
        $payload = json_decode(file_get_contents('php://input'), true);
        $message = trim($payload['message'] ?? '');

        if ($message === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Message is required']);
            return;
        }

        if (mb_strlen($message) > 280) {
            http_response_code(400);
            echo json_encode(['error' => 'Message too long']);
            return;
        }

        $db = Database::getInstance()->getPdo();
        $stmt = $db->prepare("
            INSERT INTO shoutbox_messages (user_id, message)
            VALUES (?, ?)
        ");
        $stmt->execute([$user['id'] ?? $user['user_id'], $message]);
        echo json_encode(['success' => true]);
    });

    SimpleRouter::get('/messages/recent', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();

        $userId = $user['user_id'] ?? $user['id'];
        $stmt = $db->prepare("
            SELECT id, 'netmail' as type, from_name, subject, date_written, NULL as echoarea, NULL as echoarea_color
            FROM netmail 
            WHERE user_id = ? 
            UNION ALL
            SELECT em.id, 'echomail' as type, em.from_name, em.subject, em.date_written, e.tag as echoarea, e.color as echoarea_color
            FROM echomail em
            JOIN echoareas e ON em.echoarea_id = e.id
            JOIN user_echoarea_subscriptions ues ON e.id = ues.echoarea_id AND ues.user_id = ?
            ORDER BY date_written DESC
            LIMIT 10
        ");
        $stmt->execute([$userId, $userId]);
        $messages = $stmt->fetchAll();

        echo json_encode(['messages' => $messages]);
    });

    // Chat API endpoints
    SimpleRouter::get('/chat/rooms', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        if (!\BinktermPHP\BbsConfig::isFeatureEnabled('chat')) {
            http_response_code(403);
            echo json_encode(['error' => 'Chat is disabled']);
            return;
        }

        $db = Database::getInstance()->getPdo();
        $stmt = $db->prepare("
            SELECT id, name, description
            FROM chat_rooms
            WHERE is_active = TRUE
            ORDER BY name
        ");
        $stmt->execute();
        $rooms = $stmt->fetchAll();

        echo json_encode(['rooms' => $rooms]);
    });

    SimpleRouter::get('/chat/online', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        if (!\BinktermPHP\BbsConfig::isFeatureEnabled('chat')) {
            http_response_code(403);
            echo json_encode(['error' => 'Chat is disabled']);
            return;
        }

        $onlineUsers = $auth->getOnlineUsers(15);
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        $filtered = [];
        foreach ($onlineUsers as $onlineUser) {
            if ($userId !== null && (int)$onlineUser['user_id'] === (int)$userId) {
                continue;
            }
            $filtered[] = [
                'user_id' => (int)$onlineUser['user_id'],
                'username' => $onlineUser['username'],
                'location' => $onlineUser['location'] ?? ''
            ];
        }

        echo json_encode(['users' => $filtered]);
    });

    SimpleRouter::get('/chat/messages', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        if (!\BinktermPHP\BbsConfig::isFeatureEnabled('chat')) {
            http_response_code(403);
            echo json_encode(['error' => 'Chat is disabled']);
            return;
        }

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : null;
        $dmUserId = isset($_GET['dm_user_id']) ? (int)$_GET['dm_user_id'] : null;
        $beforeId = isset($_GET['before_id']) ? (int)$_GET['before_id'] : null;
        $limit = min((int)($_GET['limit'] ?? 50), 200);
        $queryLimit = $limit + 1;

        if (!$userId || ($roomId && $dmUserId) || (!$roomId && !$dmUserId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid chat target']);
            return;
        }

        $db = Database::getInstance()->getPdo();

        if ($roomId) {
            $sql = "
                SELECT m.id, m.room_id, r.name as room_name, m.from_user_id, u.username as from_username,
                       m.body, m.created_at
                FROM chat_messages m
                JOIN chat_rooms r ON m.room_id = r.id
                JOIN users u ON m.from_user_id = u.id
                WHERE m.room_id = ? AND r.is_active = TRUE
            ";
            $params = [$roomId];
            if ($beforeId) {
                $sql .= " AND m.id < ?";
                $params[] = $beforeId;
            }
            $sql .= " ORDER BY m.id DESC LIMIT ?";
            $params[] = $queryLimit;
            $stmt = $db->prepare($sql);
            foreach ($params as $index => $value) {
                $stmt->bindValue($index + 1, $value, \PDO::PARAM_INT);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } else {
            $sql = "
                SELECT m.id, m.from_user_id, u.username as from_username,
                       m.to_user_id, m.body, m.created_at
                FROM chat_messages m
                JOIN users u ON m.from_user_id = u.id
                WHERE m.room_id IS NULL
                  AND (
                    (m.from_user_id = ? AND m.to_user_id = ?)
                    OR (m.from_user_id = ? AND m.to_user_id = ?)
                  )
            ";
            $params = [$userId, $dmUserId, $dmUserId, $userId];
            if ($beforeId) {
                $sql .= " AND m.id < ?";
                $params[] = $beforeId;
            }
            $sql .= " ORDER BY m.id DESC LIMIT ?";
            $params[] = $queryLimit;
            $stmt = $db->prepare($sql);
            foreach ($params as $index => $value) {
                $stmt->bindValue($index + 1, $value, \PDO::PARAM_INT);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll();
        }

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }
        $rows = array_reverse($rows);

        echo json_encode(['messages' => $rows, 'has_more' => $hasMore]);
    });

    SimpleRouter::post('/chat/send', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        if (!\BinktermPHP\BbsConfig::isFeatureEnabled('chat')) {
            http_response_code(403);
            echo json_encode(['error' => 'Chat is disabled']);
            return;
        }

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $input = json_decode(file_get_contents('php://input'), true);
        $roomId = isset($input['room_id']) ? (int)$input['room_id'] : null;
        $toUserId = isset($input['to_user_id']) ? (int)$input['to_user_id'] : null;
        $body = trim($input['body'] ?? '');

        if (!$userId || ($roomId && $toUserId) || (!$roomId && !$toUserId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid chat target']);
            return;
        }

        if ($body === '' || strlen($body) > 1000) {
            http_response_code(400);
            echo json_encode(['error' => 'Message must be between 1 and 1000 characters']);
            return;
        }

        if ($body === '/source') {
            $body = 'https://github.com/awehttam/binkterm-php';
        }
        if ($body === '/help') {
            $helpBody = 'Commands: /source - transmit the github page to chat; /kick <user> - remove user from room; /ban <user> - ban user from room';
            echo json_encode([
                'success' => true,
                'local_message' => [
                    'from_user_id' => null,
                    'from_username' => 'System',
                    'body' => $helpBody,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'type' => 'local'
                ]
            ]);
            return;
        }

        if (preg_match('/^\\/(kick|ban)\\s+(\\S+)/i', $body, $matches)) {
            if (empty($user['is_admin'])) {
                echo json_encode([
                    'success' => true,
                    'local_message' => [
                        'from_user_id' => null,
                        'from_username' => 'System',
                        'body' => 'Admin access required for moderation commands.',
                        'created_at' => gmdate('Y-m-d H:i:s'),
                        'type' => 'local'
                    ]
                ]);
                return;
            }
            if (!$roomId) {
                echo json_encode([
                    'success' => true,
                    'local_message' => [
                        'from_user_id' => null,
                        'from_username' => 'System',
                        'body' => 'Moderation commands can only be used in rooms.',
                        'created_at' => gmdate('Y-m-d H:i:s'),
                        'type' => 'local'
                    ]
                ]);
                return;
            }

            $action = strtolower($matches[1]);
            $targetName = ltrim($matches[2], '@');

            $db = Database::getInstance()->getPdo();
            $roomStmt = $db->prepare("SELECT id FROM chat_rooms WHERE id = ? AND is_active = TRUE");
            $roomStmt->execute([$roomId]);
            if (!$roomStmt->fetch()) {
                echo json_encode([
                    'success' => true,
                    'local_message' => [
                        'from_user_id' => null,
                        'from_username' => 'System',
                        'body' => 'Chat room not found.',
                        'created_at' => gmdate('Y-m-d H:i:s'),
                        'type' => 'local'
                    ]
                ]);
                return;
            }

            $userStmt = $db->prepare("SELECT id, username FROM users WHERE LOWER(username) = LOWER(?) AND is_active = TRUE");
            $userStmt->execute([$targetName]);
            $targetUser = $userStmt->fetch();
            if (!$targetUser) {
                echo json_encode([
                    'success' => true,
                    'local_message' => [
                        'from_user_id' => null,
                        'from_username' => 'System',
                        'body' => "User '{$targetName}' not found.",
                        'created_at' => gmdate('Y-m-d H:i:s'),
                        'type' => 'local'
                    ]
                ]);
                return;
            }

            if ((int)$targetUser['id'] === (int)$userId) {
                echo json_encode([
                    'success' => true,
                    'local_message' => [
                        'from_user_id' => null,
                        'from_username' => 'System',
                        'body' => 'You cannot moderate yourself.',
                        'created_at' => gmdate('Y-m-d H:i:s'),
                        'type' => 'local'
                    ]
                ]);
                return;
            }

            $expiresAt = null;
            if ($action === 'kick') {
                $utcNow = new DateTime('now', new DateTimeZone('UTC'));
                $utcNow->modify('+10 minutes');
                $expiresAt = $utcNow->format('Y-m-d H:i:s');
            }

            $stmt = $db->prepare("
                INSERT INTO chat_room_bans (room_id, user_id, banned_by, reason, expires_at)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT (room_id, user_id)
                DO UPDATE SET banned_by = EXCLUDED.banned_by,
                              reason = EXCLUDED.reason,
                              expires_at = EXCLUDED.expires_at,
                              created_at = NOW()
            ");
            $stmt->execute([$roomId, (int)$targetUser['id'], $user['user_id'] ?? $user['id'], null, $expiresAt]);

            $actionLabel = $action === 'ban' ? 'banned' : 'kicked';
            echo json_encode([
                'success' => true,
                'local_message' => [
                    'from_user_id' => null,
                    'from_username' => 'System',
                    'body' => "{$targetUser['username']} has been {$actionLabel} from this room.",
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'type' => 'local'
                ]
            ]);
            return;
        }

        $db = Database::getInstance()->getPdo();

        if ($roomId) {
            error_log('[CHAT SEND] user_id=' . $userId . ' room_id=' . $roomId);
            $roomStmt = $db->prepare("SELECT id FROM chat_rooms WHERE id = ? AND is_active = TRUE");
            $roomStmt->execute([$roomId]);
            if (!$roomStmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Chat room not found']);
                return;
            }

            $banStmt = $db->prepare("
                SELECT 1
                FROM chat_room_bans
                WHERE room_id = ? AND user_id = ? AND (expires_at IS NULL OR expires_at > NOW())
            ");
            $banStmt->execute([$roomId, $userId]);
            $banHit = $banStmt->fetchColumn();
            error_log('[CHAT SEND] ban_hit=' . ($banHit ? '1' : '0'));
            if ($banHit) {
                http_response_code(403);
                echo json_encode(['error' => 'You are banned from this room']);
                return;
            }
        } else {
            $userStmt = $db->prepare("SELECT id FROM users WHERE id = ? AND is_active = TRUE");
            $userStmt->execute([$toUserId]);
            if (!$userStmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
                return;
            }
        }

        if ($roomId) {
            $stmt = $db->prepare("
                INSERT INTO chat_messages (room_id, from_user_id, to_user_id, body)
                SELECT ?, ?, ?, ?
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM chat_room_bans
                    WHERE room_id = ? AND user_id = ? AND (expires_at IS NULL OR expires_at > NOW())
                )
                RETURNING id, created_at
            ");
            $stmt->execute([$roomId, $userId, $toUserId, $body, $roomId, $userId]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO chat_messages (room_id, from_user_id, to_user_id, body)
                VALUES (?, ?, ?, ?)
                RETURNING id, created_at
            ");
            $stmt->execute([$roomId, $userId, $toUserId, $body]);
        }
        $result = $stmt->fetch();
        if (!$result) {
            error_log('[CHAT SEND] insert blocked by ban');
            http_response_code(403);
            echo json_encode(['error' => 'You are banned from this room']);
            return;
        }

        echo json_encode([
            'success' => true,
            'message_id' => (int)$result['id'],
            'created_at' => $result['created_at']
        ]);
    });

    SimpleRouter::post('/chat/moderate', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        if (!\BinktermPHP\BbsConfig::isFeatureEnabled('chat')) {
            http_response_code(403);
            echo json_encode(['error' => 'Chat is disabled']);
            return;
        }

        if (empty($user['is_admin'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $roomId = isset($input['room_id']) ? (int)$input['room_id'] : null;
        $targetUserId = isset($input['user_id']) ? (int)$input['user_id'] : null;
        $action = $input['action'] ?? '';

        if (!$roomId || !$targetUserId || !in_array($action, ['kick', 'ban'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid moderation request']);
            return;
        }

        $db = Database::getInstance()->getPdo();
        $roomStmt = $db->prepare("SELECT id FROM chat_rooms WHERE id = ?");
        $roomStmt->execute([$roomId]);
        if (!$roomStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Chat room not found']);
            return;
        }

        $userStmt = $db->prepare("SELECT id FROM users WHERE id = ? AND is_active = TRUE");
        $userStmt->execute([$targetUserId]);
        if (!$userStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found']);
            return;
        }

        $expiresAt = null;
        if ($action === 'kick') {
            $utcNow = new DateTime('now', new DateTimeZone('UTC'));
            $utcNow->modify('+10 minutes');
            $expiresAt = $utcNow->format('Y-m-d H:i:s');
        }

        $stmt = $db->prepare("
            INSERT INTO chat_room_bans (room_id, user_id, banned_by, reason, expires_at)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT (room_id, user_id)
            DO UPDATE SET banned_by = EXCLUDED.banned_by,
                          reason = EXCLUDED.reason,
                          expires_at = EXCLUDED.expires_at,
                          created_at = NOW()
        ");
        $stmt->execute([$roomId, $targetUserId, $user['user_id'] ?? $user['id'], null, $expiresAt]);

        echo json_encode(['success' => true]);
    });

    SimpleRouter::get('/chat/poll', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        if (!\BinktermPHP\BbsConfig::isFeatureEnabled('chat')) {
            http_response_code(403);
            echo json_encode(['error' => 'Chat is disabled']);
            return;
        }

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $lastId = (int)($_GET['since_id'] ?? 0);

        $db = Database::getInstance()->getPdo();
        $stmt = $db->prepare("
            SELECT m.id, m.room_id, r.name as room_name, m.from_user_id, u.username as from_username,
                   m.to_user_id, m.body, m.created_at
            FROM chat_messages m
            LEFT JOIN chat_rooms r ON m.room_id = r.id
            JOIN users u ON m.from_user_id = u.id
            WHERE m.id > ?
              AND (
                (m.room_id IS NOT NULL AND r.is_active = TRUE)
                OR m.to_user_id = ?
                OR m.from_user_id = ?
              )
            ORDER BY m.id ASC
            LIMIT 200
        ");
        $stmt->execute([$lastId, $userId, $userId]);
        $rows = $stmt->fetchAll();

        $messages = [];
        foreach ($rows as $row) {
            $messages[] = [
                'id' => (int)$row['id'],
                'type' => $row['room_id'] ? 'room' : 'dm',
                'room_id' => $row['room_id'] ? (int)$row['room_id'] : null,
                'room_name' => $row['room_name'],
                'from_user_id' => (int)$row['from_user_id'],
                'from_username' => $row['from_username'],
                'to_user_id' => $row['to_user_id'] ? (int)$row['to_user_id'] : null,
                'body' => $row['body'],
                'created_at' => $row['created_at']
            ];
        }

        echo json_encode(['messages' => $messages]);
    });

    SimpleRouter::get('/echoareas', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $filter = $_GET['filter'] ?? 'active';
        $subscribedOnly = $_GET['subscribed_only'] ?? 'false';
        $isAdmin = !empty($user['is_admin']);
        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        $db = Database::getInstance()->getPdo();

        // Query with separate subqueries for total and unread counts
        $sql = "SELECT
                    e.id,
                    e.tag,
                    e.description,
                    e.moderator,
                    e.uplink_address,
                    e.color,
                    e.is_active,
                    e.created_at,
                    e.domain,
                    e.is_local,
                    e.is_sysop_only,
                    COALESCE(total_counts.message_count, 0) as message_count,
                    COALESCE(unread_counts.unread_count, 0) as unread_count
                FROM echoareas e";

        // Add subscription filtering if requested
        if ($subscribedOnly === 'true') {
            $sql .= " INNER JOIN user_echoarea_subscriptions ues ON e.id = ues.echoarea_id AND ues.user_id = ? AND ues.is_active = TRUE";
            $params = [$userId, $userId];
        } else {
            $params = [$userId];
        }

        $sql .= " LEFT JOIN (
                    SELECT echoarea_id, COUNT(*) as message_count
                    FROM echomail 
                    GROUP BY echoarea_id
                ) total_counts ON e.id = total_counts.echoarea_id
                LEFT JOIN (
                    SELECT 
                        em.echoarea_id,
                        COUNT(*) as unread_count
                    FROM echomail em
                    LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                    WHERE mrs.read_at IS NULL
                    GROUP BY em.echoarea_id
                ) unread_counts ON e.id = unread_counts.echoarea_id";

        if ($subscribedOnly === 'true') {
            // For subscribed only, we already have the JOIN, just need to add WHERE conditions
            $conditions = [];
            if (!$isAdmin) {
                $conditions[] = "COALESCE(e.is_sysop_only, FALSE) = FALSE";
            }
            if ($filter === 'active') {
                $conditions[] = "e.is_active = TRUE";
            } elseif ($filter === 'inactive') {
                $conditions[] = "e.is_active = FALSE";
            }
            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }
        } else {
            // Standard filtering
            $conditions = [];
            if (!$isAdmin) {
                $conditions[] = "COALESCE(e.is_sysop_only, FALSE) = FALSE";
            }
            if ($filter === 'active') {
                $conditions[] = "e.is_active = TRUE";
            } elseif ($filter === 'inactive') {
                $conditions[] = "e.is_active = FALSE";
            }
            if (!empty($conditions)) {
                $sql .= " WHERE " . implode(' AND ', $conditions);
            }
        }
        // 'all' filter shows everything

        $sql .= " ORDER BY e.tag";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $echoareas = $stmt->fetchAll();

        echo json_encode(['echoareas' => $echoareas]);
    });

    SimpleRouter::get('/echoareas/{id}', function($id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();
        $stmt = $db->prepare("SELECT * FROM echoareas WHERE id = ?");
        $stmt->execute([$id]);
        $echoarea = $stmt->fetch();

        if ($echoarea) {
            echo json_encode(['echoarea' => $echoarea]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Echo area not found']);
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::post('/echoareas', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        header('Content-Type: application/json');

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            $tag = strtoupper(trim($input['tag'] ?? ''));
            $description = trim($input['description'] ?? '');
            $moderator = trim($input['moderator'] ?? '') ?: null;
            $uplinkAddress = trim($input['uplink_address'] ?? '') ?: null;
            $color = $input['color'] ?? '#28a745';
            $isActive = !empty($input['is_active']);
            $isLocal = !empty($input['is_local']);
            $isSysopOnly = !empty($input['is_sysop_only']);
            $domain = trim($input['domain'] ?? '' ) ?: null;

            if (empty($tag) || empty($description)) {
                throw new \Exception('Tag and description are required');
            }

            if (!preg_match('/^[A-Z0-9._-]+$/', $tag)) {
                throw new \Exception('Invalid tag format. Use only letters, numbers, dots, underscores, and hyphens');
            }

            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                throw new \Exception('Invalid color format');
            }

            $db = Database::getInstance()->getPdo();

            $stmt = $db->prepare("
                INSERT INTO echoareas (tag, description, moderator, uplink_address, color, is_active, is_local, is_sysop_only, domain)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $result = $stmt->execute([$tag, $description, $moderator, $uplinkAddress, $color, $isActive ? 1 : 0, $isLocal ? 1 : 0, $isSysopOnly ? 1 : 0, $domain]);

            if ($result) {
                echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
            } else {
                throw new \Exception('Failed to create echo area');
            }
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    SimpleRouter::put('/echoareas/{id}', function($id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        header('Content-Type: application/json');

        try {
            $input = json_decode(file_get_contents('php://input'), true);

            $tag = strtoupper(trim($input['tag'] ?? ''));
            $description = trim($input['description'] ?? '');
            $moderator = trim($input['moderator'] ?? '') ?: null;
            $uplinkAddress = trim($input['uplink_address'] ?? '') ?: null;
            $color = $input['color'] ?? '#28a745';
            $isActive = !empty($input['is_active']);
            $isLocal = !empty($input['is_local']);
            $isSysopOnly = !empty($input['is_sysop_only']);
            $domain = trim($input['domain'] ?? '' ) ?: null;

            if (empty($tag) || empty($description)) {
                throw new \Exception('Tag and description are required');
            }

            if (!preg_match('/^[A-Z0-9._-]+$/', $tag)) {
                throw new \Exception('Invalid tag format. Use only letters, numbers, dots, underscores, and hyphens');
            }

            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                throw new \Exception('Invalid color format');
            }

            $db = Database::getInstance()->getPdo();

            $stmt = $db->prepare("
                UPDATE echoareas
                SET tag = ?, description = ?, moderator = ?, uplink_address = ?, color = ?, is_active = ?, is_local = ?, is_sysop_only = ?, domain = ?
                WHERE id = ?
            ");

            $result = $stmt->execute([$tag, $description, $moderator, $uplinkAddress, $color, $isActive ? 1 : 0, $isLocal ? 1 : 0, $isSysopOnly ? 1 : 0, $domain, $id]);

            if ($result && $stmt->rowCount() > 0) {
                echo json_encode(['success' => true]);
            } else {
                throw new \Exception('Echo area not found or no changes made');
            }
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::delete('/echoareas/{id}', function($id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        header('Content-Type: application/json');

        try {
            $db = Database::getInstance()->getPdo();

            // Check if echo area has messages
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM echomail WHERE echoarea_id = ?");
            $stmt->execute([$id]);
            $messageCount = $stmt->fetch()['count'];

            if ($messageCount > 0) {
                throw new \Exception("Cannot delete echo area with existing messages ($messageCount messages). Deactivate instead.");
            }

            $stmt = $db->prepare("DELETE FROM echoareas WHERE id = ?");
            $result = $stmt->execute([$id]);

            if ($result && $stmt->rowCount() > 0) {
                echo json_encode(['success' => true]);
            } else {
                throw new \Exception('Echo area not found');
            }
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::get('/echoareas/stats', function() {
        $auth = new Auth();
        $auth->requireAuth();

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();

        $activeCount = $db->query("SELECT COUNT(*) as count FROM echoareas WHERE is_active = TRUE")->fetch()['count'];
        $totalMessages = $db->query("SELECT SUM(message_count) as count FROM echoareas")->fetch()['count'] ?? 0;
        $todayMessages = $db->query("SELECT COUNT(*) as count FROM echomail WHERE date_received > date('now')")->fetch()['count'];

        echo json_encode([
            'active_count' => (int)$activeCount,
            'total_messages' => (int)$totalMessages,
            'today_messages' => (int)$todayMessages
        ]);
    });

    // Message API routes
    SimpleRouter::get('/messages/netmail', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $handler = new MessageHandler();
        $page = intval($_GET['page'] ?? 1);
        $filter = $_GET['filter'] ?? 'all';
        $threaded = isset($_GET['threaded']) && $_GET['threaded'] === 'true';
        $result = $handler->getNetmail($user['user_id'], $page, null, $filter, $threaded);
        echo json_encode($result);
    });

    // Statistics endpoints - must come before parameterized routes
    SimpleRouter::get('/messages/netmail/stats', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        // Get system's FidoNet address
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $systemAddress = $binkpConfig->getSystemAddress();
        } catch (\Exception $e) {
            $systemAddress = null;
        }

        // Total messages for user (received + sent)
        if ($systemAddress) {
            $totalStmt = $db->prepare("SELECT COUNT(*) as count FROM netmail WHERE user_id = ? OR from_address = ?");
            $totalStmt->execute([$userId, $systemAddress]);
        } else {
            $totalStmt = $db->prepare("SELECT COUNT(*) as count FROM netmail WHERE user_id = ?");
            $totalStmt->execute([$userId]);
        }
        $total = $totalStmt->fetch()['count'];

        // Unread messages (using message_read_status table)
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $myAddresses = $binkpConfig->getMyAddresses();
            $myAddresses[] = $binkpConfig->getSystemAddress();
        } catch (\Exception $e) {
            $myAddresses = [];
        }

        if (!empty($myAddresses)) {
            $addressPlaceholders = implode(',', array_fill(0, count($myAddresses), '?'));
            $unreadStmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM netmail n
                LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
                WHERE mrs.read_at IS NULL
                  AND (
                    n.user_id = ?
                    OR ((LOWER(n.to_name) = LOWER(?) OR LOWER(n.to_name) = LOWER(?)) AND n.to_address IN ($addressPlaceholders))
                  )
            ");
            $params = [$userId, $userId, $user['username'], $user['real_name']];
            $params = array_merge($params, $myAddresses);
            $unreadStmt->execute($params);
        } else {
            $unreadStmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM netmail n
                LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
                WHERE n.user_id = ? AND mrs.read_at IS NULL
            ");
            $unreadStmt->execute([$userId, $userId]);
        }
        $unread = $unreadStmt->fetch()['count'];

        // Sent messages
        if ($systemAddress) {
            $sentStmt = $db->prepare("SELECT COUNT(*) as count FROM netmail WHERE from_address = ?");
            $sentStmt->execute([$systemAddress]);
            $sent = $sentStmt->fetch()['count'];
        } else {
            $sent = 0;
        }

        echo json_encode([
            'total' => $total,
            'unread' => $unread,
            'sent' => $sent
        ]);
    });

    SimpleRouter::get('/messages/netmail/{id}', function($id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $handler = new MessageHandler();
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $message = $handler->getMessage($id, 'netmail', $userId);

        if ($message) {
            // Parse REPLYTO kludge from message text and add to response
            $replyToData = parseReplyToKludge($message['message_text']);
            if ($replyToData) {
                $message['replyto_address'] = $replyToData['address'];
                $message['replyto_name'] = $replyToData['name'];
            }

            // Also check kludge_lines for REPLYTO
            if (isset($message['kludge_lines'])) {
                $replyToDataKludge = parseReplyToKludge($message['kludge_lines']);
                if ($replyToDataKludge) {
                    $message['replyto_address'] = $replyToDataKludge['address'];
                    $message['replyto_name'] = $replyToDataKludge['name'];
                }
            }

            echo json_encode($message);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Message not found']);
        }
    });

    SimpleRouter::delete('/messages/netmail/{id}', function($id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $handler = new MessageHandler();
        $result = $handler->deleteNetmail($id, $user['user_id']);

        if ($result) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Message not found or access denied']);
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::get('/messages/echomail', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        $handler = new MessageHandler();
        $page = intval($_GET['page'] ?? 1);
        $filter = $_GET['filter'] ?? 'all';
        $threaded = isset($_GET['threaded']) && $_GET['threaded'] === 'true';

        // Get messages from subscribed echoareas only
        $result = $handler->getEchomailFromSubscribedAreas($userId, $page, null, $filter, $threaded);
        echo json_encode($result);
    });

    // Echomail statistics endpoints - must come before parameterized routes
    SimpleRouter::get('/messages/echomail/stats', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $isAdmin = !empty($user['is_admin']);
        $sysopFilter = $isAdmin ? "" : " AND COALESCE(ea.is_sysop_only, FALSE) = FALSE";

        // Global echomail statistics (only from subscribed echoareas)
        $totalStmt = $db->prepare("SELECT COUNT(*) as count FROM echomail em JOIN echoareas ea ON em.echoarea_id = ea.id JOIN user_echoarea_subscriptions ues ON ea.id = ues.echoarea_id AND ues.user_id = ? WHERE ea.is_active = TRUE{$sysopFilter}");
        $totalStmt->execute([$userId]);
        $total = $totalStmt->fetch()['count'];

        $recentStmt = $db->prepare("SELECT COUNT(*) as count FROM echomail em JOIN echoareas ea ON em.echoarea_id = ea.id JOIN user_echoarea_subscriptions ues ON ea.id = ues.echoarea_id AND ues.user_id = ? WHERE ea.is_active = TRUE AND date_received > NOW() - INTERVAL '1 day'{$sysopFilter}");
        $recentStmt->execute([$userId]);
        $recent = $recentStmt->fetch()['count'];

        $areasStmt = $db->prepare("SELECT COUNT(*) as count FROM echoareas ea JOIN user_echoarea_subscriptions ues ON ea.id = ues.echoarea_id AND ues.user_id = ? WHERE ea.is_active = TRUE{$sysopFilter}");
        $areasStmt->execute([$userId]);
        $areas = $areasStmt->fetch()['count'];

        // Filter counts for this user
        $allCount = $total; // All messages is same as total
        $unreadCount = 0;
        $readCount = 0;
        $toMeCount = 0;
        $savedCount = 0;

        if ($userId) {
            // Get user info for 'to me' filter
            $userStmt = $db->prepare("SELECT username, real_name FROM users WHERE id = ?");
            $userStmt->execute([$userId]);
            $userInfo = $userStmt->fetch();

            // Unread count
            $unreadStmt = $db->prepare("
                SELECT COUNT(*) as count FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                JOIN user_echoarea_subscriptions ues ON ea.id = ues.echoarea_id AND ues.user_id = ?
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                WHERE ea.is_active = TRUE AND mrs.read_at IS NULL{$sysopFilter}
            ");
            $unreadStmt->execute([$userId, $userId]);
            $unreadCount = $unreadStmt->fetch()['count'];

            // Read count
            $readStmt = $db->prepare("
                SELECT COUNT(*) as count FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                JOIN user_echoarea_subscriptions ues ON ea.id = ues.echoarea_id AND ues.user_id = ?
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                WHERE ea.is_active = TRUE AND mrs.read_at IS NOT NULL{$sysopFilter}
            ");
            $readStmt->execute([$userId, $userId]);
            $readCount = $readStmt->fetch()['count'];

            // To Me count
            if ($userInfo) {
                $toMeStmt = $db->prepare("
                    SELECT COUNT(*) as count FROM echomail em
                    JOIN echoareas ea ON em.echoarea_id = ea.id
                    JOIN user_echoarea_subscriptions ues ON ea.id = ues.echoarea_id AND ues.user_id = ?
                    WHERE ea.is_active = TRUE AND (LOWER(em.to_name) = LOWER(?) OR LOWER(em.to_name) = LOWER(?)){$sysopFilter}
                ");
                $toMeStmt->execute([$userId, $userInfo['username'], $userInfo['real_name']]);
                $toMeCount = $toMeStmt->fetch()['count'];
            }

            // Saved count
            $savedStmt = $db->prepare("
                SELECT COUNT(*) as count FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                JOIN user_echoarea_subscriptions ues ON ea.id = ues.echoarea_id AND ues.user_id = ?
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE ea.is_active = TRUE AND sav.id IS NOT NULL{$sysopFilter}
            ");
            $savedStmt->execute([$userId, $userId]);
            $savedCount = $savedStmt->fetch()['count'];
        }

        // Drafts count
        $draftsCount = 0;
        if ($userId) {
            $draftsStmt = $db->prepare("SELECT COUNT(*) as count FROM drafts WHERE user_id = ? AND type = 'echomail'");
            $draftsStmt->execute([$userId]);
            $draftsCount = $draftsStmt->fetch()['count'];
        }

        echo json_encode([
            'total' => $total,
            'recent' => $recent,
            'areas' => $areas,
            'unread' => $unreadCount,
            'filter_counts' => [
                'all' => $allCount,
                'unread' => $unreadCount,
                'read' => $readCount,
                'tome' => $toMeCount,
                'saved' => $savedCount,
                'drafts' => $draftsCount
            ]
        ]);
    });

    SimpleRouter::get('/messages/echomail/stats/{echoarea}', function($echoarea) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        // URL decode the echoarea parameter to handle dots and special characters
        $echoarea = urldecode($echoarea);
        $foo=explode("@", $echoarea);
        $echoarea=$foo[0];
        $domain=$foo[1];
        $db = Database::getInstance()->getPdo();
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $isAdmin = !empty($user['is_admin']);

        if (!$isAdmin && $userId) {
            $subscriptionManager = new \BinktermPHP\EchoareaSubscriptionManager();
            $echoareaStmt = $db->prepare("SELECT id FROM echoareas WHERE tag = ? AND domain = ? AND is_active = TRUE");
            $echoareaStmt->execute([$echoarea, $domain]);
            $echoareaRow = $echoareaStmt->fetch();

            if (!$echoareaRow || !$subscriptionManager->isUserSubscribed($userId, $echoareaRow['id'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
                return;
            }
        }

        // Statistics for specific echoarea
        $stmt = $db->prepare("
            SELECT COUNT(*) as total, 
                   COUNT(CASE WHEN date_received > NOW() - INTERVAL '1 day' THEN 1 END) as recent
            FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            WHERE ea.tag = ? AND domain=?
        ");
        $stmt->execute([$echoarea, $domain]);
        $stats = $stmt->fetch();

        // Filter counts for this echoarea and user
        $allCount = $stats['total']; // All messages is same as total
        $unreadCount = 0;
        $readCount = 0;
        $toMeCount = 0;
        $savedCount = 0;

        if ($userId) {
            // Get user info for 'to me' filter
            $userStmt = $db->prepare("SELECT username, real_name FROM users WHERE id = ?");
            $userStmt->execute([$userId]);
            $userInfo = $userStmt->fetch();

            // Unread count
            $unreadStmt = $db->prepare("
                SELECT COUNT(*) as count FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                WHERE ea.tag = ? AND mrs.read_at IS NULL
            ");
            $unreadStmt->execute([$userId, $echoarea]);
            $unreadCount = $unreadStmt->fetch()['count'];

            // Read count
            $readStmt = $db->prepare("
                SELECT COUNT(*) as count FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                WHERE ea.tag = ? AND mrs.read_at IS NOT NULL
            ");
            $readStmt->execute([$userId, $echoarea]);
            $readCount = $readStmt->fetch()['count'];

            // To Me count
            if ($userInfo) {
                $toMeStmt = $db->prepare("
                    SELECT COUNT(*) as count FROM echomail em
                    JOIN echoareas ea ON em.echoarea_id = ea.id
                    WHERE ea.tag = ? AND (LOWER(em.to_name) = LOWER(?) OR LOWER(em.to_name) = LOWER(?))
                ");
                $toMeStmt->execute([$echoarea, $userInfo['username'], $userInfo['real_name']]);
                $toMeCount = $toMeStmt->fetch()['count'];
            }

            // Saved count
            $savedStmt = $db->prepare("
                SELECT COUNT(*) as count FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE ea.tag = ? AND sav.id IS NOT NULL
            ");
            $savedStmt->execute([$userId, $echoarea]);
            $savedCount = $savedStmt->fetch()['count'];
        }

        echo json_encode([
            'echoarea' => $echoarea,
            'total' => $stats['total'],
            'recent' => $stats['recent'],
            'unread' => $unreadCount,
            'filter_counts' => [
                'all' => $allCount,
                'unread' => $unreadCount,
                'read' => $readCount,
                'tome' => $toMeCount,
                'saved' => $savedCount
            ]
        ]);
    })->where(['echoarea' => '[A-Za-z0-9@._-]+']);

    // Route for getting specific echomail message by ID only (when echoarea not known)
    SimpleRouter::get('/messages/echomail/message/{id}', function($id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        $handler = new MessageHandler();
        $message = $handler->getMessage($id, 'echomail', $userId);

        if ($message) {
            // Parse REPLYTO kludge from message text and add to response
            $replyToData = parseReplyToKludge($message['message_text']);
            if ($replyToData) {
                $message['replyto_address'] = $replyToData['address'];
                $message['replyto_name'] = $replyToData['name'];
            }

            // Also check kludge_lines for REPLYTO
            if (isset($message['kludge_lines'])) {
                $replyToDataKludge = parseReplyToKludge($message['kludge_lines']);
                if ($replyToDataKludge) {
                    $message['replyto_address'] = $replyToDataKludge['address'];
                    $message['replyto_name'] = $replyToDataKludge['name'];
                }
            }

            echo json_encode($message);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Message not found']);
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::get('/messages/echomail/{id}/download', function($id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $handler = new MessageHandler();
        $message = $handler->getMessage($id, 'echomail', $userId);

        if (!$message) {
            http_response_code(404);
            echo 'Message not found';
            return;
        }

        $subject = $message['subject'] ?? 'message';
        $filename = sanitizeFilenameForWindows((string)$subject) . '.txt';
        $area = $message['echoarea'] ?? '';
        $domain = $message['domain'] ?? '';
        $areaLabel = $area !== '' ? $area . ($domain !== '' ? '@' . $domain : '') : '';

        $headerLines = [
            'From: ' . ($message['from_name'] ?? 'Unknown'),
            'To: ' . ($message['to_name'] ?? 'All'),
            'Subject: ' . ($message['subject'] ?? '(No Subject)'),
            'Date: ' . ($message['date_written'] ?? ''),
            'Area: ' . $areaLabel
        ];

        $headerText = implode("\r\n", $headerLines) . "\r\n\r\n";
        $bodyText = (string)($message['message_text'] ?? '');
        $bodyText = str_replace(["\r\n", "\r"], "\n", $bodyText);
        $bodyText = str_replace("\n", "\r\n", $bodyText);
        $content = $headerText . $bodyText;

        $charset = 'utf-8';
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'CP437//TRANSLIT//IGNORE', $content);
            if ($converted !== false) {
                $content = $converted;
                $charset = 'cp437';
            }
        }

        $safeFilename = str_replace(['"', "\r", "\n"], '_', $filename);
        header('Content-Type: text/plain; charset=' . $charset);
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
        header('X-Content-Type-Options: nosniff');
        echo $content;
    })->where(['id' => '[0-9]+']);

    SimpleRouter::get('/messages/echomail/{echoarea}', function($echoarea) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        // URL decode the echoarea parameter to handle dots and special characters
        $echoarea = urldecode($echoarea);

        $foo=explode("@", $echoarea);
        $echoarea=$foo[0];
        $domain=$foo[1];

        $handler = new MessageHandler();
        $page = intval($_GET['page'] ?? 1);
        $filter = $_GET['filter'] ?? 'all';
        $threaded = isset($_GET['threaded']) && $_GET['threaded'] === 'true';
        $result = $handler->getEchomail($echoarea, $domain, $page, null, $userId, $filter, $threaded, false);
        echo json_encode($result);
    })->where(['echoarea' => '[A-Za-z0-9@._-]+']);

    SimpleRouter::get('/messages/echomail/{echoarea}/{id}', function($echoarea, $id) {
        $auth = new Auth();
        $user = $auth->requireAuth();
        header('Content-Type: application/json');

        // URL decode the echoarea parameter to handle dots and special characters
        $echoarea = urldecode($echoarea);
        $foo=explode("@", $echoarea);
        $echoarea=$foo[0];
        $domain=$foo[1];

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        $handler = new MessageHandler();
        $message = $handler->getMessage($id, 'echomail', $userId);

        if ($message) {
            // Parse REPLYTO kludge from message text and add to response
            $replyToData = parseReplyToKludge($message['message_text']);
            if ($replyToData) {
                $message['replyto_address'] = $replyToData['address'];
                $message['replyto_name'] = $replyToData['name'];
            }

            // Also check kludge_lines for REPLYTO
            if (isset($message['kludge_lines'])) {
                $replyToDataKludge = parseReplyToKludge($message['kludge_lines']);
                if ($replyToDataKludge) {
                    $message['replyto_address'] = $replyToDataKludge['address'];
                    $message['replyto_name'] = $replyToDataKludge['name'];
                }
            }

            echo json_encode($message);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Message not found']);
        }
    })->where(['echoarea' => '[A-Za-z0-9._@-]+', 'id' => '[0-9]+']);

    SimpleRouter::post('/messages/send', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $type = $input['type'] ?? '';

        $handler = new MessageHandler();

        try {
            if ($type === 'netmail') {

                if(trim($input['to_address'])==""){
                    $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
                    $input['to_address'] = $binkpConfig->getSystemAddress();
                }

                $crashmailFlag = !empty($input['crashmail']);
                $result = $handler->sendNetmail(
                    $user['user_id'],
                    $input['to_address'],
                    $input['to_name'],
                    $input['subject'],
                    $input['message_text'],
                    null, // fromName
                    $input['reply_to_id'] ?? null,
                    $crashmailFlag
                );
            } elseif ($type === 'echomail') {
                $foo=explode("@", $input['echoarea']);
                $echoarea = $foo[0];
                $domain = $foo[1];

                $result = $handler->postEchomail(
                    $user['user_id'],
                    $echoarea,
                    $domain,
                    $input['to_name'],
                    $input['subject'],
                    $input['message_text'],
                    $input['reply_to_id']
                );
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid message type']);
                return;
            }

            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to send message']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    // Save message draft
    SimpleRouter::post('/messages/draft', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON input']);
            return;
        }

        $handler = new MessageHandler();

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(500);
            echo json_encode(['error' => 'User ID not found in session']);
            return;
        }

        try {
            $result = $handler->saveDraft($userId, $input);

            if ($result['success']) {
                echo json_encode($result);
            } else {
                http_response_code(500);
                echo json_encode(['error' => $result['error'] ?? 'Failed to save draft']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    // Get user's drafts
    SimpleRouter::get('/messages/drafts', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $type = $_GET['type'] ?? null; // Optional filter by type

        $handler = new MessageHandler();

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(500);
            echo json_encode(['error' => 'User ID not found in session']);
            return;
        }

        try {
            $drafts = $handler->getUserDrafts($userId, $type);
            echo json_encode(['success' => true, 'drafts' => $drafts]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    // Get specific draft
    SimpleRouter::get('/messages/drafts/{id}', function($id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $handler = new MessageHandler();

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(500);
            echo json_encode(['error' => 'User ID not found in session']);
            return;
        }

        try {
            $draft = $handler->getDraft($userId, $id);
            if ($draft) {
                echo json_encode(['success' => true, 'draft' => $draft]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Draft not found']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    // Delete draft
    SimpleRouter::delete('/messages/drafts/{id}', function($id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $handler = new MessageHandler();

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(500);
            echo json_encode(['error' => 'User ID not found in session']);
            return;
        }

        try {
            $result = $handler->deleteDraft($userId, $id);
            echo json_encode($result);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    SimpleRouter::get('/messages/search', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $query = $_GET['q'] ?? '';
        $type = $_GET['type'] ?? null;
        $echoarea = $_GET['echoarea'] ?? null;

        // URL decode the echoarea parameter if provided
        if ($echoarea) {
            $echoarea = urldecode($echoarea);
        }

        if (strlen($query) < 2) {
            http_response_code(400);
            echo json_encode(['error' => 'Query too short']);
            return;
        }

        $handler = new MessageHandler();

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        $messages = $handler->searchMessages($query, $type, $echoarea, $userId);
        echo json_encode(['messages' => $messages]);
    });

    // Mark message as read
    SimpleRouter::post('/messages/{type}/{id}/read', function($type, $id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(500);
            echo json_encode(['error' => 'User ID not found in session']);
            return;
        }

        if (!in_array($type, ['echomail', 'netmail'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid message type']);
            return;
        }

        try {
            $db = Database::getInstance()->getPdo();

            // Insert or update read status
            $stmt = $db->prepare("
                INSERT INTO message_read_status (user_id, message_id, message_type, read_at)
                VALUES (?, ?, ?, NOW())
                ON CONFLICT (user_id, message_id, message_type) DO UPDATE SET
                    read_at = EXCLUDED.read_at
            ");

            $result = $stmt->execute([$userId, (int)$id, $type]);

            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to mark message as read']);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
    });

    // Save message for later viewing
    SimpleRouter::post('/messages/{type}/{id}/save', function($type, $id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(500);
            echo json_encode(['error' => 'User ID not found in session']);
            return;
        }

        if (!in_array($type, ['echomail', 'netmail'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid message type']);
            return;
        }

        try {
            $db = Database::getInstance()->getPdo();

            // Insert saved message (ignore if already exists)
            $stmt = $db->prepare("
                INSERT INTO saved_messages (user_id, message_id, message_type, saved_at)
                VALUES (?, ?, ?, NOW())
                ON CONFLICT (user_id, message_id, message_type) DO NOTHING
            ");

            $result = $stmt->execute([$userId, (int)$id, $type]);

            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Message saved']);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to save message']);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
    });

    // Unsave message
    SimpleRouter::delete('/messages/{type}/{id}/save', function($type, $id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(500);
            echo json_encode(['error' => 'User ID not found in session']);
            return;
        }

        if (!in_array($type, ['echomail', 'netmail'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid message type']);
            return;
        }

        try {
            $db = Database::getInstance()->getPdo();

            // Delete saved message
            $stmt = $db->prepare("
                DELETE FROM saved_messages 
                WHERE user_id = ? AND message_id = ? AND message_type = ?
            ");

            $result = $stmt->execute([$userId, (int)$id, $type]);

            if ($result && $stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Message unsaved']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Message was not saved or already removed']);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
    });

    // Simple test endpoint
    SimpleRouter::get('/test', function() {
        header('Content-Type: application/json');
        echo json_encode(['test' => 'success', 'timestamp' => date('Y-m-d H:i:s')]);
    });

    // User profile API endpoints
    SimpleRouter::post('/user/profile', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        try {
            $input = $_POST;

            $db = Database::getInstance()->getPdo();

            // Validate input
            $realName = trim($input['real_name'] ?? '');
            $email = trim($input['email'] ?? '');
            $location = trim($input['location'] ?? '');
            $currentPassword = $input['current_password'] ?? '';
            $newPassword = $input['new_password'] ?? '';

            // Update profile information (users cannot change their name)
            $stmt = $db->prepare("UPDATE users SET email = ?, location = ? WHERE id = ?");
            $stmt->execute([$email, $location ?: null, $user['user_id']]);

            // Handle password change if provided
            if (!empty($currentPassword) && !empty($newPassword)) {
                // Verify current password
                if (!password_verify($currentPassword, $user['password_hash'])) {
                    throw new \Exception('Current password is incorrect');
                }

                if (strlen($newPassword) < 6) {
                    throw new \Exception('New password must be at least 6 characters long');
                }

                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$newPasswordHash, $user['user_id']]);
            }

            echo json_encode(['success' => true, 'real_name' => $realName]);
        } catch (\Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    SimpleRouter::get('/user/stats', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();

        // Get user message statistics
        $netmailStmt = $db->prepare("SELECT COUNT(*) as count FROM netmail WHERE user_id = ?");
        $netmailStmt->execute([$user['user_id']]);
        $netmailCount = $netmailStmt->fetch()['count'];

        // For echomail, we need to count by system address since users don't have individual addresses
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $systemAddress = $binkpConfig->getSystemAddress();

            $echomailStmt = $db->prepare("SELECT COUNT(*) as count FROM echomail WHERE from_address = ?");
            $echomailStmt->execute([$systemAddress]);
            $echomailCount = $echomailStmt->fetch()['count'];
        } catch (\Exception $e) {
            $echomailCount = 0;
        }

        echo json_encode([
            'netmail_count' => (int)$netmailCount,
            'echomail_count' => (int)$echomailCount
        ]);
    });

    SimpleRouter::get('/user/sessions', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();

        $stmt = $db->prepare("
            SELECT session_id as id, ip_address, created_at, expires_at,
                   CASE WHEN session_id = ? THEN 1 ELSE 0 END as is_current
            FROM user_sessions 
            WHERE user_id = ? AND expires_at > NOW()
            ORDER BY created_at DESC
        ");

        $currentSessionId = $_COOKIE['binktermphp_session'] ?? '';
        $stmt->execute([$currentSessionId, $user['user_id']]);
        $sessions = $stmt->fetchAll();

        echo json_encode(['sessions' => $sessions]);
    });

    SimpleRouter::delete('/user/sessions/{sessionId}', function($sessionId) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();

        // Only allow users to revoke their own sessions
        $stmt = $db->prepare("DELETE FROM user_sessions WHERE session_id = ? AND user_id = ?");
        $result = $stmt->execute([$sessionId, $user['user_id']]);

        if ($result) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Session not found']);
        }
    });

    SimpleRouter::delete('/user/sessions/all', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();

        // Delete all sessions for this user
        $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
        $result = $stmt->execute([$user['user_id']]);

        if ($result) {
            // Clear the current session cookie
            setcookie('binktermphp_session', '', time() - 3600, '/');
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to logout all sessions']);
        }
    });

    SimpleRouter::get('/whosonline', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $onlineUsers = $auth->getOnlineUsers(15);
        $isAdmin = !empty($user['is_admin']);

        $responseUsers = array_map(function($onlineUser) use ($isAdmin) {
            $entry = [
                'user_id' => (int)$onlineUser['user_id'],
                'username' => $onlineUser['username'],
                'location' => $onlineUser['location'] ?? ''
            ];
            if ($isAdmin) {
                $entry['activity'] = $onlineUser['activity'] ?? '';
            }
            return $entry;
        }, $onlineUsers);

        echo json_encode([
            'users' => $responseUsers,
            'online_minutes' => 15
        ]);
    });

    SimpleRouter::post('/user/activity', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $activity = $input['activity'] ?? '';
        $sessionId = $_COOKIE['binktermphp_session'] ?? null;

        if (!$sessionId) {
            http_response_code(400);
            echo json_encode(['error' => 'Session not found']);
            return;
        }

        $auth->updateSessionActivity($sessionId, (string)$activity);
        echo json_encode(['success' => true]);
    });

    SimpleRouter::get('/system/status', function() {
        $auth = new Auth();
        $auth->requireAuth();

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();

        // Get last poll time (we'll need to add this to a system_status table or use binkp logs)
        // For now, return some basic info
        $messagesTodayStmt = $db->query("SELECT COUNT(*) as count FROM echomail WHERE date_received > date('now')");
        $messagesToday = $messagesTodayStmt->fetch()['count'];

        echo json_encode([
            'last_poll' => null, // TODO: implement proper last poll tracking
            'messages_today' => (int)$messagesToday
        ]);
    });

    // Binkp API routes
    SimpleRouter::get('/binkp/status', function() {
        // Clean output buffer to prevent any warnings/output from corrupting JSON
        ob_start();

        $auth = new Auth();
        $user = $auth->requireAuth();

        // Check if user is admin
        if (!$user['is_admin']) {
            ob_clean();
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Admin access required']);
            return;
        }

        try {
            $controller = new \BinktermPHP\Binkp\Web\BinkpController();
            $status = $controller->getStatus();

            // Clean any unwanted output
            ob_clean();

            header('Content-Type: application/json');
            echo json_encode($status);
        } catch (\Exception $e) {
            ob_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    SimpleRouter::post('/binkp/poll', function() {
        ob_start();

        $user = requireBinkpAdmin();

        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $address = $input['address'] ?? '';

            $client = new \BinktermPHP\Admin\AdminDaemonClient();
            if (empty($address)) {
                $result = $client->binkPoll('all');
            } else {
                $result = $client->binkPoll($address);
            }

            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'result' => $result]);
        } catch (\Exception $e) {
            ob_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    SimpleRouter::post('/binkp/poll-all', function() {
        ob_start();

        $user = requireBinkpAdmin();

        try {
            $client = new \BinktermPHP\Admin\AdminDaemonClient();
            $result = $client->binkPoll('all');

            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'result' => $result]);
        } catch (\Exception $e) {
            ob_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    SimpleRouter::post('/binkp/process-packets', function() {
        ob_start();

        $user = requireBinkpAdmin();

        try {
            $client = new \BinktermPHP\Admin\AdminDaemonClient();
            $result = $client->processPackets();

            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'result' => $result]);
        } catch (\Exception $e) {
            ob_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    SimpleRouter::get('/binkp/uplinks', function() {
        ob_start();

        $user = requireBinkpAdmin();

        try {
            $controller = new \BinktermPHP\Binkp\Web\BinkpController();
            $uplinks = $controller->getUplinks();

            ob_clean();
            header('Content-Type: application/json');
            echo json_encode($uplinks);
        } catch (\Exception $e) {
            ob_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    SimpleRouter::post('/binkp/uplinks', function() {
        $user = requireBinkpAdmin();

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $controller = new \BinktermPHP\Binkp\Web\BinkpController();
        echo json_encode($controller->addUplink($input));
    });

    SimpleRouter::put('/binkp/uplinks/{address}', function($address) {
        $user = requireBinkpAdmin();

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $controller = new \BinktermPHP\Binkp\Web\BinkpController();
        echo json_encode($controller->updateUplink($address, $input));
    });

    SimpleRouter::delete('/binkp/uplinks/{address}', function($address) {
        $user = requireBinkpAdmin();

        header('Content-Type: application/json');

        $controller = new \BinktermPHP\Binkp\Web\BinkpController();
        echo json_encode($controller->removeUplink($address));
    });

    SimpleRouter::get('/binkp/files/inbound', function() {
        ob_start();

        $auth = new Auth();
        $auth->requireAuth();

        try {
            $controller = new \BinktermPHP\Binkp\Web\BinkpController();
            $files = $controller->getInboundFiles();

            ob_clean();
            header('Content-Type: application/json');
            echo json_encode($files);
        } catch (\Exception $e) {
            ob_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    SimpleRouter::get('/binkp/files/outbound', function() {
        ob_start();

        $auth = new Auth();
        $auth->requireAuth();

        try {
            $controller = new \BinktermPHP\Binkp\Web\BinkpController();
            $files = $controller->getOutboundFiles();

            ob_clean();
            header('Content-Type: application/json');
            echo json_encode($files);
        } catch (\Exception $e) {
            ob_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    SimpleRouter::post('/binkp/process/inbound', function() {
        ob_start();

        $auth = new Auth();
        $user = $auth->requireAuth();
        requireBinkpAdmin($user);

        try {
            $client = new \BinktermPHP\Admin\AdminDaemonClient();
            $result = $client->processPackets();

            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'result' => $result]);
        } catch (\Exception $e) {
            ob_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    SimpleRouter::post('/binkp/process/outbound', function() {
        ob_start();

        $auth = new Auth();
        $user = $auth->requireAuth();
        requireBinkpAdmin($user);

        try {
            $client = new \BinktermPHP\Admin\AdminDaemonClient();
            $result = $client->binkPoll('all');

            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'result' => $result]);
        } catch (\Exception $e) {
            ob_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    SimpleRouter::get('/binkp/logs', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();
        requireBinkpAdmin($user);

        header('Content-Type: application/json');

        $lines = intval($_GET['lines'] ?? 100);
        $controller = new \BinktermPHP\Binkp\Web\BinkpController();
        echo json_encode($controller->getLogs($lines));
    });

    // Test endpoint to verify delete endpoint is accessible
    SimpleRouter::get('/messages/echomail/delete-test', function() {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Delete endpoint is accessible']);
    });

    // Message sharing API endpoints
    SimpleRouter::post('/messages/echomail/{id}/share', function($id) {
        header('Content-Type: application/json');

        $auth = new Auth();
        $user = $auth->requireAuth();
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        $input = json_decode(file_get_contents('php://input'), true);

        // Properly handle boolean conversion for is_public
        $isPublic = false;
        if (isset($input['public'])) {
            $isPublic = filter_var($input['public'], FILTER_VALIDATE_BOOLEAN);
        }

        // Properly handle expires_hours
        $expiresHours = null;
        if (isset($input['expires_hours']) && $input['expires_hours'] !== '' && $input['expires_hours'] !== null) {
            $expiresHours = intval($input['expires_hours']);
            if ($expiresHours <= 0) {
                $expiresHours = null; // Treat 0 or negative as no expiration
            }
        }

        // Debug logging
        //error_log("Share API - isPublic: " . var_export($isPublic, true) . ", expiresHours: " . var_export($expiresHours, true));

        try {
            $handler = new MessageHandler();
            $result = $handler->createMessageShare($id, 'echomail', $userId, $isPublic, $expiresHours);

            if ($result['success']) {
                echo json_encode($result);
            } else {
                http_response_code(400);
                echo json_encode($result);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    SimpleRouter::get('/messages/echomail/{id}/shares', function($id) {
        header('Content-Type: application/json');

        $auth = new Auth();
        $user = $auth->requireAuth();
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        try {
            $handler = new MessageHandler();
            $result = $handler->getMessageShares($id, 'echomail', $userId);
            echo json_encode($result);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    SimpleRouter::delete('/messages/echomail/{id}/share', function($id) {
        header('Content-Type: application/json');

        $auth = new Auth();
        $user = $auth->requireAuth();
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        try {
            $handler = new MessageHandler();
            $result = $handler->revokeShare($id, 'echomail', $userId);

            if ($result['success']) {
                echo json_encode($result);
            } else {
                http_response_code(404);
                echo json_encode($result);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    SimpleRouter::get('/messages/shared/{shareKey}', function($shareKey) {
        header('Content-Type: application/json');

        // Get current user if logged in, but don't require auth
        $auth = new Auth();
        $user = $auth->getCurrentUser();
        $userId = $user ? ($user['user_id'] ?? $user['id'] ?? null) : null;

        try {
            $handler = new MessageHandler();
            $result = $handler->getSharedMessage($shareKey, $userId);

            if ($result['success']) {
                echo json_encode($result);
            } else {
                $statusCode = ($result['error'] === 'Login required to access this share') ? 401 : 404;
                http_response_code($statusCode);
                echo json_encode($result);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    SimpleRouter::get('/user/shares', function() {
        header('Content-Type: application/json');

        $auth = new Auth();
        $user = $auth->requireAuth();
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        try {
            $handler = new MessageHandler();
            $shares = $handler->getUserShares($userId);
            echo json_encode(['success' => true, 'shares' => $shares]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    // User settings API endpoints
    SimpleRouter::get('/user/settings', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();
        header('Content-Type: application/json');

        $userId = $user['user_id'] ?? $user['id'] ?? null;

        try {
            $handler = new MessageHandler();
            $settings = $handler->getUserSettings($userId);
            echo json_encode(['success' => true, 'settings' => $settings]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    SimpleRouter::post('/user/settings', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();
        header('Content-Type: application/json');

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || !isset($input['settings'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid input']);
            return;
        }

        try {
            $handler = new MessageHandler();
            $result = $handler->updateUserSettings($userId, $input['settings']);

            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Failed to update settings']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    });

    // Admin API endpoints for user management
    SimpleRouter::get('/admin/pending-users', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        header('Content-Type: application/json');

        try {
            $handler = new MessageHandler();
            $users = $handler->getPendingUsers();
            echo json_encode(['success' => true, 'users' => $users]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    SimpleRouter::get('/admin/pending-users/{id}', function($id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        header('Content-Type: application/json');

        try {
            $db = Database::getInstance()->getPdo();
            $stmt = $db->prepare("SELECT * FROM pending_users WHERE id = ?");
            $stmt->execute([$id]);
            $pendingUser = $stmt->fetch();

            if (!$pendingUser) {
                http_response_code(404);
                echo json_encode(['error' => 'Pending user not found']);
                return;
            }

            echo json_encode(['success' => true, 'user' => $pendingUser]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::post('/admin/pending-users/{id}/approve', function($id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        header('Content-Type: application/json');

        $notes = $_POST['notes'] ?? '';

        try {
            $handler = new MessageHandler();
            $newUserId = $handler->approveUserRegistration($id, $user['user_id'], $notes);
            echo json_encode(['success' => true, 'new_user_id' => $newUserId]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::post('/admin/pending-users/{id}/reject', function($id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        header('Content-Type: application/json');

        $notes = $_POST['notes'] ?? '';

        try {
            $handler = new MessageHandler();
            $handler->rejectUserRegistration($id, $user['user_id'], $notes);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::get('/admin/users', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        header('Content-Type: application/json');

        try {
            // Get pagination parameters
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 25;
            $search = isset($_GET['search']) ? $_GET['search'] : '';

            $adminController = new AdminController();
            $result = $adminController->getAllUsers($page, $limit, $search);
            echo json_encode(['success' => true, 'users' => $result['users'], 'pagination' => $result['pagination']]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    // Get single user for editing
    SimpleRouter::get('/admin/users/{id}', function($id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        header('Content-Type: application/json');

        try {
            $db = Database::getInstance()->getPdo();
            $stmt = $db->prepare("SELECT id, username, real_name, email, is_active, is_admin, created_at, last_login FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $userData = $stmt->fetch();

            if (!$userData) {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
                return;
            }

            echo json_encode(['success' => true, 'user' => $userData]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    })->where(['id' => '[0-9]+']);

    // Update user
    SimpleRouter::post('/admin/users/{id}', function($id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        header('Content-Type: application/json');

        try {
            $db = Database::getInstance()->getPdo();

            // Get the user to update
            $checkStmt = $db->prepare("SELECT id FROM users WHERE id = ?");
            $checkStmt->execute([$id]);
            if (!$checkStmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
                return;
            }

            $realName = $_POST['real_name'] ?? '';
            $email = $_POST['email'] ?? '';
            $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
            $isAdmin = isset($_POST['is_admin']) ? (int)$_POST['is_admin'] : 0;
            $password = $_POST['password'] ?? '';

            if (empty($realName)) {
                http_response_code(400);
                echo json_encode(['error' => 'Real name is required']);
                return;
            }

            // Build update query
            $updateFields = [
                'real_name = ?',
                'email = ?',
                'is_active = ?',
                'is_admin = ?'
            ];
            $updateParams = [$realName, $email ?: null, $isActive, $isAdmin];

            // Add password if provided
            if ($password) {
                if (strlen($password) < 8) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Password must be at least 8 characters long']);
                    return;
                }
                $updateFields[] = 'password_hash = ?';
                $updateParams[] = password_hash($password, PASSWORD_DEFAULT);
            }

            $updateParams[] = $id; // WHERE clause parameter

            $updateStmt = $db->prepare("
                UPDATE users 
                SET " . implode(', ', $updateFields) . "
                WHERE id = ?
            ");

            $updateStmt->execute($updateParams);

            echo json_encode(['success' => true]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    })->where(['id' => '[0-9]+']);

    // Toggle user status
    SimpleRouter::post('/admin/users/{id}/toggle-status', function($id) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        header('Content-Type: application/json');

        try {
            $db = Database::getInstance()->getPdo();

            $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

            $updateStmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $updateStmt->execute([$isActive, $id]);

            if ($updateStmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
                return;
            }

            echo json_encode(['success' => true]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    })->where(['id' => '[0-9]+']);

    // Create new user
    SimpleRouter::post('/admin/users/create', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        header('Content-Type: application/json');

        try {
            $username = $_POST['username'] ?? '';
            $realName = $_POST['real_name'] ?? '';
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
            $isAdmin = isset($_POST['is_admin']) ? (int)$_POST['is_admin'] : 0;

            // Validate required fields
            if (empty($username) || empty($realName) || empty($password)) {
                http_response_code(400);
                echo json_encode(['error' => 'Username, real name, and password are required']);
                return;
            }

            // Validate username format
            if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
                http_response_code(400);
                echo json_encode(['error' => 'Username must be 3-20 characters, letters, numbers, and underscores only']);
                return;
            }

            // Validate password length
            if (strlen($password) < 8) {
                http_response_code(400);
                echo json_encode(['error' => 'Password must be at least 8 characters long']);
                return;
            }

            $db = Database::getInstance()->getPdo();

            // Check if username already exists
            $checkStmt = $db->prepare("SELECT 1 FROM users WHERE username = ?");
            $checkStmt->execute([$username]);

            if ($checkStmt->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'Username already exists']);
                return;
            }

            // Hash password
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            // Create user
            $insertStmt = $db->prepare("
                INSERT INTO users (username, password_hash, real_name, email, is_active, is_admin, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $insertStmt->execute([
                $username,
                $passwordHash,
                $realName,
                $email ?: null,
                $isActive,
                $isAdmin,
                date('Y-m-d H:i:s')
            ]);

            $newUserId = $db->lastInsertId();

            // Create default user settings
            $settingsStmt = $db->prepare("
                INSERT INTO user_settings (user_id, messages_per_page) 
                VALUES (?, 25)
            ");
            $settingsStmt->execute([$newUserId]);

            echo json_encode(['success' => true, 'user_id' => $newUserId]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    // Cleanup old registrations
    SimpleRouter::post('/admin/users/cleanup', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        header('Content-Type: application/json');

        try {
            $handler = new MessageHandler();
            $result = $handler->performFullCleanup();
            echo json_encode(['success' => true, 'result' => $result]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    // Send account reminder to user
    SimpleRouter::post('/admin/users/{userId}/send-reminder', function($userId) {
        $auth = new Auth();
        $user = $auth->requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        header('Content-Type: application/json');

        try {
            // Get user info to get username
            $adminController = new AdminController();
            $targetUser = $adminController->getUser($userId);

            if (!$targetUser) {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
                return;
            }

            $handler = new MessageHandler();

            // Check if user can receive reminder
            if (!$handler->canSendReminder($targetUser['username'])) {
                http_response_code(400);
                echo json_encode(['error' => 'User has already logged in or is not eligible for reminders']);
                return;
            }

            // Send reminder
            $result = $handler->sendAccountReminder($targetUser['username']);

            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Account reminder sent successfully',
                    'email_sent' => $result['email_sent'] ?? false
                ]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => $result['error']]);
            }

        } catch (Exception $e) {
            error_log("Admin reminder error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    // Get users who need reminders
    SimpleRouter::get('/admin/users/need-reminders', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin access required']);
            return;
        }

        header('Content-Type: application/json');

        try {
            $adminController = new AdminController();
            $usersNeedingReminder = $adminController->getUsersNeedingReminder();

            echo json_encode(['success' => true, 'users' => $usersNeedingReminder]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    // Debug endpoint to test auth
    SimpleRouter::get('/admin/debug', function() {
        header('Content-Type: application/json');

        try {
            $auth = new Auth();
            $user = $auth->getCurrentUser();

            $response = [
                'user' => $user,
                'is_admin' => $user ? (bool)$user['is_admin'] : false,
                'cookie_present' => isset($_COOKIE['binktermphp_session']),
                'cookie_value' => $_COOKIE['binktermphp_session'] ?? null
            ];

            echo json_encode($response);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    // Address Book API routes
    SimpleRouter::group(['prefix' => '/address-book'], function() {

        // Get user's address book entries
        SimpleRouter::get('/', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            header('Content-Type: application/json');

            try {
                $search = $_GET['search'] ?? '';
                $userId = $user['user_id'] ?? $user['id'] ?? null;
                $addressBook = new AddressBookController();
                $entries = $addressBook->getUserEntries($userId, $search);

                echo json_encode(['success' => true, 'entries' => $entries]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        });

        // Get specific address book entry
        SimpleRouter::get('/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            header('Content-Type: application/json');

            try {
                $userId = $user['user_id'] ?? $user['id'] ?? null;
                $addressBook = new AddressBookController();
                $entry = $addressBook->getEntry($id, $userId);

                if ($entry) {
                    echo json_encode(['success' => true, 'entry' => $entry]);
                } else {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Entry not found']);
                }
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        })->where(['id' => '[0-9]+']);

        // Create new address book entry
        SimpleRouter::post('/', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            header('Content-Type: application/json');

            try {
                $data = json_decode(file_get_contents('php://input'), true);

                // Debug logging
                //error_log("[ADDRESS_BOOK] Creating entry for user: " . print_r($user, true));
                //error_log("[ADDRESS_BOOK] Entry data: " . print_r($data, true));

                $userId = $user['user_id'] ?? $user['id'] ?? null;
                if (!$user || !$userId) {
                    throw new Exception('User ID not found in authentication data');
                }

                $addressBook = new AddressBookController();
                $entryId = $addressBook->createEntry($userId, $data);

                echo json_encode(['success' => true, 'entry_id' => $entryId]);
            } catch (Exception $e) {
                //error_log("[ADDRESS_BOOK] Error creating entry: " . $e->getMessage());
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        });

        // Update address book entry
        SimpleRouter::put('/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            header('Content-Type: application/json');

            try {
                $data = json_decode(file_get_contents('php://input'), true);
                $userId = $user['user_id'] ?? $user['id'] ?? null;
                $addressBook = new AddressBookController();
                $success = $addressBook->updateEntry($id, $userId, $data);

                if ($success) {
                    echo json_encode(['success' => true]);
                } else {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Failed to update entry']);
                }
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        })->where(['id' => '[0-9]+']);

        // Delete address book entry
        SimpleRouter::delete('/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            header('Content-Type: application/json');

            try {
                $userId = $user['user_id'] ?? $user['id'] ?? null;
                $addressBook = new AddressBookController();
                $success = $addressBook->deleteEntry($id, $userId);

                if ($success) {
                    echo json_encode(['success' => true]);
                } else {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'Entry not found']);
                }
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        })->where(['id' => '[0-9]+']);

        // Search address book for autocomplete
        SimpleRouter::get('/search/{query}', function($query) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            header('Content-Type: application/json');

            try {
                $limit = isset($_GET['limit']) ? min(20, (int)$_GET['limit']) : 10;
                $userId = $user['user_id'] ?? $user['id'] ?? null;
                $addressBook = new AddressBookController();
                $entries = $addressBook->searchEntries($userId, urldecode($query), $limit);

                echo json_encode(['success' => true, 'entries' => $entries]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        });

        // Get address book statistics
        SimpleRouter::get('/stats', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            header('Content-Type: application/json');

            try {
                $userId = $user['user_id'] ?? $user['id'] ?? null;
                $addressBook = new AddressBookController();
                $stats = $addressBook->getUserStats($userId);

                echo json_encode(['success' => true, 'stats' => $stats]);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
        });
    });
});



// User subscription API routes
SimpleRouter::group(['prefix' => '/api/subscriptions'], function() {

    // User subscription management
    SimpleRouter::get('/user', function() {
        $controller = new BinktermPHP\SubscriptionController();
        $controller->handleUserSubscriptions();
    });

    SimpleRouter::post('/user', function() {
        $controller = new BinktermPHP\SubscriptionController();
        $controller->handleUserSubscriptions();
    });

    // Admin subscription management
    SimpleRouter::get('/admin', function() {
        $controller = new BinktermPHP\SubscriptionController();
        $controller->handleAdminSubscriptions();
    });

    SimpleRouter::post('/admin', function() {
        $controller = new BinktermPHP\SubscriptionController();
        $controller->handleAdminSubscriptions();
    });
});
