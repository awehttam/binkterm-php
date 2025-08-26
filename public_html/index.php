<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\AdminController;
use BinktermPHP\MessageHandler;


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

// Web routes
SimpleRouter::get('/', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    
    $template = new Template();
    $template->renderResponse('dashboard.twig');
});

SimpleRouter::get('/login', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    
    if ($user) {
        return SimpleRouter::response()->redirect('/');
    }
    
    $template = new Template();
    $template->renderResponse('login.twig');
});

SimpleRouter::get('/register', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    
    if ($user) {
        return SimpleRouter::response()->redirect('/');
    }
    
    $template = new Template();
    $template->renderResponse('register.twig');
});

SimpleRouter::get('/netmail', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    
    // Get system address for message filtering
    try {
        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $systemAddress = $binkpConfig->getSystemAddress();
    } catch (\Exception $e) {
        $systemAddress = 'Unknown';
    }
    
    $template = new Template();
    $template->renderResponse('netmail.twig', ['system_address' => $systemAddress]);
});

SimpleRouter::get('/echomail', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    
    $template = new Template();
    $template->renderResponse('echomail.twig', ['echoarea' => null]);
});

SimpleRouter::get('/echomail/{echoarea}', function($echoarea) {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    
    // URL decode the echoarea parameter to handle dots and special characters  
    $echoarea = urldecode($echoarea);
    $template = new Template();
    $template->renderResponse('echomail.twig', ['echoarea' => $echoarea]);
})->where(['echoarea' => '[A-Za-z0-9._-]+']);

SimpleRouter::get('/binkp', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    
    // Check if user is admin
    if (!$user['is_admin']) {
        http_response_code(403);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title' => 'Access Denied',
            'error_message' => 'Only administrators can access BinkP functionality.'
        ]);
        return;
    }
    
    $template = new Template();
    $template->renderResponse('binkp.twig');
});

SimpleRouter::get('/profile', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    
    // Get system configuration for display
    try {
        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $systemName = $binkpConfig->getSystemName();
        $systemAddress = $binkpConfig->getSystemAddress();
        $sysopName = $binkpConfig->getSystemSysop();
    } catch (\Exception $e) {
        $systemName = 'BinktermPHP System';
        $systemAddress = 'Not configured';
        $sysopName = 'Unknown';
    }
    
    $templateVars = [
        'user_username' => $user['username'],
        'user_real_name' => $user['real_name'] ?? '',
        'user_email' => $user['email'] ?? '',
        'user_created_at' => $user['created_at'],
        'user_last_login' => $user['last_login'],
        'user_is_admin' => (bool)$user['is_admin'],
        'system_name_display' => $systemName,
        'system_address_display' => $systemAddress,
        'system_sysop' => $sysopName
    ];
    
    $template = new Template();
    $template->renderResponse('profile.twig', $templateVars);
});

SimpleRouter::get('/settings', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    
    // Get system configuration for display
    try {
        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $systemName = $binkpConfig->getSystemName();
        $systemAddress = $binkpConfig->getSystemAddress();
        $sysopName = $binkpConfig->getSystemSysop();
    } catch (\Exception $e) {
        $systemName = 'BinktermPHP System';
        $systemAddress = 'Not configured';
        $sysopName = 'Unknown';
    }
    
    $templateVars = [
        'system_name_display' => $systemName,
        'system_address_display' => $systemAddress,
        'system_sysop' => $sysopName
    ];
    
    $template = new Template();
    $template->renderResponse('settings.twig', $templateVars);
});

SimpleRouter::get('/admin/users', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    
    // Check if user is admin
    if (!$user['is_admin']) {
        http_response_code(403);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title' => 'Access Denied',
            'error_message' => 'Only administrators can access user management.'
        ]);
        return;
    }
    
    $template = new Template();
    $template->renderResponse('admin_users.twig');
});

SimpleRouter::get('/echoareas', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    
    // Check if user is admin (echoareas management is admin only)
    if (!$user['is_admin']) {
        return SimpleRouter::response()->httpCode(403);
    }
    
    $template = new Template();
    $template->renderResponse('echoareas.twig');
});

SimpleRouter::get('/compose/{type}', function($type) {
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }
    
    if (!in_array($type, ['netmail', 'echomail'])) {
        return SimpleRouter::response()->httpCode(404);
    }
    
    // Handle reply and echoarea parameters
    $replyId = $_GET['reply'] ?? null;
    $echoarea = $_GET['echoarea'] ?? null;
    
    // Handle new message parameters (from nodelist)
    $toAddress = $_GET['to'] ?? null;
    $toName = $_GET['to_name'] ?? null;
    // Get system configuration for display
    try {
        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $systemName = $binkpConfig->getSystemName();
        $systemAddress = $binkpConfig->getSystemAddress();
    } catch (\Exception $e) {
        $systemName = 'BinktermPHP System';
        $systemAddress = 'Not configured';
    }
    
    $templateVars = [
        'type' => $type,
        'current_user' => $user,
        'user_name' => $user['real_name'] ?: $user['username'],
        'system_name_display' => $systemName,
        'system_address_display' => $systemAddress
    ];
    
    if ($replyId) {
        $handler = new MessageHandler();
        $originalMessage = $handler->getMessage($replyId, $type);
        
        if ($originalMessage) {
            if ($type === 'netmail') {
                $templateVars['reply_to_id'] = $replyId;
                $templateVars['reply_to_address'] = $originalMessage['from_address'];
                $templateVars['reply_to_name'] = $originalMessage['from_name'];
                $templateVars['reply_subject'] = 'Re: ' . ltrim($originalMessage['subject'] ?? '', 'Re: ');
                $templateVars['reply_text'] = "\n\n--- Original Message ---\n" . 
                    "From: {$originalMessage['from_name']} <{$originalMessage['from_address']}>\n" .
                    "Date: {$originalMessage['date_written']}\n" .
                    "Subject: {$originalMessage['subject']}\n\n" .
                    "> " . str_replace("\n", "\n> ", $originalMessage['message_text']);
            } else {
                $templateVars['reply_to_id'] = $replyId;
                $templateVars['reply_to_name'] = $originalMessage['from_name'];
                $templateVars['reply_subject'] = 'Re: ' . ltrim($originalMessage['subject'] ?? '', 'Re: ');
                $echoarea = $originalMessage['echoarea']; // Use original echoarea for reply
            }
        }
    }
    
    if ($echoarea) {
        $templateVars['echoarea'] = $echoarea;
    }
    
    // Handle new message parameters (from nodelist)
    if ($toAddress && $type === 'netmail' && !$replyId) {
        $templateVars['reply_to_address'] = $toAddress;
        if ($toName) {
            $templateVars['reply_to_name'] = $toName;
        }
    }
    
    // Ensure reply_to_name has a safe default value and add a processed version
    if (!isset($templateVars['reply_to_name']) || $templateVars['reply_to_name'] === '') {
        $templateVars['reply_to_name'] = ($type === 'echomail') ? 'All' : '';
    }
    
    // Add a safe processed version for template display
    $templateVars['to_name_value'] = $templateVars['reply_to_name'] ?: (($type === 'echomail') ? 'All' : '');
    
    $template = new Template();
    $template->renderResponse('compose.twig', $templateVars);
});

// Helper function to check admin access for BinkP functionality
function requireBinkpAdmin() {
    $auth = new Auth();
    $user = $auth->requireAuth();
    
    if (!$user['is_admin']) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Admin access required for BinkP functionality']);
        exit;
    }
    
    return $user;
}

// API routes
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
    
    SimpleRouter::post('/register', function() {
        header('Content-Type: application/json');
        
        // Get form data (not JSON for form submission)
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $email = $_POST['email'] ?? '';
        $realName = $_POST['real_name'] ?? '';
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
            
            // Check if username already exists in users or pending_users
            $checkStmt = $db->prepare("
                SELECT 1 FROM users WHERE username = ? 
                UNION 
                SELECT 1 FROM pending_users WHERE username = ? AND status = 'pending'
            ");
            $checkStmt->execute([$username, $username]);
            
            if ($checkStmt->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'Username already exists or registration is pending']);
                return;
            }
            
            // Hash password
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            
            // Get client info
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            // Insert pending user
            $insertStmt = $db->prepare("
                INSERT INTO pending_users (username, password_hash, email, real_name, reason, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $insertStmt->execute([
                $username,
                $passwordHash,
                $email ?: null,
                $realName,
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
    
    SimpleRouter::get('/dashboard/stats', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();
        
        header('Content-Type: application/json');
        
        $db = Database::getInstance()->getPdo();
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        
        // Unread netmail using message_read_status table
        $unreadStmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM netmail n
            LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
            WHERE n.user_id = ? AND mrs.read_at IS NULL
        ");
        $unreadStmt->execute([$userId, $userId]);
        $unreadNetmail = $unreadStmt->fetch()['count'] ?? 0;
        
        $newEchomail = $db->query("SELECT COUNT(*) as count FROM echomail WHERE date_received > datetime('now', '-1 day')")->fetch()['count'] ?? 0;
        
        echo json_encode([
            'unread_netmail' => $unreadNetmail,
            'new_echomail' => $newEchomail
        ]);
    });
    
    SimpleRouter::get('/messages/recent', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();
        
        header('Content-Type: application/json');
        
        $db = Database::getInstance()->getPdo();
        
        $stmt = $db->prepare("
            SELECT 'netmail' as type, from_name, subject, date_written, NULL as echoarea, NULL as echoarea_color
            FROM netmail 
            WHERE user_id = ? 
            UNION ALL
            SELECT 'echomail' as type, from_name, subject, date_written, e.tag as echoarea, e.color as echoarea_color
            FROM echomail em
            JOIN echoareas e ON em.echoarea_id = e.id
            ORDER BY date_written DESC
            LIMIT 10
        ");
        $stmt->execute([$user['user_id']]);
        $messages = $stmt->fetchAll();
        
        echo json_encode(['messages' => $messages]);
    });
    
    SimpleRouter::get('/echoareas', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();
        
        header('Content-Type: application/json');
        
        $filter = $_GET['filter'] ?? 'active';
        
        $db = Database::getInstance()->getPdo();
        
        $sql = "SELECT id, tag, description, moderator, uplink_address, color, is_active, message_count, created_at FROM echoareas";
        $params = [];
        
        if ($filter === 'active') {
            $sql .= " WHERE is_active = 1";
        } elseif ($filter === 'inactive') {
            $sql .= " WHERE is_active = 0";
        }
        // 'all' filter shows everything
        
        $sql .= " ORDER BY tag";
        
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
                INSERT INTO echoareas (tag, description, moderator, uplink_address, color, is_active) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([$tag, $description, $moderator, $uplinkAddress, $color, $isActive ? 1 : 0]);
            
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
                SET tag = ?, description = ?, moderator = ?, uplink_address = ?, color = ?, is_active = ? 
                WHERE id = ?
            ");
            
            $result = $stmt->execute([$tag, $description, $moderator, $uplinkAddress, $color, $isActive ? 1 : 0, $id]);
            
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
        
        $activeCount = $db->query("SELECT COUNT(*) as count FROM echoareas WHERE is_active = 1")->fetch()['count'];
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
        $result = $handler->getNetmail($user['user_id'], $page, null, $filter);
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
        $unreadStmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM netmail n
            LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
            WHERE n.user_id = ? AND mrs.read_at IS NULL
        ");
        $unreadStmt->execute([$userId, $userId]);
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
        $result = $handler->getEchomail(null, $page, null, $userId, $filter);
        echo json_encode($result);
    });
    
    // Echomail statistics endpoints - must come before parameterized routes
    SimpleRouter::get('/messages/echomail/stats', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();
        
        header('Content-Type: application/json');
        
        $db = Database::getInstance()->getPdo();
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        
        // Global echomail statistics
        $totalStmt = $db->query("SELECT COUNT(*) as count FROM echomail");
        $total = $totalStmt->fetch()['count'];
        
        $recentStmt = $db->query("SELECT COUNT(*) as count FROM echomail WHERE date_received > datetime('now', '-1 day')");
        $recent = $recentStmt->fetch()['count'];
        
        $areasStmt = $db->query("SELECT COUNT(*) as count FROM echoareas WHERE is_active = 1");
        $areas = $areasStmt->fetch()['count'];
        
        // Unread echomail count for this user
        $unreadCount = 0;
        if ($userId) {
            $unreadStmt = $db->prepare("
                SELECT COUNT(*) as count FROM echomail em
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                WHERE mrs.read_at IS NULL
            ");
            $unreadStmt->execute([$userId]);
            $unreadCount = $unreadStmt->fetch()['count'];
        }
        
        echo json_encode([
            'total' => $total,
            'recent' => $recent,
            'areas' => $areas,
            'unread' => $unreadCount
        ]);
    });
    
    SimpleRouter::get('/messages/echomail/stats/{echoarea}', function($echoarea) {
        $auth = new Auth();
        $user = $auth->requireAuth();
        
        header('Content-Type: application/json');
        
        // URL decode the echoarea parameter to handle dots and special characters
        $echoarea = urldecode($echoarea);
        
        $db = Database::getInstance()->getPdo();
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        
        // Statistics for specific echoarea
        $stmt = $db->prepare("
            SELECT COUNT(*) as total, 
                   COUNT(CASE WHEN date_received > datetime('now', '-1 day') THEN 1 END) as recent
            FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            WHERE ea.tag = ?
        ");
        $stmt->execute([$echoarea]);
        $stats = $stmt->fetch();
        
        // Unread count for this echoarea and user
        $unreadCount = 0;
        if ($userId) {
            $unreadStmt = $db->prepare("
                SELECT COUNT(*) as count FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                WHERE ea.tag = ? AND mrs.read_at IS NULL
            ");
            $unreadStmt->execute([$userId, $echoarea]);
            $unreadCount = $unreadStmt->fetch()['count'];
        }
        
        echo json_encode([
            'echoarea' => $echoarea,
            'total' => $stats['total'],
            'recent' => $stats['recent'],
            'unread' => $unreadCount
        ]);
    })->where(['echoarea' => '[A-Za-z0-9._-]+']);
    
    // Route for getting specific echomail message by ID only (when echoarea not known)
    SimpleRouter::get('/messages/echomail/message/{id}', function($id) {
        $auth = new Auth();
        $user = $auth->requireAuth();
        
        header('Content-Type: application/json');
        $handler = new MessageHandler();
        $message = $handler->getMessage($id, 'echomail');
        
        if ($message) {
            echo json_encode($message);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Message not found']);
        }
    })->where(['id' => '[0-9]+']);
    
    SimpleRouter::get('/messages/echomail/{echoarea}', function($echoarea) {
        $auth = new Auth();
        $user = $auth->requireAuth();
        
        header('Content-Type: application/json');
        
        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        
        // URL decode the echoarea parameter to handle dots and special characters
        $echoarea = urldecode($echoarea);
        
        $handler = new MessageHandler();
        $page = intval($_GET['page'] ?? 1);
        $filter = $_GET['filter'] ?? 'all';
        $result = $handler->getEchomail($echoarea, $page, null, $userId, $filter);
        echo json_encode($result);
    })->where(['echoarea' => '[A-Za-z0-9._-]+']);
    
    SimpleRouter::get('/messages/echomail/{echoarea}/{id}', function($echoarea, $id) {
        $auth = new Auth();
        $user = $auth->requireAuth();
        header('Content-Type: application/json');
        
        // URL decode the echoarea parameter to handle dots and special characters
        $echoarea = urldecode($echoarea);
        
        $handler = new MessageHandler();
        $message = $handler->getMessage($id, 'echomail');
        
        if ($message) {
            echo json_encode($message);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Message not found']);
        }
    })->where(['echoarea' => '[A-Za-z0-9._-]+', 'id' => '[0-9]+']);
    
    SimpleRouter::post('/messages/send', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();
        
        header('Content-Type: application/json');
        
        $input = json_decode(file_get_contents('php://input'), true);
        $type = $input['type'] ?? '';
        
        $handler = new MessageHandler();
        
        try {
            if ($type === 'netmail') {
                $result = $handler->sendNetmail(
                    $user['user_id'],
                    $input['to_address'],
                    $input['to_name'],
                    $input['subject'],
                    $input['message_text']
                );
            } elseif ($type === 'echomail') {
                $result = $handler->postEchomail(
                    $user['user_id'],
                    $input['echoarea'],
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
        
        $messages = $handler->searchMessages($query, $type, $echoarea);
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
                INSERT OR REPLACE INTO message_read_status (user_id, message_id, message_type, read_at)
                VALUES (?, ?, ?, datetime('now'))
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
            $currentPassword = $input['current_password'] ?? '';
            $newPassword = $input['new_password'] ?? '';
            
            // Update basic profile information
            $stmt = $db->prepare("UPDATE users SET real_name = ?, email = ? WHERE id = ?");
            $stmt->execute([$realName, $email, $user['user_id']]);
            
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
    
    SimpleRouter::get('/user/settings', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();
        
        header('Content-Type: application/json');
        
        $db = Database::getInstance()->getPdo();
        
        // Get user settings (we'll store them in user_settings table)
        // Handle both 'user_id' and 'id' field names for compatibility
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if (!$userId) {
            http_response_code(500);
            echo json_encode(['error' => 'User ID not found in session']);
            return;
        }
        
        $stmt = $db->prepare("SELECT * FROM user_settings WHERE user_id = ?");
        $stmt->execute([$userId]);
        $settings = $stmt->fetch();
        
        // Default settings if none exist
        if (!$settings) {
            $defaultSettings = [
                'messages_per_page' => 25,
                'timezone' => 'America/Los_Angeles',
                'theme' => 'light',
                'show_origin' => true,
                'show_tearline' => true,
                'auto_refresh' => false
            ];
            echo json_encode($defaultSettings);
        } else {
            echo json_encode([
                'messages_per_page' => (int)$settings['messages_per_page'],
                'timezone' => $settings['timezone'],
                'theme' => $settings['theme'],
                'show_origin' => (bool)$settings['show_origin'],
                'show_tearline' => (bool)$settings['show_tearline'],
                'auto_refresh' => (bool)$settings['auto_refresh']
            ]);
        }
    });
    
    SimpleRouter::post('/user/settings', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();
        
        header('Content-Type: application/json');
        
        try {
            $input = $_POST;
            
            $messagesPerPage = (int)($input['messages_per_page'] ?? 25);
            $timezone = $input['timezone'] ?? 'America/Los_Angeles';
            $theme = $input['theme'] ?? 'light';
            $showOrigin = !empty($input['show_origin']) ? 1 : 0;
            $showTearline = !empty($input['show_tearline']) ? 1 : 0;
            $autoRefresh = !empty($input['auto_refresh']) ? 1 : 0;
            
            $db = Database::getInstance()->getPdo();
            
            // Handle both 'user_id' and 'id' field names for compatibility
            $userId = $user['user_id'] ?? $user['id'] ?? null;
            if (!$userId) {
                http_response_code(500);
                echo json_encode(['error' => 'User ID not found in session']);
                return;
            }
            
            // Insert or update settings
            $stmt = $db->prepare("
                INSERT OR REPLACE INTO user_settings 
                (user_id, messages_per_page, timezone, theme, show_origin, show_tearline, auto_refresh)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $userId,
                $messagesPerPage,
                $timezone,
                $theme,
                $showOrigin,
                $showTearline,
                $autoRefresh
            ]);
            
            if ($result) {
                echo json_encode(['success' => true]);
            } else {
                throw new \Exception('Failed to save settings');
            }
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
            WHERE user_id = ? AND expires_at > datetime('now')
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
    
    SimpleRouter::post('/binkp/poll', function() {
        $auth = new Auth();
        $user = $auth->requireAuth();
        
        // Check if user is admin
        if (!$user['is_admin']) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Admin access required']);
            return;
        }
        
        header('Content-Type: application/json');
        
        try {
            // Trigger a poll of all uplinks
            $controller = new \BinktermPHP\Binkp\Web\BinkpController();
            $result = $controller->pollAllUplinks();
            
            echo json_encode(['success' => true, 'message' => 'Poll initiated']);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
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
            
            if (empty($address)) {
                throw new \Exception('Address is required');
            }
            
            $controller = new \BinktermPHP\Binkp\Web\BinkpController();
            $result = $controller->pollUplink($address);
            
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode($result);
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
            $controller = new \BinktermPHP\Binkp\Web\BinkpController();
            $result = $controller->pollAllUplinks();
            
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode($result);
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
            // Capture any PHP warnings/errors that might corrupt JSON output
            set_error_handler(function($severity, $message, $file, $line) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });
            
            $controller = new \BinktermPHP\Binkp\Web\BinkpController();
            $result = $controller->processInbound();
            
            restore_error_handler();
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode($result);
        } catch (\Exception $e) {
            restore_error_handler();
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
            $controller = new \BinktermPHP\Binkp\Web\BinkpController();
            $result = $controller->processOutbound();
            
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode($result);
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
    
    SimpleRouter::get('/binkp/config', function() {
        ob_start();
        
        $auth = new Auth();
        $user = $auth->requireAuth();
        requireBinkpAdmin($user);
        
        try {
            $controller = new \BinktermPHP\Binkp\Web\BinkpController();
            $config = $controller->getConfig();
            
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode($config);
        } catch (\Exception $e) {
            ob_clean();
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
        }
    });
    
    SimpleRouter::put('/binkp/config/{section}', function($section) {
        $auth = new Auth();
        $user = $auth->requireAuth();
        requireBinkpAdmin($user);
        
        header('Content-Type: application/json');
        
        $input = json_decode(file_get_contents('php://input'), true);
        $controller = new \BinktermPHP\Binkp\Web\BinkpController();
        echo json_encode($controller->updateConfig($section, $input));
    });
    
    // Test endpoint to verify delete endpoint is accessible
    SimpleRouter::get('/messages/echomail/delete-test', function() {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Delete endpoint is accessible']);
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
            $newUserId = $handler->approveUserRegistration($id, $user['id'], $notes);
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
            $handler->rejectUserRegistration($id, $user['id'], $notes);
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
            $db = Database::getInstance()->getPdo();
            $stmt = $db->query("
                SELECT id, username, real_name, email, created_at, last_login, is_active, is_admin
                FROM users 
                ORDER BY created_at DESC
            ");
            $users = $stmt->fetchAll();
            echo json_encode(['success' => true, 'users' => $users]);
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
});

// Admin routes
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

// Nodelist routes
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
    });
    
    SimpleRouter::get('/node', function() {
        $controller = new BinktermPHP\Web\NodelistController();
        $controller->api('node');
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

// Start router
SimpleRouter::start();