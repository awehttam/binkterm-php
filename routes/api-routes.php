<?php

use BinktermPHP\ActivityTracker;
use BinktermPHP\AddressBookController;
use BinktermPHP\AdminController;
use BinktermPHP\Auth;
use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\I18n\LocaleResolver;
use BinktermPHP\I18n\Translator;
use BinktermPHP\MessageHandler;
use BinktermPHP\RouteHelper;
use BinktermPHP\UserCredit;
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

if (!function_exists('apiError')) {
    function apiError(string $errorCode, string $message, ?int $status = null, array $extra = []): void
    {
        if ($status !== null) {
            http_response_code($status);
        }
        echo json_encode(array_merge([
            'success' => false,
            'error_code' => $errorCode,
            'error' => $message,
        ], $extra));
        exit;
    }
}

if (!function_exists('apiLocalizedText')) {
    function apiLocalizedText(string $key, string $fallback, ?array $user = null, array $params = [], string $namespace = 'errors'): string
    {
        static $translator = null;
        static $resolver = null;
        if ($translator === null || $resolver === null) {
            $translator = new Translator();
            $resolver = new LocaleResolver($translator);
        }

        if ($user === null) {
            try {
                $auth = new Auth();
                $resolvedUser = $auth->getCurrentUser();
                if (is_array($resolvedUser)) {
                    $user = $resolvedUser;
                }
            } catch (\Throwable $e) {
                // Fall back to default locale when no user context is available.
            }
        }

        $resolvedLocale = $resolver->resolveLocale((string)($user['locale'] ?? ''), $user);
        $translated = $translator->translate($key, $params, $resolvedLocale, [$namespace]);
        return $translated === $key ? $fallback : $translated;
    }
}

if (!function_exists('apiLocalizeErrorPayload')) {
    function apiLocalizeErrorPayload(array $payload, ?array $user = null): array
    {
        if (!empty($payload['error_code'])) {
            $payload['error'] = apiLocalizedText((string)$payload['error_code'], (string)($payload['error'] ?? ''), $user);
        }
        return $payload;
    }
}

SimpleRouter::group(['prefix' => '/api'], function() {

    /**
     * Public verification endpoint for LovlyNet registry and other network registries.
     * Returns the system name and software version to prove site ownership.
     * No authentication required.
     */
    SimpleRouter::get('/verify', function() {
        header('Content-Type: application/json');

        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();

        echo json_encode([
            'system_name' => $binkpConfig->getSystemName(),
            'software' => \BinktermPHP\Version::getFullVersion()
        ]);
    });

    SimpleRouter::post('/auth/login', function() {
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        $service = $input['service'] ?? 'web';

        if (empty($username) || empty($password)) {
            apiError('errors.auth.missing_credentials', apiLocalizedText('errors.auth.missing_credentials', 'Username and password required'), 400);
            return;
        }

        $auth = new Auth();
        $sessionId = $auth->login($username, $password, $service);

        if ($sessionId) {
            setcookie('binktermphp_session', $sessionId, [
                'expires'  => time() + 86400 * 30,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            // Track login event and retrieve CSRF token for the response
            $csrfToken = null;
            try {
                $db = Database::getInstance()->getPdo();
                $stmt = $db->prepare("SELECT user_id FROM user_sessions WHERE session_id = ?");
                $stmt->execute([$sessionId]);
                $row = $stmt->fetch();
                if ($row) {
                    $userId = (int)$row['user_id'];
                    ActivityTracker::track($userId, ActivityTracker::TYPE_LOGIN);
                    $meta      = new UserMeta();
                    $csrfToken = $meta->getValue($userId, 'csrf_token');
                }
            } catch (\Exception $e) {
                // Tracking errors must not break login
            }
            echo json_encode(['success' => true, 'csrf_token' => $csrfToken]);
        } else {
            apiError('errors.auth.invalid_credentials', apiLocalizedText('errors.auth.invalid_credentials', 'Invalid credentials'), 401);
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
            apiError('errors.auth.invalid_api_key', apiLocalizedText('errors.auth.invalid_api_key', 'Invalid API key'), 401, ['valid' => false]);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $userId = $input['userid'] ?? $input['user_id'] ?? null;
        $token = $input['token'] ?? '';

        if (empty($userId) || empty($token)) {
            apiError('errors.auth.gateway_token_missing_fields', apiLocalizedText('errors.auth.gateway_token_missing_fields', 'userid and token are required'), 400, ['valid' => false]);
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
            apiError('errors.auth.invalid_or_expired_gateway_token', apiLocalizedText('errors.auth.invalid_or_expired_gateway_token', 'Invalid or expired token'), 400, ['valid' => false]);
        }
    });

    // Generate gateway token for authenticated user
    SimpleRouter::post('/auth/gateway-token', function() {
        header('Content-Type: application/json');

        $user = RouteHelper::requireAuth();

        $input = json_decode(file_get_contents('php://input'), true);
        $door = $input['door'] ?? null;
        $ttl = $input['ttl'] ?? 300; // Default 5 minutes

        // Cap TTL at 10 minutes for security
        $ttl = min((int)$ttl, 600);
        $auth = new Auth();
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
            apiError('errors.auth.username_or_email_required', apiLocalizedText('errors.auth.username_or_email_required', 'Username or email is required'), 400);
            return;
        }

        $controller = new \BinktermPHP\PasswordResetController();
        $result = $controller->requestPasswordReset($usernameOrEmail);
        $result = apiLocalizeErrorPayload($result);

        echo json_encode($result);
    });

    SimpleRouter::post('/auth/validate-reset-token', function() {
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['token'] ?? '';

        if (empty($token)) {
            apiError('errors.auth.token_required', apiLocalizedText('errors.auth.token_required', 'Token is required'), 400, ['valid' => false]);
            return;
        }

        $controller = new \BinktermPHP\PasswordResetController();
        $tokenData = $controller->validateToken($token);

        if ($tokenData) {
            echo json_encode(['valid' => true]);
        } else {
            apiError('errors.auth.invalid_or_expired_token', apiLocalizedText('errors.auth.invalid_or_expired_token', 'Invalid or expired token'), 400, ['valid' => false]);
        }
    });

    SimpleRouter::post('/auth/reset-password', function() {
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['token'] ?? '';
        $newPassword = $input['newPassword'] ?? '';

        if (empty($token) || empty($newPassword)) {
            apiError('errors.auth.token_and_password_required', apiLocalizedText('errors.auth.token_and_password_required', 'Token and new password are required'), 400);
            return;
        }

        $controller = new \BinktermPHP\PasswordResetController();
        $result = $controller->resetPassword($token, $newPassword);
        $result = apiLocalizeErrorPayload($result);

        if (!$result['success']) {
            http_response_code(400);
        }

        echo json_encode($result);
    });

    SimpleRouter::post('/register', function() {
        header('Content-Type: application/json');

        // Start session for anti-spam checks
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Accept both JSON and form data
        $data = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($contentType, 'application/json') !== false) {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true) ?? [];
        } else {
            $data = $_POST;
        }

        // Check if this is a telnet registration
        $isTelnetRegistration = ($data['reason'] ?? '') === 'Telnet registration';

        // Anti-spam validation 1: Honeypot field (skip for telnet)
        if (!$isTelnetRegistration && !empty($data['website'])) {
            // Silent rejection - don't tell bots why they failed
            apiError('errors.register.invalid_submission', apiLocalizedText('errors.register.invalid_submission', 'Invalid submission'), 400);
            return;
        }

        // Anti-spam validation 2: Time-based check (skip for telnet)
        if (!$isTelnetRegistration) {
            $registrationTime = $_SESSION['registration_time'] ?? 0;
            $currentTime = time();
            $timeTaken = $currentTime - $registrationTime;

            if ($timeTaken < 3) {
                // Too fast - likely a bot
                apiError('errors.register.too_fast', apiLocalizedText('errors.register.too_fast', 'Please take your time filling out the form.'), 400);
                return;
            }

            if ($timeTaken > 1800) {
                // 30 minutes - session likely expired
                apiError('errors.register.session_expired', apiLocalizedText('errors.register.session_expired', 'Session expired. Please refresh the page and try again.'), 400);
                return;
            }
        }

        // Anti-spam validation 4: Rate limiting by IP
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        try {
            $db = Database::getInstance()->getPdo();

            // Check registration attempts in last 24 hours
            $rateLimitStmt = $db->prepare("
                SELECT COUNT(*) as attempt_count
                FROM registration_attempts
                WHERE ip_address = ?
                AND attempt_time > NOW() - INTERVAL '24 hours'
            ");
            $rateLimitStmt->execute([$ipAddress]);
            $rateLimitResult = $rateLimitStmt->fetch();

            if ($rateLimitResult && $rateLimitResult['attempt_count'] >= 3) {
                apiError('errors.register.rate_limited', apiLocalizedText('errors.register.rate_limited', 'Too many registration attempts. Please try again later.'), 429);
                return;
            }

            // Log this attempt
            $logAttemptStmt = $db->prepare("
                INSERT INTO registration_attempts (ip_address, attempt_time, success)
                VALUES (?, NOW(), FALSE)
            ");
            $logAttemptStmt->execute([$ipAddress]);

        } catch (Exception $e) {
            error_log("Rate limit check failed: " . $e->getMessage());
            // Continue with registration if rate limit check fails
        }

        // Clear the session timestamp
        unset($_SESSION['registration_time']);

        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $email = $data['email'] ?? '';
        $realName = $data['real_name'] ?? '';
        $location = $data['location'] ?? '';
        $reason = $data['reason'] ?? '';

        // Validate required fields
        if (empty($username) || empty($password) || empty($realName)) {
            apiError('errors.register.required_fields', apiLocalizedText('errors.register.required_fields', 'Username, password, and real name are required'), 400);
            return;
        }

        // Validate username format
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            apiError('errors.register.invalid_username_format', apiLocalizedText('errors.register.invalid_username_format', 'Username must be 3-20 characters, letters, numbers, and underscores only'), 400);
            return;
        }

        if (\BinktermPHP\UserRestrictions::isRestrictedUsername($username)
            || \BinktermPHP\UserRestrictions::isRestrictedRealName($realName)) {
            apiError('errors.register.restricted_name', apiLocalizedText('errors.register.restricted_name', 'This username or real name is not allowed'), 400);
            return;
        }

        // Validate password length
        if (strlen($password) < 8) {
            apiError('errors.register.weak_password', apiLocalizedText('errors.register.weak_password', 'Password must be at least 8 characters long'), 400);
            return;
        }

        try {
            $db = Database::getInstance()->getPdo();

            // Check if username or real_name already exists in users or pending_users (case-insensitive).
            // Also cross-check: new username must not match any existing real_name, and new real_name
            // must not match any existing username — otherwise netmail could be misrouted.
            $checkStmt = $db->prepare("
                SELECT 1 FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(username) = LOWER(?) OR LOWER(real_name) = LOWER(?) OR LOWER(real_name) = LOWER(?)
                UNION
                SELECT 1 FROM pending_users WHERE (LOWER(username) = LOWER(?) OR LOWER(username) = LOWER(?) OR LOWER(real_name) = LOWER(?) OR LOWER(real_name) = LOWER(?)) AND status = 'pending'
            ");
            $checkStmt->execute([$username, $realName, $username, $realName, $username, $realName, $username, $realName]);

            if ($checkStmt->fetch()) {
                apiError('errors.register.user_exists', apiLocalizedText('errors.register.user_exists', 'A user with this username or name already exists. Please try logging in or contact the sysop for assistance.'), 409);
                return;
            }

            // Hash password
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            // Get client info
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

            // Check for referral code in session
            $referralCode = $_SESSION['referral_code'] ?? null;
            $referrerId = null;

            if ($referralCode) {
                // Look up referrer by code (only active users can refer)
                $referrerStmt = $db->prepare("SELECT id FROM users WHERE referral_code = ? AND is_active = TRUE");
                $referrerStmt->execute([$referralCode]);
                $referrer = $referrerStmt->fetch(PDO::FETCH_ASSOC);

                if ($referrer) {
                    $referrerId = (int)$referrer['id'];

                    // Prevent self-referral (in case user is logged in)
                    if (isset($_SESSION['user']['id']) && $_SESSION['user']['id'] == $referrerId) {
                        $referrerId = null;
                    }
                }

                // Clear referral code from session
                unset($_SESSION['referral_code']);
            }

            // Insert pending user
            $insertStmt = $db->prepare("
                INSERT INTO pending_users (username, password_hash, email, real_name, location, reason, ip_address, user_agent, referral_code, referrer_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $insertStmt->execute([
                $username,
                $passwordHash,
                $email ?: null,
                $realName,
                $location ?: null,
                $reason ?: null,
                $ipAddress,
                $userAgent,
                $referralCode,
                $referrerId
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

            // Mark registration attempt as successful
            try {
                $updateAttemptStmt = $db->prepare("
                    UPDATE registration_attempts
                    SET success = TRUE
                    WHERE ip_address = ?
                    ORDER BY attempt_time DESC
                    LIMIT 1
                ");
                $updateAttemptStmt->execute([$ipAddress]);
            } catch (Exception $e) {
                error_log("Failed to update registration attempt: " . $e->getMessage());
            }

            echo json_encode([
                'success' => true,
                'message_code' => 'ui.register.submitted_success'
            ]);

        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            apiError('errors.register.failed', apiLocalizedText('errors.register.failed', 'Registration failed. Please try again later.'), 500);
        }
    });

    SimpleRouter::post('/account/reminder', function() {
        header('Content-Type: application/json');

        // Get form data
        $username = $_POST['username'] ?? '';

        // Validate required fields
        if (empty($username)) {
            apiError('errors.reminder.username_required', apiLocalizedText('errors.reminder.username_required', 'Username is required'), 400);
            return;
        }

        try {
            $handler = new MessageHandler();

            // Check if user exists and hasn't logged in
            if (!$handler->canSendReminder($username)) {
                apiError('errors.reminder.user_not_found_or_logged_in', apiLocalizedText('errors.reminder.user_not_found_or_logged_in', 'User not found or already logged in'), 404);
                return;
            }

            // Send reminder
            $result = $handler->sendAccountReminder($username);

            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.api.reminder.sent',
                    'email_sent' => $result['email_sent'] ?? false
                ]);
            } else {
                apiError('errors.reminder.send_failed', apiLocalizedText('errors.reminder.send_failed', 'Failed to send reminder. Please try again later.'), 400);
            }

        } catch (Exception $e) {
            error_log("Account reminder error: " . $e->getMessage());
            apiError('errors.reminder.send_failed', apiLocalizedText('errors.reminder.send_failed', 'Failed to send reminder. Please try again later.'), 500);
        }
    });

    SimpleRouter::get('/notify/state', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(400);
            apiError('errors.notify.user_id_missing', apiLocalizedText('errors.notify.user_id_missing', 'Unable to resolve user session', $user));
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
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(400);
            apiError('errors.notify.user_id_missing', apiLocalizedText('errors.notify.user_id_missing', 'Unable to resolve user session', $user));
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $state = $input['state'] ?? null;
        if (!is_array($state)) {
            http_response_code(400);
            apiError('errors.notify.invalid_state', apiLocalizedText('errors.notify.invalid_state', 'Invalid notification state payload', $user));
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
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $isAdmin = !empty($user['is_admin']);
        if (!$userId) {
            http_response_code(400);
            apiError('errors.notify.user_id_missing', apiLocalizedText('errors.notify.user_id_missing', 'Unable to resolve user session', $user));
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $target = strtolower((string)($input['target'] ?? ''));
        if (!in_array($target, ['netmail', 'echomail', 'chat'], true)) {
            http_response_code(400);
            apiError('errors.notify.invalid_target', apiLocalizedText('errors.notify.invalid_target', 'Invalid notification target', $user));
            return;
        }

        $meta = new UserMeta();
        $currentCount = (int)($input['current_count'] ?? 0);

        // Store the current count - badge will only show if count increases
        $meta->setValue((int)$userId, 'last_' . $target . '_count', (string)$currentCount);

        echo json_encode(['success' => true, 'target' => $target, 'count' => $currentCount]);
    });

    SimpleRouter::get('/dashboard/stats', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $isAdmin = !empty($user['is_admin']);
        $meta = new UserMeta();

        // Get last seen counts (not timestamps - we compare counts, not dates)
        $lastNetmailCount = (int)($meta->getValue((int)$userId, 'last_netmail_count') ?? 0);
        $lastEchomailCount = (int)($meta->getValue((int)$userId, 'last_echomail_count') ?? 0);
        $lastChatCount = (int)($meta->getValue((int)$userId, 'last_chat_count') ?? 0);

        // Get address list for netmail queries
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $myAddresses = $binkpConfig->getMyAddresses();
            $myAddresses[] = $binkpConfig->getSystemAddress();
        } catch (\Exception $e) {
            $myAddresses = [];
        }

        // Total unread netmail
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

        // Total unread echomail
        $sysopUnreadFilter = $isAdmin ? "" : " AND COALESCE(e.is_sysop_only, FALSE) = FALSE";
        $unreadEchomailStmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM echomail em
            INNER JOIN echoareas e ON em.echoarea_id = e.id
            INNER JOIN user_echoarea_subscriptions ues ON e.id = ues.echoarea_id AND ues.user_id = ?
            LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
            WHERE mrs.read_at IS NULL AND e.is_active = TRUE AND ues.is_active = TRUE{$sysopUnreadFilter}
        ");
        $unreadEchomailStmt->execute([$userId, $userId]);
        $unreadEchomail = $unreadEchomailStmt->fetch()['count'] ?? 0;

        // Total chat count
        $chatTotalStmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM chat_messages m
            LEFT JOIN chat_rooms r ON m.room_id = r.id
            WHERE (m.room_id IS NOT NULL AND r.is_active = TRUE)
               OR m.to_user_id = ?
               OR m.from_user_id = ?
        ");
        $chatTotalStmt->execute([$userId, $userId]);
        $chatTotal = $chatTotalStmt->fetch()['count'] ?? 0;

        // Notification badge shows ONLY if count increased
        $netmailBadge = $unreadNetmail > $lastNetmailCount ? $unreadNetmail : 0;
        $echomailBadge = $unreadEchomail > $lastEchomailCount ? $unreadEchomail : 0;
        $chatBadge = $chatTotal > $lastChatCount ? $chatTotal : 0;

        // Get user's credit balance
        $creditBalance = 0;
        if (\BinktermPHP\UserCredit::isEnabled()) {
            try {
                $creditBalance = \BinktermPHP\UserCredit::getBalance($userId);
            } catch (\Exception $e) {
                $creditBalance = 0;
            }
        }

        echo json_encode([
            'unread_netmail' => $netmailBadge,
            'new_echomail' => $echomailBadge,
            'chat_total' => (int)$chatBadge,
            'total_netmail' => $unreadNetmail,
            'total_echomail' => $unreadEchomail,
            'total_chat' => $chatTotal,
            'credit_balance' => $creditBalance
        ]);
    });

    SimpleRouter::get('/polls/active', function() {
        $user = RouteHelper::requireAuth();
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
        $user = RouteHelper::requireAuth();
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        header('Content-Type: application/json');

        $payload = json_decode(file_get_contents('php://input'), true);
        $optionId = isset($payload['option_id']) ? (int)$payload['option_id'] : 0;
        if ($optionId <= 0) {
            http_response_code(400);
            apiError('errors.polls.option_required', apiLocalizedText('errors.polls.option_required', 'A poll option is required', $user));
            return;
        }

        $db = Database::getInstance()->getPdo();
        $pollStmt = $db->prepare("SELECT id FROM polls WHERE id = ? AND is_active = TRUE");
        $pollStmt->execute([$id]);
        $poll = $pollStmt->fetch();
        if (!$poll) {
            http_response_code(404);
            apiError('errors.polls.not_found', apiLocalizedText('errors.polls.not_found', 'Poll not found', $user));
            return;
        }

        $optionStmt = $db->prepare("SELECT id FROM poll_options WHERE id = ? AND poll_id = ?");
        $optionStmt->execute([$optionId, $id]);
        if (!$optionStmt->fetch()) {
            http_response_code(400);
            apiError('errors.polls.invalid_option', apiLocalizedText('errors.polls.invalid_option', 'Invalid poll option', $user));
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
            apiError('errors.polls.vote_failed', apiLocalizedText('errors.polls.vote_failed', 'Failed to record vote', $user));
            return;
        }

        echo json_encode(['success' => true]);
    });

    SimpleRouter::post('/polls/create', function() {
        $user = RouteHelper::requireAuth();
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        header('Content-Type: application/json');

        $payload = json_decode(file_get_contents('php://input'), true);

        // Validate input
        $question = trim($payload['question'] ?? '');
        $options = $payload['options'] ?? [];

        if (empty($question)) {
            http_response_code(400);
            apiError('errors.polls.question_required', apiLocalizedText('errors.polls.question_required', 'Poll question is required', $user));
            return;
        }

        if (strlen($question) < 10 || strlen($question) > 500) {
            http_response_code(400);
            apiError('errors.polls.question_length_invalid', apiLocalizedText('errors.polls.question_length_invalid', 'Poll question must be between 10 and 500 characters', $user));
            return;
        }

        if (!is_array($options) || count($options) < 2 || count($options) > 10) {
            http_response_code(400);
            apiError('errors.polls.options_count_invalid', apiLocalizedText('errors.polls.options_count_invalid', 'Poll must include between 2 and 10 options', $user));
            return;
        }

        // Validate and clean options
        $cleanOptions = [];
        foreach ($options as $option) {
            $trimmed = trim($option);
            if (empty($trimmed)) {
                http_response_code(400);
                apiError('errors.polls.option_empty', apiLocalizedText('errors.polls.option_empty', 'Poll options cannot be empty', $user));
                return;
            }
            if (strlen($trimmed) > 200) {
                http_response_code(400);
                apiError('errors.polls.option_length_invalid', apiLocalizedText('errors.polls.option_length_invalid', 'Poll options must be 200 characters or fewer', $user));
                return;
            }
            $cleanOptions[] = $trimmed;
        }

        // Check for duplicate options
        if (count($cleanOptions) !== count(array_unique($cleanOptions))) {
            http_response_code(400);
            apiError('errors.polls.options_duplicate', apiLocalizedText('errors.polls.options_duplicate', 'Poll options must be unique', $user));
            return;
        }

        // Get poll creation cost
        $cost = UserCredit::getCreditCost('poll_creation', 15);

        // Deduct credits first (will fail if insufficient balance)
        $debitSuccess = UserCredit::debit(
            $userId,
            $cost,
            "Created poll: " . substr($question, 0, 50),
            null,
            UserCredit::TYPE_PAYMENT
        );

        if (!$debitSuccess) {
            http_response_code(400);
            apiError(
                'errors.polls.insufficient_credits',
                apiLocalizedText('errors.polls.insufficient_credits', 'Failed to deduct credits. You may have insufficient balance.', $user),
                null,
                ['cost' => $cost]
            );
            return;
        }

        try {
            $db = Database::getInstance()->getPdo();

            // Create poll
            $pollStmt = $db->prepare("
                INSERT INTO polls (question, is_active, created_by, created_at, updated_at)
                VALUES (?, TRUE, ?, NOW(), NOW())
                RETURNING id
            ");
            $pollStmt->execute([$question, $userId]);
            $pollId = $pollStmt->fetch()['id'];

            // Insert options
            $optionStmt = $db->prepare("
                INSERT INTO poll_options (poll_id, option_text, sort_order)
                VALUES (?, ?, ?)
            ");
            foreach ($cleanOptions as $index => $option) {
                $optionStmt->execute([$pollId, $option, $index]);
            }

            echo json_encode([
                'success' => true,
                'poll_id' => $pollId,
                'credits_spent' => $cost,
                'message_code' => 'ui.polls.create.created_success_spent',
                'message_params' => [
                    'spent' => $cost
                ]
            ]);
        } catch (Exception $e) {
            // Refund credits if poll creation failed
            UserCredit::credit(
                $userId,
                $cost,
                "Poll creation failed - refund",
                null,
                UserCredit::TYPE_REFUND
            );

            http_response_code(500);
            apiError('errors.polls.create_failed', apiLocalizedText('errors.polls.create_failed', 'Failed to create poll', $user));
        }
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
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');
        $payload = json_decode(file_get_contents('php://input'), true);
        $message = trim($payload['message'] ?? '');

        if ($message === '') {
            http_response_code(400);
            apiError('errors.shoutbox.message_required', apiLocalizedText('errors.shoutbox.message_required', 'Message is required', $user));
            return;
        }

        if (mb_strlen($message) > 280) {
            http_response_code(400);
            apiError('errors.shoutbox.message_too_long', apiLocalizedText('errors.shoutbox.message_too_long', 'Message cannot exceed 280 characters', $user));
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
        $user = RouteHelper::requireAuth();

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
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        if (!\BinktermPHP\BbsConfig::isFeatureEnabled('chat')) {
            http_response_code(403);
            apiError('errors.chat.feature_disabled', apiLocalizedText('errors.chat.feature_disabled', 'Chat is disabled', $user));
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
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        if (!\BinktermPHP\BbsConfig::isFeatureEnabled('chat')) {
            http_response_code(403);
            apiError('errors.chat.feature_disabled', apiLocalizedText('errors.chat.feature_disabled', 'Chat is disabled', $user));
            return;
        }
        $auth = new Auth();

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
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        if (!\BinktermPHP\BbsConfig::isFeatureEnabled('chat')) {
            http_response_code(403);
            apiError('errors.chat.feature_disabled', apiLocalizedText('errors.chat.feature_disabled', 'Chat is disabled', $user));
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
            apiError('errors.chat.invalid_message_query', apiLocalizedText('errors.chat.invalid_message_query', 'Invalid chat message query', $user));
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
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        if (!\BinktermPHP\BbsConfig::isFeatureEnabled('chat')) {
            http_response_code(403);
            apiError('errors.chat.feature_disabled', apiLocalizedText('errors.chat.feature_disabled', 'Chat is disabled', $user));
            return;
        }

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $input = json_decode(file_get_contents('php://input'), true);
        $roomId = isset($input['room_id']) ? (int)$input['room_id'] : null;
        $toUserId = isset($input['to_user_id']) ? (int)$input['to_user_id'] : null;
        $body = trim($input['body'] ?? '');

        if (!$userId || ($roomId && $toUserId) || (!$roomId && !$toUserId)) {
            http_response_code(400);
            apiError('errors.chat.invalid_send_target', apiLocalizedText('errors.chat.invalid_send_target', 'Invalid chat target', $user));
            return;
        }

        if ($body === '' || strlen($body) > 1000) {
            http_response_code(400);
            apiError('errors.chat.message_length_invalid', apiLocalizedText('errors.chat.message_length_invalid', 'Message must be between 1 and 1000 characters', $user));
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

            // Use conditional SQL to avoid TIMESTAMPTZ type inference issues
            if ($action === 'kick') {
                // Kick = temporary 10 minute ban
                $stmt = $db->prepare("
                    INSERT INTO chat_room_bans (room_id, user_id, banned_by, reason, expires_at)
                    VALUES (?, ?, ?, ?, NOW() + INTERVAL '10 minutes')
                    ON CONFLICT (room_id, user_id)
                    DO UPDATE SET banned_by = EXCLUDED.banned_by,
                                  reason = EXCLUDED.reason,
                                  expires_at = EXCLUDED.expires_at,
                                  created_at = NOW()
                ");
                $stmt->execute([
                    $roomId,
                    (int)$targetUser['id'],
                    $user['user_id'] ?? $user['id'],
                    null
                ]);
            } else {
                // Ban = permanent (NULL expiry)
                $stmt = $db->prepare("
                    INSERT INTO chat_room_bans (room_id, user_id, banned_by, reason, expires_at)
                    VALUES (?, ?, ?, ?, NULL)
                    ON CONFLICT (room_id, user_id)
                    DO UPDATE SET banned_by = EXCLUDED.banned_by,
                                  reason = EXCLUDED.reason,
                                  expires_at = EXCLUDED.expires_at,
                                  created_at = NOW()
                ");
                $stmt->execute([
                    $roomId,
                    (int)$targetUser['id'],
                    $user['user_id'] ?? $user['id'],
                    null
                ]);
            }

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
                apiError('errors.chat.room_not_found', apiLocalizedText('errors.chat.room_not_found', 'Chat room not found', $user));
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
                apiError('errors.chat.user_banned', apiLocalizedText('errors.chat.user_banned', 'You are banned from this room', $user));
                return;
            }
        } else {
            $userStmt = $db->prepare("SELECT id FROM users WHERE id = ? AND is_active = TRUE");
            $userStmt->execute([$toUserId]);
            if (!$userStmt->fetch()) {
                http_response_code(404);
                apiError('errors.chat.recipient_not_found', apiLocalizedText('errors.chat.recipient_not_found', 'Recipient not found', $user));
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
            apiError('errors.chat.send_blocked', apiLocalizedText('errors.chat.send_blocked', 'Message could not be sent', $user));
            return;
        }

        ActivityTracker::track((int)$userId, ActivityTracker::TYPE_CHAT_SEND, $roomId);

        echo json_encode([
            'success' => true,
            'message_id' => (int)$result['id'],
            'created_at' => $result['created_at']
        ]);
    });

    SimpleRouter::post('/chat/moderate', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        if (!\BinktermPHP\BbsConfig::isFeatureEnabled('chat')) {
            http_response_code(403);
            apiError('errors.chat.feature_disabled', apiLocalizedText('errors.chat.feature_disabled', 'Chat is disabled', $user));
            return;
        }

        if (empty($user['is_admin'])) {
            http_response_code(403);
            apiError('errors.chat.admin_required', apiLocalizedText('errors.chat.admin_required', 'Admin privileges are required', $user));
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $roomId = isset($input['room_id']) ? (int)$input['room_id'] : null;
        $targetUserId = isset($input['user_id']) ? (int)$input['user_id'] : null;
        $action = $input['action'] ?? '';

        if (!$roomId || !$targetUserId || !in_array($action, ['kick', 'ban'], true)) {
            http_response_code(400);
            apiError('errors.chat.invalid_moderation_request', apiLocalizedText('errors.chat.invalid_moderation_request', 'Invalid moderation request', $user));
            return;
        }

        $db = Database::getInstance()->getPdo();
        $roomStmt = $db->prepare("SELECT id FROM chat_rooms WHERE id = ?");
        $roomStmt->execute([$roomId]);
        if (!$roomStmt->fetch()) {
            http_response_code(404);
            apiError('errors.chat.room_not_found', apiLocalizedText('errors.chat.room_not_found', 'Chat room not found', $user));
            return;
        }

        $userStmt = $db->prepare("SELECT id FROM users WHERE id = ? AND is_active = TRUE");
        $userStmt->execute([$targetUserId]);
        if (!$userStmt->fetch()) {
            http_response_code(404);
            apiError('errors.chat.user_not_found', apiLocalizedText('errors.chat.user_not_found', 'User not found', $user));
            return;
        }

        // Use conditional SQL to avoid TIMESTAMPTZ type inference issues
        if ($action === 'kick') {
            // Kick = temporary 10 minute ban
            $stmt = $db->prepare("
                INSERT INTO chat_room_bans (room_id, user_id, banned_by, reason, expires_at)
                VALUES (?, ?, ?, ?, NOW() + INTERVAL '10 minutes')
                ON CONFLICT (room_id, user_id)
                DO UPDATE SET banned_by = EXCLUDED.banned_by,
                              reason = EXCLUDED.reason,
                              expires_at = EXCLUDED.expires_at,
                              created_at = NOW()
            ");
            $stmt->execute([
                $roomId,
                $targetUserId,
                $user['user_id'] ?? $user['id'],
                null
            ]);
        } else {
            // Ban = permanent (NULL expiry)
            $stmt = $db->prepare("
                INSERT INTO chat_room_bans (room_id, user_id, banned_by, reason, expires_at)
                VALUES (?, ?, ?, ?, NULL)
                ON CONFLICT (room_id, user_id)
                DO UPDATE SET banned_by = EXCLUDED.banned_by,
                              reason = EXCLUDED.reason,
                              expires_at = EXCLUDED.expires_at,
                              created_at = NOW()
            ");
            $stmt->execute([
                $roomId,
                $targetUserId,
                $user['user_id'] ?? $user['id'],
                null
            ]);
        }

        echo json_encode(['success' => true]);
    });

    SimpleRouter::get('/chat/poll', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        if (!\BinktermPHP\BbsConfig::isFeatureEnabled('chat')) {
            http_response_code(403);
            apiError('errors.chat.feature_disabled', apiLocalizedText('errors.chat.feature_disabled', 'Chat is disabled', $user));
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
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $filter = $_GET['filter'] ?? 'active';
        $subscribedOnly = $_GET['subscribed_only'] ?? 'false';
        $isAdmin = !empty($user['is_admin']);
        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        $db = Database::getInstance()->getPdo();

        // Query with separate subqueries for total and unread counts, plus last post info
        $sql = "SELECT
                    e.id,
                    e.tag,
                    e.description,
                    e.moderator,
                    e.uplink_address,
                    e.posting_name_policy,
                    e.color,
                    e.is_active,
                    e.created_at,
                    e.domain,
                    e.is_local,
                    e.is_sysop_only,
                    COALESCE(total_counts.message_count, 0) as message_count,
                    COALESCE(unread_counts.unread_count, 0) as unread_count,
                    last_posts.last_subject,
                    last_posts.last_author,
                    last_posts.last_date
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
                ) unread_counts ON e.id = unread_counts.echoarea_id
                LEFT JOIN (
                    SELECT DISTINCT ON (echoarea_id)
                        echoarea_id,
                        subject as last_subject,
                        from_name as last_author,
                        date_received as last_date
                    FROM echomail
                    ORDER BY echoarea_id, date_received DESC
                ) last_posts ON e.id = last_posts.echoarea_id";

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

        // Order: Local first, then LovlyNet domain, then others, all sorted by tag
        $sql .= " ORDER BY
            CASE
                WHEN COALESCE(e.is_local, FALSE) = TRUE THEN 0
                WHEN LOWER(e.domain) = 'lovlynet' THEN 1
                ELSE 2
            END,
            e.tag";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $echoareas = $stmt->fetchAll();

        $binkpConfig = null;
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        } catch (\Throwable $e) {
            $binkpConfig = null;
        }

        foreach ($echoareas as &$echoarea) {
            $echoPolicy = strtolower(trim((string)($echoarea['posting_name_policy'] ?? '')));
            if (in_array($echoPolicy, ['real_name', 'username'], true)) {
                $echoarea['effective_posting_name_policy'] = $echoPolicy;
                continue;
            }

            $resolvedPolicy = 'real_name';
            $domain = trim((string)($echoarea['domain'] ?? ''));
            if ($domain !== '' && $binkpConfig !== null) {
                try {
                    $resolvedPolicy = $binkpConfig->getPostingNamePolicyForDomain($domain);
                } catch (\Throwable $e) {
                    $resolvedPolicy = 'real_name';
                }
            }

            $echoarea['effective_posting_name_policy'] = in_array($resolvedPolicy, ['real_name', 'username'], true)
                ? $resolvedPolicy
                : 'real_name';
        }
        unset($echoarea);

        echo json_encode(['echoareas' => $echoareas]);
    });

    SimpleRouter::get('/echoareas/{id}', function($id) {
        $user = RouteHelper::requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            apiError('errors.echoareas.admin_required', apiLocalizedText('errors.echoareas.admin_required', 'Admin privileges are required', $user));
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
            apiError('errors.echoareas.not_found', apiLocalizedText('errors.echoareas.not_found', 'Echo area not found', $user));
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::post('/echoareas', function() {
        $user = RouteHelper::requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            apiError('errors.echoareas.admin_required', apiLocalizedText('errors.echoareas.admin_required', 'Admin privileges are required', $user));
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
            $geminiPublic = !empty($input['gemini_public']);
            $domain = trim($input['domain'] ?? '');
            $postingNamePolicy = strtolower(trim((string)($input['posting_name_policy'] ?? '')));

            if ($postingNamePolicy === '' || $postingNamePolicy === 'inherit') {
                $postingNamePolicy = null;
            } elseif (!in_array($postingNamePolicy, ['real_name', 'username'], true)) {
                throw new \Exception('Invalid posting name policy');
            }

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
                INSERT INTO echoareas (tag, description, moderator, uplink_address, posting_name_policy, color, is_active, is_local, is_sysop_only, domain, gemini_public)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $result = $stmt->execute([$tag, $description, $moderator, $uplinkAddress, $postingNamePolicy, $color, $isActive ? 'true' : 'false', $isLocal ? 'true' : 'false', $isSysopOnly ? 'true' : 'false', $domain, $geminiPublic ? 'true' : 'false']);

            if ($result) {
                echo json_encode([
                    'success' => true,
                    'id' => $db->lastInsertId(),
                    'message_code' => 'ui.echoareas.created_success'
                ]);
            } else {
                throw new \Exception('Failed to create echo area');
            }
        } catch (\Exception $e) {
            http_response_code(400);
            $message = $e->getMessage();
            if ($message === 'Invalid posting name policy') {
                apiError('errors.echoareas.invalid_posting_name_policy', apiLocalizedText('errors.echoareas.invalid_posting_name_policy', 'Invalid posting name policy', $user));
            } elseif ($message === 'Tag and description are required') {
                apiError('errors.echoareas.tag_description_required', apiLocalizedText('errors.echoareas.tag_description_required', 'Tag and description are required', $user));
            } elseif (str_starts_with($message, 'Invalid tag format')) {
                apiError('errors.echoareas.invalid_tag_format', apiLocalizedText('errors.echoareas.invalid_tag_format', 'Invalid tag format', $user));
            } elseif ($message === 'Invalid color format') {
                apiError('errors.echoareas.invalid_color_format', apiLocalizedText('errors.echoareas.invalid_color_format', 'Invalid color format', $user));
            } else {
                apiError('errors.echoareas.create_failed', apiLocalizedText('errors.echoareas.create_failed', 'Failed to create echo area', $user));
            }
        }
    });

    SimpleRouter::put('/echoareas/{id}', function($id) {
        $user = RouteHelper::requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            apiError('errors.echoareas.admin_required', apiLocalizedText('errors.echoareas.admin_required', 'Admin privileges are required', $user));
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
            $geminiPublic = !empty($input['gemini_public']);
            $domain = trim($input['domain'] ?? '');
            $postingNamePolicy = strtolower(trim((string)($input['posting_name_policy'] ?? '')));

            if ($postingNamePolicy === '' || $postingNamePolicy === 'inherit') {
                $postingNamePolicy = null;
            } elseif (!in_array($postingNamePolicy, ['real_name', 'username'], true)) {
                throw new \Exception('Invalid posting name policy');
            }

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
                SET tag = ?, description = ?, moderator = ?, uplink_address = ?, posting_name_policy = ?, color = ?, is_active = ?, is_local = ?, is_sysop_only = ?, domain = ?, gemini_public = ?
                WHERE id = ?
            ");

            $result = $stmt->execute([$tag, $description, $moderator, $uplinkAddress, $postingNamePolicy, $color, $isActive ? 'true' : 'false', $isLocal ? 'true' : 'false', $isSysopOnly ? 'true' : 'false', $domain, $geminiPublic ? 'true' : 'false', $id]);

            if ($result && $stmt->rowCount() > 0) {
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.echoareas.updated_success'
                ]);
            } else {
                throw new \Exception('Echo area not found or no changes made');
            }
        } catch (\Exception $e) {
            http_response_code(400);
            $message = $e->getMessage();
            if ($message === 'Invalid posting name policy') {
                apiError('errors.echoareas.invalid_posting_name_policy', apiLocalizedText('errors.echoareas.invalid_posting_name_policy', 'Invalid posting name policy', $user));
            } elseif ($message === 'Tag and description are required') {
                apiError('errors.echoareas.tag_description_required', apiLocalizedText('errors.echoareas.tag_description_required', 'Tag and description are required', $user));
            } elseif (str_starts_with($message, 'Invalid tag format')) {
                apiError('errors.echoareas.invalid_tag_format', apiLocalizedText('errors.echoareas.invalid_tag_format', 'Invalid tag format', $user));
            } elseif ($message === 'Invalid color format') {
                apiError('errors.echoareas.invalid_color_format', apiLocalizedText('errors.echoareas.invalid_color_format', 'Invalid color format', $user));
            } elseif ($message === 'Echo area not found or no changes made') {
                apiError('errors.echoareas.not_found_or_unchanged', apiLocalizedText('errors.echoareas.not_found_or_unchanged', 'Echo area not found or no changes made', $user));
            } else {
                apiError('errors.echoareas.update_failed', apiLocalizedText('errors.echoareas.update_failed', 'Failed to update echo area', $user));
            }
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::delete('/echoareas/{id}', function($id) {
        $user = RouteHelper::requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            apiError('errors.echoareas.admin_required', apiLocalizedText('errors.echoareas.admin_required', 'Admin privileges are required', $user));
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
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.echoareas.deleted_success'
                ]);
            } else {
                throw new \Exception('Echo area not found');
            }
        } catch (\Exception $e) {
            http_response_code(400);
            $message = $e->getMessage();
            if (str_starts_with($message, 'Cannot delete echo area with existing messages')) {
                apiError('errors.echoareas.delete_blocked_has_messages', apiLocalizedText('errors.echoareas.delete_blocked_has_messages', 'Cannot delete echo area with existing messages', $user));
            } elseif ($message === 'Echo area not found') {
                apiError('errors.echoareas.not_found', apiLocalizedText('errors.echoareas.not_found', 'Echo area not found', $user));
            } else {
                apiError('errors.echoareas.delete_failed', apiLocalizedText('errors.echoareas.delete_failed', 'Failed to delete echo area', $user));
            }
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

    // File Areas API routes
    SimpleRouter::get('/fileareas', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $manager = new \BinktermPHP\FileAreaManager();
        $filter = $_GET['filter'] ?? 'active';
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $isAdmin = !empty($user['is_admin']);
        $fileareas = $manager->getFileAreas($filter, $userId, $isAdmin);

        echo json_encode(['fileareas' => $fileareas]);
    });

    SimpleRouter::get('/fileareas/{id}', function($id) {
        $user = RouteHelper::requireAdmin();

        header('Content-Type: application/json');

        $manager = new \BinktermPHP\FileAreaManager();
        $filearea = $manager->getFileAreaById((int)$id);

        if ($filearea) {
            echo json_encode(['filearea' => $filearea]);
        } else {
            http_response_code(404);
            apiError('errors.fileareas.not_found', apiLocalizedText('errors.fileareas.not_found', 'File area not found', $user));
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::post('/fileareas', function() {
        $user = RouteHelper::requireAdmin();

        header('Content-Type: application/json');

        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $manager = new \BinktermPHP\FileAreaManager();
            $id = $manager->createFileArea($data);

            echo json_encode([
                'success' => true,
                'id' => $id,
                'message_code' => 'ui.fileareas.created_success'
            ]);

        } catch (\Exception $e) {
            http_response_code(400);
            apiError('errors.fileareas.create_failed', apiLocalizedText('errors.fileareas.create_failed', 'Failed to create file area', $user));
        }
    });

    SimpleRouter::put('/fileareas/{id}', function($id) {
        $user = RouteHelper::requireAdmin();

        header('Content-Type: application/json');

        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $manager = new \BinktermPHP\FileAreaManager();
            $manager->updateFileArea((int)$id, $data);

            echo json_encode([
                'success' => true,
                'message_code' => 'ui.fileareas.updated_success'
            ]);

        } catch (\Exception $e) {
            http_response_code(400);
            apiError('errors.fileareas.update_failed', apiLocalizedText('errors.fileareas.update_failed', 'Failed to update file area', $user));
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::delete('/fileareas/{id}', function($id) {
        $user = RouteHelper::requireAdmin();

        header('Content-Type: application/json');

        try {
            $manager = new \BinktermPHP\FileAreaManager();
            $manager->deleteFileArea((int)$id);

            echo json_encode([
                'success' => true,
                'message_code' => 'ui.fileareas.deleted_success'
            ]);

        } catch (\Exception $e) {
            http_response_code(400);
            apiError('errors.fileareas.delete_failed', apiLocalizedText('errors.fileareas.delete_failed', 'Failed to delete file area', $user));
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::get('/fileareas/stats', function() {
        RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $manager = new \BinktermPHP\FileAreaManager();
        $stats = $manager->getStats();

        echo json_encode($stats);
    });

    // Files API routes
    SimpleRouter::get('/files', function() {
        $user = RouteHelper::requireAuth();

        if (!\BinktermPHP\FileAreaManager::isFeatureEnabled()) {
            http_response_code(404);
            apiError('errors.files.feature_disabled', apiLocalizedText('errors.files.feature_disabled', 'File areas feature is disabled', $user));
            return;
        }

        header('Content-Type: application/json');

        $areaId = $_GET['area_id'] ?? null;
        if (!$areaId) {
            http_response_code(400);
            apiError('errors.files.area_id_required', apiLocalizedText('errors.files.area_id_required', 'File area ID is required', $user));
            return;
        }

        $manager = new \BinktermPHP\FileAreaManager();
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $isAdmin = !empty($user['is_admin']);

        // Check if user has access to this file area
        if (!$manager->canAccessFileArea((int)$areaId, $userId, $isAdmin)) {
            http_response_code(403);
            apiError('errors.files.access_denied', apiLocalizedText('errors.files.access_denied', 'Access denied to this file area', $user));
            return;
        }

        $files = $manager->getFiles((int)$areaId);

        ActivityTracker::track($userId, ActivityTracker::TYPE_FILEAREA_VIEW, (int)$areaId);

        echo json_encode(['files' => $files]);
    });

    SimpleRouter::get('/files/recent', function() {
        $user = RouteHelper::requireAuth();

        if (!\BinktermPHP\FileAreaManager::isFeatureEnabled()) {
            http_response_code(404);
            apiError('errors.files.feature_disabled', apiLocalizedText('errors.files.feature_disabled', 'File areas feature is disabled', $user));
            return;
        }

        header('Content-Type: application/json');

        $limit = min((int)($_GET['limit'] ?? 25), 50);
        $manager = new \BinktermPHP\FileAreaManager();
        $files = $manager->getRecentFiles($limit);

        echo json_encode(['files' => $files]);
    });

    SimpleRouter::get('/files/{id}', function($id) {
        $user = RouteHelper::requireAuth();

        if (!\BinktermPHP\FileAreaManager::isFeatureEnabled()) {
            http_response_code(404);
            apiError('errors.files.feature_disabled', apiLocalizedText('errors.files.feature_disabled', 'File areas feature is disabled', $user));
            return;
        }

        header('Content-Type: application/json');

        $manager = new \BinktermPHP\FileAreaManager();
        $file = $manager->getFileById((int)$id);

        if ($file) {
            echo json_encode(['file' => $file]);
        } else {
            http_response_code(404);
            apiError('errors.files.not_found', apiLocalizedText('errors.files.not_found', 'File not found', $user));
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::get('/files/{id}/download', function($id) {
        $user = RouteHelper::requireAuth();

        if (!\BinktermPHP\FileAreaManager::isFeatureEnabled()) {
            http_response_code(404);
            echo apiLocalizedText('errors.files.feature_disabled', 'File areas feature is disabled', $user);
            return;
        }

        $manager = new \BinktermPHP\FileAreaManager();
        $file = $manager->getFileById((int)$id);

        if (!$file || $file['status'] !== 'approved') {
            http_response_code(404);
            echo apiLocalizedText('errors.files.not_found', 'File not found', $user);
            return;
        }

        // Check if user has access to this file's area
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $isAdmin = !empty($user['is_admin']);

        $hasAccess = $manager->canAccessFileArea($file['file_area_id'], $userId, $isAdmin);

        // Senders of netmail attachments can always download what they sent, even though
        // the file lives in the recipient's private area.
        if (!$hasAccess && $file['source_type'] === 'netmail_attachment' && $file['message_id'] !== null) {
            $db = \BinktermPHP\Database::getInstance()->getPdo();
            $nmStmt = $db->prepare("SELECT user_id FROM netmail WHERE id = ? LIMIT 1");
            $nmStmt->execute([$file['message_id']]);
            $nm = $nmStmt->fetch();
            if ($nm && (int)$nm['user_id'] === (int)$userId) {
                $hasAccess = true;
            }
        }

        if (!$hasAccess) {
            http_response_code(403);
            echo apiLocalizedText('errors.files.access_denied', 'Access denied to this file area', $user);
            return;
        }

        $storagePath = $file['storage_path'];
        if (!file_exists($storagePath)) {
            http_response_code(404);
            echo apiLocalizedText('errors.files.not_found', 'File not found', $user);
            return;
        }

        // Set headers for file download
        // Properly encode filename for Content-Disposition header (RFC 6266 & RFC 5987)
        $filename = basename($file['filename']);
        $encodedFilename = rawurlencode($filename);

        ActivityTracker::track($userId, ActivityTracker::TYPE_FILE_DOWNLOAD, (int)$id, $filename);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"; filename*=UTF-8\'\'' . $encodedFilename);
        header('Content-Length: ' . filesize($storagePath));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: public');

        // Output file
        readfile($storagePath);
        exit;
    })->where(['id' => '[0-9]+']);

    /**
     * POST /api/files/{id}/share
     * Create a share link for a file (auth required). Returns existing share if one exists.
     */
    SimpleRouter::post('/files/{id}/share', function($id) {
        $user = RouteHelper::requireAuth();
        header('Content-Type: application/json');

        if (!\BinktermPHP\FileAreaManager::isFeatureEnabled()) {
            http_response_code(404);
            apiError('errors.files.feature_disabled', apiLocalizedText('errors.files.feature_disabled', 'File areas feature is disabled', $user));
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $expiresHours = isset($input['expires_hours']) && $input['expires_hours'] !== ''
            ? (int)$input['expires_hours']
            : null;

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $manager = new \BinktermPHP\FileAreaManager();
        $result = $manager->createFileShare((int)$id, (int)$userId, $expiresHours);
        $result = apiLocalizeErrorPayload($result, $user);

        if (!$result['success']) {
            http_response_code(400);
        }
        echo json_encode($result);
    })->where(['id' => '[0-9]+']);

    /**
     * GET /api/files/shared/check/{fileId}
     * Check if the current user has an active share for a file (auth required).
     * Returns the share URL (area/filename format) if found.
     */
    SimpleRouter::get('/files/shared/check/{fileId}', function($fileId) {
        $user = RouteHelper::requireAuth();
        header('Content-Type: application/json');

        if (!\BinktermPHP\FileAreaManager::isFeatureEnabled()) {
            http_response_code(404);
            apiError('errors.files.feature_disabled', apiLocalizedText('errors.files.feature_disabled', 'File areas feature is disabled', $user));
            return;
        }

        $userId  = $user['user_id'] ?? $user['id'] ?? null;
        $isAdmin = !empty($user['is_admin']);
        $manager = new \BinktermPHP\FileAreaManager();
        $share   = $manager->getExistingFileShare((int)$fileId);

        if ($share) {
            // Need the file's area tag and filename to build the URL
            $file = $manager->getFileById((int)$fileId);
            $shareUrl = $file
                ? \BinktermPHP\Config::getSiteUrl()
                    . '/shared/file/'
                    . rawurlencode($file['area_tag'])
                    . '/'
                    . rawurlencode($file['filename'])
                : null;

            echo json_encode([
                'success'    => true,
                'share_id'   => (int)$share['id'],
                'share_url'  => $shareUrl,
                'can_revoke' => $isAdmin || (int)$share['shared_by_user_id'] === (int)$userId,
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'exists' => false
            ]);
        }
    })->where(['fileId' => '[0-9]+']);

    /**
     * GET /api/files/shared/{area}/{filename}
     * Get shared file info by area tag and filename (no auth required).
     */
    SimpleRouter::get('/files/shared/{area}/{filename}', function($area, $filename) {
        header('Content-Type: application/json');
        $auth = new \BinktermPHP\Auth();
        $currentUser = $auth->getCurrentUser();

        if (!\BinktermPHP\FileAreaManager::isFeatureEnabled()) {
            http_response_code(404);
            apiError('errors.files.feature_disabled', apiLocalizedText('errors.files.feature_disabled', 'File areas feature is disabled', $currentUser));
            return;
        }

        $requestingUserId = $currentUser ? ($currentUser['user_id'] ?? $currentUser['id'] ?? null) : null;

        $manager = new \BinktermPHP\FileAreaManager();
        $result  = $manager->getSharedFile($area, $filename, $requestingUserId);
        $result = apiLocalizeErrorPayload($result, $user);

        if (!$result['success']) {
            http_response_code(404);
        }
        echo json_encode($result);
    })->where(['area' => '[A-Za-z0-9@._-]+', 'filename' => '[A-Za-z0-9._-]+']);

    /**
     * DELETE /api/files/shares/{shareId}
     * Revoke a file share (auth required, owner or admin).
     */
    SimpleRouter::delete('/files/shares/{shareId}', function($shareId) {
        $user = RouteHelper::requireAuth();
        header('Content-Type: application/json');

        if (!\BinktermPHP\FileAreaManager::isFeatureEnabled()) {
            http_response_code(404);
            apiError('errors.files.feature_disabled', apiLocalizedText('errors.files.feature_disabled', 'File areas feature is disabled', $user));
            return;
        }

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $isAdmin = !empty($user['is_admin']);

        $manager = new \BinktermPHP\FileAreaManager();
        $revoked = $manager->revokeFileShare((int)$shareId, (int)$userId, $isAdmin);

        if (!$revoked) {
            http_response_code(404);
            apiError('errors.files.share_not_found_or_forbidden', apiLocalizedText('errors.files.share_not_found_or_forbidden', 'Share link not found or not permitted', $user));
            return;
        }
        echo json_encode([
            'success' => true,
            'message_code' => 'ui.files.share_revoked'
        ]);
    })->where(['shareId' => '[0-9]+']);

    SimpleRouter::post('/files/upload', function() {
        $user = RouteHelper::requireAuth();

        if (!\BinktermPHP\FileAreaManager::isFeatureEnabled()) {
            http_response_code(404);
            apiError('errors.files.feature_disabled', apiLocalizedText('errors.files.feature_disabled', 'File areas feature is disabled', $user));
            return;
        }

        header('Content-Type: application/json');

        try {
            if (!isset($_FILES['file'])) {
                throw new \Exception('No file uploaded');
            }

            $fileAreaId = (int)($_POST['file_area_id'] ?? 0);
            $shortDescription = trim($_POST['short_description'] ?? '');
            $longDescription = trim($_POST['long_description'] ?? '');

            if (!$fileAreaId) {
                throw new \Exception('File area ID is required');
            }

            if (empty($shortDescription)) {
                throw new \Exception('Short description is required');
            }

            // Check upload permissions for this file area
            $manager = new \BinktermPHP\FileAreaManager();
            $fileArea = $manager->getFileAreaById($fileAreaId);

            if (!$fileArea) {
                throw new \Exception('File area not found');
            }

            $uploadPermission = $fileArea['upload_permission'] ?? \BinktermPHP\FileAreaManager::UPLOAD_USERS_ALLOWED;
            $isAdmin = ($user['is_admin'] ?? false) === true || ($user['is_admin'] ?? 0) === 1;

            // Check upload permission
            if ($uploadPermission === \BinktermPHP\FileAreaManager::UPLOAD_READ_ONLY) {
                throw new \Exception('This file area is read-only. Uploads are not permitted.');
            } elseif ($uploadPermission === \BinktermPHP\FileAreaManager::UPLOAD_ADMIN_ONLY && !$isAdmin) {
                throw new \Exception('Only administrators can upload files to this area.');
            }

            // Get user's FidoNet address or username
            $uploadedBy = $user['username'] ?? 'Unknown';
            $ownerId = $user['user_id'] ?? $user['id'] ?? null;
            $fileId = $manager->uploadFile(
                $fileAreaId,
                $_FILES['file'],
                $shortDescription,
                $longDescription,
                $uploadedBy,
                $ownerId
            );

            ActivityTracker::track($ownerId, ActivityTracker::TYPE_FILE_UPLOAD, (int)$fileId, $_FILES['file']['name'] ?? null, ['file_area_id' => $fileAreaId]);

            echo json_encode([
                'success' => true,
                'file_id' => $fileId,
                'message_code' => 'ui.api.files.uploaded'
            ]);

        } catch (\Exception $e) {
            http_response_code(400);
            $message = $e->getMessage();
            if ($message === 'No file uploaded') {
                apiError('errors.files.upload.no_file', apiLocalizedText('errors.files.upload.no_file', 'No file uploaded', $user));
            } elseif ($message === 'File area ID is required') {
                apiError('errors.files.upload.area_id_required', apiLocalizedText('errors.files.upload.area_id_required', 'File area ID is required', $user));
            } elseif ($message === 'Short description is required') {
                apiError('errors.files.upload.short_description_required', apiLocalizedText('errors.files.upload.short_description_required', 'Short description is required', $user));
            } elseif ($message === 'File area not found') {
                apiError('errors.files.upload.area_not_found', apiLocalizedText('errors.files.upload.area_not_found', 'File area not found', $user));
            } elseif ($message === 'This file area is read-only. Uploads are not permitted.') {
                apiError('errors.files.upload.read_only', apiLocalizedText('errors.files.upload.read_only', 'This file area is read-only', $user));
            } elseif ($message === 'Only administrators can upload files to this area.') {
                apiError('errors.files.upload.admin_only', apiLocalizedText('errors.files.upload.admin_only', 'Only administrators can upload files to this area', $user));
            } else {
                apiError('errors.files.upload.failed', apiLocalizedText('errors.files.upload.failed', 'Failed to upload file', $user));
            }
        }
    });

    SimpleRouter::delete('/files/{id}/delete', function($id) {
        $user = RouteHelper::requireAuth();

        if (!\BinktermPHP\FileAreaManager::isFeatureEnabled()) {
            http_response_code(404);
            apiError('errors.files.feature_disabled', apiLocalizedText('errors.files.feature_disabled', 'File areas feature is disabled', $user));
            return;
        }

        header('Content-Type: application/json');

        try {
            $userId = $user['user_id'] ?? $user['id'] ?? 0;
            $isAdmin = !empty($user['is_admin']);

            $manager = new \BinktermPHP\FileAreaManager();
            $manager->deleteFile((int)$id, $userId, $isAdmin);

            echo json_encode([
                'success' => true,
                'message_code' => 'ui.api.files.deleted'
            ]);

        } catch (\Exception $e) {
            http_response_code(403);
            apiError('errors.files.delete_failed', apiLocalizedText('errors.files.delete_failed', 'Failed to delete file', $user));
        }
    })->where(['id' => '[0-9]+']);

    /**
     * PUT /api/files/{id}/rename
     * Rename a file (auth required, owner or admin).
     */
    SimpleRouter::put('/files/{id}/rename', function($id) {
        $user = RouteHelper::requireAuth();

        if (!\BinktermPHP\FileAreaManager::isFeatureEnabled()) {
            http_response_code(404);
            apiError('errors.files.feature_disabled', apiLocalizedText('errors.files.feature_disabled', 'File areas feature is disabled', $user));
            return;
        }

        header('Content-Type: application/json');

        $body = json_decode(file_get_contents('php://input'), true);
        $newFilename = trim($body['filename'] ?? '');

        if ($newFilename === '') {
            http_response_code(400);
            apiError('errors.files.rename_filename_required', apiLocalizedText('errors.files.rename_filename_required', 'New filename is required', $user));
            return;
        }

        try {
            $userId = $user['user_id'] ?? $user['id'] ?? 0;
            $isAdmin = !empty($user['is_admin']);

            $manager = new \BinktermPHP\FileAreaManager();
            $manager->renameFile((int)$id, $newFilename, $userId, $isAdmin);

            echo json_encode([
                'success' => true,
                'filename' => basename($newFilename),
                'message_code' => 'ui.api.files.renamed'
            ]);

        } catch (\Exception $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'permission')) {
                http_response_code(403);
                apiError('errors.files.rename_forbidden', apiLocalizedText('errors.files.rename_forbidden', 'You do not have permission to rename this file', $user));
            } elseif (str_contains($msg, 'already exists')) {
                http_response_code(409);
                apiError('errors.files.rename_conflict', apiLocalizedText('errors.files.rename_conflict', 'A file with that name already exists in this area', $user));
            } else {
                http_response_code(400);
                apiError('errors.files.rename_failed', apiLocalizedText('errors.files.rename_failed', 'Failed to rename file', $user));
            }
        }
    })->where(['id' => '[0-9]+']);

    // Message API routes
    SimpleRouter::get('/messages/netmail', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $handler = new MessageHandler();
        $page = intval($_GET['page'] ?? 1);
        $filter = $_GET['filter'] ?? 'all';
        $threaded = isset($_GET['threaded']) && $_GET['threaded'] === 'true';
        $result = $handler->getNetmail($user['user_id'], $page, null, $filter, $threaded);
        $result = apiLocalizeErrorPayload($result, $user);
        echo json_encode($result);
    });

    // Statistics endpoints - must come before parameterized routes
    SimpleRouter::get('/messages/netmail/stats', function() {
        $user = RouteHelper::requireAuth();

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
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $handler = new MessageHandler();
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $message = $handler->getMessage($id, 'netmail', $userId);

        if ($message) {
            ActivityTracker::track($userId, ActivityTracker::TYPE_NETMAIL_READ, (int)$id);

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

            // Include file attachments if file areas feature is enabled
            if (\BinktermPHP\FileAreaManager::isFeatureEnabled()) {
                try {
                    $fileAreaManager = new \BinktermPHP\FileAreaManager();
                    $attachments = $fileAreaManager->getMessageAttachments($id, 'netmail');
                    $message['attachments'] = $attachments;
                } catch (\Exception $e) {
                    $message['attachments'] = [];
                }
            } else {
                $message['attachments'] = [];
            }

            echo json_encode($message);
        } else {
            http_response_code(404);
            apiError('errors.messages.netmail.not_found', apiLocalizedText('errors.messages.netmail.not_found', 'Message not found', $user));
        }
    });

    SimpleRouter::delete('/messages/netmail/{id}', function($id) {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $handler = new MessageHandler();
        $result = $handler->deleteNetmail($id, $user['user_id']);

        if ($result) {
            echo json_encode([
                'success' => true,
                'message_code' => 'ui.netmail.message_deleted_success'
            ]);
        } else {
            http_response_code(404);
            apiError('errors.messages.netmail.delete_failed', apiLocalizedText('errors.messages.netmail.delete_failed', 'Failed to delete message', $user));
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::get('/messages/netmail/{id}/download', function($id) {
        $user = RouteHelper::requireAuth();

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $handler = new MessageHandler();
        $message = $handler->getMessage($id, 'netmail', $userId);

        if (!$message) {
            http_response_code(404);
            echo apiLocalizedText('errors.messages.netmail.not_found', 'Message not found', $user);
            return;
        }

        $subject = $message['subject'] ?? 'message';
        $filename = sanitizeFilenameForWindows((string)$subject) . '.txt';

        $fromName = $message['from_name'] ?? 'Unknown';
        $fromAddress = $message['from_address'] ?? '';
        $fromLine = $fromAddress ? "$fromName <$fromAddress>" : $fromName;

        $toName = $message['to_name'] ?? 'Unknown';
        $toAddress = $message['to_address'] ?? '';
        $toLine = $toAddress ? "$toName <$toAddress>" : $toName;

        $headerLines = [
            'From: ' . $fromLine,
            'To: ' . $toLine,
            'Subject: ' . ($message['subject'] ?? '(No Subject)'),
            'Date: ' . ($message['date_written'] ?? '')
        ];

        $headerText = implode("\r\n", $headerLines) . "\r\n\r\n";
        $bodyText = (string)($message['message_text'] ?? '');
        $bodyText = str_replace(["\r\n", "\r"], "\n", $bodyText);
        $bodyText = str_replace("\n", "\r\n", $bodyText);
        $content = $headerText . $bodyText;

        $charset = 'utf-8';
        $encodedFilename = rawurlencode($filename);

        header('Content-Type: text/plain; charset=' . $charset);
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"; filename*=UTF-8\'\'' . $encodedFilename);
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: public');

        echo $content;
        exit;
    })->where(['id' => '[0-9]+']);

    SimpleRouter::post('/messages/netmail/bulk-delete', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $messageIds = $input['message_ids'] ?? [];

        if (empty($messageIds) || !is_array($messageIds)) {
            http_response_code(400);
            apiError('errors.messages.netmail.bulk_delete.invalid_input', apiLocalizedText('errors.messages.netmail.bulk_delete.invalid_input', 'A non-empty message ID list is required', $user));
            return;
        }

        $handler = new MessageHandler();
        $deleted = 0;

        foreach ($messageIds as $id) {
            if ($handler->deleteNetmail($id, $user['user_id'])) {
                $deleted++;
            }
        }

        echo json_encode([
            'success' => true,
            'message_code' => 'ui.netmail.bulk_delete.success',
            'message_params' => ['count' => $deleted],
            'deleted' => $deleted,
            'total' => count($messageIds)
        ]);
    });

    SimpleRouter::get('/messages/echomail', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        $handler = new MessageHandler();
        $page = intval($_GET['page'] ?? 1);
        $filter = $_GET['filter'] ?? 'all';
        $threaded = isset($_GET['threaded']) && $_GET['threaded'] === 'true';
        $allowedSorts = ['date_desc', 'date_asc', 'subject', 'author'];
        $sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'date_desc';

        // Get messages from subscribed echoareas only
        $result = $handler->getEchomailFromSubscribedAreas($userId, $page, null, $filter, $threaded, $sort);
        $result = apiLocalizeErrorPayload($result, $user);
        echo json_encode($result);
    });

    // Echomail bulk read endpoint - must come before parameterized routes
    SimpleRouter::post('/messages/echomail/read', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $messageIds = $input['messageIds'] ?? [];

        if (empty($messageIds) || !is_array($messageIds)) {
            http_response_code(400);
            apiError('errors.messages.echomail.bulk_read.invalid_input', apiLocalizedText('errors.messages.echomail.bulk_read.invalid_input', 'A non-empty message ID list is required', $user));
            return;
        }

        $userId = (int)$user['user_id'];
        $db = Database::getInstance()->getPdo();
        $marked = 0;

        try {
            $db->beginTransaction();
            $stmt = $db->prepare("
                INSERT INTO message_read_status (user_id, message_id, message_type, read_at)
                VALUES (?, ?, 'echomail', NOW())
                ON CONFLICT (user_id, message_id, message_type) DO UPDATE SET
                    read_at = EXCLUDED.read_at
            ");

            foreach ($messageIds as $id) {
                $stmt->execute([$userId, (int)$id]);
                $marked++;
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            http_response_code(500);
            apiError('errors.messages.echomail.bulk_read.failed', apiLocalizedText('errors.messages.echomail.bulk_read.failed', 'Failed to mark messages as read', $user));
            return;
        }

        echo json_encode([
            'success' => true,
            'message_code' => 'ui.echomail.bulk_mark_read_success',
            'message_params' => ['count' => $marked],
            'marked' => $marked,
            'total' => count($messageIds)
        ]);
    });

    // Echomail bulk delete endpoint - must come before parameterized routes
    SimpleRouter::post('/messages/echomail/delete', function() {
        $user = RouteHelper::requireAuth();

        if (empty($user['is_admin'])) {
            http_response_code(403);
            apiError('errors.messages.echomail.bulk_delete.admin_required', apiLocalizedText('errors.messages.echomail.bulk_delete.admin_required', 'Admin privileges are required', $user));
            return;
        }

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $messageIds = $input['messageIds'] ?? [];

        if (empty($messageIds) || !is_array($messageIds)) {
            http_response_code(400);
            apiError('errors.messages.echomail.bulk_delete.invalid_input', apiLocalizedText('errors.messages.echomail.bulk_delete.invalid_input', 'A non-empty message ID list is required', $user));
            return;
        }

        $db = Database::getInstance()->getPdo();
        $deleted = 0;

        foreach ($messageIds as $id) {
            $stmt = $db->prepare("DELETE FROM echomail WHERE id = ?");
            if ($stmt->execute([$id])) {
                $deleted++;
            }
        }

        echo json_encode([
            'success' => true,
            'message_code' => 'ui.echomail.bulk_delete.success',
            'message_params' => ['count' => $deleted],
            'deleted' => $deleted,
            'total' => count($messageIds)
        ]);
    });

    // Echomail statistics endpoints - must come before parameterized routes
    SimpleRouter::get('/messages/echomail/stats', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $isAdmin = !empty($user['is_admin']);
        $sysopFilter = $isAdmin ? "" : " AND COALESCE(ea.is_sysop_only, FALSE) = FALSE";

        // Global echomail statistics (only from subscribed echoareas)
        $totalStmt = $db->prepare("SELECT COUNT(*) as count FROM echomail em JOIN echoareas ea ON em.echoarea_id = ea.id JOIN user_echoarea_subscriptions ues ON ea.id = ues.echoarea_id AND ues.user_id = ? WHERE ea.is_active = TRUE AND ues.is_active = TRUE{$sysopFilter}");
        $totalStmt->execute([$userId]);
        $total = $totalStmt->fetch()['count'];

        $recentStmt = $db->prepare("SELECT COUNT(*) as count FROM echomail em JOIN echoareas ea ON em.echoarea_id = ea.id JOIN user_echoarea_subscriptions ues ON ea.id = ues.echoarea_id AND ues.user_id = ? WHERE ea.is_active = TRUE AND ues.is_active = TRUE AND date_received > NOW() - INTERVAL '1 day'{$sysopFilter}");
        $recentStmt->execute([$userId]);
        $recent = $recentStmt->fetch()['count'];

        $areasStmt = $db->prepare("SELECT COUNT(*) as count FROM echoareas ea JOIN user_echoarea_subscriptions ues ON ea.id = ues.echoarea_id AND ues.user_id = ? WHERE ea.is_active = TRUE AND ues.is_active = TRUE{$sysopFilter}");
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
                WHERE ea.is_active = TRUE AND ues.is_active = TRUE AND mrs.read_at IS NULL{$sysopFilter}
            ");
            $unreadStmt->execute([$userId, $userId]);
            $unreadCount = $unreadStmt->fetch()['count'];

            // Read count
            $readStmt = $db->prepare("
                SELECT COUNT(*) as count FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                JOIN user_echoarea_subscriptions ues ON ea.id = ues.echoarea_id AND ues.user_id = ?
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                WHERE ea.is_active = TRUE AND ues.is_active = TRUE AND mrs.read_at IS NOT NULL{$sysopFilter}
            ");
            $readStmt->execute([$userId, $userId]);
            $readCount = $readStmt->fetch()['count'];

            // To Me count
            if ($userInfo) {
                $toMeStmt = $db->prepare("
                    SELECT COUNT(*) as count FROM echomail em
                    JOIN echoareas ea ON em.echoarea_id = ea.id
                    JOIN user_echoarea_subscriptions ues ON ea.id = ues.echoarea_id AND ues.user_id = ?
                    WHERE ea.is_active = TRUE AND ues.is_active = TRUE AND (LOWER(em.to_name) = LOWER(?) OR LOWER(em.to_name) = LOWER(?)){$sysopFilter}
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
                WHERE ea.is_active = TRUE AND ues.is_active = TRUE AND sav.id IS NOT NULL{$sysopFilter}
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
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        // URL decode the echoarea parameter to handle dots and special characters
        $echoarea = urldecode($echoarea);
        $foo=explode("@", $echoarea);
        $echoarea=$foo[0];
        $domain=$foo[1] ?? '';
        $db = Database::getInstance()->getPdo();
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $isAdmin = !empty($user['is_admin']);

        if (!$isAdmin && $userId) {
            $subscriptionManager = new \BinktermPHP\EchoareaSubscriptionManager();
            if (empty($domain)) {
                $echoareaStmt = $db->prepare("SELECT id FROM echoareas WHERE tag = ? AND (domain IS NULL OR domain = '') AND is_active = TRUE");
                $echoareaStmt->execute([$echoarea]);
            } else {
                $echoareaStmt = $db->prepare("SELECT id FROM echoareas WHERE tag = ? AND domain = ? AND is_active = TRUE");
                $echoareaStmt->execute([$echoarea, $domain]);
            }
            $echoareaRow = $echoareaStmt->fetch();

            if (!$echoareaRow || !$subscriptionManager->isUserSubscribed($userId, $echoareaRow['id'])) {
                http_response_code(403);
                apiError('errors.messages.echomail.stats.subscription_required', apiLocalizedText('errors.messages.echomail.stats.subscription_required', 'Subscription required for this echo area', $user));
                return;
            }
        }

        // Statistics for specific echoarea
        $domainCondition = empty($domain) ? "(ea.domain IS NULL OR ea.domain = '')" : "ea.domain = ?";
        $stmt = $db->prepare("
            SELECT COUNT(*) as total,
                   COUNT(CASE WHEN date_received > NOW() - INTERVAL '1 day' THEN 1 END) as recent
            FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            WHERE ea.tag = ? AND {$domainCondition}
        ");
        $params = [$echoarea];
        if (!empty($domain)) {
            $params[] = $domain;
        }
        $stmt->execute($params);
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
                WHERE ea.tag = ? AND {$domainCondition} AND mrs.read_at IS NULL
            ");
            $unreadParams = [$userId, $echoarea];
            if (!empty($domain)) $unreadParams[] = $domain;
            $unreadStmt->execute($unreadParams);
            $unreadCount = $unreadStmt->fetch()['count'];

            // Read count
            $readStmt = $db->prepare("
                SELECT COUNT(*) as count FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                WHERE ea.tag = ? AND {$domainCondition} AND mrs.read_at IS NOT NULL
            ");
            $readParams = [$userId, $echoarea];
            if (!empty($domain)) $readParams[] = $domain;
            $readStmt->execute($readParams);
            $readCount = $readStmt->fetch()['count'];

            // To Me count
            if ($userInfo) {
                $toMeStmt = $db->prepare("
                    SELECT COUNT(*) as count FROM echomail em
                    JOIN echoareas ea ON em.echoarea_id = ea.id
                    WHERE ea.tag = ? AND {$domainCondition} AND (LOWER(em.to_name) = LOWER(?) OR LOWER(em.to_name) = LOWER(?))
                ");
                $toMeParams = [$echoarea];
                if (!empty($domain)) $toMeParams[] = $domain;
                $toMeParams[] = $userInfo['username'];
                $toMeParams[] = $userInfo['real_name'];
                $toMeStmt->execute($toMeParams);
                $toMeCount = $toMeStmt->fetch()['count'];
            }

            // Saved count
            $savedStmt = $db->prepare("
                SELECT COUNT(*) as count FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE ea.tag = ? AND {$domainCondition} AND sav.id IS NOT NULL
            ");
            $savedParams = [$userId, $echoarea];
            if (!empty($domain)) $savedParams[] = $domain;
            $savedStmt->execute($savedParams);
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
        $user = RouteHelper::requireAuth();

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
            apiError('', apiLocalizedText('', ''));
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::get('/messages/echomail/{id}/download', function($id) {
        $user = RouteHelper::requireAuth();

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $handler = new MessageHandler();
        $message = $handler->getMessage($id, 'echomail', $userId);

        if (!$message) {
            http_response_code(404);
            echo apiLocalizedText('errors.messages.echomail.not_found', 'Message not found', $user);
            return;
        }

        $subject = $message['subject'] ?? 'message';
        $filename = sanitizeFilenameForWindows((string)$subject) . '.txt';
        $area = $message['echoarea'] ?? '';
        $domain = $message['domain'] ?? '';
        $areaLabel = $area !== '' ? $area . ($domain !== '' ? '@' . $domain : '') : '';

        $fromName = $message['from_name'] ?? 'Unknown';
        $fromAddress = $message['from_address'] ?? '';
        $fromLine = $fromAddress ? "$fromName <$fromAddress>" : $fromName;

        $headerLines = [
            'From: ' . $fromLine,
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
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        // URL decode the echoarea parameter to handle dots and special characters
        $echoarea = urldecode($echoarea);

        $foo=explode("@", $echoarea);
        $echoarea=$foo[0];
        $domain=$foo[1] ?? '';

        $handler = new MessageHandler();
        $page = intval($_GET['page'] ?? 1);
        $filter = $_GET['filter'] ?? 'all';
        $threaded = isset($_GET['threaded']) && $_GET['threaded'] === 'true';
        $allowedSorts = ['date_desc', 'date_asc', 'subject', 'author'];
        $sort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'date_desc';
        $result = $handler->getEchomail($echoarea, $domain, $page, null, $userId, $filter, $threaded, false, $sort);
        $result = apiLocalizeErrorPayload($result, $user);

        ActivityTracker::track($userId, ActivityTracker::TYPE_ECHOMAIL_AREA_VIEW, null, $echoarea);

        echo json_encode($result);
    })->where(['echoarea' => '[A-Za-z0-9@._-]+']);

    SimpleRouter::get('/messages/echomail/{echoarea}/{id}', function($echoarea, $id) {
        $user = RouteHelper::requireAuth();
        header('Content-Type: application/json');

        // URL decode the echoarea parameter to handle dots and special characters
        $echoarea = urldecode($echoarea);
        $foo=explode("@", $echoarea);
        $echoarea=$foo[0];
        $domain=$foo[1] ?? '';

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
            apiError('', apiLocalizedText('', ''));
        }
    })->where(['echoarea' => '[A-Za-z0-9._@-]+', 'id' => '[0-9]+']);

    /**
     * Upload a file for attachment to an outbound netmail.
     * Returns a token used to reference the file when sending.
     */
    SimpleRouter::post('/netmail/attachment/upload', function() {
        $user = RouteHelper::requireAuth();
        header('Content-Type: application/json');

        if (empty($_FILES['file'])) {
            error_log('[netmail/attachment/upload] No file in $_FILES');
            http_response_code(400);
            apiError('', apiLocalizedText('', ''));
            return;
        }

        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            error_log('[netmail/attachment/upload] PHP upload error code: ' . $file['error']);
            http_response_code(400);
            apiError('', apiLocalizedText('', ''));
            return;
        }

        $maxBytes = (int)\BinktermPHP\Config::env('NETMAIL_ATTACHMENT_MAX_SIZE', 10 * 1024 * 1024);
        if ($file['size'] > $maxBytes) {
            error_log('[netmail/attachment/upload] File too large: ' . $file['size'] . ' bytes (max ' . $maxBytes . ')');
            http_response_code(400);
            apiError('', apiLocalizedText('', ''));
            return;
        }

        // Sanitise filename: keep only safe characters
        $originalName = basename($file['name']);
        $safeName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $originalName);
        if ($safeName === '' || $safeName === '.') {
            $safeName = 'attachment';
        }

        $token = bin2hex(random_bytes(16));
        $destDir = __DIR__ . '/../data/netmail_attachments';
        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0777, true)) {
                error_log('[netmail/attachment/upload] Failed to create directory: ' . $destDir);
                http_response_code(500);
                apiError('', apiLocalizedText('', ''));
                return;
            }
        }
        $destPath = $destDir . '/' . $token . '_' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            error_log('[netmail/attachment/upload] move_uploaded_file failed: tmp=' . $file['tmp_name'] . ' dest=' . $destPath . ' dir_writable=' . (is_writable($destDir) ? 'yes' : 'no'));
            http_response_code(500);
            apiError('', apiLocalizedText('', ''));
            return;
        }

        echo json_encode([
            'token'             => $token,
            'original_filename' => $safeName,
            'size'              => $file['size'],
        ]);
    });

    SimpleRouter::post('/messages/send', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $type = $input['type'] ?? '';
        // Support both new markup_type and legacy send_markdown for backwards compatibility
        $markupType = $input['markup_type'] ?? (empty($input['send_markdown']) ? null : 'markdown');

        $handler = new MessageHandler();

        try {
            if ($type === 'netmail') {

                if(trim($input['to_address'])==""){
                    $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
                    $input['to_address'] = $binkpConfig->getSystemAddress();
                }

                // Resolve attachment token if provided
                $attachment = null;
                $attachmentToken = $input['attachment_token'] ?? '';
                if (!empty($attachmentToken) && preg_match('/^[0-9a-f]{32}$/', $attachmentToken)) {
                    $attachDir = __DIR__ . '/../data/netmail_attachments';
                    $matches = glob($attachDir . '/' . $attachmentToken . '_*');
                    if (!empty($matches)) {
                        $attachPath = $matches[0];
                        $attachFilename = substr(basename($attachPath), 33); // strip token + underscore
                        $attachment = ['file_path' => $attachPath, 'filename' => $attachFilename];
                    }
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
                    $crashmailFlag,
                    $input['tagline'] ?? null,
                    $attachment,
                    $markupType
                );
            } elseif ($type === 'echomail') {
                $foo = explode("@", (string)($input['echoarea'] ?? ''), 2);
                $echoarea = trim((string)($foo[0] ?? ''));
                $domain = trim((string)($foo[1] ?? ''));
                if ($domain === 'null' || $domain === 'undefined') {
                    $domain = '';
                }

                $result = $handler->postEchomail(
                    $user['user_id'],
                    $echoarea,
                    $domain,
                    $input['to_name'],
                    $input['subject'],
                    $input['message_text'],
                    $input['reply_to_id'],
                    $input['tagline'] ?? null,
                    false,
                    $markupType
                );

                // Handle cross-posting to additional areas
                $crossPostAreas = $input['cross_post_areas'] ?? [];
                $crossPostCount = 0;
                if ($result && is_array($crossPostAreas) && !empty($crossPostAreas) && empty($input['reply_to_id'])) {
                    $bbsConfig = \BinktermPHP\BbsConfig::getConfig();
                    $maxCrossPost = (int)($bbsConfig['max_cross_post_areas'] ?? 5);
                    $crossPostAreas = array_slice($crossPostAreas, 0, $maxCrossPost);

                    foreach ($crossPostAreas as $areaTag) {
                        $parts = explode("@", (string)$areaTag, 2);
                        $xEchoarea = trim((string)($parts[0] ?? ''));
                        $xDomain = trim((string)($parts[1] ?? ''));
                        if ($xDomain === 'null' || $xDomain === 'undefined') {
                            $xDomain = '';
                        }
                        if ($xEchoarea === '') {
                            continue;
                        }
                        // Skip if same as primary area
                        if ($xEchoarea === $echoarea && $xDomain === $domain) {
                            continue;
                        }
                        try {
                            $handler->postEchomail(
                                $user['user_id'],
                                $xEchoarea,
                                $xDomain,
                                $input['to_name'],
                                $input['subject'],
                                $input['message_text'],
                                null,
                                $input['tagline'] ?? null,
                                true,
                                $markupType
                            );
                            $crossPostCount++;
                        } catch (\Exception $e) {
                            error_log("[CROSSPOST] Failed to cross-post to {$areaTag}: " . $e->getMessage());
                        }
                    }
                }
            } else {
                http_response_code(400);
                apiError('errors.messages.send.invalid_type', apiLocalizedText('errors.messages.send.invalid_type', 'Invalid message type', $user));
                return;
            }

            if ($result) {
                $totalAreas = 1 + ($crossPostCount ?? 0);
                if ($type === 'netmail') {
                    ActivityTracker::track($user['user_id'], ActivityTracker::TYPE_NETMAIL_SEND, null, $input['to_address'] ?? null);
                } elseif ($type === 'echomail') {
                    ActivityTracker::track($user['user_id'], ActivityTracker::TYPE_ECHOMAIL_SEND, null, $echoarea ?? null);
                }
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.api.messages.sent',
                    'areas_posted' => $totalAreas
                ]);

                if (function_exists('session_write_close')) {
                    session_write_close();
                }
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }

                $handler->flushImmediateOutboundPolls();
            } else {
                http_response_code(500);
                apiError('errors.messages.send.failed', apiLocalizedText('errors.messages.send.failed', 'Failed to send message', $user));
            }
        } catch (Exception $e) {
            error_log('[SEND] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            apiError('errors.messages.send.exception', apiLocalizedText('errors.messages.send.exception', 'Failed to send message', $user));
        }
    });

    // Markdown support lookup for compose UI
    SimpleRouter::get('/messages/markdown-support', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $address = $_GET['address'] ?? null;
        $domain  = $_GET['domain']  ?? null;
        $area    = $_GET['area']    ?? null;  // full tag@domain string for echomail
        $allowed = false;
        $postingNamePolicy = 'real_name';
        $isLocalAddress = false;

        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();

            // Local-only echo areas always allow markdown
            if (!empty($area)) {
                $atPos = strpos($area, '@');
                $tag       = $atPos !== false ? substr($area, 0, $atPos) : $area;
                $areaDomain = $atPos !== false ? substr($area, $atPos + 1) : '';

                $db = \BinktermPHP\Database::getInstance()->getPdo();
                if ($areaDomain === '') {
                    // Area has no domain (local area with NULL/empty domain)
                    $stmt = $db->prepare("SELECT is_local, posting_name_policy FROM echoareas WHERE UPPER(tag) = UPPER(?) AND (domain IS NULL OR domain = '')");
                    $stmt->execute([$tag]);
                } else {
                    $stmt = $db->prepare("SELECT is_local, posting_name_policy FROM echoareas WHERE UPPER(tag) = UPPER(?) AND LOWER(domain) = LOWER(?)");
                    $stmt->execute([$tag, $areaDomain]);
                }
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                $echoPolicy = strtolower(trim((string)($row['posting_name_policy'] ?? '')));
                if (in_array($echoPolicy, ['real_name', 'username'], true)) {
                    $postingNamePolicy = $echoPolicy;
                } elseif ($areaDomain !== '') {
                    $postingNamePolicy = $binkpConfig->getPostingNamePolicyForDomain((string)$areaDomain);
                }

                if ($row && $row['is_local']) {
                    echo json_encode([
                        'allowed' => true,
                        'posting_name_policy' => $postingNamePolicy
                    ]);
                    return;
                }
            }

            if (!empty($domain)) {
                $allowed = $binkpConfig->isMarkdownAllowedForDomain((string)$domain);
                $postingNamePolicy = $binkpConfig->getPostingNamePolicyForDomain((string)$domain);
            } elseif (!empty($address)) {
                $allowed = $binkpConfig->isMarkdownAllowedForDestination((string)$address);
                $postingNamePolicy = $binkpConfig->getPostingNamePolicyForDestination((string)$address);
                $systemAddress = $binkpConfig->getSystemAddress();
                $isLocalAddress = (trim((string)$address) === trim((string)$systemAddress));
            }
        } catch (\Exception $e) {
            $allowed = false;
            $postingNamePolicy = 'real_name';
        }

        echo json_encode([
            'allowed'            => $allowed,
            'posting_name_policy' => $postingNamePolicy,
            'is_local_address'   => $isLocalAddress,
        ]);
    });

    // Markdown preview render for compose UI
    SimpleRouter::post('/messages/markdown-preview', function() {
        RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $text = trim($input['text'] ?? '');

        if ($text === '') {
            echo json_encode(['html' => '']);
            return;
        }

        $html = \BinktermPHP\MarkdownRenderer::toHtml($text);
        echo json_encode(['html' => $html]);
    });

    // Save message draft
    SimpleRouter::post('/messages/draft', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            apiError('errors.messages.drafts.invalid_input', apiLocalizedText('errors.messages.drafts.invalid_input', 'Invalid draft payload', $user));
            return;
        }

        $handler = new MessageHandler();

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(500);
            apiError('errors.messages.drafts.user_id_missing', apiLocalizedText('errors.messages.drafts.user_id_missing', 'Unable to resolve user session', $user));
            return;
        }

        try {
            $result = $handler->saveDraft($userId, $input);

            if ($result['success']) {
                if (!isset($result['message_code'])) {
                    $result['message_code'] = 'ui.compose.draft.saved_success';
                }
                echo json_encode($result);
            } else {
                http_response_code(500);
                apiError('errors.messages.drafts.save_failed', apiLocalizedText('errors.messages.drafts.save_failed', 'Failed to save draft', $user));
            }
        } catch (Exception $e) {
            http_response_code(500);
            apiError('errors.messages.drafts.save_failed', apiLocalizedText('errors.messages.drafts.save_failed', 'Failed to save draft', $user));
        }
    });

    // Get user's drafts
    SimpleRouter::get('/messages/drafts', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $type = $_GET['type'] ?? null; // Optional filter by type

        $handler = new MessageHandler();

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(500);
            apiError('errors.messages.drafts.user_id_missing', apiLocalizedText('errors.messages.drafts.user_id_missing', 'Unable to resolve user session', $user));
            return;
        }

        try {
            $drafts = $handler->getUserDrafts($userId, $type);
            echo json_encode(['success' => true, 'drafts' => $drafts]);
        } catch (Exception $e) {
            http_response_code(500);
            apiError('errors.messages.drafts.list_failed', apiLocalizedText('errors.messages.drafts.list_failed', 'Failed to load drafts', $user));
        }
    });

    // Get specific draft
    SimpleRouter::get('/messages/drafts/{id}', function($id) {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $handler = new MessageHandler();

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(500);
            apiError('errors.messages.drafts.user_id_missing', apiLocalizedText('errors.messages.drafts.user_id_missing', 'Unable to resolve user session', $user));
            return;
        }

        try {
            $draft = $handler->getDraft($userId, $id);
            if ($draft) {
                echo json_encode(['success' => true, 'draft' => $draft]);
            } else {
                http_response_code(404);
                apiError('errors.messages.drafts.not_found', apiLocalizedText('errors.messages.drafts.not_found', 'Draft not found', $user));
            }
        } catch (Exception $e) {
            http_response_code(500);
            apiError('errors.messages.drafts.get_failed', apiLocalizedText('errors.messages.drafts.get_failed', 'Failed to load draft', $user));
        }
    });

    // Delete draft
    SimpleRouter::delete('/messages/drafts/{id}', function($id) {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $handler = new MessageHandler();

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(500);
            apiError('errors.messages.drafts.user_id_missing', apiLocalizedText('errors.messages.drafts.user_id_missing', 'Unable to resolve user session', $user));
            return;
        }

        try {
            $result = $handler->deleteDraft($userId, $id);
            $result = apiLocalizeErrorPayload($result, $user);
            if (!empty($result['success']) && !isset($result['message_code'])) {
                $result['message_code'] = 'ui.drafts.deleted_success';
            }
            echo json_encode($result);
        } catch (Exception $e) {
            http_response_code(500);
            apiError('errors.messages.drafts.delete_failed', apiLocalizedText('errors.messages.drafts.delete_failed', 'Failed to delete draft', $user));
        }
    });

    SimpleRouter::get('/messages/search', function() {
        $user = RouteHelper::requireAuth();

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
            apiError('errors.messages.search.query_too_short', apiLocalizedText('errors.messages.search.query_too_short', 'Search query must be at least 2 characters', $user));
            return;
        }

        $handler = new MessageHandler();

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        $messages = $handler->searchMessages($query, $type, $echoarea, $userId);

        // For echomail searches, also get per-echo-area counts and filter counts
        $echoareaCounts = [];
        $filterCounts = [];
        if ($type === 'echomail' || $type === null) {
            $echoareaCounts = $handler->getSearchResultCounts($query, $echoarea, $userId);
            $filterCounts = $handler->getSearchFilterCounts($query, $echoarea, $userId);
        }

        echo json_encode([
            'messages' => $messages,
            'echoarea_counts' => $echoareaCounts,
            'filter_counts' => $filterCounts
        ]);
    });

    // Mark message as read
    SimpleRouter::post('/messages/{type}/{id}/read', function($type, $id) {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(500);
            apiError('errors.messages.read.user_id_missing', apiLocalizedText('errors.messages.read.user_id_missing', 'Unable to resolve user session', $user));
            return;
        }

        if (!in_array($type, ['echomail', 'netmail'])) {
            http_response_code(400);
            apiError('errors.messages.read.invalid_type', apiLocalizedText('errors.messages.read.invalid_type', 'Invalid message type', $user));
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
                apiError('errors.messages.read.mark_failed', apiLocalizedText('errors.messages.read.mark_failed', 'Failed to mark message as read', $user));
            }

        } catch (Exception $e) {
            http_response_code(500);
            apiError('errors.messages.read.mark_failed', apiLocalizedText('errors.messages.read.mark_failed', 'Failed to mark message as read', $user));
        }
    });

    // Save message for later viewing
    SimpleRouter::post('/messages/{type}/{id}/save', function($type, $id) {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(500);
            apiError('errors.messages.save.user_id_missing', apiLocalizedText('errors.messages.save.user_id_missing', 'Unable to resolve user session', $user));
            return;
        }

        if (!in_array($type, ['echomail', 'netmail'])) {
            http_response_code(400);
            apiError('errors.messages.save.invalid_type', apiLocalizedText('errors.messages.save.invalid_type', 'Invalid message type', $user));
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
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.api.messages.saved'
                ]);
            } else {
                http_response_code(500);
                apiError('errors.messages.save.failed', apiLocalizedText('errors.messages.save.failed', 'Failed to save message', $user));
            }

        } catch (Exception $e) {
            http_response_code(500);
            apiError('errors.messages.save.failed', apiLocalizedText('errors.messages.save.failed', 'Failed to save message', $user));
        }
    });

    // Unsave message
    SimpleRouter::delete('/messages/{type}/{id}/save', function($type, $id) {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(500);
            apiError('errors.messages.unsave.user_id_missing', apiLocalizedText('errors.messages.unsave.user_id_missing', 'Unable to resolve user session', $user));
            return;
        }

        if (!in_array($type, ['echomail', 'netmail'])) {
            http_response_code(400);
            apiError('errors.messages.unsave.invalid_type', apiLocalizedText('errors.messages.unsave.invalid_type', 'Invalid message type', $user));
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
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.api.messages.unsaved'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error_code' => 'errors.messages.unsave.not_saved',
                    'error' => apiLocalizedText('errors.messages.unsave.not_saved', 'Message was not saved or already removed', $user)
                ]);
            }

        } catch (Exception $e) {
            http_response_code(500);
            apiError('errors.messages.unsave.failed', apiLocalizedText('errors.messages.unsave.failed', 'Failed to unsave message', $user));
        }
    });

    // Simple test endpoint
    SimpleRouter::get('/test', function() {
        header('Content-Type: application/json');
        echo json_encode(['test' => 'success', 'timestamp' => date('Y-m-d H:i:s')]);
    });

    // User profile API endpoints
    SimpleRouter::post('/user/profile', function() {
        $user = RouteHelper::requireAuth();

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

            echo json_encode([
                'success' => true,
                'real_name' => $realName,
                'message_code' => 'ui.profile.updated_successfully'
            ]);
        } catch (\Exception $e) {
            http_response_code(400);
            $message = $e->getMessage();
            if ($message === 'Current password is incorrect') {
                apiError('errors.user.profile.current_password_incorrect', apiLocalizedText('errors.user.profile.current_password_incorrect', 'Current password is incorrect', $user));
            } elseif ($message === 'New password must be at least 6 characters long') {
                apiError('errors.user.profile.new_password_too_short', apiLocalizedText('errors.user.profile.new_password_too_short', 'New password must be at least 6 characters long', $user));
            } else {
                apiError('errors.user.profile.update_failed', apiLocalizedText('errors.user.profile.update_failed', 'Failed to update profile', $user));
            }
        }
    });

    SimpleRouter::get('/user/stats', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();

        // Get user message statistics - is_sent = TRUE identifies messages composed and dispatched by
        // this user; received messages also carry user_id (the recipient) with is_sent = FALSE.
        $netmailStmt = $db->prepare("SELECT COUNT(*) as count FROM netmail WHERE user_id = ? AND is_sent = TRUE");
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

    SimpleRouter::get('/user/stats/{userId}', function($userId) {
        $user = RouteHelper::requireAuth();

        // Only admins can view other users' stats
        if (empty($user['is_admin'])) {
            http_response_code(403);
            header('Content-Type: application/json');
            apiError('errors.user.stats.admin_required', apiLocalizedText('errors.user.stats.admin_required', 'Admin privileges are required', $user));
            return;
        }

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();

        // Verify target user exists and is active
        $userStmt = $db->prepare("SELECT id FROM users WHERE id = ? AND is_active = TRUE");
        $userStmt->execute([$userId]);
        if (!$userStmt->fetch()) {
            http_response_code(404);
            apiError('errors.user.stats.user_not_found', apiLocalizedText('errors.user.stats.user_not_found', 'User not found', $user));
            return;
        }

        // Get user message statistics - is_sent = TRUE identifies messages composed and dispatched by
        // this user; received messages also carry user_id (the recipient) with is_sent = FALSE.
        $netmailStmt = $db->prepare("SELECT COUNT(*) as count FROM netmail WHERE user_id = ? AND is_sent = TRUE");
        $netmailStmt->execute([$userId]);
        $netmailCount = $netmailStmt->fetch()['count'];

        // For echomail, count messages from this user based on their username
        // We need to get the user's real name to match echomail posts
        $userInfoStmt = $db->prepare("SELECT real_name FROM users WHERE id = ?");
        $userInfoStmt->execute([$userId]);
        $userInfo = $userInfoStmt->fetch();

        $echomailCount = 0;
        if ($userInfo && !empty($userInfo['real_name'])) {
            $echomailStmt = $db->prepare("SELECT COUNT(*) as count FROM echomail WHERE from_name = ?");
            $echomailStmt->execute([$userInfo['real_name']]);
            $echomailCount = $echomailStmt->fetch()['count'];
        }

        echo json_encode([
            'netmail_count' => (int)$netmailCount,
            'echomail_count' => (int)$echomailCount
        ]);
    });

    SimpleRouter::get('/user/transactions/{userId}', function($userId) {
        $user = RouteHelper::requireAuth();

        // Only admins can view transaction history
        if (empty($user['is_admin'])) {
            http_response_code(403);
            header('Content-Type: application/json');
            apiError('errors.user.transactions.admin_required', apiLocalizedText('errors.user.transactions.admin_required', 'Admin privileges are required', $user));
            return;
        }

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();

        // Verify target user exists and is active
        $userStmt = $db->prepare("SELECT id FROM users WHERE id = ? AND is_active = TRUE");
        $userStmt->execute([$userId]);
        if (!$userStmt->fetch()) {
            http_response_code(404);
            apiError('errors.user.transactions.user_not_found', apiLocalizedText('errors.user.transactions.user_not_found', 'User not found', $user));
            return;
        }

        // Get pagination parameters
        $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
        $limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 10;

        try {
            $stmt = $db->prepare('
                SELECT id, user_id, other_party_id, amount, balance_after, description, transaction_type, created_at
                FROM user_transactions
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ');
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();

            $transactions = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'transactions' => $transactions,
                'offset' => $offset,
                'limit' => $limit
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            apiError('errors.user.transactions.list_failed', apiLocalizedText('errors.user.transactions.list_failed', 'Failed to load transactions', $user));
        }
    });

    SimpleRouter::post('/credits/send', function() {
        $user = RouteHelper::requireAuth();
        header('Content-Type: application/json');

        if (!UserCredit::isEnabled()) {
            http_response_code(400);
            apiError('errors.credits.feature_disabled', apiLocalizedText('errors.credits.feature_disabled', 'Credits feature is disabled', $user));
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $recipientId = isset($input['recipient_id']) ? (int)$input['recipient_id'] : 0;
        $amount = isset($input['amount']) ? (int)$input['amount'] : 0;
        $message = isset($input['message']) ? trim($input['message']) : '';

        $senderId = (int)($user['user_id'] ?? $user['id']);

        // Validate amount
        if ($amount < 1 || $amount > 200) {
            http_response_code(400);
            apiError('errors.credits.send.invalid_amount', apiLocalizedText('errors.credits.send.invalid_amount', 'Amount must be between 1 and 200', $user));
            return;
        }

        // Can't send to yourself
        if ($senderId === $recipientId) {
            http_response_code(400);
            apiError('errors.credits.send.self_transfer_forbidden', apiLocalizedText('errors.credits.send.self_transfer_forbidden', 'You cannot send credits to yourself', $user));
            return;
        }

        $db = Database::getInstance()->getPdo();

        try {
            // Verify recipient exists and is active
            $recipientStmt = $db->prepare('SELECT id, username FROM users WHERE id = ? AND is_active = TRUE');
            $recipientStmt->execute([$recipientId]);
            $recipient = $recipientStmt->fetch();

            if (!$recipient) {
                http_response_code(404);
                apiError('errors.credits.send.recipient_not_found', apiLocalizedText('errors.credits.send.recipient_not_found', 'Recipient not found', $user));
                return;
            }

            // Check sender has enough credits
            $senderBalance = UserCredit::getBalance($senderId);
            if ($senderBalance < $amount) {
                http_response_code(400);
                apiError('errors.credits.send.insufficient_balance', apiLocalizedText('errors.credits.send.insufficient_balance', 'Insufficient balance', $user));
                return;
            }

            // Get configured transfer fee percentage
            $creditsConfig = \BinktermPHP\BbsConfig::getConfig()['credits'] ?? [];
            $feePercent = isset($creditsConfig['transfer_fee_percent']) ? (float)$creditsConfig['transfer_fee_percent'] : 0.05;
            $feePercent = max(0, min(1, $feePercent)); // Clamp between 0 and 1

            // Calculate fee and distribution
            $fee = (int)ceil($amount * $feePercent);
            $amountToRecipient = $amount - $fee;

            // Get all sysops (admins)
            $sysopStmt = $db->prepare('SELECT id, username FROM users WHERE is_admin = TRUE AND is_active = TRUE');
            $sysopStmt->execute();
            $sysops = $sysopStmt->fetchAll();

            if (empty($sysops)) {
                // No sysops, give full amount to recipient (shouldn't happen but handle gracefully)
                $amountToRecipient = $amount;
                $fee = 0;
            }

            // Debit sender (UserCredit methods handle their own transactions)
            $messageText = $message ? " - {$message}" : '';
            $debitSuccess = UserCredit::debit(
                $senderId,
                $amount,
                "Sent to {$recipient['username']}{$messageText}",
                $recipientId,
                UserCredit::TYPE_PAYMENT
            );

            if (!$debitSuccess) {
                http_response_code(500);
                apiError('errors.credits.send.debit_failed', apiLocalizedText('errors.credits.send.debit_failed', 'Failed to debit sender account', $user));
                return;
            }

            // Credit recipient
            $senderUsername = $user['username'];
            $creditSuccess = UserCredit::credit(
                $recipientId,
                $amountToRecipient,
                "Received from {$senderUsername}{$messageText}",
                $senderId,
                UserCredit::TYPE_PAYMENT
            );

            if (!$creditSuccess) {
                // Try to refund sender
                UserCredit::credit(
                    $senderId,
                    $amount,
                    "Refund: Failed transfer to {$recipient['username']}",
                    $recipientId,
                    UserCredit::TYPE_REFUND
                );
                http_response_code(500);
                apiError('errors.credits.send.credit_failed', apiLocalizedText('errors.credits.send.credit_failed', 'Failed to credit recipient account', $user));
                return;
            }

            // Distribute fee to sysops
            if ($fee > 0 && !empty($sysops)) {
                $feePerSysop = (int)floor($fee / count($sysops));
                $remainder = $fee - ($feePerSysop * count($sysops));

                foreach ($sysops as $index => $sysop) {
                    $sysopFee = $feePerSysop;
                    // Give remainder to first sysop
                    if ($index === 0) {
                        $sysopFee += $remainder;
                    }

                    if ($sysopFee > 0) {
                        UserCredit::credit(
                            (int)$sysop['id'],
                            $sysopFee,
                            "Transaction fee from {$senderUsername} → {$recipient['username']}",
                            $senderId,
                            UserCredit::TYPE_SYSTEM_REWARD
                        );
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'fee' => $fee,
                'amount_received' => $amountToRecipient,
                'message_code' => 'ui.user_profile.send_credits_success',
                'message_params' => [
                    'symbol' => UserCredit::getCurrencySymbol(),
                    'amount' => $amount,
                    'username' => $recipient['username'],
                    'fee' => $fee,
                    'received' => $amountToRecipient
                ]
            ]);

        } catch (\Exception $e) {
            http_response_code(500);
            apiError('errors.credits.send.failed', apiLocalizedText('errors.credits.send.failed', 'Credit transfer failed', $user));
        }
    });

    SimpleRouter::get('/user/credits', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $balance = UserCredit::getBalance($user['user_id']);

        echo json_encode([
            'id' => $user['user_id'],
            'username' => $user['username'],
            'credit_balance' => (int)$balance
        ]);
    });

    SimpleRouter::get('/user/sessions', function() {
        $user = RouteHelper::requireAuth();

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
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();

        // Only allow users to revoke their own sessions
        $stmt = $db->prepare("DELETE FROM user_sessions WHERE session_id = ? AND user_id = ?");
        $result = $stmt->execute([$sessionId, $user['user_id']]);

        if ($result) {
            echo json_encode([
                'success' => true,
                'message_code' => 'ui.settings.sessions.revoked_success'
            ]);
        } else {
            http_response_code(404);
            apiError('errors.user.sessions.revoke_failed', apiLocalizedText('errors.user.sessions.revoke_failed', 'Failed to revoke session', $user));
        }
    });

    SimpleRouter::delete('/user/sessions/all', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();

        // Delete all sessions for this user
        $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
        $result = $stmt->execute([$user['user_id']]);

        if ($result) {
            // Clear the current session cookie
            setcookie('binktermphp_session', '', time() - 3600, '/');
            echo json_encode([
                'success' => true,
                'message_code' => 'ui.settings.sessions.logged_out_all_success'
            ]);
        } else {
            http_response_code(500);
            apiError('errors.user.sessions.revoke_all_failed', apiLocalizedText('errors.user.sessions.revoke_all_failed', 'Failed to revoke sessions', $user));
        }
    });

    // Get echolist filter preference
    // Get echolist filter preferences
    SimpleRouter::get('/user/echolist-preference', function() {
        $user = RouteHelper::requireAuth();
        header('Content-Type: application/json');

        $db = Database::getInstance()->getPdo();
        $stmt = $db->prepare("
            SELECT echolist_subscribed_only, echolist_unread_only
            FROM user_settings
            WHERE user_id = ?
        ");
        $stmt->execute([$user['user_id']]);
        $pref = $stmt->fetch();

        echo json_encode([
            'subscribed_only' => $pref ? (bool)$pref['echolist_subscribed_only'] : false,
            'unread_only'     => $pref ? (bool)$pref['echolist_unread_only']     : false,
        ]);
    });

    // Set echolist filter preferences
    SimpleRouter::post('/user/echolist-preference', function() {
        $user = RouteHelper::requireAuth();
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $subscribedOnly = !empty($input['subscribed_only']);
        $unreadOnly     = !empty($input['unread_only']);

        $db = Database::getInstance()->getPdo();

        $stmt = $db->prepare("
            INSERT INTO user_settings (user_id, echolist_subscribed_only, echolist_unread_only)
            VALUES (?, ?, ?)
            ON CONFLICT (user_id)
            DO UPDATE SET
                echolist_subscribed_only = EXCLUDED.echolist_subscribed_only,
                echolist_unread_only     = EXCLUDED.echolist_unread_only
        ");
        $stmt->execute([
            $user['user_id'],
            $subscribedOnly ? 'true' : 'false',
            $unreadOnly     ? 'true' : 'false',
        ]);

        echo json_encode(['success' => true]);
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
                $entry['service'] = $onlineUser['service'] ?? 'web';
                $entry['last_activity_ts'] = $onlineUser['last_activity'] ? (int)strtotime($onlineUser['last_activity']) : null;
            }
            return $entry;
        }, $onlineUsers);

        echo json_encode([
            'users' => $responseUsers,
            'online_minutes' => 15
        ]);
    });

    SimpleRouter::post('/user/activity', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);
        $activity = $input['activity'] ?? '';
        $sessionId = $_COOKIE['binktermphp_session'] ?? null;

        if (!$sessionId) {
            http_response_code(400);
            apiError('errors.user.activity.session_missing', apiLocalizedText('errors.user.activity.session_missing', 'Active session is required', $user));
            return;
        }
        $auth = new Auth();

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

        $user = RouteHelper::requireAuth();

        // Check if user is admin
        if (!$user['is_admin']) {
            ob_clean();
            http_response_code(403);
            header('Content-Type: application/json');
            apiError('errors.binkp.admin_required', apiLocalizedText('errors.binkp.admin_required', 'Admin access required'), 403);
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
            apiError('', apiLocalizedText('', ''));
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
            echo json_encode([
                'success' => true,
                'message_code' => 'ui.api.binkp.poll_triggered',
                'result' => $result
            ]);
        } catch (\Exception $e) {
            ob_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            apiError('errors.binkp.poll_failed', apiLocalizedText('errors.binkp.poll_failed', 'Failed to poll BinkP uplink'), 500);
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
            echo json_encode([
                'success' => true,
                'message_code' => 'ui.api.binkp.poll_all_triggered',
                'result' => $result
            ]);
        } catch (\Exception $e) {
            ob_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            apiError('errors.binkp.poll_all_failed', apiLocalizedText('errors.binkp.poll_all_failed', 'Failed to poll all BinkP uplinks'), 500);
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
            echo json_encode([
                'success' => true,
                'message_code' => 'ui.api.binkp.process_packets_started',
                'result' => $result
            ]);
        } catch (\Exception $e) {
            ob_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            apiError('errors.binkp.process_packets_failed', apiLocalizedText('errors.binkp.process_packets_failed', 'Failed to process packets'), 500);
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
            apiError('', apiLocalizedText('', ''));
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
            apiError('', apiLocalizedText('', ''));
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
            apiError('', apiLocalizedText('', ''));
        }
    });

    SimpleRouter::post('/binkp/process/inbound', function() {
        ob_start();

        $user = RouteHelper::requireAuth();
        requireBinkpAdmin($user);

        try {
            $client = new \BinktermPHP\Admin\AdminDaemonClient();
            $result = $client->processPackets();

            ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message_code' => 'ui.binkp.inbound_processing_completed',
                'result' => $result
            ]);
        } catch (\Exception $e) {
            ob_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            apiError('errors.binkp.process_packets_failed', apiLocalizedText('errors.binkp.process_packets_failed', 'Failed to process packets'), 500);
        }
    });

    SimpleRouter::post('/binkp/process/outbound', function() {
        ob_start();

        $user = RouteHelper::requireAuth();
        requireBinkpAdmin($user);

        try {
            $client = new \BinktermPHP\Admin\AdminDaemonClient();
            $result = $client->binkPoll('all');

            ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message_code' => 'ui.binkp.outbound_processing_completed',
                'result' => $result
            ]);
        } catch (\Exception $e) {
            ob_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            apiError('errors.binkp.process_outbound_failed', apiLocalizedText('errors.binkp.process_outbound_failed', 'Failed to process outbound queue'), 500);
        }
    });

    SimpleRouter::get('/binkp/logs', function() {
        $user = RouteHelper::requireAuth();
        requireBinkpAdmin($user);

        header('Content-Type: application/json');

        $lines = intval($_GET['lines'] ?? 100);
        $controller = new \BinktermPHP\Binkp\Web\BinkpController();
        echo json_encode($controller->getLogs($lines));
    });

    // Test endpoint to verify delete endpoint is accessible
    SimpleRouter::get('/messages/echomail/delete-test', function() {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message_code' => 'ui.api.debug.delete_endpoint_accessible'
        ]);
    });

    // Message sharing API endpoints
    SimpleRouter::post('/messages/echomail/{id}/share', function($id) {
        header('Content-Type: application/json');

        $user = RouteHelper::requireAuth();
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
                $result = apiLocalizeErrorPayload($result, $user);
                echo json_encode($result);
            }
        } catch (Exception $e) {
            apiError('errors.messages.share_create_failed', apiLocalizedText('errors.messages.share_create_failed', 'Failed to create share link'), 500);
        }
    });

    SimpleRouter::get('/messages/echomail/{id}/shares', function($id) {
        header('Content-Type: application/json');

        $user = RouteHelper::requireAuth();
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        try {
            $handler = new MessageHandler();
            $result = $handler->getMessageShares($id, 'echomail', $userId);
            $result = apiLocalizeErrorPayload($result, $user);
            echo json_encode($result);
        } catch (Exception $e) {
            apiError('errors.messages.share_lookup_failed', apiLocalizedText('errors.messages.share_lookup_failed', 'Failed to load share links'), 500);
        }
    });

    SimpleRouter::delete('/messages/echomail/{id}/share', function($id) {
        header('Content-Type: application/json');

        $user = RouteHelper::requireAuth();
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        try {
            $handler = new MessageHandler();
            $result = $handler->revokeShare($id, 'echomail', $userId);

            if ($result['success']) {
                echo json_encode($result);
            } else {
                http_response_code(404);
                $result = apiLocalizeErrorPayload($result, $user);
                echo json_encode($result);
            }
        } catch (Exception $e) {
            apiError('errors.messages.share_revoke_failed', apiLocalizedText('errors.messages.share_revoke_failed', 'Failed to revoke share link'), 500);
        }
    });

    SimpleRouter::post('/messages/echomail/{id}/share/friendly-url', function($id) {
        header('Content-Type: application/json');

        $user   = RouteHelper::requireAuth();
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        try {
            $handler = new MessageHandler();
            $result  = $handler->generateSlugForExistingShare((int)$id, 'echomail', $userId);

            if ($result['success']) {
                echo json_encode($result);
            } else {
                http_response_code(404);
                $result = apiLocalizeErrorPayload($result, $user);
                echo json_encode($result);
            }
        } catch (Exception $e) {
            apiError('errors.messages.shared.slug_generation_failed', apiLocalizedText('errors.messages.shared.slug_generation_failed', 'Cannot generate share slug for this message'), 500);
        }
    });

    SimpleRouter::get('/messages/shared/{area}/{slug}', function($area, $slug) {
        header('Content-Type: application/json');

        $auth   = new Auth();
        $user   = $auth->getCurrentUser();
        $userId = $user ? ($user['user_id'] ?? $user['id'] ?? null) : null;

        try {
            $handler = new MessageHandler();
            $result  = $handler->getSharedMessageBySlug($area, $slug, $userId);

            if ($result['success']) {
                echo json_encode($result);
            } else {
                $result = apiLocalizeErrorPayload($result, $user);
                $statusCode = (($result['error_code'] ?? '') === 'errors.messages.shared.login_required') ? 401 : 404;
                http_response_code($statusCode);
                echo json_encode($result);
            }
        } catch (Exception $e) {
            http_response_code(500);
            apiError('errors.messages.shared.lookup_failed', apiLocalizedText('errors.messages.shared.lookup_failed', 'Failed to load shared message'), 500);
        }
    })->where(['area' => '[A-Za-z0-9@._-]+', 'slug' => '[A-Za-z0-9_-]+']);

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
                $result = apiLocalizeErrorPayload($result, $user);
                $statusCode = (($result['error_code'] ?? '') === 'errors.messages.shared.login_required') ? 401 : 404;
                http_response_code($statusCode);
                echo json_encode($result);
            }
        } catch (Exception $e) {
            http_response_code(500);
            apiError('errors.messages.shared.lookup_failed', apiLocalizedText('errors.messages.shared.lookup_failed', 'Failed to load shared message'), 500);
        }
    });

    SimpleRouter::get('/user/shares', function() {
        header('Content-Type: application/json');

        $user = RouteHelper::requireAuth();
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        try {
            $handler = new MessageHandler();
            $shares = $handler->getUserShares($userId);
            echo json_encode(['success' => true, 'shares' => $shares]);
        } catch (Exception $e) {
            http_response_code(500);
            apiError('errors.messages.shared.user_shares_failed', apiLocalizedText('errors.messages.shared.user_shares_failed', 'Failed to load user shares'), 500);
        }
    });

    SimpleRouter::get('/taglines', function() {
        $user = RouteHelper::requireAuth();

        header('Content-Type: application/json');

        try {
            $path = __DIR__ . '/../config/taglines.txt';
            $raw = file_exists($path) ? file_get_contents($path) : '';
            if ($raw === false) {
                $raw = '';
            }
            $lines = preg_split('/\r\n|\r|\n/', (string)$raw) ?: [];
            $taglines = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                $taglines[] = $trimmed;
            }
            echo json_encode(['success' => true, 'taglines' => $taglines]);
        } catch (Exception $e) {
            http_response_code(500);
            apiError('errors.taglines.load_failed', apiLocalizedText('errors.taglines.load_failed', 'Failed to load taglines'), 500);
        }
    });

    // Client-side i18n catalog endpoint (supports lazy namespace loading)
    SimpleRouter::get('/i18n/catalog', function() {
        header('Content-Type: application/json');

        $translator = new Translator();
        $resolver = new LocaleResolver($translator);
        $auth = new Auth();
        $currentUser = $auth->getCurrentUser();

        $requestedLocale = isset($_GET['locale']) ? (string)$_GET['locale'] : null;
        $resolvedLocale = $resolver->resolveLocale($requestedLocale, $currentUser);

        $nsRaw = trim((string)($_GET['ns'] ?? 'common'));
        $namespaces = preg_split('/\s*,\s*/', $nsRaw) ?: ['common'];
        $namespaces = array_values(array_filter(array_map('trim', $namespaces), static function ($ns) {
            return $ns !== '';
        }));
        if (empty($namespaces)) {
            $namespaces = ['common'];
        }

        $catalogs = [];
        foreach ($namespaces as $namespace) {
            $catalogs[$namespace] = $translator->getCatalog($resolvedLocale, $namespace);
        }

        $resolver->persistLocale($resolvedLocale);

        echo json_encode([
            'success' => true,
            'locale' => $resolvedLocale,
            'default_locale' => $translator->getDefaultLocale(),
            'catalogs' => $catalogs
        ]);
    });

    // User settings API endpoints
    SimpleRouter::get('/user/settings', function() {
        $user = RouteHelper::requireAuth();
        header('Content-Type: application/json');

        $userId = $user['user_id'] ?? $user['id'] ?? null;

        try {
            $handler = new MessageHandler();
            $settings = $handler->getUserSettings($userId);

            $translator = new Translator();
            $resolver = new LocaleResolver($translator);
            $settings['locale'] = $resolver->resolveLocale((string)($settings['locale'] ?? ''), $settings);
            $resolver->persistLocale($settings['locale']);

            // Append shell preference from UserMeta
            if ($userId) {
                $meta = new \BinktermPHP\UserMeta();
                $settings['shell'] = $meta->getValue((int)$userId, 'shell') ?? '';
            }

            echo json_encode(['success' => true, 'settings' => $settings]);
        } catch (Exception $e) {
            http_response_code(500);
            apiError('errors.settings.load_failed', apiLocalizedText('errors.settings.load_failed', 'Failed to load user settings'), 500);
        }
    });

    SimpleRouter::post('/user/settings', function() {
        $user = RouteHelper::requireAuth();
        header('Content-Type: application/json');

        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || !isset($input['settings'])) {
            apiError('errors.settings.invalid_input', apiLocalizedText('errors.settings.invalid_input', 'Invalid input'), 400);
            return;
        }

        try {
            $settings = $input['settings'];

            if (isset($settings['locale'])) {
                $translator = new Translator();
                $resolver = new LocaleResolver($translator);
                $settings['locale'] = $resolver->resolveLocale((string)$settings['locale']);
                $resolver->persistLocale($settings['locale']);
            }

            // Handle shell preference separately (stored in UserMeta, not user_settings table)
            if (isset($settings['shell']) && $userId && !\BinktermPHP\AppearanceConfig::isShellLocked()) {
                $shellVal = (string)$settings['shell'];
                if (in_array($shellVal, ['web', 'bbs-menu'], true)) {
                    $meta = new \BinktermPHP\UserMeta();
                    $meta->setValue((int)$userId, 'shell', $shellVal);
                }
            }
            unset($settings['shell']);

            $handler = new MessageHandler();
            $result = $handler->updateUserSettings($userId, $settings);

            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.settings.saved_successfully'
                ]);
            } else {
                apiError('errors.settings.update_failed', apiLocalizedText('errors.settings.update_failed', 'Failed to update settings'), 400);
            }
        } catch (Exception $e) {
            http_response_code(500);
            apiError('errors.settings.update_failed', apiLocalizedText('errors.settings.update_failed', 'Failed to update settings'), 500);
        }
    });

    // Admin API endpoints for user management
    SimpleRouter::get('/admin/pending-users', function() {
        $user = RouteHelper::requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            apiError('', apiLocalizedText('', ''));
            return;
        }

        header('Content-Type: application/json');

        try {
            $handler = new MessageHandler();
            $users = $handler->getPendingUsers();
            echo json_encode(['success' => true, 'users' => $users]);
        } catch (Exception $e) {
            http_response_code(500);
            apiError('', apiLocalizedText('', ''));
        }
    });

    SimpleRouter::get('/admin/pending-users/{id}', function($id) {
        $user = RouteHelper::requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            apiError('', apiLocalizedText('', ''));
            return;
        }

        header('Content-Type: application/json');

        try {
            $db = Database::getInstance()->getPdo();
            $stmt = $db->prepare("
                SELECT p.*, u.username as referrer_username, u.real_name as referrer_real_name
                FROM pending_users p
                LEFT JOIN users u ON p.referrer_id = u.id
                WHERE p.id = ?
            ");
            $stmt->execute([$id]);
            $pendingUser = $stmt->fetch();

            if (!$pendingUser) {
                http_response_code(404);
                apiError('', apiLocalizedText('', ''));
                return;
            }

            echo json_encode(['success' => true, 'user' => $pendingUser]);
        } catch (Exception $e) {
            http_response_code(500);
            apiError('', apiLocalizedText('', ''));
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::post('/admin/pending-users/{id}/approve', function($id) {
        $user = RouteHelper::requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            apiError('', apiLocalizedText('', ''));
            return;
        }

        header('Content-Type: application/json');

        $notes = $_POST['notes'] ?? '';

        try {
            $handler = new MessageHandler();
            $newUserId = $handler->approveUserRegistration($id, $user['user_id'], $notes);
            echo json_encode([
                'success' => true,
                'new_user_id' => $newUserId,
                'message_code' => 'ui.admin_users.user_approved_success'
            ]);
        } catch (Exception $e) {
            http_response_code(400);
            apiError('', apiLocalizedText('', ''));
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::post('/admin/pending-users/{id}/reject', function($id) {
        $user = RouteHelper::requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            apiError('', apiLocalizedText('', ''));
            return;
        }

        header('Content-Type: application/json');

        $notes = $_POST['notes'] ?? '';

        try {
            $handler = new MessageHandler();
            $handler->rejectUserRegistration($id, $user['user_id'], $notes);
            echo json_encode([
                'success' => true,
                'message_code' => 'ui.admin_users.user_rejected_success'
            ]);
        } catch (Exception $e) {
            http_response_code(400);
            apiError('', apiLocalizedText('', ''));
        }
    })->where(['id' => '[0-9]+']);

    SimpleRouter::get('/admin/users', function() {
        $user = RouteHelper::requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            apiError('', apiLocalizedText('', ''));
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
            apiError('', apiLocalizedText('', ''));
        }
    });

    // Get single user for editing
    SimpleRouter::get('/admin/users/{id}', function($id) {
        $user = RouteHelper::requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            apiError('', apiLocalizedText('', ''));
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
                apiError('', apiLocalizedText('', ''));
                return;
            }

            echo json_encode(['success' => true, 'user' => $userData]);
        } catch (Exception $e) {
            http_response_code(500);
            apiError('', apiLocalizedText('', ''));
        }
    })->where(['id' => '[0-9]+']);

    // Update user
    SimpleRouter::post('/admin/users/{id}', function($id) {
        $user = RouteHelper::requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            apiError('', apiLocalizedText('', ''));
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
                apiError('', apiLocalizedText('', ''));
                return;
            }

            $realName = $_POST['real_name'] ?? '';
            $email = $_POST['email'] ?? '';
            $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
            $isAdmin = isset($_POST['is_admin']) ? (int)$_POST['is_admin'] : 0;
            $password = $_POST['password'] ?? '';

            if (empty($realName)) {
                http_response_code(400);
                apiError('', apiLocalizedText('', ''));
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
                    apiError('', apiLocalizedText('', ''));
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

            echo json_encode([
                'success' => true,
                'message_code' => 'ui.admin_users.user_updated_success'
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            apiError('', apiLocalizedText('', ''));
        }
    })->where(['id' => '[0-9]+']);

    // Toggle user status
    SimpleRouter::post('/admin/users/{id}/toggle-status', function($id) {
        $user = RouteHelper::requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            apiError('', apiLocalizedText('', ''));
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
                apiError('', apiLocalizedText('', ''));
                return;
            }

            echo json_encode([
                'success' => true,
                'message_code' => 'ui.admin_users.user_toggled_success',
                'message_params' => [
                    'action' => $isActive ? 'enable' : 'disable'
                ]
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            apiError('', apiLocalizedText('', ''));
        }
    })->where(['id' => '[0-9]+']);

    // Create new user
    SimpleRouter::post('/admin/users/create', function() {
        $user = RouteHelper::requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            apiError('', apiLocalizedText('', ''));
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
                apiError('', apiLocalizedText('', ''));
                return;
            }

            // Validate username format
            if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
                http_response_code(400);
                apiError('', apiLocalizedText('', ''));
                return;
            }

            if (\BinktermPHP\UserRestrictions::isRestrictedUsername($username)
                || \BinktermPHP\UserRestrictions::isRestrictedRealName($realName)) {
                http_response_code(400);
                apiError('', apiLocalizedText('', ''));
                return;
            }

            // Validate password length
            if (strlen($password) < 8) {
                http_response_code(400);
                apiError('', apiLocalizedText('', ''));
                return;
            }

            $db = Database::getInstance()->getPdo();

            // Check if username already exists
            $checkStmt = $db->prepare("SELECT 1 FROM users WHERE username = ?");
            $checkStmt->execute([$username]);

            if ($checkStmt->fetch()) {
                http_response_code(409);
                apiError('', apiLocalizedText('', ''));
                return;
            }

            // Hash password
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            // Create user
            $insertStmt = $db->prepare("
                INSERT INTO users (username, password_hash, real_name, email, is_active, is_admin, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");

            $insertStmt->execute([
                $username,
                $passwordHash,
                $realName,
                $email ?: null,
                $isActive,
                $isAdmin
            ]);

            $newUserId = $db->lastInsertId();

            // Create default user settings
            $settingsStmt = $db->prepare("
                INSERT INTO user_settings (user_id, messages_per_page) 
                VALUES (?, 25)
            ");
            $settingsStmt->execute([$newUserId]);

            echo json_encode([
                'success' => true,
                'user_id' => $newUserId,
                'message_code' => 'ui.admin_users.user_created_success'
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            apiError('', apiLocalizedText('', ''));
        }
    });

    // Cleanup old registrations
    SimpleRouter::post('/admin/users/cleanup', function() {
        $user = RouteHelper::requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            apiError('', apiLocalizedText('', ''));
            return;
        }

        header('Content-Type: application/json');

        try {
            $handler = new MessageHandler();
            $result = $handler->performFullCleanup();
            echo json_encode([
                'success' => true,
                'result' => $result,
                'message_code' => 'ui.admin_users.cleanup_success',
                'message_params' => [
                    'approved' => $result['approved_removed'] ?? 0,
                    'rejected' => $result['old_rejected_removed'] ?? 0,
                    'total' => $result['total_cleaned'] ?? 0
                ]
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            apiError('', apiLocalizedText('', ''));
        }
    });

    // Send account reminder to user
    SimpleRouter::post('/admin/users/{userId}/send-reminder', function($userId) {
        $user = RouteHelper::requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            apiError('', apiLocalizedText('', ''));
            return;
        }

        header('Content-Type: application/json');

        try {
            // Get user info to get username
            $adminController = new AdminController();
            $targetUser = $adminController->getUser($userId);

            if (!$targetUser) {
                http_response_code(404);
                apiError('', apiLocalizedText('', ''));
                return;
            }

            $handler = new MessageHandler();

            // Check if user can receive reminder
            if (!$handler->canSendReminder($targetUser['username'])) {
                http_response_code(400);
                apiError('', apiLocalizedText('', ''));
                return;
            }

            // Send reminder
            $result = $handler->sendAccountReminder($targetUser['username']);

            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.api.reminder.sent',
                    'email_sent' => $result['email_sent'] ?? false
                ]);
            } else {
                http_response_code(400);
                apiError('', apiLocalizedText('', ''));
            }

        } catch (Exception $e) {
            error_log("Admin reminder error: " . $e->getMessage());
            http_response_code(500);
            apiError('', apiLocalizedText('', ''));
        }
    });

    // Get users who need reminders
    SimpleRouter::get('/admin/users/need-reminders', function() {
        $user = RouteHelper::requireAuth();

        if (!$user['is_admin']) {
            http_response_code(403);
            apiError('', apiLocalizedText('', ''));
            return;
        }

        header('Content-Type: application/json');

        try {
            $adminController = new AdminController();
            $usersNeedingReminder = $adminController->getUsersNeedingReminder();

            echo json_encode(['success' => true, 'users' => $usersNeedingReminder]);
        } catch (Exception $e) {
            http_response_code(500);
            apiError('', apiLocalizedText('', ''));
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
            apiError('', apiLocalizedText('', ''));
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
                apiError(
                    'errors.address_book.list_failed',
                    apiLocalizedText('errors.address_book.list_failed', 'Failed to load address book entries', $user, [], 'errors')
                );
                return;
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
                    apiError(
                        'errors.address_book.not_found',
                        apiLocalizedText('errors.address_book.not_found', 'Entry not found', $user, [], 'errors'),
                        404
                    );
                    return;
                }
            } catch (Exception $e) {
                apiError(
                    'errors.address_book.get_failed',
                    apiLocalizedText('errors.address_book.get_failed', 'Failed to load address book entry', $user, [], 'errors')
                );
                return;
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
                    apiError(
                        'errors.address_book.user_not_found',
                        apiLocalizedText('errors.address_book.user_not_found', 'User ID not found in authentication data', $user, [], 'errors'),
                        400
                    );
                    return;
                }

                $addressBook = new AddressBookController();
                $entryId = $addressBook->createEntry($userId, $data);

                echo json_encode([
                    'success' => true,
                    'entry_id' => $entryId,
                    'message_code' => 'ui.compose.address_book.entry_added'
                ]);
            } catch (\BinktermPHP\AddressBookException $e) {
                $errorCode = $e->getErrorCode();
                apiError(
                    $errorCode,
                    apiLocalizedText($errorCode, $e->getMessage(), $user, [], 'errors'),
                    $e->getHttpStatus()
                );
                return;
            } catch (Exception $e) {
                apiError(
                    'errors.address_book.create_failed',
                    apiLocalizedText('errors.address_book.create_failed', 'Failed to create address book entry', $user, [], 'errors'),
                    400
                );
                return;
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
                    echo json_encode([
                        'success' => true,
                        'message_code' => 'ui.address_book.entry_updated'
                    ]);
                } else {
                    apiError(
                        'errors.address_book.update_failed',
                        apiLocalizedText('errors.address_book.update_failed', 'Failed to update address book entry', $user, [], 'errors'),
                        400
                    );
                    return;
                }
            } catch (\BinktermPHP\AddressBookException $e) {
                $errorCode = $e->getErrorCode();
                apiError(
                    $errorCode,
                    apiLocalizedText($errorCode, $e->getMessage(), $user, [], 'errors'),
                    $e->getHttpStatus()
                );
                return;
            } catch (Exception $e) {
                apiError(
                    'errors.address_book.update_failed',
                    apiLocalizedText('errors.address_book.update_failed', 'Failed to update address book entry', $user, [], 'errors'),
                    400
                );
                return;
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
                    echo json_encode([
                        'success' => true,
                        'message_code' => 'ui.address_book.entry_deleted'
                    ]);
                } else {
                    apiError(
                        'errors.address_book.not_found',
                        apiLocalizedText('errors.address_book.not_found', 'Entry not found', $user, [], 'errors'),
                        404
                    );
                    return;
                }
            } catch (Exception $e) {
                apiError(
                    'errors.address_book.delete_failed',
                    apiLocalizedText('errors.address_book.delete_failed', 'Failed to delete address book entry', $user, [], 'errors')
                );
                return;
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
                apiError(
                    'errors.address_book.search_failed',
                    apiLocalizedText('errors.address_book.search_failed', 'Failed to search address book entries', $user, [], 'errors')
                );
                return;
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
                apiError(
                    'errors.address_book.stats_failed',
                    apiLocalizedText('errors.address_book.stats_failed', 'Failed to load address book statistics', $user, [], 'errors')
                );
                return;
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

/**
 * Referral System API Endpoints
 */
SimpleRouter::group(['prefix' => '/api/referrals'], function() {

    /**
     * Get current user's referral statistics
     * Returns referral code, URL, list of referred users, and total earnings
     */
    SimpleRouter::get('/my-stats', function() {
        header('Content-Type: application/json');

        $user = RouteHelper::requireAuth();
        $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);

        try {
            $db = Database::getInstance()->getPdo();

            // Get user's referral code
            $stmt = $db->prepare("SELECT referral_code FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !$user['referral_code']) {
                http_response_code(404);
                apiError('errors.referrals.code_not_found', apiLocalizedText('errors.referrals.code_not_found', 'Referral code not found'));
                return;
            }

            // Get list of referred users
            $stmt = $db->prepare("
                SELECT username, real_name, created_at
                FROM users
                WHERE referred_by = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$userId]);
            $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get total credits earned from referrals
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) as total_earned
                FROM user_transactions
                WHERE user_id = ? AND transaction_type = ?
            ");
            $stmt->execute([$userId, UserCredit::TYPE_REFERRAL_BONUS]);
            $earnings = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get referral bonus amount for display
            $creditsConfig = UserCredit::getCreditsConfig();
            $referralBonus = $creditsConfig['referral_bonus'] ?? 25;

            echo json_encode([
                'referral_code' => $user['referral_code'],
                'referral_url' => Config::getSiteUrl() . '/register?ref=' . rawurlencode($user['referral_code']),
                'referrals' => $referrals,
                'total_count' => count($referrals),
                'total_earned' => (int)$earnings['total_earned'],
                'referral_bonus' => $referralBonus
            ]);

        } catch (Exception $e) {
            error_log("Referral stats error: " . $e->getMessage());
            http_response_code(500);
            apiError('errors.referrals.stats_failed', apiLocalizedText('errors.referrals.stats_failed', 'Failed to load referral statistics'));
        }
    });

    /**
     * Admin endpoint: Get system-wide referral statistics
     * Requires admin authentication
     */
    SimpleRouter::get('/admin/stats', function() {
        header('Content-Type: application/json');

        $user = RouteHelper::requireAdmin();

        try {
            $db = Database::getInstance()->getPdo();

            // Total referrals
            $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE referred_by IS NOT NULL");
            $totalReferrals = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Top referrers
            $stmt = $db->query("
                SELECT u.username, u.real_name, COUNT(r.id) as referral_count
                FROM users u
                INNER JOIN users r ON r.referred_by = u.id
                GROUP BY u.id, u.username, u.real_name
                ORDER BY referral_count DESC
                LIMIT 10
            ");
            $topReferrers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Recent referrals
            $stmt = $db->query("
                SELECT u.username, u.created_at, r.username as referrer
                FROM users u
                INNER JOIN users r ON u.referred_by = r.id
                ORDER BY u.created_at DESC
                LIMIT 10
            ");
            $recentReferrals = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Total credits awarded
            $stmt = $db->query("
                SELECT COALESCE(SUM(amount), 0) as total_awarded
                FROM user_transactions
                WHERE transaction_type = '" . UserCredit::TYPE_REFERRAL_BONUS . "'
            ");
            $totalCreditsAwarded = $stmt->fetch(PDO::FETCH_ASSOC)['total_awarded'];

            echo json_encode([
                'total_referrals' => (int)$totalReferrals,
                'top_referrers' => $topReferrers,
                'recent_referrals' => $recentReferrals,
                'total_credits_awarded' => (int)$totalCreditsAwarded
            ]);

        } catch (Exception $e) {
            error_log("Admin referral stats error: " . $e->getMessage());
            http_response_code(500);
            apiError('errors.referrals.admin_stats_failed', apiLocalizedText('errors.referrals.admin_stats_failed', 'Failed to load admin referral statistics', $user));
        }
    });
});

