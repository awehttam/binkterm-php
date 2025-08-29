<?php

namespace BinktermPHP;

class MessageHandler
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    public function getNetmail($userId, $page = 1, $limit = null, $filter = 'all')
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

        // Clean message data for proper JSON encoding
        $cleanMessages = [];
        foreach ($messages as $message) {
            $cleanMessages[] = $this->cleanMessageForJson($message);
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

    public function getEchomail($echoareaTag = null, $page = 1, $limit = null, $userId = null, $filter = 'all')
    {
        // Get user's messages_per_page setting if limit not specified
        if ($limit === null && $userId) {
            $settings = $this->getUserSettings($userId);
            $limit = $settings['messages_per_page'] ?? 25;
        } elseif ($limit === null) {
            $limit = 25; // Default fallback if no user ID
        }

        $offset = ($page - 1) * $limit;
        
        // Build the WHERE clause based on filter
        $filterClause = "";
        $filterParams = [];
        
        if ($filter === 'unread' && $userId) {
            $filterClause = " AND mrs.read_at IS NULL";
        } elseif ($filter === 'read' && $userId) {
            $filterClause = " AND mrs.read_at IS NOT NULL";
        }
        
        if ($echoareaTag) {
            $stmt = $this->db->prepare("
                SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                WHERE ea.tag = ?{$filterClause}
                ORDER BY CASE 
                    WHEN em.date_received > NOW() THEN 0 
                    ELSE 1 
                END, em.date_received DESC 
                LIMIT ? OFFSET ?
            ");
            $params = [$userId, $userId, $echoareaTag, $limit, $offset];
            $stmt->execute($params);
            $messages = $stmt->fetchAll();

            $countStmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                WHERE ea.tag = ?{$filterClause}
            ");
            $countParams = [$userId, $echoareaTag];
            $countStmt->execute($countParams);
        } else {
            $stmt = $this->db->prepare("
                SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                WHERE 1=1{$filterClause}
                ORDER BY CASE 
                    WHEN em.date_received > NOW() THEN 0 
                    ELSE 1 
                END, em.date_received DESC 
                LIMIT ? OFFSET ?
            ");
            $params = [$userId, $userId, $limit, $offset];
            $stmt->execute($params);
            $messages = $stmt->fetchAll();

            $countStmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM echomail em
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                WHERE 1=1{$filterClause}
            ");
            $countParams = [$userId];
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

        // Clean message data for proper JSON encoding
        $cleanMessages = [];
        foreach ($messages as $message) {
            $cleanMessages[] = $this->cleanMessageForJson($message);
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
                SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color 
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                WHERE em.id = ?
            ");
            $stmt->execute([$messageId]);
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

    public function sendNetmail($fromUserId, $toAddress, $toName, $subject, $messageText, $fromName = null)
    {
        $user = $this->getUserById($fromUserId);
        if (!$user) {
            throw new \Exception('User not found');
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

        $stmt = $this->db->prepare("
            INSERT INTO netmail (user_id, from_address, to_address, from_name, to_name, subject, message_text, date_written, is_sent)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), FALSE)
        ");
        
        $result = $stmt->execute([
            $fromUserId,
            $systemAddress,
            $toAddress,
            $senderName,
            $toName,
            $subject,
            $messageText
        ]);

        if ($result) {
            $messageId = $this->db->lastInsertId();
            $this->spoolOutboundNetmail($messageId);
        }

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

        $stmt = $this->db->prepare("
            INSERT INTO echomail (echoarea_id, from_address, from_name, to_name, subject, message_text, date_written, reply_to_id, message_id, origin_line, kludge_lines)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $echoarea['id'],
            $systemAddress,
            $user['real_name'] ?: $user['username'],
            $toName ?: 'All',
            $subject,
            $messageText,
            $replyToId,
            null, // message_id (will be generated when packet is created)
            null, // origin_line (will be added when packet is created) 
            null  // kludge_lines (empty for web-created messages)
        ]);

        if ($result) {
            $messageId = $this->db->lastInsertId();
            $this->incrementEchoareaCount($echoarea['id']);
            $this->spoolOutboundEchomail($messageId, $echoareaTag);
        }

        return $result;
    }

    public function getEchoareas()
    {
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
                WHERE (subject LIKE ? OR message_text LIKE ? OR from_name LIKE ?) 
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
                WHERE (em.subject LIKE ? OR em.message_text LIKE ? OR em.from_name LIKE ?)
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
        
        // Only allow users to delete messages they sent
        if ($message['user_id'] != $userId) {
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
    private function getUserSettings($userId)
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
                INSERT OR IGNORE INTO user_settings (user_id, messages_per_page) 
                VALUES (?, 25)
            ");
            $insertStmt->execute([$userId]);
            
            return ['messages_per_page' => 25];
        }

        return $settings;
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
        
        error_log("MessageHandler::createMessageShare - isPublic binding: " . var_export((bool)$isPublic, true));
        
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
        error_log("Share access check - raw is_public: " . var_export($share['is_public'], true) . ", converted: " . var_export($isPublic, true) . ", requestingUserId: " . var_export($requestingUserId, true));
        
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
}