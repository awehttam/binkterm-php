<?php

namespace BinktermPHP;

class MessageHandler
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    public function getNetmail($userId, $page = 1, $limit = null, $filter = 'all', $threaded = false)
    {
        $user = $this->getUserById($userId);
        if (!$user) {
            return ['messages' => [], 'pagination' => []];
        }

        // Get user's messages_per_page setting if limit not specified
        if ($limit === null) {
            $settings = $this->getUserSettings($userId);
            $limit = $settings['messages_per_page'] ?? 25;
        }

        // If threaded view is requested, use the threading method
        if ($threaded) {
            return $this->getThreadedNetmail($userId, $page, $limit, $filter);
        }

        // Get system's FidoNet address for sent message filtering
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $systemAddress = $binkpConfig->getSystemAddress();
        } catch (\Exception $e) {
            $systemAddress = null;
        }

        $offset = ($page - 1) * $limit;
        
        // Build the WHERE clause based on filter
        // Show messages where user is sender OR recipient
        $whereClause = "WHERE (n.user_id = ? OR LOWER(n.to_name) = LOWER(?) OR LOWER(n.to_name) = LOWER(?))";
        $params = [$userId, $user['username'], $user['real_name']];
        
        if ($filter === 'unread') {
            $whereClause .= " AND mrs.read_at IS NULL";
        } elseif ($filter === 'sent' && $systemAddress) {
            // Show only messages sent by this user
            $whereClause = "WHERE n.from_address = ? AND n.user_id = ?";
            $params = [$systemAddress, $userId];
        } elseif ($filter === 'received') {
            // Show only messages received by this user (where they are the recipient)
            $whereClause = "WHERE (LOWER(n.to_name) = LOWER(?) OR LOWER(n.to_name) = LOWER(?)) AND n.user_id != ?";
            $params = [$user['username'], $user['real_name'], $userId];
        }
        
        $stmt = $this->db->prepare("
            SELECT n.*, 
                   CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read
            FROM netmail n
            LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
            $whereClause
            ORDER BY CASE 
                WHEN n.date_received > NOW() THEN 0 
                ELSE 1 
            END, n.date_received DESC 
            LIMIT ? OFFSET ?
        ");
        
        // Insert userId at the beginning for the LEFT JOIN, then add existing params
        $allParams = [$userId];
        foreach ($params as $param) {
            $allParams[] = $param;
        }
        $allParams[] = $limit;
        $allParams[] = $offset;
        
        $stmt->execute($allParams);
        $messages = $stmt->fetchAll();

        // Get total count with same filter - need to include the LEFT JOIN for unread filter
        $countAllParams = [$userId];
        foreach ($params as $param) {
            $countAllParams[] = $param;
        }
        
        $countStmt = $this->db->prepare("
            SELECT COUNT(*) as total 
            FROM netmail n
            LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
            $whereClause
        ");
        $countStmt->execute($countAllParams);
        $total = $countStmt->fetch()['total'];

        // Clean message data for proper JSON encoding and add REPLYTO parsing
        $cleanMessages = [];
        foreach ($messages as $message) {
            $cleanMessage = $this->cleanMessageForJson($message);
            
            // Parse REPLYTO kludge - check kludge_lines first, then message_text for backward compatibility
            $replyToData = null;
            
            // For echomail, check kludge_lines first
            if (isset($message['kludge_lines']) && !empty($message['kludge_lines'])) {
                $replyToData = $this->parseEchomailReplyToKludge($message['kludge_lines']);
            }
            
            // For netmail or if no kludge_lines found, check message array (handles both kludge_lines and message_text)
            if (!$replyToData) {
                $replyToData = $this->parseReplyToKludge($message);
            }
            
            if ($replyToData && isset($replyToData['address'])) {
                $cleanMessage['replyto_address'] = $replyToData['address'];
                $cleanMessage['replyto_name'] = $replyToData['name'] ?? null;
            }
            
            $cleanMessages[] = $cleanMessage;
        }

        return [
            'messages' => $cleanMessages,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ];
    }

    public function getEchomail($echoareaTag = null, $page = 1, $limit = null, $userId = null, $filter = 'all', $threaded = false, $checkSubscriptions = true)
    {
        // Check subscription access if user is specified and subscription checking is enabled
        if ($userId && $checkSubscriptions && $echoareaTag) {
            $subscriptionManager = new EchoareaSubscriptionManager();
            
            // Get echoarea ID from tag
            $stmt = $this->db->prepare("SELECT id FROM echoareas WHERE tag = ? AND is_active = TRUE");
            $stmt->execute([$echoareaTag]);
            $echoarea = $stmt->fetch();
            
            if ($echoarea && !$subscriptionManager->isUserSubscribed($userId, $echoarea['id'])) {
                // User is not subscribed to this echoarea
                return [
                    'messages' => [],
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => 0,
                        'total_messages' => 0,
                        'has_next' => false,
                        'has_prev' => false
                    ],
                    'error' => 'You are not subscribed to this echoarea.'
                ];
            }
        }
        
        // Get user's messages_per_page setting if limit not specified
        if ($limit === null && $userId) {
            $settings = $this->getUserSettings($userId);
            $limit = $settings['messages_per_page'] ?? 25;
        } elseif ($limit === null) {
            $limit = 25; // Default fallback if no user ID
        }

        // If threaded view is requested, use the threading method
        if ($threaded) {
            return $this->getThreadedEchomail($echoareaTag, $page, $limit, $userId, $filter);
        }

        $offset = ($page - 1) * $limit;
        
        // Build the WHERE clause based on filter
        $filterClause = "";
        $filterParams = [];
        
        if ($filter === 'unread' && $userId) {
            $filterClause = " AND mrs.read_at IS NULL";
        } elseif ($filter === 'read' && $userId) {
            $filterClause = " AND mrs.read_at IS NOT NULL";
        } elseif ($filter === 'tome' && $userId) {
            $user = $this->getUserById($userId);
            if ($user) {
                $filterClause = " AND (LOWER(em.to_name) = LOWER(?) OR LOWER(em.to_name) = LOWER(?))";
                $filterParams[] = $user['username'];
                $filterParams[] = $user['real_name'];
            }
        } elseif ($filter === 'saved' && $userId) {
            $filterClause = " AND sav.id IS NOT NULL";
        }
        
        if ($echoareaTag) {
            $stmt = $this->db->prepare("
                SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE ea.tag = ?{$filterClause}
                ORDER BY CASE 
                    WHEN em.date_received > NOW() THEN 0 
                    ELSE 1 
                END, em.date_received DESC 
                LIMIT ? OFFSET ?
            ");
            $params = [$userId, $userId, $userId, $echoareaTag];
            foreach ($filterParams as $param) {
                $params[] = $param;
            }
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $messages = $stmt->fetchAll();

            $countStmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE ea.tag = ?{$filterClause}
            ");
            $countParams = [$userId, $userId, $echoareaTag];
            foreach ($filterParams as $param) {
                $countParams[] = $param;
            }
            $countStmt->execute($countParams);
        } else {
            $stmt = $this->db->prepare("
                SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE 1=1{$filterClause}
                ORDER BY CASE 
                    WHEN em.date_received > NOW() THEN 0 
                    ELSE 1 
                END, em.date_received DESC 
                LIMIT ? OFFSET ?
            ");
            $params = [$userId, $userId, $userId];
            foreach ($filterParams as $param) {
                $params[] = $param;
            }
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $messages = $stmt->fetchAll();

            $countStmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM echomail em
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE 1=1{$filterClause}
            ");
            $countParams = [$userId, $userId];
            foreach ($filterParams as $param) {
                $countParams[] = $param;
            }
            $countStmt->execute($countParams);
        }

        $total = $countStmt->fetch()['total'];
        
        // Get unread count for the current user
        $unreadCount = 0;
        if ($userId) {
            if ($echoareaTag) {
                $unreadCountStmt = $this->db->prepare("
                    SELECT COUNT(*) as count FROM echomail em
                    JOIN echoareas ea ON em.echoarea_id = ea.id
                    LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                    WHERE ea.tag = ? AND mrs.read_at IS NULL
                ");
                $unreadCountStmt->execute([$userId, $echoareaTag]);
            } else {
                $unreadCountStmt = $this->db->prepare("
                    SELECT COUNT(*) as count FROM echomail em
                    LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                    WHERE mrs.read_at IS NULL
                ");
                $unreadCountStmt->execute([$userId]);
            }
            $unreadCount = $unreadCountStmt->fetch()['count'];
        }

        // Clean message data for proper JSON encoding and add REPLYTO parsing
        $cleanMessages = [];
        foreach ($messages as $message) {
            $cleanMessage = $this->cleanMessageForJson($message);
            
            // Parse REPLYTO kludge - check kludge_lines first, then message_text for backward compatibility
            $replyToData = null;
            
            // For echomail, check kludge_lines first
            if (isset($message['kludge_lines']) && !empty($message['kludge_lines'])) {
                $replyToData = $this->parseEchomailReplyToKludge($message['kludge_lines']);
            }
            
            // For netmail or if no kludge_lines found, check message array (handles both kludge_lines and message_text)
            if (!$replyToData) {
                $replyToData = $this->parseReplyToKludge($message);
            }
            
            if ($replyToData && isset($replyToData['address'])) {
                $cleanMessage['replyto_address'] = $replyToData['address'];
                $cleanMessage['replyto_name'] = $replyToData['name'] ?? null;
            }
            
            $cleanMessages[] = $cleanMessage;
        }

        return [
            'messages' => $cleanMessages,
            'unreadCount' => $unreadCount,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ];
    }

    /**
     * Get echomail messages from only subscribed echoareas
     */
    public function getEchomailFromSubscribedAreas($userId, $page = 1, $limit = null, $filter = 'all', $threaded = false)
    {
        if (!$userId) {
            return ['messages' => [], 'pagination' => ['page' => 1, 'limit' => 25, 'total' => 0, 'pages' => 0]];
        }

        $subscriptionManager = new EchoareaSubscriptionManager();
        $subscribedEchoareas = $subscriptionManager->getUserSubscribedEchoareas($userId);
        
        if (empty($subscribedEchoareas)) {
            return [
                'messages' => [],
                'pagination' => ['page' => 1, 'limit' => 25, 'total' => 0, 'pages' => 0],
                'info' => 'You are not subscribed to any echoareas. Visit /subscriptions to subscribe to echoareas.'
            ];
        }

        // Get user's messages_per_page setting if limit not specified
        if ($limit === null) {
            $settings = $this->getUserSettings($userId);
            $limit = $settings['messages_per_page'] ?? 25;
        }

        // If threaded view is requested, use the threading method
        if ($threaded) {
            return $this->getThreadedEchomailFromSubscribedAreas($userId, $page, $limit, $filter, $subscribedEchoareas);
        }

        $offset = ($page - 1) * $limit;
        
        // Build the WHERE clause based on filter
        $filterClause = "";
        $filterParams = [];
        
        if ($filter === 'unread') {
            $filterClause = " AND mrs.read_at IS NULL";
        } elseif ($filter === 'read') {
            $filterClause = " AND mrs.read_at IS NOT NULL";
        } elseif ($filter === 'tome') {
            $user = $this->getUserById($userId);
            if ($user) {
                $filterClause = " AND (LOWER(em.to_name) = LOWER(?) OR LOWER(em.to_name) = LOWER(?))";
                $filterParams[] = $user['username'];
                $filterParams[] = $user['real_name'];
            }
        } elseif ($filter === 'saved') {
            $filterClause = " AND sav.id IS NOT NULL";
        }
        
        // Create IN clause for subscribed echoareas
        $echoareaIds = array_column($subscribedEchoareas, 'id');
        $placeholders = str_repeat('?,', count($echoareaIds) - 1) . '?';
        
        $stmt = $this->db->prepare("
            SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color,
                   CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                   CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                   CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
            FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
            LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
            LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
            WHERE ea.id IN ($placeholders) AND ea.is_active = TRUE{$filterClause}
            ORDER BY em.date_received DESC 
            LIMIT ? OFFSET ?
        ");
        
        $params = [$userId, $userId, $userId];
        $params = array_merge($params, $echoareaIds);
        foreach ($filterParams as $param) {
            $params[] = $param;
        }
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt->execute($params);
        $messages = $stmt->fetchAll();

        // Get total count for pagination
        $countStmt = $this->db->prepare("
            SELECT COUNT(*) as total FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
            LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
            WHERE ea.id IN ($placeholders) AND ea.is_active = TRUE{$filterClause}
        ");
        
        $countParams = [$userId, $userId];
        $countParams = array_merge($countParams, $echoareaIds);
        foreach ($filterParams as $param) {
            $countParams[] = $param;
        }
        
        $countStmt->execute($countParams);
        $total = $countStmt->fetch()['total'];

        // Get unread count
        $unreadCount = 0;
        if ($userId) {
            $unreadCountStmt = $this->db->prepare("
                SELECT COUNT(*) as count FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                WHERE ea.id IN ($placeholders) AND ea.is_active = TRUE AND mrs.read_at IS NULL
            ");
            $unreadParams = [$userId];
            $unreadParams = array_merge($unreadParams, $echoareaIds);
            $unreadCountStmt->execute($unreadParams);
            $unreadCount = $unreadCountStmt->fetch()['count'];
        }

        // Clean message data for proper JSON encoding
        $cleanMessages = [];
        foreach ($messages as $message) {
            $cleanMessage = $this->cleanMessageForJson($message);
            
            // Parse REPLYTO kludge - check kludge_lines first, then message_text for backward compatibility
            $replyToData = null;
            
            // For echomail, check kludge_lines first
            if (isset($message['kludge_lines']) && !empty($message['kludge_lines'])) {
                $replyToData = $this->parseEchomailReplyToKludge($message['kludge_lines']);
            }
            
            // For netmail or if no kludge_lines found, check message array (handles both kludge_lines and message_text)
            if (!$replyToData) {
                $replyToData = $this->parseReplyToKludge($message);
            }
            
            if ($replyToData && isset($replyToData['address'])) {
                $cleanMessage['replyto_address'] = $replyToData['address'];
                $cleanMessage['replyto_name'] = $replyToData['name'] ?? null;
            }
            
            $cleanMessages[] = $cleanMessage;
        }

        return [
            'messages' => $cleanMessages,
            'unreadCount' => $unreadCount,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ];
    }

    public function getMessage($messageId, $type, $userId = null)
    {
        if ($type === 'netmail') {
            // For netmail, user can access messages they sent OR received
            $user = $this->getUserById($userId);
            if (!$user) {
                return null;
            }
            $stmt = $this->db->prepare("
                SELECT * FROM netmail 
                WHERE id = ? AND (user_id = ? OR LOWER(to_name) = LOWER(?) OR LOWER(to_name) = LOWER(?))
            ");
            $stmt->execute([$messageId, $userId, $user['username'], $user['real_name']]);
        } else {
            // Echomail is public, so no user restriction needed
            $stmt = $this->db->prepare("
                SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                WHERE em.id = ?
            ");
            $stmt->execute([$userId, $userId, $messageId]);
        }
        
        $message = $stmt->fetch();

        if ($message) {
            if ($type === 'netmail') {
                $this->markNetmailAsRead($messageId, $userId);
            } elseif ($type === 'echomail') {
                $this->markEchomailAsRead($messageId, $userId);
            }
            
            // Clean message for JSON encoding
            $message = $this->cleanMessageForJson($message);
        }

        return $message;
    }

    public function sendNetmail($fromUserId, $toAddress, $toName, $subject, $messageText, $fromName = null, $replyToId = null)
    {
        $user = $this->getUserById($fromUserId);
        if (!$user) {
            throw new \Exception('User not found');
        }

        // Special case: if sending to "sysop", route to local sysop user
        if (!empty($toName) && strtolower($toName) === 'sysop') {
            return $this->sendLocalSysopMessage($fromUserId, $subject, $messageText, $fromName, $replyToId);
        }

        // Use provided fromName or fall back to user's real name
        $senderName = $fromName ?: ($user['real_name'] ?: $user['username']);
        
        // Get system's FidoNet address since users don't have individual addresses
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $systemAddress = $binkpConfig->getSystemAddress();
        } catch (\Exception $e) {
            throw new \Exception('System FidoNet address not configured');
        }

        // Generate MSGID for storage (address + hash format)
        $msgIdHash = $this->generateMessageId($senderName, $toName, $subject, $systemAddress);
        $msgId = $systemAddress . ' ' . $msgIdHash;

        // Generate kludges for this netmail
        $kludgeLines = $this->generateNetmailKludges($systemAddress, $toAddress, $senderName, $toName, $subject, $replyToId);
        
        $stmt = $this->db->prepare("
            INSERT INTO netmail (user_id, from_address, to_address, from_name, to_name, subject, message_text, date_written, is_sent, reply_to_id, message_id, kludge_lines)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), FALSE, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $fromUserId,
            $systemAddress,
            $toAddress,
            $senderName,
            $toName,
            $subject,
            $messageText,
            $replyToId,
            $msgId,
            $kludgeLines
        ]);

        if ($result) {
            $messageId = $this->db->lastInsertId();
            $this->spoolOutboundNetmail($messageId);
        }

        return $result;
    }

    private function sendLocalSysopMessage($fromUserId, $subject, $messageText, $fromName = null, $replyToId = null)
    {
        // Get sysop name from config
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $sysopName = $binkpConfig->getSystemSysop();
            $systemAddress = $binkpConfig->getSystemAddress();
        } catch (\Exception $e) {
            throw new \Exception('System configuration not available');
        }

        if (empty($sysopName)) {
            throw new \Exception('System sysop not configured');
        }

        // Find sysop user in database
        $stmt = $this->db->prepare("
            SELECT id FROM users 
            WHERE LOWER(real_name) = LOWER(?) OR LOWER(username) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$sysopName, $sysopName]);
        $sysopUser = $stmt->fetch();

        if (!$sysopUser) {
            // Fallback to admin user if sysop not found
            $stmt = $this->db->prepare("SELECT id FROM users WHERE is_admin=true ORDER BY id LIMIT 1");
            $stmt->execute();
            $sysopUser = $stmt->fetch();
            
            if (!$sysopUser) {
                throw new \Exception('Sysop user not found in database');
            }
        }

        // Get sender info
        $senderUser = $this->getUserById($fromUserId);
        $senderName = $fromName ?: ($senderUser['real_name'] ?: $senderUser['username']);

        // Generate MSGID for local message
        $msgIdHash = $this->generateMessageId($senderName, $sysopName, $subject, $systemAddress);
        $msgId = $systemAddress . ' ' . $msgIdHash;

        // Generate kludges for this local netmail
        $kludgeLines = $this->generateNetmailKludges($systemAddress, $systemAddress, $senderName, $sysopName, $subject, $replyToId);

        // Create local netmail message to sysop
        $stmt = $this->db->prepare("
            INSERT INTO netmail (user_id, from_address, to_address, from_name, to_name, subject, message_text, date_written, is_sent, reply_to_id, message_id, kludge_lines)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), TRUE, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $sysopUser['id'],
            $systemAddress,
            $systemAddress,  // Local delivery - same address
            $senderName,
            $sysopName,
            $subject,
            $messageText,
            $replyToId,
            $msgId,
            $kludgeLines
        ]);

        return $result;
    }

    public function postEchomail($fromUserId, $echoareaTag, $toName, $subject, $messageText, $replyToId = null)
    {
        $user = $this->getUserById($fromUserId);
        if (!$user) {
            throw new \Exception('User not found');
        }

        $echoarea = $this->getEchoareaByTag($echoareaTag);
        if (!$echoarea) {
            throw new \Exception('Echo area not found');
        }
        
        // Get system's FidoNet address since users don't have individual addresses
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $systemAddress = $binkpConfig->getSystemAddress();
            
            // For echomail from points, keep the FULL point address in the from_address
            // The point routing will be handled by FMPT kludge lines
        } catch (\Exception $e) {
            throw new \Exception('System FidoNet address not configured');
        }

        // Generate kludges for this echomail
        $fromName = $user['real_name'] ?: $user['username'];
        $toName = $toName ?: 'All';
        $kludgeLines = $this->generateEchomailKludges($systemAddress, $fromName, $toName, $subject, $echoareaTag, $replyToId);
        $msgId = $systemAddress . ' ' . $this->generateMessageId($fromName, $toName, $subject, $systemAddress);
        
        $stmt = $this->db->prepare("
            INSERT INTO echomail (echoarea_id, from_address, from_name, to_name, subject, message_text, date_written, reply_to_id, message_id, origin_line, kludge_lines)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $echoarea['id'],
            $systemAddress,
            $fromName,
            $toName,
            $subject,
            $messageText,
            $replyToId,
            $msgId,
            null, // origin_line (will be added when packet is created) 
            $kludgeLines  // Store generated kludges
        ]);

        if ($result) {
            $messageId = $this->db->lastInsertId();
            $this->incrementEchoareaCount($echoarea['id']);
            $this->spoolOutboundEchomail($messageId, $echoareaTag);
        }

        return $result;
    }

    public function getEchoareas($userId = null, $subscribedOnly = false)
    {
        if ($userId && $subscribedOnly) {
            // Get only echoareas the user is subscribed to
            $subscriptionManager = new EchoareaSubscriptionManager();
            return $subscriptionManager->getUserSubscribedEchoareas($userId);
        }
        
        $stmt = $this->db->query("SELECT * FROM echoareas WHERE is_active = TRUE ORDER BY tag");
        return $stmt->fetchAll();
    }

    public function searchMessages($query, $type = null, $echoarea = null, $userId = null)
    {
        $searchTerm = '%' . $query . '%';
        
        if ($type === 'netmail') {
            if ($userId === null) {
                // If no user ID provided, return empty results for privacy
                return [];
            }
            $stmt = $this->db->prepare("
                SELECT * FROM netmail 
                WHERE (subject ILIKE ? OR message_text ILIKE ? OR from_name ILIKE ?) 
                AND user_id = ?
                ORDER BY CASE 
                    WHEN date_received > NOW() THEN 0 
                    ELSE 1 
                END, date_received DESC
                LIMIT 50
            ");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $userId]);
        } else {
            $sql = "
                SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color 
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                WHERE (em.subject ILIKE ? OR em.message_text ILIKE ? OR em.from_name ILIKE ?)
            ";
            
            $params = [$searchTerm, $searchTerm, $searchTerm];
            
            if ($echoarea) {
                $sql .= " AND ea.tag = ?";
                $params[] = $echoarea;
            }
            
            $sql .= " ORDER BY CASE 
                WHEN em.date_written > NOW() THEN 0 
                ELSE 1 
            END, em.date_written DESC LIMIT 50";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }

        return $stmt->fetchAll();
    }

    private function getUserById($userId)
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }

    private function getEchoareaByTag($tag)
    {
        $stmt = $this->db->prepare("SELECT * FROM echoareas WHERE tag = ? AND is_active = TRUE");
        $stmt->execute([$tag]);
        return $stmt->fetch();
    }

    public function deleteNetmail($messageId, $userId)
    {
        $stmt = $this->db->prepare("SELECT * FROM netmail WHERE id = ?");
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();
        
        if (!$message) {
            return false;
        }
        
        $user = $this->getUserById($userId);
        if (!$user) {
            return false;
        }
        
        // Allow users to delete messages they sent OR received (same logic as getNetmail/getMessage)
        $isSender = ($message['user_id'] == $userId);
        $isRecipient = (strtolower($message['to_name']) === strtolower($user['username']) || 
                       strtolower($message['to_name']) === strtolower($user['real_name']));
        
        if (!$isSender && !$isRecipient) {
            return false;
        }
        
        $deleteStmt = $this->db->prepare("DELETE FROM netmail WHERE id = ?");
        return $deleteStmt->execute([$messageId]);
    }

    private function markNetmailAsRead($messageId, $userId = null)
    {
        // Get the current user ID if not provided
        if ($userId === null) {
            $auth = new Auth();
            $user = $auth->getCurrentUser();
            $userId = $user['user_id'] ?? $user['id'] ?? null;
        }
        
        if ($userId) {
            $stmt = $this->db->prepare("
                INSERT INTO message_read_status (user_id, message_id, message_type, read_at)
                VALUES (?, ?, 'netmail', NOW())
                ON CONFLICT (user_id, message_id, message_type) DO UPDATE SET
                    read_at = NOW()
            ");
            $stmt->execute([$userId, $messageId]);
        }
    }

    private function markEchomailAsRead($messageId, $userId = null)
    {
        // Get the current user ID if not provided
        if ($userId === null) {
            $auth = new Auth();
            $user = $auth->getCurrentUser();
            $userId = $user['user_id'] ?? $user['id'] ?? null;
        }
        
        if ($userId) {
            $stmt = $this->db->prepare("
                INSERT INTO message_read_status (user_id, message_id, message_type, read_at)
                VALUES (?, ?, 'echomail', NOW())
                ON CONFLICT (user_id, message_id, message_type) DO UPDATE SET
                    read_at = NOW()
            ");
            $stmt->execute([$userId, $messageId]);
        }
    }

    private function incrementEchoareaCount($echoareaId)
    {
        $stmt = $this->db->prepare("UPDATE echoareas SET message_count = message_count + 1 WHERE id = ?");
        $stmt->execute([$echoareaId]);
    }

    private function spoolOutboundNetmail($messageId)
    {
        $stmt = $this->db->prepare("SELECT * FROM netmail WHERE id = ?");
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();
        
        if (!$message) {
            return false;
        }

        try {
            $binkdProcessor = new BinkdProcessor();
            
            // Set netmail attributes (private flag)
            $message['attributes'] = 0x0001;
            
            // Create outbound packet for this message
            $binkdProcessor->createOutboundPacket([$message], $message['to_address']);
            
            // Mark message as sent
            $this->db->prepare("UPDATE netmail SET is_sent = TRUE WHERE id = ?")
                     ->execute([$messageId]);
            
            return true;
        } catch (\Exception $e) {
            // Log error but don't fail the message creation
            error_log("Failed to spool netmail $messageId: " . $e->getMessage());
            return false;
        }
    }

    private function spoolOutboundEchomail($messageId, $echoareaTag)
    {
        $stmt = $this->db->prepare("
            SELECT em.*, ea.tag as echoarea_tag 
            FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            WHERE em.id = ?
        ");
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();
        
        if (!$message) {
            return false;
        }

        try {
            $binkdProcessor = new BinkdProcessor();
            
            // Don't add plain text AREA: line - echomail is identified by ^AAREA: kludge line only
            
            // Debug logging
            error_log("DEBUG: Spooling echomail with AREA tag: " . $message['echoarea_tag']);
            error_log("DEBUG: Message text starts with: " . substr($message['message_text'], 0, 50));
            
            // Set echomail attributes (no private flag)
            $message['attributes'] = 0x0000;
            
            // Mark as echomail for proper packet formatting
            $message['is_echomail'] = true;
            // Keep echoarea_tag available for kludge line generation
            $message['echoarea_tag'] = $message['echoarea_tag'];
            
            // For echomail, we typically send to our uplink

            $uplinkAddress = $this->getEchoareaUplink($message['echoarea_tag']);
            
            if ($uplinkAddress) {
                $message['to_address'] = $uplinkAddress;
                error_log("DEBUG: Creating echomail packet to uplink: " . $uplinkAddress);
                $binkdProcessor->createOutboundPacket([$message], $uplinkAddress);
            } else {
                error_log("WARNING: No uplink address configured for echoarea: " . $message['echoarea_tag']);
            }
            
            return true;
        } catch (\Exception $e) {
            // Log error but don't fail the message creation
            error_log("Failed to spool echomail $messageId: " . $e->getMessage());
            return false;
        }
    }

    private function getEchoareaUplink($echoareaTag)
    {
        $stmt = $this->db->prepare("SELECT uplink_address FROM echoareas WHERE tag = ? AND is_active = TRUE");
        $stmt->execute([$echoareaTag]);
        $result = $stmt->fetch();
        
        if ($result && $result['uplink_address']) {
            return $result['uplink_address'];
        }
        
        // Fall back to default uplink from JSON config
        try {
            $config = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $defaultAddress = $config->getDefaultUplinkAddress();
            if ($defaultAddress) {
                return $defaultAddress;
            }
        } catch (\Exception $e) {
            // Log error but continue with hardcoded fallback
            error_log("Failed to get default uplink from config: " . $e->getMessage());
        }
        
        // Ultimate fallback if config fails
        return '1:123/1';
    }

    public function deleteEchomail($messageIds, $userId)
    {
        //error_log("MessageHandler::deleteEchomail called with messageIds: " . print_r($messageIds, true) . ", userId: $userId");
        
        // Validate input
        if (empty($messageIds) || !is_array($messageIds)) {
            error_log("MessageHandler::deleteEchomail - Invalid input");
            return ['success' => false, 'error' => 'No messages selected'];
        }

        // Get user info for permission checking
        $user = $this->getUserById($userId);
        //error_log("MessageHandler::deleteEchomail - Retrieved user: " . print_r($user, true));
        if (!$user) {
            error_log("MessageHandler::deleteEchomail - User not found for ID: $userId");
            return ['success' => false, 'error' => 'User not found'];
        }

        $deletedCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($messageIds as $messageId) {
            try {
                // Get message details
                $stmt = $this->db->prepare("SELECT * FROM echomail WHERE id = ?");
                $stmt->execute([$messageId]);
                $message = $stmt->fetch();

                if (!$message) {
                    $errors[] = "Message ID $messageId not found";
                    $failedCount++;
                    continue;
                }

                // Check permissions - only allow users to delete their own messages or admins to delete any
                $isOwner = ($message['from_name'] === $user['real_name'] || $message['from_name'] === $user['username']);
                $isAdmin = $this->isAdmin($user);
                
                error_log("Permission check for message $messageId: isOwner=$isOwner, isAdmin=$isAdmin");
                error_log("Message from_name: '{$message['from_name']}', User real_name: '{$user['real_name']}', User username: '{$user['username']}'");
                
                if (!$isOwner && !$isAdmin) {
                    $errors[] = "Not authorized to delete message ID $messageId";
                    $failedCount++;
                    continue;
                }

                // Delete the message
                $deleteStmt = $this->db->prepare("DELETE FROM echomail WHERE id = ?");
                if ($deleteStmt->execute([$messageId])) {
                    // Update echoarea message count
                    $this->db->prepare("UPDATE echoareas SET message_count = message_count - 1 WHERE id = ? AND message_count > 0")
                             ->execute([$message['echoarea_id']]);
                    $deletedCount++;
                } else {
                    $errors[] = "Failed to delete message ID $messageId";
                    $failedCount++;
                }

            } catch (\Exception $e) {
                $errors[] = "Error deleting message ID $messageId: " . $e->getMessage();
                $failedCount++;
            }
        }

        return [
            'success' => $deletedCount > 0,
            'deleted' => $deletedCount,
            'failed' => $failedCount,
            'errors' => $errors,
            'message' => $deletedCount > 0 ? 
                "Deleted $deletedCount message(s)" . ($failedCount > 0 ? " ($failedCount failed)" : "") :
                "No messages were deleted"
        ];
    }

    private function isAdmin($user)
    {
        // Check if user is admin - adjust this logic based on your user role system
        return isset($user['is_admin']) && $user['is_admin'] == 1;
    }

    /**
     * Get user settings including messages_per_page
     */
    public function getUserSettings($userId)
    {
        if (!$userId) {
            return ['messages_per_page' => 25]; // Default fallback
        }

        $stmt = $this->db->prepare("SELECT * FROM user_settings WHERE user_id = ?");
        $stmt->execute([$userId]);
        $settings = $stmt->fetch();

        if (!$settings) {
            // Create default settings for user if they don't exist
            $insertStmt = $this->db->prepare("
                INSERT INTO user_settings (user_id, messages_per_page, threaded_view, netmail_threaded_view, default_sort, font_family, font_size) 
                VALUES (?, 25, FALSE, FALSE, 'date_desc', 'Courier New, Monaco, Consolas, monospace', 16) 
                ON CONFLICT (user_id) DO UPDATE SET
                    messages_per_page = COALESCE(user_settings.messages_per_page, 25),
                    threaded_view = COALESCE(user_settings.threaded_view, FALSE),
                    netmail_threaded_view = COALESCE(user_settings.netmail_threaded_view, FALSE),
                    default_sort = COALESCE(user_settings.default_sort, 'date_desc'),
                    font_family = COALESCE(user_settings.font_family, 'Courier New, Monaco, Consolas, monospace'),
                    font_size = COALESCE(user_settings.font_size, 16)
            ");
            $insertStmt->execute([$userId]);
            
            return [
                'messages_per_page' => 25,
                'threaded_view' => false,
                'netmail_threaded_view' => false,
                'default_sort' => 'date_desc',
                'font_family' => 'Courier New, Monaco, Consolas, monospace',
                'font_size' => 16
            ];
        }

        return $settings;
    }

    /**
     * Update user settings
     */
    public function updateUserSettings($userId, $settings)
    {
        if (!$userId || empty($settings)) {
            return false;
        }

        $allowedSettings = [
            'messages_per_page' => 'INTEGER',
            'threaded_view' => 'BOOLEAN',
            'netmail_threaded_view' => 'BOOLEAN',
            'default_sort' => 'STRING',
            'font_family' => 'STRING',
            'font_size' => 'INTEGER',
            'timezone' => 'STRING',
            'theme' => 'STRING',
            'show_origin' => 'BOOLEAN',
            'show_tearline' => 'BOOLEAN',
            'auto_refresh' => 'BOOLEAN'
        ];

        $updates = [];
        $params = [];

        foreach ($settings as $key => $value) {
            if (!isset($allowedSettings[$key])) {
                continue; // Skip unknown settings
            }

            $updates[] = "$key = ?";
            
            // Type casting for proper database storage
            switch ($allowedSettings[$key]) {
                case 'INTEGER':
                    $params[] = (int)$value;
                    break;
                case 'BOOLEAN':
                    $params[] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'TRUE' : 'FALSE';
                    break;
                default:
                    $params[] = $value;
                    break;
            }
        }

        if (empty($updates)) {
            return false;
        }

        $params[] = $userId;

        $sql = "UPDATE user_settings SET " . implode(', ', $updates) . " WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute($params);
    }

    /**
     * Clean message data to ensure proper JSON encoding
     * Fixes UTF-8 encoding issues that prevent json_encode from working
     */
    private function cleanMessageForJson($message)
    {
        if (!is_array($message)) {
            return $message;
        }

        $cleaned = [];
        foreach ($message as $key => $value) {
            if (is_string($value)) {
                // Convert to UTF-8 and remove invalid sequences
                $cleaned[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                
                // If that didn't work, try converting from CP437 (common in FidoNet)
                if (!mb_check_encoding($cleaned[$key], 'UTF-8')) {
                    $cleaned[$key] = mb_convert_encoding($value, 'UTF-8', 'CP437');
                }
                
                // If still not valid, force UTF-8 conversion with substitution
                if (!mb_check_encoding($cleaned[$key], 'UTF-8')) {
                    $cleaned[$key] = mb_convert_encoding($value, 'UTF-8', mb_detect_encoding($value, ['UTF-8', 'ISO-8859-1', 'CP437', 'ASCII'], true));
                }
                
                // As a last resort, remove invalid bytes
                if (!mb_check_encoding($cleaned[$key], 'UTF-8')) {
                    $cleaned[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                }
            } else {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
    }

    /**
     * Send netmail notification to sysop about new user registration
     */
    public function sendRegistrationNotification($pendingUserId, $username, $realName, $email, $reason, $ipAddress)
    {
        try {
            // Get sysop's address from binkd config
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $systemAddress = $binkpConfig->getSystemAddress();
            $sysopName = $binkpConfig->getSystemSysop();
            $systemName = $binkpConfig->getSystemName();
        } catch (\Exception $e) {
            // Fallback if config not available
            $systemAddress = '1:123/456';
            $sysopName = 'Sysop';
            $systemName = 'BinktermPHP System';
        }

        // Find admin user ID for the netmail
        $adminStmt = $this->db->prepare("SELECT id FROM users WHERE is_admin = TRUE ORDER BY id LIMIT 1");
        $adminStmt->execute();
        $admin = $adminStmt->fetch();
        $adminUserId = $admin ? $admin['id'] : 1;

        $subject = "New User Registration Request";
        
        $messageText = "A new user has requested access to $systemName.\n\n";
        $messageText .= "Registration Details:\n";
        $messageText .= "==================\n";
        $messageText .= "Username: $username\n";
        $messageText .= "Real Name: $realName\n";
        
        if ($email) {
            $messageText .= "Email: $email\n";
        }
        
        $messageText .= "IP Address: $ipAddress\n";
        $messageText .= "Registration Time: " . date('Y-m-d H:i:s') . "\n\n";
        
        if ($reason) {
            $messageText .= "Reason for Joining:\n";
            $messageText .= "==================\n";
            $messageText .= "$reason\n\n";
        }
        
        $messageText .= "To approve or reject this registration, please log into the\n";
        $messageText .= "web interface and visit the Admin > Pending Users section.\n\n";
        $messageText .= "Pending User ID: $pendingUserId\n\n";
        $messageText .= "This message was automatically generated by the BinktermPHP system.";

        // Insert netmail notification
        $insertStmt = $this->db->prepare("
            INSERT INTO netmail (
                user_id, from_address, to_address, from_name, to_name, 
                subject, message_text, date_written, date_received, attributes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $insertStmt->execute([
            $adminUserId,
            'System',  // from_address  
            $systemAddress,  // to_address
            'Registration System',  // from_name
            $sysopName,  // to_name
            $subject,
            $messageText,
            date('Y-m-d H:i:s'),  // date_written
            date('Y-m-d H:i:s'),  // date_received
            0  // attributes
        ]);

        return true;
    }

    /**
     * Approve a pending user registration
     */
    public function approveUserRegistration($pendingUserId, $adminUserId, $notes = '')
    {
        $this->db->beginTransaction();
        
        try {
            // Get pending user data
            $pendingStmt = $this->db->prepare("SELECT * FROM pending_users WHERE id = ? AND status = 'pending'");
            $pendingStmt->execute([$pendingUserId]);
            $pendingUser = $pendingStmt->fetch();
            
            if (!$pendingUser) {
                throw new \Exception("Pending user not found or already processed");
            }
            
            // Create actual user account
            $userStmt = $this->db->prepare("
                INSERT INTO users (username, password_hash, email, real_name, created_at, is_active)
                VALUES (?, ?, ?, ?, NOW(), TRUE)
            ");
            
            $userStmt->execute([
                $pendingUser['username'],
                $pendingUser['password_hash'],
                $pendingUser['email'],
                $pendingUser['real_name']
            ]);
            
            $newUserId = $this->db->lastInsertId();
            
            // Create default user settings
            $settingsStmt = $this->db->prepare("
                INSERT INTO user_settings (user_id, messages_per_page) 
                VALUES (?, 25)
            ");
            $settingsStmt->execute([$newUserId]);
            
            // Remove the pending user record since they're now a real user
            $deleteStmt = $this->db->prepare("DELETE FROM pending_users WHERE id = ?");
            $deleteStmt->execute([$pendingUserId]);
            
            $this->db->commit();
            
            // Send welcome netmail to new user
            $this->sendWelcomeMessage($newUserId, $pendingUser['username'], $pendingUser['real_name']);
            
            return $newUserId;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Reject a pending user registration
     */
    public function rejectUserRegistration($pendingUserId, $adminUserId, $reason = '')
    {
        $updateStmt = $this->db->prepare("
            UPDATE pending_users 
            SET status = 'rejected', reviewed_by = ?, reviewed_at = ?, admin_notes = ?
            WHERE id = ? AND status = 'pending'
        ");
        
        $result = $updateStmt->execute([
            $adminUserId,      // reviewed_by
            date('Y-m-d H:i:s'), // reviewed_at
            $reason,           // admin_notes
            $pendingUserId     // WHERE id = ?
        ]);
        
        if ($updateStmt->rowCount() === 0) {
            throw new \Exception("Pending user not found or already processed");
        }
        
        return true;
    }

    /**
     * Send welcome message to newly approved user
     */
    private function sendWelcomeMessage($userId, $username, $realName)
    {
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $systemAddress = $binkpConfig->getSystemAddress();
            $sysopName = $binkpConfig->getSystemSysop();
            $systemName = $binkpConfig->getSystemName();
        } catch (\Exception $e) {
            $systemAddress = '1:123/456';
            $sysopName = 'Sysop';
            $systemName = 'BinktermPHP System';
        }

        $subject = "Welcome to $systemName!";
        
        // Load welcome message template
        $messageText = $this->loadWelcomeTemplate($realName, $systemName, $systemAddress, $sysopName);

        // Send netmail
        $insertStmt = $this->db->prepare("
            INSERT INTO netmail (
                user_id, from_address, to_address, from_name, to_name, 
                subject, message_text, date_written, date_received, attributes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
        ");

        $insertStmt->execute([
            $userId,
            $systemAddress,  // from_address
            $systemAddress,  // to_address (user's address would be system address)
            $sysopName,      // from_name
            $realName,       // to_name
            $subject,
            $messageText,
            0                // attributes
        ]);
        
        // Also send email notification if email is available and SMTP is enabled
        try {
            // Get user's email address
            $userStmt = $this->db->prepare("SELECT email FROM users WHERE id = ?");
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch();
            
            if ($user && !empty($user['email'])) {
                $mailer = new \BinktermPHP\Mail();
                if ($mailer->isEnabled()) {
                    $emailSent = $mailer->sendWelcomeEmail($user['email'], $username, $realName);
                    if ($emailSent) {
                        error_log("Welcome email sent successfully to {$user['email']} for user $username");
                    } else {
                        error_log("Failed to send welcome email to {$user['email']} for user $username");
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("Error sending welcome email for user $username: " . $e->getMessage());
        }
    }

    /**
     * Load welcome message template with variable substitution
     */
    private function loadWelcomeTemplate($realName, $systemName, $systemAddress, $sysopName)
    {
        $welcomeFile = __DIR__ . '/../config/newuser_welcome.txt';
        
        // Fallback message if template file doesn't exist
        $defaultMessage = "Welcome to $systemName, $realName!\n\n";
        $defaultMessage .= "Your user account has been approved and is now active.\n";
        $defaultMessage .= "You can now participate in all available echoareas and send netmail.\n\n";
        $defaultMessage .= "System Information:\n";
        $defaultMessage .= "==================\n";
        $defaultMessage .= "System Name: $systemName\n";
        $defaultMessage .= "System Address: $systemAddress\n";
        $defaultMessage .= "Sysop: $sysopName\n\n";
        $defaultMessage .= "Getting Started:\n";
        $defaultMessage .= "===============\n";
        $defaultMessage .= "- Visit the Echomail section to browse available discussion areas\n";
        $defaultMessage .= "- Use the Netmail section to send private messages\n";
        $defaultMessage .= "- Check your Settings to customize your experience\n\n";
        $defaultMessage .= "If you have any questions, feel free to send netmail to the sysop.\n\n";
        $defaultMessage .= "Welcome to the FidoNet community!";
        
        if (!file_exists($welcomeFile)) {
            return $defaultMessage;
        }
        
        $template = file_get_contents($welcomeFile);
        if ($template === false) {
            return $defaultMessage;
        }
        
        // Perform variable substitutions
        $replacements = [
            '{REAL_NAME}' => $realName,
            '{SYSTEM_NAME}' => $systemName,
            '{SYSTEM_ADDRESS}' => $systemAddress,
            '{SYSOP_NAME}' => $sysopName,
            '{real_name}' => $realName,
            '{system_name}' => $systemName,
            '{system_address}' => $systemAddress,
            '{sysop_name}' => $sysopName
        ];
        
        $messageText = str_replace(array_keys($replacements), array_values($replacements), $template);
        
        // Convert HTML tags to plain text for netmail
        $messageText = str_replace(['<B>', '</B>'], ['', ''], $messageText);
        $messageText = str_replace(['<b>', '</b>'], ['', ''], $messageText);
        
        return $messageText;
    }

    /**
     * Get all pending user registrations (only pending ones, not approved/rejected)
     */
    public function getPendingUsers()
    {
        $stmt = $this->db->query("
            SELECT p.*, u.username as reviewed_by_username
            FROM pending_users p
            LEFT JOIN users u ON p.reviewed_by = u.id
            WHERE p.status = 'pending'
            ORDER BY p.requested_at DESC
        ");
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get all pending user registrations including processed ones (for admin history)
     */
    public function getAllPendingUsers()
    {
        $stmt = $this->db->query("
            SELECT p.*, u.username as reviewed_by_username
            FROM pending_users p
            LEFT JOIN users u ON p.reviewed_by = u.id
            ORDER BY p.requested_at DESC
        ");
        
        return $stmt->fetchAll();
    }
    
    /**
     * Clean up old rejected registrations (older than 30 days)
     */
    public function cleanupOldRejectedRegistrations()
    {
        $stmt = $this->db->prepare("
            DELETE FROM pending_users 
            WHERE status = 'rejected' 
            AND reviewed_at < DATE('now', '-30 days')
        ");
        
        $stmt->execute();
        return $stmt->rowCount();
    }
    
    /**
     * Clean up all approved registrations (since they're now real users)
     * This is useful for cleaning up historical data
     */
    public function cleanupApprovedRegistrations()
    {
        $stmt = $this->db->prepare("DELETE FROM pending_users WHERE status = 'approved'");
        $stmt->execute();
        return $stmt->rowCount();
    }
    
    /**
     * Full cleanup: remove approved registrations and old rejected ones
     */
    public function performFullCleanup()
    {
        $approvedCleaned = $this->cleanupApprovedRegistrations();
        $rejectedCleaned = $this->cleanupOldRejectedRegistrations();
        
        return [
            'approved_removed' => $approvedCleaned,
            'old_rejected_removed' => $rejectedCleaned,
            'total_cleaned' => $approvedCleaned + $rejectedCleaned
        ];
    }

    /**
     * Create a share link for a message
     */
    public function createMessageShare($messageId, $messageType, $userId, $isPublic = false, $expiresHours = null)
    {
        // Ensure proper boolean conversion - handle empty strings and various inputs
        $isPublic = filter_var($isPublic, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($isPublic === null) {
            $isPublic = false; // Default to false for any invalid input
        }
        
        // Validate user can access this message
        if ($messageType === 'echomail') {
            $message = $this->getMessage($messageId, $messageType, $userId);
        } else {
            // For netmail, ensure user owns or is recipient of the message
            $message = $this->getMessage($messageId, $messageType, $userId);
        }
        
        if (!$message) {
            return ['success' => false, 'error' => 'Message not found or access denied'];
        }

        // Check user's sharing settings
        $userSettings = $this->getUserSettings($userId);
        if (isset($userSettings['allow_sharing']) && !$userSettings['allow_sharing']) {
            return ['success' => false, 'error' => 'Sharing is disabled for your account'];
        }

        // Check if user has reached their share limit
        $shareCount = $this->getUserActiveShareCount($userId);
        $maxShares = $userSettings['max_shares_per_user'] ?? 50;
        if ($shareCount >= $maxShares) {
            return ['success' => false, 'error' => "Maximum number of active shares ($maxShares) reached"];
        }

        // Check if message is already shared by this user
        $existingShare = $this->getExistingShare($messageId, $messageType, $userId);
        if ($existingShare) {
            return [
                'success' => true,
                'share_key' => $existingShare['share_key'],
                'share_url' => $this->buildShareUrl($existingShare['share_key']),
                'existing' => true
            ];
        }

        // Generate unique share key
        $shareKey = $this->generateShareKey();
        
        $expiresAt = null;
        if ($expiresHours) {
            $expiresAt = date('Y-m-d H:i:s', time() + ($expiresHours * 3600));
        }

        $stmt = $this->db->prepare("
            INSERT INTO shared_messages (message_id, message_type, shared_by_user_id, share_key, expires_at, is_public)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        // Bind parameters explicitly with proper types
        $stmt->bindValue(1, $messageId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $messageType, \PDO::PARAM_STR);
        $stmt->bindValue(3, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(4, $shareKey, \PDO::PARAM_STR);
        $stmt->bindValue(5, $expiresAt, \PDO::PARAM_STR);
        $stmt->bindValue(6, (bool)$isPublic, \PDO::PARAM_BOOL);
        
        //error_log("MessageHandler::createMessageShare - isPublic binding: " . var_export((bool)$isPublic, true));
        
        $result = $stmt->execute();

        if ($result) {
            return [
                'success' => true,
                'share_key' => $shareKey,
                'share_url' => $this->buildShareUrl($shareKey),
                'expires_at' => $expiresAt,
                'is_public' => $isPublic
            ];
        }

        return ['success' => false, 'error' => 'Failed to create share link'];
    }

    /**
     * Get shared message by share key
     */
    public function getSharedMessage($shareKey, $requestingUserId = null)
    {
        // Clean up expired shares first
        $this->cleanupExpiredShares();

        $stmt = $this->db->prepare("
            SELECT sm.*, u.username as shared_by_username, u.real_name as shared_by_real_name
            FROM shared_messages sm
            JOIN users u ON sm.shared_by_user_id = u.id
            WHERE sm.share_key = ? 
              AND sm.is_active = TRUE 
              AND (sm.expires_at IS NULL OR sm.expires_at > NOW())
        ");

        $stmt->execute([$shareKey]);
        $share = $stmt->fetch();

        if (!$share) {
            return ['success' => false, 'error' => 'Share not found or expired'];
        }

        // Check access permissions - ensure proper boolean conversion
        $isPublic = filter_var($share['is_public'], FILTER_VALIDATE_BOOLEAN);
        //error_log("Share access check - raw is_public: " . var_export($share['is_public'], true) . ", converted: " . var_export($isPublic, true) . ", requestingUserId: " . var_export($requestingUserId, true));
        
        if (!$isPublic && !$requestingUserId) {
            return ['success' => false, 'error' => 'Login required to access this share'];
        }

        // Get the actual message
        $message = null;
        if ($share['message_type'] === 'echomail') {
            $stmt = $this->db->prepare("
                SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color 
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                WHERE em.id = ?
            ");
            $stmt->execute([$share['message_id']]);
            $message = $stmt->fetch();
        } else if ($share['message_type'] === 'netmail') {
            $stmt = $this->db->prepare("SELECT * FROM netmail WHERE id = ?");
            $stmt->execute([$share['message_id']]);
            $message = $stmt->fetch();
        }

        if (!$message) {
            return ['success' => false, 'error' => 'Original message not found'];
        }

        // Update access statistics
        $this->updateShareAccess($share['id']);

        // Clean message for JSON encoding
        $message = $this->cleanMessageForJson($message);

        return [
            'success' => true,
            'message' => $message,
            'share_info' => [
                'shared_by' => $share['shared_by_real_name'] ?: $share['shared_by_username'],
                'created_at' => $share['created_at'],
                'expires_at' => $share['expires_at'],
                'is_public' => $share['is_public'],
                'access_count' => $share['access_count']
            ]
        ];
    }

    /**
     * Get all shares for a message by a user
     */
    public function getMessageShares($messageId, $messageType, $userId)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM shared_messages 
            WHERE message_id = ? AND message_type = ? AND shared_by_user_id = ? AND is_active = TRUE
            ORDER BY created_at DESC
        ");

        $stmt->execute([$messageId, $messageType, $userId]);
        $shares = $stmt->fetchAll();

        $result = [];
        foreach ($shares as $share) {
            $result[] = [
                'share_key' => $share['share_key'],
                'share_url' => $this->buildShareUrl($share['share_key']),
                'created_at' => $share['created_at'],
                'expires_at' => $share['expires_at'],
                'is_public' => $share['is_public'],
                'access_count' => $share['access_count'],
                'last_accessed_at' => $share['last_accessed_at']
            ];
        }

        return ['success' => true, 'shares' => $result];
    }

    /**
     * Revoke a share link
     */
    public function revokeShare($messageId, $messageType, $userId)
    {
        $stmt = $this->db->prepare("
            UPDATE shared_messages 
            SET is_active = FALSE 
            WHERE message_id = ? AND message_type = ? AND shared_by_user_id = ?
        ");

        $result = $stmt->execute([$messageId, $messageType, $userId]);
        
        if ($result && $stmt->rowCount() > 0) {
            return ['success' => true, 'message' => 'Share link revoked'];
        }

        return ['success' => false, 'error' => 'Share not found or already revoked'];
    }

    /**
     * Get user's active share count
     */
    private function getUserActiveShareCount($userId)
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM shared_messages 
            WHERE shared_by_user_id = ? 
              AND is_active = TRUE 
              AND (expires_at IS NULL OR expires_at > NOW())
        ");

        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        return $result['count'];
    }

    /**
     * Check for existing share
     */
    private function getExistingShare($messageId, $messageType, $userId)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM shared_messages 
            WHERE message_id = ? AND message_type = ? AND shared_by_user_id = ? 
              AND is_active = TRUE 
              AND (expires_at IS NULL OR expires_at > NOW())
        ");

        $stmt->execute([$messageId, $messageType, $userId]);
        return $stmt->fetch();
    }

    /**
     * Generate unique share key
     */
    private function generateShareKey()
    {
        do {
            $shareKey = bin2hex(random_bytes(16));
            $stmt = $this->db->prepare("SELECT id FROM shared_messages WHERE share_key = ?");
            $stmt->execute([$shareKey]);
        } while ($stmt->fetch());

        return $shareKey;
    }

    /**
     * Build share URL
     */
    private function buildShareUrl($shareKey)
    {
        $siteUrl = \BinktermPHP\Config::env('SITE_URL');
        
        if ($siteUrl) {
            // Remove trailing slash if present
            $siteUrl = rtrim($siteUrl, '/');
            return "$siteUrl/shared/$shareKey";
        }
        
        // Fallback to old protocol detection method if SITE_URL not configured
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "$protocol://$host/shared/$shareKey";
    }

    /**
     * Update share access statistics
     */
    private function updateShareAccess($shareId)
    {
        $stmt = $this->db->prepare("
            UPDATE shared_messages 
            SET access_count = access_count + 1, last_accessed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$shareId]);
    }

    /**
     * Clean up expired shares
     */
    public function cleanupExpiredShares()
    {
        $stmt = $this->db->prepare("
            DELETE FROM shared_messages 
            WHERE expires_at IS NOT NULL AND expires_at < NOW()
        ");
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Get user's all shares (for management)
     */
    public function getUserShares($userId, $limit = 50)
    {
        $stmt = $this->db->prepare("
            SELECT sm.*, 
                   CASE 
                       WHEN sm.message_type = 'echomail' THEN em.subject 
                       ELSE nm.subject 
                   END as message_subject,
                   CASE 
                       WHEN sm.message_type = 'echomail' THEN ea.tag 
                       ELSE 'netmail' 
                   END as area_tag
            FROM shared_messages sm
            LEFT JOIN echomail em ON (sm.message_type = 'echomail' AND sm.message_id = em.id)
            LEFT JOIN netmail nm ON (sm.message_type = 'netmail' AND sm.message_id = nm.id)
            LEFT JOIN echoareas ea ON (em.echoarea_id = ea.id)
            WHERE sm.shared_by_user_id = ? AND sm.is_active = TRUE
            ORDER BY sm.created_at DESC
            LIMIT ?
        ");

        $stmt->execute([$userId, $limit]);
        $shares = $stmt->fetchAll();

        $result = [];
        foreach ($shares as $share) {
            $result[] = [
                'id' => $share['id'],
                'message_id' => $share['message_id'],
                'message_type' => $share['message_type'],
                'message_subject' => $share['message_subject'],
                'area_tag' => $share['area_tag'],
                'share_key' => $share['share_key'],
                'share_url' => $this->buildShareUrl($share['share_key']),
                'created_at' => $share['created_at'],
                'expires_at' => $share['expires_at'],
                'is_public' => $share['is_public'],
                'access_count' => $share['access_count'],
                'last_accessed_at' => $share['last_accessed_at']
            ];
        }

        return $result;
    }

    /**
     * Generate message ID using CRC32B hash (same as BinkdProcessor)
     * Format: <8-character-hex-crc32>
     */
    private function generateMessageId($fromName, $toName, $subject, $nodeAddress)
    {
        // Get current timestamp in microseconds for more uniqueness
        $timestamp = microtime(true);
        
        // Create the data string to hash (from, to, subject, timestamp)
        $dataString = $fromName . $toName . $subject . $timestamp;
        
        // Generate CRC32B hash and convert to uppercase hex (8 characters)
        $crc32 = sprintf('%08X', crc32($dataString));
        
        return $crc32;
    }

    /**
     * Generate kludge lines for netmail messages
     */
    private function generateNetmailKludges($fromAddress, $toAddress, $fromName, $toName, $subject, $replyToId = null)
    {
        $kludgeLines = [];
        
        // Add TZUTC kludge line for netmail
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $timezone = $binkpConfig->getSystemTimezone();
            $tz = new \DateTimeZone($timezone);
            $now = new \DateTime('now', $tz);
            $offset = $now->getOffset();
            $offsetHours = intval($offset / 3600);
            $offsetMinutes = intval(abs($offset % 3600) / 60);
            $offsetStr = sprintf('%+03d%02d', $offsetHours, $offsetMinutes);
            $kludgeLines[] = "\x01TZUTC: {$offsetStr}";
        } catch (\Exception $e) {
            // Fallback to UTC if timezone is invalid
            $kludgeLines[] = "\x01TZUTC: +0000";
        }
        
        // Add MSGID kludge (required for netmail)
        $msgId = $this->generateMessageId($fromName, $toName, $subject, $fromAddress);
        $kludgeLines[] = "\x01MSGID: {$fromAddress} {$msgId}";
        
        // Add REPLY kludge if this is a reply to another message
        if (!empty($replyToId)) {
            $originalMsgId = $this->getOriginalNetmailMessageId($replyToId);
            if ($originalMsgId) {
                $kludgeLines[] = "\x01REPLY: {$originalMsgId}";
            }
        }
        
        // Add reply address information in multiple formats for compatibility
        $kludgeLines[] = "\x01REPLYADDR {$fromAddress}";
        $kludgeLines[] = "\x01REPLYTO {$fromAddress}";
        
        // Add INTL kludge for zone routing (required for inter-zone mail)
        list($fromZone, $fromRest) = explode(':', $fromAddress);
        list($toZone, $toRest) = explode(':', $toAddress);
        $kludgeLines[] = "\x01INTL {$toZone}:{$toRest} {$fromZone}:{$fromRest}";
        
        // Add FMPT/TOPT kludges for point addressing if needed
        if (strpos($fromAddress, '.') !== false) {
            list($mainAddr, $point) = explode('.', $fromAddress);
            $kludgeLines[] = "\x01FMPT {$point}";
        }
        
        if (strpos($toAddress, '.') !== false) {
            list($mainAddr, $point) = explode('.', $toAddress);  
            $kludgeLines[] = "\x01TOPT {$point}";
        }
        
        // Add FLAGS kludge for netmail attributes (always private)
        $kludgeLines[] = "\x01FLAGS PVT";
        
        return implode("\n", $kludgeLines);
    }

    /**
     * Generate kludge lines for echomail messages
     */
    private function generateEchomailKludges($fromAddress, $fromName, $toName, $subject, $echoareaTag, $replyToId = null)
    {
        $kludgeLines = [];
        
        // Add TZUTC kludge line for echomail
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $timezone = $binkpConfig->getSystemTimezone();
            $tz = new \DateTimeZone($timezone);
            $now = new \DateTime('now', $tz);
            $offset = $now->getOffset();
            $offsetHours = intval($offset / 3600);
            $offsetMinutes = intval(abs($offset % 3600) / 60);
            $offsetStr = sprintf('%+03d%02d', $offsetHours, $offsetMinutes);
            $kludgeLines[] = "\x01TZUTC: {$offsetStr}";
        } catch (\Exception $e) {
            // Fallback to UTC if timezone is invalid
            $kludgeLines[] = "\x01TZUTC: +0000";
        }
        
        // Add MSGID kludge (required for echomail)
        $msgId = $this->generateMessageId($fromName, $toName, $subject, $fromAddress);
        $kludgeLines[] = "\x01MSGID: {$fromAddress} {$msgId}";
        
        // Add REPLY kludge if this is a reply to another message
        if (!empty($replyToId)) {
            $originalMsgId = $this->getOriginalEchomailMessageId($replyToId);
            if ($originalMsgId) {
                $kludgeLines[] = "\x01REPLY: {$originalMsgId}";
            }
        }
        
        return implode("\n", $kludgeLines);
    }

    /**
     * Get the original message's MSGID for REPLY kludge generation in echomail
     */
    private function getOriginalEchomailMessageId($messageId)
    {
        $stmt = $this->db->prepare("SELECT message_id FROM echomail WHERE id = ?");
        $stmt->execute([$messageId]);
        $originalMessage = $stmt->fetch();
        
        if (!$originalMessage || !$originalMessage['message_id']) {
            return null;
        }
        
        // Return the stored MSGID (format: "address hash")
        return $originalMessage['message_id'];
    }

    /**
     * Get the original message's MSGID for REPLY kludge generation in netmail
     */
    private function getOriginalNetmailMessageId($messageId)
    {
        $stmt = $this->db->prepare("SELECT message_id FROM netmail WHERE id = ?");
        $stmt->execute([$messageId]);
        $originalMessage = $stmt->fetch();
        
        if (!$originalMessage || !$originalMessage['message_id']) {
            return null;
        }
        
        // Return the stored MSGID (format: "address hash")
        return $originalMessage['message_id'];
    }

    /**
     * Ensure complete thread context for "all messages" view
     * This loads any missing parent or child messages needed for proper threading
     */
    private function ensureCompleteThreadContext($messages, $userId)
    {
        $messagesByMsgId = [];
        $missingMsgIds = [];
        $messagesById = [];
        
        // Index existing messages
        foreach ($messages as $message) {
            $messagesByMsgId[$message['message_id']] = $message;
            $messagesById[$message['id']] = $message;
        }
        
        // Find missing parent messages (messages that are referenced in REPLY kludges)
        foreach ($messages as $message) {
            $replyTo = $this->extractReplyFromKludge($message['kludge_lines']);
            if ($replyTo && !isset($messagesByMsgId[$replyTo])) {
                $missingMsgIds[] = $replyTo;
            }
        }
        
        // Find missing child messages (messages that reply to current messages)
        // Use a simpler approach - just look for messages with REPLY kludges
        $currentMsgIds = array_keys($messagesByMsgId);
        if (!empty($currentMsgIds)) {
            foreach ($currentMsgIds as $currentMsgId) {
                $stmt = $this->db->prepare("
                    SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color,
                           CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                           CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                           CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                    FROM echomail em
                    JOIN echoareas ea ON em.echoarea_id = ea.id
                    LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                    LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                    LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                    WHERE em.kludge_lines LIKE ? 
                    LIMIT 10
                ");
                
                $searchPattern = '%REPLY: ' . $currentMsgId . '%';
                $stmt->execute([$userId, $userId, $userId, $searchPattern]);
                $childMessages = $stmt->fetchAll();
                
                foreach ($childMessages as $childMessage) {
                    if (!isset($messagesById[$childMessage['id']])) {
                        // Verify this is actually a reply to avoid false positives
                        $replyTo = $this->extractReplyFromKludge($childMessage['kludge_lines']);
                        if ($replyTo === $currentMsgId) {
                            $messages[] = $childMessage;
                            $messagesByMsgId[$childMessage['message_id']] = $childMessage;
                            $messagesById[$childMessage['id']] = $childMessage;
                        }
                    }
                }
            }
        }
        
        // Load missing parent messages
        if (!empty($missingMsgIds)) {
            $placeholders = implode(',', array_fill(0, count($missingMsgIds), '?'));
            $stmt = $this->db->prepare("
                SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE em.message_id IN ({$placeholders})
            ");
            
            $params = [$userId, $userId, $userId];
            foreach ($missingMsgIds as $msgId) {
                $params[] = $msgId;
            }
            
            $stmt->execute($params);
            $parentMessages = $stmt->fetchAll();
            
            foreach ($parentMessages as $parentMessage) {
                if (!isset($messagesById[$parentMessage['id']])) {
                    $messages[] = $parentMessage;
                }
            }
        }
        
        return $messages;
    }

    /**
     * Parse kludges from either kludge_lines column or message_text (for backward compatibility)
     * This provides a unified way to handle kludge parsing for netmail messages
     */
    private function parseNetmailKludges($message, $kludgeType = null)
    {
        $kludgeData = [];
        $kludgeText = '';
        
        // First try the dedicated kludge_lines column
        if (!empty($message['kludge_lines'])) {
            $kludgeText = $message['kludge_lines'];
        } else {
            // Fallback to parsing from message_text for backward compatibility
            $messageText = $message['message_text'] ?? '';
            $lines = preg_split('/\r\n|\r|\n/', $messageText);
            $kludgeLines = [];
            
            foreach ($lines as $line) {
                if (strlen($line) > 0 && ord($line[0]) === 0x01) {
                    $kludgeLines[] = $line;
                }
            }
            
            $kludgeText = implode("\n", $kludgeLines);
        }
        
        if (empty($kludgeText)) {
            return null;
        }
        
        // Parse specific kludge types if requested
        $lines = explode("\n", $kludgeText);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            
            // Parse REPLYTO kludge
            if (preg_match('/^\x01REPLYTO\s+(.+)$/i', $trimmed, $matches)) {
                $replyToData = trim($matches[1]);
                if (preg_match('/^(\S+)(?:\s+(.+))?$/', $replyToData, $addressMatches)) {
                    $address = trim($addressMatches[1]);
                    $name = isset($addressMatches[2]) ? trim($addressMatches[2]) : null;
                    
                    if ($this->isValidFidonetAddress($address)) {
                        $kludgeData['REPLYTO'] = [
                            'address' => $address,
                            'name' => $name
                        ];
                    }
                }
            }
            
            // Parse MSGID kludge
            if (preg_match('/^\x01MSGID:\s*(.+)$/i', $trimmed, $matches)) {
                $kludgeData['MSGID'] = trim($matches[1]);
            }
            
            // Parse REPLY kludge  
            if (preg_match('/^\x01REPLY:\s*(.+)$/i', $trimmed, $matches)) {
                $kludgeData['REPLY'] = trim($matches[1]);
            }
            
            // Parse TZUTC kludge
            if (preg_match('/^\x01TZUTC:\s*(.+)$/i', $trimmed, $matches)) {
                $kludgeData['TZUTC'] = trim($matches[1]);
            }
        }
        
        // Return specific kludge type if requested, otherwise return all
        if ($kludgeType && isset($kludgeData[$kludgeType])) {
            return $kludgeData[$kludgeType];
        }
        
        return empty($kludgeData) ? null : $kludgeData;
    }

    /**
     * Get threaded echomail messages from subscribed echoareas using MSGID/REPLY relationships
     */
    private function getThreadedEchomailFromSubscribedAreas($userId, $page = 1, $limit = null, $filter = 'all', $subscribedEchoareas = null)
    {
        // Get subscribed echoareas if not provided
        if ($subscribedEchoareas === null) {
            $subscriptionManager = new EchoareaSubscriptionManager();
            $subscribedEchoareas = $subscriptionManager->getUserSubscribedEchoareas($userId);
        }
        
        if (empty($subscribedEchoareas)) {
            return [
                'messages' => [],
                'pagination' => ['page' => 1, 'limit' => 25, 'total' => 0, 'pages' => 0],
                'info' => 'You are not subscribed to any echoareas. Visit /subscriptions to subscribe to echoareas.'
            ];
        }

        // Get user's messages_per_page setting if limit not specified
        if ($limit === null && $userId) {
            $settings = $this->getUserSettings($userId);
            $limit = $settings['messages_per_page'] ?? 25;
        } elseif ($limit === null) {
            $limit = 25; // Default fallback if no user ID
        }

        // Build the WHERE clause based on filter
        $filterClause = "";
        $filterParams = [];
        
        if ($filter === 'unread' && $userId) {
            $filterClause = " AND mrs.read_at IS NULL";
        } elseif ($filter === 'read' && $userId) {
            $filterClause = " AND mrs.read_at IS NOT NULL";
        } elseif ($filter === 'tome' && $userId) {
            $user = $this->getUserById($userId);
            if ($user) {
                $filterClause = " AND (LOWER(em.to_name) = LOWER(?) OR LOWER(em.to_name) = LOWER(?))";
                $filterParams[] = $user['username'];
                $filterParams[] = $user['real_name'];
            }
        } elseif ($filter === 'saved' && $userId) {
            $filterClause = " AND sav.id IS NOT NULL";
        }

        // Create IN clause for subscribed echoareas
        $echoareaIds = array_column($subscribedEchoareas, 'id');
        $placeholders = str_repeat('?,', count($echoareaIds) - 1) . '?';

        // Get messages for current page using standard pagination
        $offset = ($page - 1) * $limit;
        $stmt = $this->db->prepare("
            SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color,
                   CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                   CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                   CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
            FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
            LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
            LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
            WHERE ea.id IN ($placeholders) AND ea.is_active = TRUE{$filterClause}
            ORDER BY em.date_received DESC
            LIMIT ? OFFSET ?
        ");
        
        $params = [$userId, $userId, $userId];
        $params = array_merge($params, $echoareaIds);
        foreach ($filterParams as $param) {
            $params[] = $param;
        }
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $pageMessages = $stmt->fetchAll();
        
        // Debug: log what we got
        error_log("DEBUG: Page $page, got " . count($pageMessages) . " page messages");
        
        // For now, just use the page messages without complex threading
        $allMessages = $pageMessages;
        
        // Build threading relationships
        $threads = $this->buildMessageThreads($allMessages);
        
        // Debug: log thread info
        error_log("DEBUG: Built " . count($threads) . " threads from " . count($allMessages) . " messages");
        
        // Sort threads by most recent message in each thread
        usort($threads, function($a, $b) {
            $aLatest = $this->getLatestMessageInThread($a);
            $bLatest = $this->getLatestMessageInThread($b);
            return strtotime($bLatest['date_received']) - strtotime($aLatest['date_received']);
        });
        
        // Get total count for pagination (based on actual message count, not thread count)
        $countStmt = $this->db->prepare("
            SELECT COUNT(*) as total FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
            LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
            WHERE ea.id IN ($placeholders) AND ea.is_active = TRUE{$filterClause}
        ");
        
        $countParams = [$userId, $userId];
        $countParams = array_merge($countParams, $echoareaIds);
        foreach ($filterParams as $param) {
            $countParams[] = $param;
        }
        
        $countStmt->execute($countParams);
        $totalMessages = $countStmt->fetch()['total'];
        
        // No need to paginate threads since we already got the right page from SQL
        $totalThreads = count($threads);
        
        // Debug: log final results
        error_log("DEBUG: Using " . count($threads) . " threads for display");
        
        // Flatten threads for display while maintaining structure
        $messages = $this->flattenThreadsForDisplay($threads);
        
        // Get unread count
        $unreadCount = 0;
        if ($userId) {
            foreach ($allMessages as $msg) {
                if (!$msg['is_read']) {
                    $unreadCount++;
                }
            }
        }

        // Clean message data for proper JSON encoding and add REPLYTO parsing
        $cleanMessages = [];
        foreach ($messages as $message) {
            $cleanMessage = $this->cleanMessageForJson($message);
            
            // Parse REPLYTO kludge - check kludge_lines first, then message_text for backward compatibility
            $replyToData = null;
            
            // For echomail, check kludge_lines first
            if (isset($message['kludge_lines']) && !empty($message['kludge_lines'])) {
                $replyToData = $this->parseEchomailReplyToKludge($message['kludge_lines']);
            }
            
            // For netmail or if no kludge_lines found, check message array (handles both kludge_lines and message_text)
            if (!$replyToData) {
                $replyToData = $this->parseReplyToKludge($message);
            }
            
            if ($replyToData && isset($replyToData['address'])) {
                $cleanMessage['replyto_address'] = $replyToData['address'];
                $cleanMessage['replyto_name'] = $replyToData['name'] ?? null;
            }
            
            $cleanMessages[] = $cleanMessage;
        }

        return [
            'messages' => $cleanMessages,
            'unreadCount' => $unreadCount,
            'threaded' => true,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalMessages,
                'pages' => ceil($totalMessages / $limit)
            ]
        ];
    }

    /**
     * Get threaded echomail messages using MSGID/REPLY relationships
     */
    public function getThreadedEchomail($echoareaTag = null, $page = 1, $limit = null, $userId = null, $filter = 'all')
    {
        // Get user's messages_per_page setting if limit not specified
        if ($limit === null && $userId) {
            $settings = $this->getUserSettings($userId);
            $limit = $settings['messages_per_page'] ?? 25;
        } elseif ($limit === null) {
            $limit = 25; // Default fallback if no user ID
        }

        // Build the WHERE clause based on filter
        $filterClause = "";
        $filterParams = [];
        
        if ($filter === 'unread' && $userId) {
            $filterClause = " AND mrs.read_at IS NULL";
        } elseif ($filter === 'read' && $userId) {
            $filterClause = " AND mrs.read_at IS NOT NULL";
        } elseif ($filter === 'tome' && $userId) {
            $user = $this->getUserById($userId);
            if ($user) {
                $filterClause = " AND (LOWER(em.to_name) = LOWER(?) OR LOWER(em.to_name) = LOWER(?))";
                $filterParams[] = $user['username'];
                $filterParams[] = $user['real_name'];
            }
        } elseif ($filter === 'saved' && $userId) {
            $filterClause = " AND sav.id IS NOT NULL";
        }

        // Get all messages for threading (need to load more data to ensure thread completeness)
        if ($echoareaTag) {
            $stmt = $this->db->prepare("
                SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE ea.tag = ?{$filterClause}
                ORDER BY em.date_received DESC
            ");
            $params = [$userId, $userId, $userId, $echoareaTag];
            foreach ($filterParams as $param) {
                $params[] = $param;
            }
            $stmt->execute($params);
        } else {
            // For "all messages" view, we need to load thread-related messages too
            // First get the base messages with a larger limit to include thread context
            $threadLimit = $limit * 3; // Load more to capture thread relationships
            $stmt = $this->db->prepare("
                SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE 1=1{$filterClause}
                ORDER BY em.date_received DESC
                LIMIT {$threadLimit}
            ");
            $params = [$userId, $userId, $userId];
            foreach ($filterParams as $param) {
                $params[] = $param;
            }
            $stmt->execute($params);
        }
        
        $allMessages = $stmt->fetchAll();
        
        // For "all messages" view, ensure we have complete thread context
        if (!$echoareaTag) {
            $allMessages = $this->ensureCompleteThreadContext($allMessages, $userId);
        }
        
        // Build threading relationships
        $threads = $this->buildMessageThreads($allMessages);
        
        // Sort threads by most recent message in each thread
        usort($threads, function($a, $b) {
            $aLatest = $this->getLatestMessageInThread($a);
            $bLatest = $this->getLatestMessageInThread($b);
            return strtotime($bLatest['date_received']) - strtotime($aLatest['date_received']);
        });
        
        // Apply pagination to threads
        $totalThreads = count($threads);
        $offset = ($page - 1) * $limit;
        $pagedThreads = array_slice($threads, $offset, $limit);
        
        // Flatten threads for display while maintaining structure
        $messages = $this->flattenThreadsForDisplay($pagedThreads);
        
        // Get unread count
        $unreadCount = 0;
        if ($userId) {
            foreach ($allMessages as $msg) {
                if (!$msg['is_read']) {
                    $unreadCount++;
                }
            }
        }

        // Clean message data for proper JSON encoding and add REPLYTO parsing
        $cleanMessages = [];
        foreach ($messages as $message) {
            $cleanMessage = $this->cleanMessageForJson($message);
            
            // Parse REPLYTO kludge - check kludge_lines first, then message_text for backward compatibility
            $replyToData = null;
            
            // For echomail, check kludge_lines first
            if (isset($message['kludge_lines']) && !empty($message['kludge_lines'])) {
                $replyToData = $this->parseEchomailReplyToKludge($message['kludge_lines']);
            }
            
            // For netmail or if no kludge_lines found, check message array (handles both kludge_lines and message_text)
            if (!$replyToData) {
                $replyToData = $this->parseReplyToKludge($message);
            }
            
            if ($replyToData && isset($replyToData['address'])) {
                $cleanMessage['replyto_address'] = $replyToData['address'];
                $cleanMessage['replyto_name'] = $replyToData['name'] ?? null;
            }
            
            $cleanMessages[] = $cleanMessage;
        }

        return [
            'messages' => $cleanMessages,
            'unreadCount' => $unreadCount,
            'threaded' => true,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalThreads,
                'pages' => ceil($totalThreads / $limit)
            ]
        ];
    }

    /**
     * Build message threads using MSGID/REPLY relationships
     */
    private function buildMessageThreads($messages)
    {
        $messagesByMsgId = [];
        $messagesByReply = [];
        $rootMessages = [];
        
        // Index messages by their MSGID and build reply relationships
        foreach ($messages as $message) {
            $msgId = $message['message_id'];
            $messagesByMsgId[$msgId] = $message;
            
            // Extract REPLY from kludge lines
            $replyTo = @$this->extractReplyFromKludge($message['kludge_lines']);
            
            if ($replyTo) {
                $messagesByReply[$replyTo][] = $message;
                $message['reply_to_msgid'] = $replyTo;
            } else {
                // No REPLY found, this is a root message
                $rootMessages[] = $message;
            }
        }
        
        // Build thread trees
        $threads = [];
        foreach ($rootMessages as $root) {
            $thread = $this->buildThreadTree($root, $messagesByReply);
            $threads[] = $thread;
        }
        
        // Handle orphaned replies (replies that don't have parent messages)
        foreach ($messagesByReply as $parentMsgId => $replies) {
            if (!isset($messagesByMsgId[$parentMsgId])) {
                // Parent not found, treat each orphaned reply as a separate thread
                foreach ($replies as $orphan) {
                    $thread = $this->buildThreadTree($orphan, $messagesByReply);
                    $threads[] = $thread;
                }
            }
        }
        
        return $threads;
    }
    
    /**
     * Recursively build a thread tree
     */
    private function buildThreadTree($message, $messagesByReply)
    {
        $msgId = $message['message_id'];
        $thread = [
            'message' => $message,
            'replies' => []
        ];
        
        if (isset($messagesByReply[$msgId])) {
            foreach ($messagesByReply[$msgId] as $reply) {
                $thread['replies'][] = $this->buildThreadTree($reply, $messagesByReply);
            }
            
            // Sort replies by date
            usort($thread['replies'], function($a, $b) {
                return strtotime($a['message']['date_received']) - strtotime($b['message']['date_received']);
            });
        }
        
        return $thread;
    }
    
    /**
     * Extract REPLY MSGID from kludge lines
     */
    private function extractReplyFromKludge($kludgeLines)
    {
        if (empty($kludgeLines)) {
            return null;
        }
        
        // Look for ^AREPLY: line in kludge
        $lines = explode("\n", $kludgeLines);
        foreach ($lines as $line) {
            $line = trim($line);
            // Check for REPLY kludge (starts with \x01 or ^A)
            if (preg_match('/^\x01REPLY:\s*(.+)$/i', $line, $matches)) {
                return trim($matches[1]);
            }
            // Also handle ^A notation (visible ^A character)
            if (preg_match('/^\^AREPLY:\s*(.+)$/i', $line, $matches)) {
                return trim($matches[1]);
            }
            // Also handle plain REPLY: without control character
            if (preg_match('/^REPLY:\s*(.+)$/i', $line, $matches)) {
                return trim($matches[1]);
            }
        }
        
        return null;
    }
    
    /**
     * Get the latest message in a thread (recursively)
     */
    private function getLatestMessageInThread($thread)
    {
        $latest = $thread['message'];
        
        foreach ($thread['replies'] as $reply) {
            $replyLatest = $this->getLatestMessageInThread($reply);
            if (strtotime($replyLatest['date_received']) > strtotime($latest['date_received'])) {
                $latest = $replyLatest;
            }
        }
        
        return $latest;
    }
    
    /**
     * Flatten threads for display while maintaining structure
     */
    private function flattenThreadsForDisplay($threads)
    {
        $flattened = [];
        
        foreach ($threads as $thread) {
            $this->flattenThread($thread, $flattened, 0);
        }
        
        return $flattened;
    }
    
    /**
     * Recursively flatten a thread
     */
    private function flattenThread($thread, &$flattened, $level)
    {
        // Add thread level and reply count info to message
        $message = $thread['message'];
        $message['thread_level'] = $level;
        $message['reply_count'] = $this->countRepliesInThread($thread);
        $message['is_thread_root'] = ($level == 0);
        
        $flattened[] = $message;
        
        // Add replies with increased level
        foreach ($thread['replies'] as $reply) {
            $this->flattenThread($reply, $flattened, $level + 1);
        }
    }
    
    /**
     * Count total replies in a thread
     */
    private function countRepliesInThread($thread)
    {
        $count = count($thread['replies']);
        
        foreach ($thread['replies'] as $reply) {
            $count += $this->countRepliesInThread($reply);
        }
        
        return $count;
    }

    /**
     * Get threaded netmail messages using MSGID/REPLY relationships
     */
    public function getThreadedNetmail($userId, $page = 1, $limit = null, $filter = 'all')
    {
        $user = $this->getUserById($userId);
        if (!$user) {
            return ['messages' => [], 'pagination' => []];
        }

        // Get user's messages_per_page setting if limit not specified
        if ($limit === null) {
            $settings = $this->getUserSettings($userId);
            $limit = $settings['messages_per_page'] ?? 25;
        }

        // Get system's FidoNet address for sent message filtering
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $systemAddress = $binkpConfig->getSystemAddress();
        } catch (\Exception $e) {
            $systemAddress = null;
        }

        // Build the WHERE clause based on filter
        // Show messages where user is sender OR recipient
        $whereClause = "WHERE (n.user_id = ? OR LOWER(n.to_name) = LOWER(?) OR LOWER(n.to_name) = LOWER(?))";
        $params = [$userId, $user['username'], $user['real_name']];
        
        if ($filter === 'unread') {
            $whereClause .= " AND mrs.read_at IS NULL";
        } elseif ($filter === 'sent' && $systemAddress) {
            // Show only messages sent by this user
            $whereClause = "WHERE n.from_address = ? AND n.user_id = ?";
            $params = [$systemAddress, $userId];
        } elseif ($filter === 'received') {
            // Show only messages received by this user (where they are the recipient)
            $whereClause = "WHERE (LOWER(n.to_name) = LOWER(?) OR LOWER(n.to_name) = LOWER(?)) AND n.user_id != ?";
            $params = [$user['username'], $user['real_name'], $userId];
        }

        // Get all messages first
        $stmt = $this->db->prepare("
            SELECT n.*, 
                   CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read
            FROM netmail n
            LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
            $whereClause
            ORDER BY n.date_received DESC
        ");
        
        // Insert userId at the beginning for the LEFT JOIN, then add existing params
        $allParams = [$userId];
        foreach ($params as $param) {
            $allParams[] = $param;
        }
        
        $stmt->execute($allParams);
        $allMessages = $stmt->fetchAll();
        
        // Build threading relationships
        $threads = $this->buildMessageThreads($allMessages);
        
        // Sort threads by most recent message in each thread
        usort($threads, function($a, $b) {
            $aLatest = $this->getLatestMessageInThread($a);
            $bLatest = $this->getLatestMessageInThread($b);
            return strtotime($bLatest['date_received']) - strtotime($aLatest['date_received']);
        });
        
        // Apply pagination to threads
        $totalThreads = count($threads);
        $offset = ($page - 1) * $limit;
        $pagedThreads = array_slice($threads, $offset, $limit);
        
        // Flatten threads for display while maintaining structure
        $messages = $this->flattenThreadsForDisplay($pagedThreads);
        
        // Get unread count
        $unreadCount = 0;
        foreach ($allMessages as $msg) {
            if (!$msg['is_read']) {
                $unreadCount++;
            }
        }

        // Clean message data for proper JSON encoding and add REPLYTO parsing
        $cleanMessages = [];
        foreach ($messages as $message) {
            $cleanMessage = $this->cleanMessageForJson($message);
            
            // Parse REPLYTO kludge - check kludge_lines first, then message_text for backward compatibility
            $replyToData = null;
            
            // For echomail, check kludge_lines first
            if (isset($message['kludge_lines']) && !empty($message['kludge_lines'])) {
                $replyToData = $this->parseEchomailReplyToKludge($message['kludge_lines']);
            }
            
            // For netmail or if no kludge_lines found, check message array (handles both kludge_lines and message_text)
            if (!$replyToData) {
                $replyToData = $this->parseReplyToKludge($message);
            }
            
            if ($replyToData && isset($replyToData['address'])) {
                $cleanMessage['replyto_address'] = $replyToData['address'];
                $cleanMessage['replyto_name'] = $replyToData['name'] ?? null;
            }
            
            $cleanMessages[] = $cleanMessage;
        }

        return [
            'messages' => $cleanMessages,
            'unreadCount' => $unreadCount,
            'threaded' => true,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $totalThreads,
                'pages' => ceil($totalThreads / $limit)
            ]
        ];
    }

    /**
     * Get thread context for specific messages efficiently
     */
    private function getThreadContextForMessages($pageMessages, $userId, $echoareaIds, $filterClause, $filterParams)
    {
        // Start with the page messages
        $allMessages = $pageMessages;
        
        // Extract MSGID and REPLY values from page messages
        $msgIds = [];
        $replyIds = [];
        
        foreach ($pageMessages as $msg) {
            if (!empty($msg['kludge_lines'])) {
                $kludgeLines = explode("\n", $msg['kludge_lines']);
                foreach ($kludgeLines as $line) {
                    if (preg_match('/^\x01MSGID:\s*(.+)$/i', trim($line), $matches)) {
                        $msgIds[] = trim($matches[1]);
                    }
                    if (preg_match('/^\x01REPLY:\s*(.+)$/i', trim($line), $matches)) {
                        $replyIds[] = trim($matches[1]);
                    }
                }
            }
        }
        
        // If we have thread references, get the related messages
        if (!empty($msgIds) || !empty($replyIds)) {
            $threadIds = array_merge($msgIds, $replyIds);
            $threadIds = array_unique($threadIds);
            
            if (!empty($threadIds)) {
                $placeholders = str_repeat('?,', count($threadIds) - 1) . '?';
                $echoareaPlaceholders = str_repeat('?,', count($echoareaIds) - 1) . '?';
                
                // Build LIKE conditions for thread IDs
                $likeConditions = [];
                $threadParams = [$userId, $userId, $userId];
                $threadParams = array_merge($threadParams, $echoareaIds);
                foreach ($filterParams as $param) {
                    $threadParams[] = $param;
                }
                
                foreach ($threadIds as $threadId) {
                    $likeConditions[] = "(em.kludge_lines LIKE ? OR em.kludge_lines LIKE ?)";
                    $threadParams[] = "%MSGID: " . $threadId . "%";
                    $threadParams[] = "%REPLY: " . $threadId . "%";
                }
                
                $likeClause = implode(' OR ', $likeConditions);
                
                // Get related thread messages
                $threadStmt = $this->db->prepare("
                    SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color,
                           CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                           CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                           CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                    FROM echomail em
                    JOIN echoareas ea ON em.echoarea_id = ea.id
                    LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                    LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                    LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                    WHERE ea.id IN ($echoareaPlaceholders) AND ea.is_active = TRUE{$filterClause}
                    AND ($likeClause)
                ");
                
                $threadStmt->execute($threadParams);
                $threadMessages = $threadStmt->fetchAll();
                
                // Merge with page messages, avoiding duplicates
                $messageIds = array_column($allMessages, 'id');
                foreach ($threadMessages as $threadMsg) {
                    if (!in_array($threadMsg['id'], $messageIds)) {
                        $allMessages[] = $threadMsg;
                    }
                }
            }
        }
        
        return $allMessages;
    }

    /**
     * Get users who haven't logged in yet (new accounts that need reminders)
     */
    public function getUsersNeedingReminder()
    {
        $stmt = $this->db->query("
            SELECT id, username, real_name, email, created_at 
            FROM users 
            WHERE last_login IS NULL 
              AND is_active = TRUE 
              AND created_at < NOW() - INTERVAL '24 hours'
            ORDER BY created_at ASC
        ");
        
        return $stmt->fetchAll();
    }

    /**
     * Send account reminder to user who hasn't logged in
     */
    public function sendAccountReminder($username)
    {
        // Get user info
        $stmt = $this->db->prepare("
            SELECT id, username, real_name, email, created_at 
            FROM users 
            WHERE username = ? AND last_login IS NULL AND is_active = TRUE
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return ['success' => false, 'error' => 'User not found or already logged in'];
        }

        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $systemAddress = $binkpConfig->getSystemAddress();
            $sysopName = $binkpConfig->getSystemSysop();
            $systemName = $binkpConfig->getSystemName();
        } catch (\Exception $e) {
            $systemAddress = '1:123/456';
            $sysopName = 'Sysop';
            $systemName = 'BinktermPHP System';
        }

        $subject = "Account Reminder - $systemName";
        
        $messageText = "Hello " . ($user['real_name'] ?: $user['username']) . ",\n\n";
        $messageText .= "This is a friendly reminder that your account on $systemName is ready to use!\n\n";
        $messageText .= "Account Details:\n";
        $messageText .= "===============\n";
        $messageText .= "Username: " . $user['username'] . "\n";
        $messageText .= "Account Created: " . date('Y-m-d H:i:s', strtotime($user['created_at'])) . "\n";
        $messageText .= "System: $systemName ($systemAddress)\n\n";
        $messageText .= "Getting Started:\n";
        $messageText .= "===============\n";
        $messageText .= "1. Visit the web interface and log in with your username\n";
        $messageText .= "2. Browse available echo areas for discussions\n";
        $messageText .= "3. Send and receive netmail (private messages)\n";
        $messageText .= "4. Customize your settings and preferences\n\n";
        $messageText .= "If you've forgotten your password or have any questions,\n";
        $messageText .= "please contact the sysop at $sysopName.\n\n";
        $messageText .= "Welcome to the FidoNet community!\n\n";
        $messageText .= "This message was automatically generated by the BinktermPHP system.";

        // Send netmail reminder
        $insertStmt = $this->db->prepare("
            INSERT INTO netmail (
                user_id, from_address, to_address, from_name, to_name, 
                subject, message_text, date_written, date_received, attributes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
        ");

        $insertStmt->execute([
            $user['id'],
            $systemAddress,  // from_address
            $systemAddress,  // to_address (user's address would be system address)
            'Account Reminder System',  // from_name
            $user['real_name'] ?: $user['username'],  // to_name
            $subject,
            $messageText,
            0  // attributes
        ]);

        // Also send email reminder if email is available and SMTP is enabled
        $emailSent = false;
        try {
            if (!empty($user['email'])) {
                $mailer = new \BinktermPHP\Mail();
                if ($mailer->isEnabled()) {
                    $emailSent = $mailer->sendAccountReminder(
                        $user['email'], 
                        $user['username'], 
                        $user['real_name'] ?: $user['username']
                    );
                }
            }
        } catch (\Exception $e) {
            error_log("Error sending reminder email for user " . $user['username'] . ": " . $e->getMessage());
        }

        // Update last_reminded timestamp since reminder was sent successfully
        try {
            $updateStmt = $this->db->prepare("UPDATE users SET last_reminded = NOW() WHERE id = ?");
            $updateResult = $updateStmt->execute([$user['id']]);
            $rowsUpdated = $updateStmt->rowCount();
            
            error_log("[REMINDER] Updated last_reminded for user ID {$user['id']} ({$user['username']}): success=" . ($updateResult ? 'true' : 'false') . ", rows_affected=$rowsUpdated");
        } catch (\Exception $e) {
            error_log("[REMINDER] Failed to update last_reminded for user {$user['username']}: " . $e->getMessage());
            error_log("[REMINDER] This likely means the database migration v1.4.8_add_last_reminded_field.sql has not been run");
        }

        return [
            'success' => true, 
            'message' => 'Account reminder sent successfully',
            'email_sent' => $emailSent
        ];
    }

    /**
     * Check if a user exists and hasn't logged in (for public reminder form)
     */
    public function canSendReminder($username)
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM users 
            WHERE username = ? AND last_login IS NULL AND is_active = TRUE
        ");
        $stmt->execute([$username]);
        $result = $stmt->fetch();
        
        return $result['count'] > 0;
    }

    /**
     * Parse REPLYTO kludge from echomail kludge_lines
     */
    private function parseEchomailReplyToKludge($kludgeLines)
    {
        if (empty($kludgeLines)) {
            return null;
        }
        
        $lines = explode("\n", $kludgeLines);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            
            // Look for REPLYTO kludge line (must have \x01 prefix)
            if (preg_match('/^\x01REPLYTO\s+(.+)$/i', $trimmed, $matches)) {
                $replyToData = trim($matches[1]);
                
                // Parse "address name" or just "address"
                if (preg_match('/^(\S+)(?:\s+(.+))?$/', $replyToData, $addressMatches)) {
                    $address = trim($addressMatches[1]);
                    $name = isset($addressMatches[2]) ? trim($addressMatches[2]) : null;
                    
                    // Only return if it's a valid FidoNet address
                    if ($this->isValidFidonetAddress($address)) {
                        return [
                            'address' => $address,
                            'name' => $name
                        ];
                    }
                }
            }
        }
        
        return null;
    }

    /**
     * Parse REPLYTO kludge line to extract address and name
     * Format: "REPLYTO 2:460/256 8421559770" -> ['address' => '2:460/256', 'name' => '8421559770']
     * Only returns data if the address is a valid FidoNet address
     */
    private function parseReplyToKludge($messageOrText)
    {
        // Handle both message array (new way) and message text (backward compatibility)
        if (is_array($messageOrText)) {
            // New way: pass entire message array to unified parser
            return $this->parseNetmailKludges($messageOrText, 'REPLYTO');
        } else {
            // Backward compatibility: create a fake message array with just message_text
            $fakeMessage = ['message_text' => $messageOrText, 'kludge_lines' => null];
            return $this->parseNetmailKludges($fakeMessage, 'REPLYTO');
        }
    }

    /**
     * Validate FidoNet address format
     */
    private function isValidFidonetAddress($address)
    {
        return preg_match('/^\d+:\d+\/\d+(?:\.\d+)?(?:@\w+)?$/', trim($address));
    }

    /**
     * Save a message draft
     */
    public function saveDraft($userId, $draftData)
    {
        try {
            // Validate required fields
            if (!isset($draftData['type']) || !in_array($draftData['type'], ['netmail', 'echomail'])) {
                throw new \Exception('Invalid message type');
            }

            // Check if draft already exists for this user and type with same content
            $existingDraft = $this->findExistingDraft($userId, $draftData);

            if ($existingDraft) {
                // Update existing draft
                $stmt = $this->db->prepare("
                    UPDATE drafts SET
                        to_address = ?,
                        to_name = ?,
                        echoarea = ?,
                        subject = ?,
                        message_text = ?,
                        reply_to_id = ?,
                        updated_at = NOW() AT TIME ZONE 'UTC'
                    WHERE id = ?
                ");

                $stmt->execute([
                    $draftData['to_address'] ?? null,
                    $draftData['to_name'] ?? null,
                    $draftData['echoarea'] ?? null,
                    $draftData['subject'] ?? null,
                    $draftData['message_text'] ?? null,
                    $draftData['reply_to_id'] ?? null,
                    $existingDraft['id']
                ]);

                return ['success' => true, 'draft_id' => $existingDraft['id'], 'updated' => true];
            } else {
                // Create new draft
                $stmt = $this->db->prepare("
                    INSERT INTO drafts (user_id, type, to_address, to_name, echoarea, subject, message_text, reply_to_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW() AT TIME ZONE 'UTC', NOW() AT TIME ZONE 'UTC')
                ");

                $stmt->execute([
                    $userId,
                    $draftData['type'],
                    $draftData['to_address'] ?? null,
                    $draftData['to_name'] ?? null,
                    $draftData['echoarea'] ?? null,
                    $draftData['subject'] ?? null,
                    $draftData['message_text'] ?? null,
                    $draftData['reply_to_id'] ?? null
                ]);

                $draftId = $this->db->lastInsertId();
                return ['success' => true, 'draft_id' => $draftId, 'created' => true];
            }
        } catch (\Exception $e) {
            error_log("Error saving draft: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Find existing draft that might be similar to current one
     */
    private function findExistingDraft($userId, $draftData)
    {
        try {
            // Look for recent drafts (within last hour) for same user/type
            $stmt = $this->db->prepare("
                SELECT * FROM drafts
                WHERE user_id = ?
                AND type = ?
                AND created_at > NOW() - INTERVAL '1 hour'
                ORDER BY updated_at DESC
                LIMIT 1
            ");

            $stmt->execute([$userId, $draftData['type']]);
            return $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("Error finding existing draft: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get user's drafts
     */
    public function getUserDrafts($userId, $type = null)
    {
        try {
            $sql = "SELECT * FROM drafts WHERE user_id = ?";
            $params = [$userId];

            if ($type) {
                $sql .= " AND type = ?";
                $params[] = $type;
            }

            $sql .= " ORDER BY updated_at DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("Error getting user drafts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a specific draft by ID
     */
    public function getDraft($userId, $draftId)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM drafts
                WHERE id = ? AND user_id = ?
            ");

            $stmt->execute([$draftId, $userId]);
            return $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("Error getting draft: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete a draft
     */
    public function deleteDraft($userId, $draftId)
    {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM drafts
                WHERE id = ? AND user_id = ?
            ");

            $stmt->execute([$draftId, $userId]);
            return ['success' => true];
        } catch (\Exception $e) {
            error_log("Error deleting draft: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}