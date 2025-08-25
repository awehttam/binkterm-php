<?php

namespace Binktest;

class MessageHandler
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    public function getNetmail($userId, $page = 1, $limit = 25, $filter = 'all')
    {
        $user = $this->getUserById($userId);
        if (!$user) {
            return ['messages' => [], 'pagination' => []];
        }

        // Get system's FidoNet address for sent message filtering
        try {
            $binkpConfig = \Binktest\Binkp\Config\BinkpConfig::getInstance();
            $systemAddress = $binkpConfig->getSystemAddress();
        } catch (\Exception $e) {
            $systemAddress = null;
        }

        $offset = ($page - 1) * $limit;
        
        // Build the WHERE clause based on filter
        $whereClause = "WHERE n.user_id = ?";
        $params = [$userId];
        
        if ($filter === 'unread') {
            $whereClause .= " AND mrs.read_at IS NULL";
        } elseif ($filter === 'sent' && $systemAddress) {
            $whereClause = "WHERE n.from_address = ?";
            $params = [$systemAddress];
        }
        
        $stmt = $this->db->prepare("
            SELECT n.*, 
                   CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read
            FROM netmail n
            LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
            $whereClause
            ORDER BY CASE 
                WHEN n.date_written > datetime('now') THEN 0 
                ELSE 1 
            END, n.date_written DESC 
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

        return [
            'messages' => $messages,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ];
    }

    public function getEchomail($echoareaTag = null, $page = 1, $limit = 25, $userId = null)
    {
        $offset = ($page - 1) * $limit;
        
        if ($echoareaTag) {
            $stmt = $this->db->prepare("
                SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                WHERE ea.tag = ?
                ORDER BY CASE 
                    WHEN em.date_written > datetime('now') THEN 0 
                    ELSE 1 
                END, em.date_written DESC 
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$userId, $echoareaTag, $limit, $offset]);
            $messages = $stmt->fetchAll();

            $countStmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                WHERE ea.tag = ?
            ");
            $countStmt->execute([$echoareaTag]);
        } else {
            $stmt = $this->db->prepare("
                SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                ORDER BY CASE 
                    WHEN em.date_written > datetime('now') THEN 0 
                    ELSE 1 
                END, em.date_written DESC 
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$userId, $limit, $offset]);
            $messages = $stmt->fetchAll();

            $countStmt = $this->db->query("SELECT COUNT(*) as total FROM echomail");
        }

        $total = $countStmt->fetch()['total'];

        return [
            'messages' => $messages,
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
            $stmt = $this->db->prepare("SELECT * FROM netmail WHERE id = ?");
        } else {
            $stmt = $this->db->prepare("
                SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color 
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                WHERE em.id = ?
            ");
        }
        
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();

        if ($message && $type === 'netmail') {
            $this->markNetmailAsRead($messageId, $userId);
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
            $binkpConfig = \Binktest\Binkp\Config\BinkpConfig::getInstance();
            $systemAddress = $binkpConfig->getSystemAddress();
        } catch (\Exception $e) {
            throw new \Exception('System FidoNet address not configured');
        }

        $stmt = $this->db->prepare("
            INSERT INTO netmail (user_id, from_address, to_address, from_name, to_name, subject, message_text, date_written, is_sent)
            VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), 0)
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
            $binkpConfig = \Binktest\Binkp\Config\BinkpConfig::getInstance();
            $systemAddress = $binkpConfig->getSystemAddress();
            
            // For echomail from points, keep the FULL point address in the from_address
            // The point routing will be handled by FMPT kludge lines
        } catch (\Exception $e) {
            throw new \Exception('System FidoNet address not configured');
        }

        $stmt = $this->db->prepare("
            INSERT INTO echomail (echoarea_id, from_address, from_name, to_name, subject, message_text, date_written, reply_to_id, message_id, origin_line, kludge_lines)
            VALUES (?, ?, ?, ?, ?, ?, datetime('now'), ?, ?, ?, ?)
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
        $stmt = $this->db->query("SELECT * FROM echoareas WHERE is_active = 1 ORDER BY tag");
        return $stmt->fetchAll();
    }

    public function searchMessages($query, $type = null, $echoarea = null)
    {
        $searchTerm = '%' . $query . '%';
        
        if ($type === 'netmail') {
            $stmt = $this->db->prepare("
                SELECT * FROM netmail 
                WHERE subject LIKE ? OR message_text LIKE ? OR from_name LIKE ?
                ORDER BY CASE 
                    WHEN date_written > datetime('now') THEN 0 
                    ELSE 1 
                END, date_written DESC
                LIMIT 50
            ");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
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
                WHEN em.date_written > datetime('now') THEN 0 
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
        $stmt = $this->db->prepare("SELECT * FROM echoareas WHERE tag = ? AND is_active = 1");
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
                INSERT OR REPLACE INTO message_read_status (user_id, message_id, message_type, read_at)
                VALUES (?, ?, 'netmail', datetime('now'))
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
            $this->db->prepare("UPDATE netmail SET is_sent = 1 WHERE id = ?")
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
        $stmt = $this->db->prepare("SELECT uplink_address FROM echoareas WHERE tag = ? AND is_active = 1");
        $stmt->execute([$echoareaTag]);
        $result = $stmt->fetch();
        
        if ($result && $result['uplink_address']) {
            return $result['uplink_address'];
        }
        
        // Fall back to default uplink from JSON config
        try {
            $config = \Binktest\Binkp\Config\BinkpConfig::getInstance();
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
        error_log("MessageHandler::deleteEchomail called with messageIds: " . print_r($messageIds, true) . ", userId: $userId");
        
        // Validate input
        if (empty($messageIds) || !is_array($messageIds)) {
            error_log("MessageHandler::deleteEchomail - Invalid input");
            return ['success' => false, 'error' => 'No messages selected'];
        }

        // Get user info for permission checking
        $user = $this->getUserById($userId);
        error_log("MessageHandler::deleteEchomail - Retrieved user: " . print_r($user, true));
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
}