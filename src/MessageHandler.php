<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 * 
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the 
 * following conditions are met:
 * 
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 * 
 */


namespace BinktermPHP;

class MessageHandler
{
    // Configuration: which date field to use for echomail sorting
    // Options: 'date_received' or 'date_written'
    private const ECHOMAIL_DATE_FIELD = 'date_received';    // Related to USE_DATE_FIELD in echomail.js

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

        // Get system's configured FidoNet addresses
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $systemAddress = $binkpConfig->getSystemAddress();
            $myAddresses = $binkpConfig->getMyAddresses();
            $myAddresses[] = $systemAddress; // Include main system address
        } catch (\Exception $e) {
            $systemAddress = null;
            $myAddresses = [];
        }

        $offset = ($page - 1) * $limit;

        // Build the WHERE clause based on filter
        // Show messages where user is sender OR recipient (must match name AND to_address must be one of our addresses)
        if (!empty($myAddresses)) {
            $addressPlaceholders = implode(',', array_fill(0, count($myAddresses), '?'));
            $whereClause = "WHERE (n.user_id = ? OR ((LOWER(n.to_name) = LOWER(?) OR LOWER(n.to_name) = LOWER(?)) AND n.to_address IN ($addressPlaceholders)))";
            $params = [$userId, $user['username'], $user['real_name']];
            $params = array_merge($params, $myAddresses);
        } else {
            // Fallback if no addresses configured - only show sent messages
            $whereClause = "WHERE n.user_id = ?";
            $params = [$userId];
        }

        if ($filter === 'unread') {
            // Show only unread messages TO this user (not FROM this user)
            $whereClause .= " AND mrs.read_at IS NULL AND LOWER(n.from_name) != LOWER(?) AND LOWER(n.from_name) != LOWER(?)";
            $params[] = $user['username'];
            $params[] = $user['real_name'];
        } elseif ($filter === 'sent') {
            // Show only messages sent by this user (check from_name since user_id is unreliable for received messages)
            $whereClause = "WHERE (LOWER(n.from_name) = LOWER(?) OR LOWER(n.from_name) = LOWER(?))";
            $params = [$user['username'], $user['real_name']];
        } elseif ($filter === 'received' && !empty($myAddresses)) {
            // Show only messages received by this user (must match name AND to_address must be one of our addresses)
            $whereClause = "WHERE (LOWER(n.to_name) = LOWER(?) OR LOWER(n.to_name) = LOWER(?)) AND n.to_address IN ($addressPlaceholders) AND n.user_id != ?";
            $params = [$user['username'], $user['real_name']];
            $params = array_merge($params, $myAddresses, [$userId]);
        }

        $stmt = $this->db->prepare("
            SELECT n.id, n.from_name, n.from_address, n.to_name, n.to_address,
                   n.subject, n.date_received, n.user_id, n.date_written,
                   n.attributes, n.is_sent, n.reply_to_id,
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

            // Add domain information for netmail from/to addresses
            try {
                $binkpCfg = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
                if (!empty($cleanMessage['from_address'])) {
                    $cleanMessage['from_domain'] = $binkpCfg->getDomainByAddress($cleanMessage['from_address']) ?: null;
                }
                if (!empty($cleanMessage['to_address'])) {
                    $cleanMessage['to_domain'] = $binkpCfg->getDomainByAddress($cleanMessage['to_address']) ?: null;
                }
            } catch (\Exception $e) {
                // Ignore errors, domains are optional
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

    public function getEchomail($echoareaTag = null, $domain, $page = 1, $limit = null, $userId = null, $filter = 'all', $threaded = false, $checkSubscriptions = true)
    {
        // Check subscription access if user is specified and subscription checking is enabled
        if ($userId && $checkSubscriptions && $echoareaTag) {
            $subscriptionManager = new EchoareaSubscriptionManager();
            
            // Get echoarea ID from tag
            $stmt = $this->db->prepare("SELECT id FROM echoareas WHERE tag = ? AND domain=? AND is_active = TRUE");
            $stmt->execute([$echoareaTag, $domain]);
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
            return $this->getThreadedEchomail($echoareaTag, $domain, $page, $limit, $userId, $filter);
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
        $dateField = self::ECHOMAIL_DATE_FIELD;

        if ($echoareaTag) {
            $stmt = $this->db->prepare("
                SELECT em.id, em.from_name, em.from_address, em.to_name,
                       em.subject, em.date_received, em.date_written, em.echoarea_id,
                       em.message_id, em.reply_to_id,
                       ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE ea.tag = ?{$filterClause} AND ea.domain=?
                ORDER BY CASE
                    WHEN em.{$dateField} > NOW() THEN 0
                    ELSE 1
                END, em.{$dateField} DESC
                LIMIT ? OFFSET ?
            ");
            $params = [$userId, $userId, $userId, $echoareaTag];
            foreach ($filterParams as $param) {
                $params[] = $param;
            }
            $params[] = $domain;
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $messages = $stmt->fetchAll();

            $countStmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE ea.tag = ?{$filterClause} AND ea.domain=?
            ");
            $countParams = [$userId, $userId, $echoareaTag];
            foreach ($filterParams as $param) {
                $countParams[] = $param;
            }
            $countParams[] = $domain;
            $countStmt->execute($countParams);
        } else {
            $stmt = $this->db->prepare("
                SELECT em.id, em.from_name, em.from_address, em.to_name,
                       em.subject, em.date_received, em.date_written, em.echoarea_id,
                       em.message_id, em.reply_to_id,
                       ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE 1=1{$filterClause}
                ORDER BY CASE WHEN em.{$dateField} > NOW() THEN 0 ELSE 1 END, em.{$dateField} DESC
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
                    WHERE ea.tag = ? AND mrs.read_at IS NULL AND ea.domain=?
                ");
                $unreadCountStmt->execute([$userId, $echoareaTag, $domain]);
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
            $cleanMessage = $this->cleanMessageForJson($message);
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

        $dateField = self::ECHOMAIL_DATE_FIELD;
        $stmt = $this->db->prepare("
            SELECT em.id, em.from_name, em.from_address, em.to_name,
                   em.subject, em.date_received, em.date_written, em.echoarea_id,
                   em.message_id, em.reply_to_id,
                   ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                   CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                   CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                   CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
            FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
            LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
            LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
            WHERE ea.id IN ($placeholders) AND ea.is_active = TRUE{$filterClause}
            ORDER BY CASE WHEN em.{$dateField} > NOW() THEN 0 ELSE 1 END, em.{$dateField} DESC
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
        $user = null;
        $isAdmin = false;
        if ($userId) {
            $user = $this->getUserById($userId);
            $isAdmin = $user && !empty($user['is_admin']);
        }

        if ($type === 'netmail') {
            // For netmail, user can access messages they sent OR received (must match name AND to_address must be one of our addresses)
            if (!$user) {
                return null;
            }

            // Get system's configured FidoNet addresses
            try {
                $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
                $myAddresses = $binkpConfig->getMyAddresses();
                $myAddresses[] = $binkpConfig->getSystemAddress();
            } catch (\Exception $e) {
                $myAddresses = [];
            }

            if (!empty($myAddresses)) {
                $addressPlaceholders = implode(',', array_fill(0, count($myAddresses), '?'));
                $stmt = $this->db->prepare("
                    SELECT * FROM netmail
                    WHERE id = ? AND (user_id = ? OR ((LOWER(to_name) = LOWER(?) OR LOWER(to_name) = LOWER(?)) AND to_address IN ($addressPlaceholders)))
                ");
                $params = [$messageId, $userId, $user['username'], $user['real_name']];
                $params = array_merge($params, $myAddresses);
                $stmt->execute($params);
            } else {
                // Fallback if no addresses configured - only show sent messages
                $stmt = $this->db->prepare("
                    SELECT * FROM netmail WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$messageId, $userId]);
            }
        } else {
            // Echomail is public, so no user restriction needed
            $stmt = $this->db->prepare("
                SELECT em.*, ea.tag as echoarea, ea.domain as domain, ea.color as echoarea_color, ea.is_sysop_only as is_sysop_only,
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
            if ($type === 'echomail' && !empty($message['is_sysop_only']) && !$isAdmin) {
                return null;
            }

            if ($type === 'netmail') {
                $this->markNetmailAsRead($messageId, $userId);
            } elseif ($type === 'echomail') {
                $this->markEchomailAsRead($messageId, $userId);
            }

            // Clean message for JSON encoding
            $message = $this->cleanMessageForJson($message);

            // Add domain information for netmail messages
            if ($type === 'netmail') {
                try {
                    $binkpCfg = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
                    if (!empty($message['from_address'])) {
                        $message['from_domain'] = $binkpCfg->getDomainByAddress($message['from_address']) ?: null;
                    }
                    if (!empty($message['to_address'])) {
                        $message['to_domain'] = $binkpCfg->getDomainByAddress($message['to_address']) ?: null;
                    }
                } catch (\Exception $e) {
                    // Ignore errors, domains are optional
                }
            }
        }

        return $message;
    }

    /** Records a netmail message to the database and queues it for delivery if toAddress is not blank.
     * @param $fromUserId
     * @param $toAddress
     * @param $toName
     * @param $subject
     * @param $messageText
     * @param $fromName
     * @param $replyToId
     * @param $crashmail
     * @return bool
     * @throws \Exception
     */
    public function sendNetmail($fromUserId, $toAddress, $toName, $subject, $messageText, $fromName = null, $replyToId = null, $crashmail = false, $tagline = null)
    {
        $user = $this->getUserById($fromUserId);
        if (!$user) {
            throw new \Exception('User not found');
        }

        $creditsRules = $this->getCreditsRules();
        if ($creditsRules['enabled']) {
            $totalCost = $creditsRules['netmail_cost'];
            if ($crashmail && $creditsRules['crashmail_cost'] > 0) {
                $totalCost += $creditsRules['crashmail_cost'];
            }

            if ($totalCost > 0) {
                $balance = UserCredit::getBalance($fromUserId);
                if ($balance < $totalCost) {
                    throw new \Exception('Insufficient credits to send ' . ($crashmail ? 'crashmail' : 'netmail') . '.');
                }
            }
        }

        $messageText = $this->applyUserSignatureAndTagline($messageText, $fromUserId, $tagline ?? null);

        // Special case: if sending to "sysop" at our local system, route to local sysop user
        if (!empty($toName) && strtolower($toName) === 'sysop') {
            try {
                $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
                // Only route locally if destination is one of our addresses (or no address specified)
                if (empty($toAddress) || $binkpConfig->isMyAddress($toAddress)) {
                    return $this->sendLocalSysopMessage($fromUserId, $subject, $messageText, $fromName, $replyToId, $tagline ?? null);
                }
            } catch (\Exception $e) {
                // If we can't get config, fall through to normal send
            }
        }

        // Use provided fromName or fall back to user's real name
        $senderName = $fromName ?: ($user['real_name'] ?: $user['username']);

        // Get the appropriate origin address for the destination network
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();

            // Use the "me" address from the uplink that will route this message
            $originAddress = $binkpConfig->getOriginAddressByDestination($toAddress);

            if (!$originAddress) {
                // No uplink can route to this destination - fall back to default
                $originAddress = $binkpConfig->getSystemAddress();
                error_log("[NETMAIL] WARNING: No specific uplink for destination {$toAddress}, using system address");
            }
        } catch (\Exception $e) {
            throw new \Exception('Cannot determine origin address: ' . $e->getMessage());
        }

        // Verify outbound directory is writable before accepting the message
        // (only needed if message will be spooled, i.e., not local delivery)
        if ($toAddress !== $originAddress) {
            $outboundPath = $binkpConfig->getOutboundPath();
            if (!is_dir($outboundPath) || !is_writable($outboundPath)) {
                error_log("[NETMAIL] ERROR: Outbound directory not writable: {$outboundPath}");
                throw new \Exception('Message delivery system unavailable. Please try again later.');
            }
        }

        // For crashmail, verify destination can be resolved via nodelist before accepting
        if ($crashmail && $toAddress !== $originAddress) {
            $crashmailService = new \BinktermPHP\Crashmail\CrashmailService();
            $routeInfo = $crashmailService->resolveDestination($toAddress);

            if (empty($routeInfo['hostname'])) {
                error_log("[NETMAIL] Crashmail destination not resolvable: {$toAddress}");
                throw new \Exception("Cannot send crashmail to {$toAddress}: destination not found in nodelist. The node may not have an internet address listed, or may not exist. Try sending without crashmail to route via your hub.");
            }
        }

        // Generate MSGID for storage (address + hash format)
        $msgIdHash = $this->generateMessageId($senderName, $toName, $subject, $originAddress);
        $msgId = $originAddress . ' ' . $msgIdHash;

        // Generate kludges for this netmail
        $kludgeLines = $this->generateNetmailKludges($originAddress, $toAddress, $senderName, $toName, $subject, $replyToId);

        $stmt = $this->db->prepare("
            INSERT INTO netmail (user_id, from_address, to_address, from_name, to_name, subject, message_text, date_written, is_sent, reply_to_id, message_id, kludge_lines)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), FALSE, ?, ?, ?)
        ");

        $result = $stmt->execute([
            $fromUserId,
            $originAddress,
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

            if ($creditsRules['enabled'] && $creditsRules['netmail_cost'] > 0) {
                $charged = UserCredit::debit(
                    $fromUserId,
                    (int)$creditsRules['netmail_cost'],
                    'Netmail sent',
                    null,
                    UserCredit::TYPE_PAYMENT
                );
            }

            if ($creditsRules['enabled'] && $crashmail && $creditsRules['crashmail_cost'] > 0) {
                $charged = UserCredit::debit(
                    $fromUserId,
                    (int)$creditsRules['crashmail_cost'],
                    'Crashmail priority delivery',
                    null,
                    UserCredit::TYPE_PAYMENT
                );
            }

            if($toAddress!=$originAddress) {
                if ($crashmail) {
                    // Crashmail: queue for direct delivery only, skip normal hub routing
                    try {
                        $crashmailService = new \BinktermPHP\Crashmail\CrashmailService();
                        $crashmailService->queueCrashmail($messageId);
                    } catch (\Exception $e) {
                        // If crashmail queue fails, fall back to normal spooling
                        error_log("[NETMAIL] Crashmail queue failed, falling back to normal delivery: " . $e->getMessage());
                        $this->spoolOutboundNetmail($messageId);
                    }
                } else {
                    // Normal delivery via hub routing
                    $this->spoolOutboundNetmail($messageId);
                }
            }
        }

        return $result;
    }

    private function sendLocalSysopMessage($fromUserId, $subject, $messageText, $fromName = null, $replyToId = null, $tagline = null)
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
            $this->applyUserSignatureAndTagline($messageText, $fromUserId, $tagline),
            $replyToId,
            $msgId,
            $kludgeLines
        ]);

        if ($result) {
            $messageId = $this->db->lastInsertId();
            $creditsRules = $this->getCreditsRules();
            if ($creditsRules['enabled'] && $creditsRules['netmail_cost'] > 0) {
                $charged = UserCredit::debit(
                    $fromUserId,
                    (int)$creditsRules['netmail_cost'],
                    'Netmail sent',
                    null,
                    UserCredit::TYPE_PAYMENT
                );
            }
        }

        return $result;
    }

    public function postEchomail($fromUserId, $echoareaTag, $domain, $toName, $subject, $messageText, $replyToId = null, $tagline = null)
    {
        $user = $this->getUserById($fromUserId);
        if (!$user) {
            throw new \Exception('User not found');
        }

        $echoarea = $this->getEchoareaByTag($echoareaTag, $domain);
        if (!$echoarea) {
            throw new \Exception('Echo area not found');
        }

        $isLocalArea = !empty($echoarea['is_local']);
        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();

        // Determine the from address
        $myAddress = $binkpConfig->getMyAddressByDomain($domain);
        if (!$myAddress) {
            if ($isLocalArea) {
                // For local echoareas, use system address as fallback
                $myAddress = $binkpConfig->getSystemAddress();
            } else {
                throw new \Exception('Can not determine sending address for this network - missing uplink?');
            }
        }

        // Verify outbound directory is writable (only needed for non-local areas)
        if (!$isLocalArea) {
            $outboundPath = $binkpConfig->getOutboundPath();
            if (!is_dir($outboundPath) || !is_writable($outboundPath)) {
                error_log("[ECHOMAIL] ERROR: Outbound directory not writable: {$outboundPath}");
                throw new \Exception('Message delivery system unavailable. Please try again later.');
            }
        }

        // Generate kludges for this echomail
        $fromName = $user['real_name'] ?: $user['username'];
        $toName = $toName ?: 'All';
        $kludgeLines = $this->generateEchomailKludges($myAddress, $fromName, $toName, $subject, $echoareaTag, $replyToId);
        $msgId = $myAddress . ' ' . $this->generateMessageId($fromName, $toName, $subject, $myAddress);
        
        $stmt = $this->db->prepare("
            INSERT INTO echomail (echoarea_id, from_address, from_name, to_name, subject, message_text, date_written, reply_to_id, message_id, origin_line, kludge_lines)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $echoarea['id'],
            $myAddress,
            $fromName,
            $toName,
            $subject,
            $this->applyUserSignatureAndTagline($messageText, $fromUserId, $tagline),
            $replyToId,
            $msgId,
            null, // origin_line (will be added when packet is created) 
            $kludgeLines  // Store generated kludges
        ]);

        if ($result) {
            $messageId = $this->db->lastInsertId();
            $creditsRules = $this->getCreditsRules();
            if ($creditsRules['enabled'] && $creditsRules['echomail_reward'] > 0) {
                // Award 2x credits for longer messages (over 1200 characters)
                $messageLength = strlen($messageText);
                $rewardAmount = $messageLength > 1200
                    ? (int)$creditsRules['echomail_reward'] * 2
                    : (int)$creditsRules['echomail_reward'];

                $rewarded = UserCredit::credit(
                    $fromUserId,
                    $rewardAmount,
                    'Echomail posted',
                    null,
                    UserCredit::TYPE_SYSTEM_REWARD
                );
                if (!$rewarded) {
                    error_log('[CREDITS] Echomail reward failed.');
                }
            }
            $this->incrementEchoareaCount($echoarea['id']);
            $this->spoolOutboundEchomail($messageId, $echoareaTag, $domain);
        }

        return $result;
    }

    private function applyUserSignatureAndTagline(string $messageText, int $userId, ?string $tagline = null): string
    {
        $body = rtrim($messageText, "\r\n");
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];

        $taglineText = trim((string)($tagline ?? ''));
        if ($taglineText !== '') {
            $taglineText = str_replace(["\r\n", "\r", "\n"], ' ', $taglineText);
            if (strpos($taglineText, '... ') !== 0) {
                $taglineText = '... ' . $taglineText;
            }
            $lastLine = '';
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if (trim((string)$lines[$i]) !== '') {
                    $lastLine = trim((string)$lines[$i]);
                    break;
                }
            }
            if ($lastLine !== $taglineText) {
                if ($body !== '') {
                    $body = rtrim($body, "\r\n") . "\n\n" . $taglineText;
                } else {
                    $body = $taglineText;
                }
            }
        }

        return $body;
    }

    private function getCreditsRules(): array
    {
        $credits = BbsConfig::getConfig()['credits'] ?? [];

        return [
            'enabled' => !empty($credits['enabled']),
            'netmail_cost' => max(0, (int)($credits['netmail_cost'] ?? 1)),
            'echomail_reward' => max(0, (int)($credits['echomail_reward'] ?? 3)),
            'crashmail_cost' => max(0, (int)($credits['crashmail_cost'] ?? 10))
        ];
    }

    public function getEchoareas($userId = null, $subscribedOnly = false)
    {
        if ($userId && $subscribedOnly) {
            // Get only echoareas the user is subscribed to
            $subscriptionManager = new EchoareaSubscriptionManager();
            return $subscriptionManager->getUserSubscribedEchoareas($userId);
        }

        if ($userId) {
            $user = $this->getUserById($userId);
            $isAdmin = $user && !empty($user['is_admin']);
            if (!$isAdmin) {
                $stmt = $this->db->prepare("
                    SELECT * FROM echoareas
                    WHERE is_active = TRUE AND COALESCE(is_sysop_only, FALSE) = FALSE
                    ORDER BY
                        CASE
                            WHEN COALESCE(is_local, FALSE) = TRUE THEN 0
                            WHEN LOWER(domain) = 'lovlynet' THEN 1
                            ELSE 2
                        END,
                        tag
                ");
                $stmt->execute();
                return $stmt->fetchAll();
            }
        }

        $stmt = $this->db->query("
            SELECT * FROM echoareas
            WHERE is_active = TRUE
            ORDER BY
                CASE
                    WHEN COALESCE(is_local, FALSE) = TRUE THEN 0
                    WHEN LOWER(domain) = 'lovlynet' THEN 1
                    ELSE 2
                END,
                tag
        ");
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
            $dateField = self::ECHOMAIL_DATE_FIELD;
            $isAdmin = false;
            if ($userId) {
                $user = $this->getUserById($userId);
                $isAdmin = $user && !empty($user['is_admin']);
            }
            $sql = "
                SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                WHERE (em.subject ILIKE ? OR em.message_text ILIKE ? OR em.from_name ILIKE ?)
            ";

            $params = [$searchTerm, $searchTerm, $searchTerm];
            if (!$isAdmin) {
                $sql .= " AND COALESCE(ea.is_sysop_only, FALSE) = FALSE";
            }

            if ($echoarea) {
                $sql .= " AND ea.tag = ?";
                $params[] = $echoarea;
            }

            $sql .= " ORDER BY CASE WHEN em.{$dateField} > NOW() THEN 0 ELSE 1 END, em.{$dateField} DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }

        return $stmt->fetchAll();
    }

    /**
     * Get filter counts for search results (all, unread, read, tome, saved, drafts)
     *
     * @param string $query The search query
     * @param string|null $echoarea Optional specific echo area to search within
     * @param int|null $userId User ID for permission checking
     * @return array Array with filter counts
     */
    public function getSearchFilterCounts($query, $echoarea = null, $userId = null)
    {
        $searchTerm = '%' . $query . '%';

        $isAdmin = false;
        $userRealName = null;
        if ($userId) {
            $user = $this->getUserById($userId);
            $isAdmin = $user && !empty($user['is_admin']);
            $userRealName = $user['real_name'] ?? null;
        }

        $sql = "
            SELECT
                COUNT(*) as all_count,
                COUNT(*) FILTER (WHERE mr.id IS NULL) as unread_count,
                COUNT(*) FILTER (WHERE mr.id IS NOT NULL) as read_count,
                COUNT(*) FILTER (WHERE em.to_name = ?) as tome_count,
                COUNT(*) FILTER (WHERE sm.message_id IS NOT NULL) as saved_count
            FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            LEFT JOIN message_read_status mr ON mr.message_id = em.id
                AND mr.message_type = 'echomail'
                AND mr.user_id = ?
            LEFT JOIN saved_messages sm ON sm.message_id = em.id
                AND sm.message_type = 'echomail'
                AND sm.user_id = ?
            WHERE (em.subject ILIKE ? OR em.message_text ILIKE ? OR em.from_name ILIKE ?)
                AND ea.is_active = TRUE
        ";

        $params = [$userRealName, $userId, $userId, $searchTerm, $searchTerm, $searchTerm];

        if (!$isAdmin) {
            $sql .= " AND COALESCE(ea.is_sysop_only, FALSE) = FALSE";
        }

        if ($echoarea) {
            $sql .= " AND ea.tag = ?";
            $params[] = $echoarea;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        // Drafts are not included in echomail search
        return [
            'all' => (int)$result['all_count'],
            'unread' => (int)$result['unread_count'],
            'read' => (int)$result['read_count'],
            'tome' => (int)$result['tome_count'],
            'saved' => (int)$result['saved_count'],
            'drafts' => 0
        ];
    }

    /**
     * Get per-echo-area message counts for search results
     *
     * @param string $query The search query
     * @param string|null $echoarea Optional specific echo area to search within
     * @param int|null $userId User ID for permission checking
     * @return array Array of echo areas with their search result counts
     */
    public function getSearchResultCounts($query, $echoarea = null, $userId = null)
    {
        $searchTerm = '%' . $query . '%';

        $isAdmin = false;
        if ($userId) {
            $user = $this->getUserById($userId);
            $isAdmin = $user && !empty($user['is_admin']);
        }

        $sql = "
            SELECT
                ea.id,
                ea.tag,
                ea.domain,
                COUNT(em.id) as message_count
            FROM echoareas ea
            LEFT JOIN echomail em ON em.echoarea_id = ea.id
                AND (em.subject ILIKE ? OR em.message_text ILIKE ? OR em.from_name ILIKE ?)
            WHERE ea.is_active = TRUE
        ";

        $params = [$searchTerm, $searchTerm, $searchTerm];

        if (!$isAdmin) {
            $sql .= " AND COALESCE(ea.is_sysop_only, FALSE) = FALSE";
        }

        if ($echoarea) {
            $sql .= " AND ea.tag = ?";
            $params[] = $echoarea;
        }

        $sql .= " GROUP BY ea.id, ea.tag, ea.domain ORDER BY ea.tag";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function getUserById($userId)
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }

    private function getEchoareaByTag($tag, $domain)
    {
        $stmt = $this->db->prepare("SELECT * FROM echoareas WHERE tag = ? AND domain = ? AND is_active = TRUE");
        $stmt->execute([$tag, $domain]);
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

        // Extract message details for logging
        $fromName = $message['from_name'] ?? 'unknown';
        $fromAddr = $message['from_address'] ?? 'unknown';
        $toName = $message['to_name'] ?? 'unknown';
        $toAddr = $message['to_address'] ?? 'unknown';
        $subject = $message['subject'] ?? '(no subject)';

        error_log("[SPOOL] Spooling netmail #{$messageId}: from=\"{$fromName}\" <{$fromAddr}> to=\"{$toName}\" <{$toAddr}>, subject=\"{$subject}\"");

        try {
            $binkdProcessor = new BinkdProcessor();

            // Set netmail attributes (private flag)
            $message['attributes'] = 0x0001;

            // Get the uplink that handles routing for this destination
            // The packet must be addressed to the hub/uplink, not the final destination
            // The final destination is preserved in the message headers and INTL kludge
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $uplink = $binkpConfig->getUplinkForDestination($toAddr);

            if ($uplink) {
                $routeAddress = $uplink['address'];
                error_log("[SPOOL] Routing netmail through uplink {$routeAddress} for destination {$toAddr}");
            } else {
                // No uplink found - try direct delivery (for local or crash mail)
                $routeAddress = $toAddr;
                error_log("[SPOOL] No uplink found for {$toAddr}, attempting direct delivery");
            }

            // Create outbound packet routed through the uplink (or direct if no uplink)
            $packetFile = $binkdProcessor->createOutboundPacket([$message], $routeAddress);
            $packetName = basename($packetFile);

            // Mark message as sent
            $this->db->prepare("UPDATE netmail SET is_sent = TRUE WHERE id = ?")
                     ->execute([$messageId]);

            error_log("[SPOOL] Netmail #{$messageId} spooled to packet {$packetName} (routed via {$routeAddress})");
            return true;
        } catch (\Exception $e) {
            // Log error but don't fail the message creation
            error_log("[SPOOL] Failed to spool netmail #{$messageId} (from=\"{$fromName}\" subject=\"{$subject}\"): " . $e->getMessage());
            return false;
        }
    }

    private function spoolOutboundEchomail($messageId, $echoareaTag, $domain)
    {
        $stmt = $this->db->prepare("
            SELECT em.*, ea.tag as echoarea_tag, ea.domain as echoarea_domain, ea.is_local
            FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            WHERE em.id = ?
        ");
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();

        if (!$message) {
            return false;
        }

        // Check if this is a local-only echoarea
        if (!empty($message['is_local'])) {
            error_log("[SPOOL] Echomail #{$messageId} in local-only area {$echoareaTag} - not spooling to uplink");
            return true; // Success - message stored locally, no upstream transmission needed
        }

        // Extract message details for logging
        $fromName = $message['from_name'] ?? 'unknown';
        $fromAddr = $message['from_address'] ?? 'unknown';
        $subject = $message['subject'] ?? '(no subject)';
        $areaTag = $message['echoarea_tag'] ?? $echoareaTag;

        error_log("[SPOOL] Spooling echomail #{$messageId}: area={$areaTag}, from=\"{$fromName}\" <{$fromAddr}>, subject=\"{$subject}\"");

        try {
            $binkdProcessor = new BinkdProcessor();

            // Set echomail attributes (no private flag)
            $message['attributes'] = 0x0000;

            // Mark as echomail for proper packet formatting
            $message['is_echomail'] = true;
            // Keep echoarea_tag available for kludge line generation
            $message['echoarea_tag'] = $message['echoarea_tag'];

            // For echomail, we typically send to our uplink
            $uplinkAddress = $this->getEchoareaUplink($message['echoarea_tag'], $domain);

            if ($uplinkAddress) {
                $message['to_address'] = $uplinkAddress;
                $packetFile = $binkdProcessor->createOutboundPacket([$message], $uplinkAddress);
                $packetName = basename($packetFile);
                error_log("[SPOOL] Echomail #{$messageId} spooled to packet {$packetName} for uplink {$uplinkAddress}");
            } else {
                error_log("[SPOOL] WARNING: No uplink address configured for echoarea {$areaTag} - message #{$messageId} not spooled");
                return false;
            }

            return true;
        } catch (\Exception $e) {
            // Log error but don't fail the message creation
            error_log("[SPOOL] Failed to spool echomail #{$messageId} (area={$areaTag}, from=\"{$fromName}\", subject=\"{$subject}\"): " . $e->getMessage());
            return false;
        }
    }

    /** Returns an active uplink address for a given echoarea tag and domain.  First choice is uplink in echoarea table, then to binkp.json configuration.
     * @param $echoareaTag - the tag, eg: LOCALTEST
     * @param $domain - the domain, eg: fidonet
     * @return false|mixed|string
     */
    private function getEchoareaUplink($echoareaTag, $domain='')
    {
        $stmt = $this->db->prepare("SELECT uplink_address FROM echoareas WHERE tag = ? AND domain=? AND is_active = TRUE");
        $stmt->execute([$echoareaTag, $domain]);
        $result = $stmt->fetch();
        
        if ($result && $result['uplink_address']) {
            return $result['uplink_address'];
        }

        if($domain) {
            // Fall back to default uplink from JSON config
            try {
                $config = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
                $defaultAddress = $config->getUplinkAddressForDomain($domain);
                if ($defaultAddress) {
                    return $defaultAddress;
                }
            } catch (\Exception $e) {
                // Log error but continue with hardcoded fallback
                error_log("Failed to get default uplink for domain " . $e->getMessage());
            }
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
        
        // Ultimate fallback if config fails (was '1:123/1';
        return false;
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
                INSERT INTO user_settings (user_id, messages_per_page, threaded_view, netmail_threaded_view, default_sort, font_family, font_size, date_format, default_tagline)
                VALUES (?, 25, FALSE, FALSE, 'date_desc', 'Courier New, Monaco, Consolas, monospace', 16, 'en-US', NULL)
                ON CONFLICT (user_id) DO UPDATE SET
                    messages_per_page = COALESCE(user_settings.messages_per_page, 25),
                    threaded_view = COALESCE(user_settings.threaded_view, FALSE),
                    netmail_threaded_view = COALESCE(user_settings.netmail_threaded_view, FALSE),
                    default_sort = COALESCE(user_settings.default_sort, 'date_desc'),
                    font_family = COALESCE(user_settings.font_family, 'Courier New, Monaco, Consolas, monospace'),
                    font_size = COALESCE(user_settings.font_size, 16),
                    date_format = COALESCE(user_settings.date_format, 'en-US'),
                    default_tagline = COALESCE(user_settings.default_tagline, NULL)
            ");
            $insertStmt->execute([$userId]);

            return [
                'messages_per_page' => 25,
                'threaded_view' => false,
                'netmail_threaded_view' => false,
                'default_sort' => 'date_desc',
                'font_family' => 'Courier New, Monaco, Consolas, monospace',
                'font_size' => 16,
                'date_format' => 'en-US',
                'signature_text' => '',
                'default_tagline' => ''
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
            'default_echo_list' => 'STRING',
            'show_origin' => 'BOOLEAN',
            'show_tearline' => 'BOOLEAN',
            'auto_refresh' => 'BOOLEAN',
            'quote_coloring' => 'BOOLEAN',
            'date_format' => 'STRING',
            'signature_text' => 'SIGNATURE',
            'default_tagline' => 'TAGLINE'
        ];

        $updates = [];
        $params = [];
        $taglines = null;

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
                case 'SIGNATURE':
                    $signature = str_replace(["\r\n", "\r"], "\n", (string)$value);
                    $lines = preg_split('/\n/', $signature) ?: [];
                    $lines = array_slice($lines, 0, 4);
                    $lines = array_map('rtrim', $lines);
                    $params[] = implode("\n", $lines);
                    break;
                case 'TAGLINE':
                    $tagline = trim((string)$value);
                    $tagline = str_replace(["\r\n", "\r", "\n"], ' ', $tagline);
                    if ($tagline === '') {
                        $params[] = null;
                        break;
                    }
                    if ($taglines === null) {
                        $taglines = $this->getTaglinesList();
                    }
                    $params[] = in_array($tagline, $taglines, true) ? $tagline : null;
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
     * Load configured taglines from disk.
     *
     * @return array
     */
    private function getTaglinesList(): array
    {
        $path = __DIR__ . '/../config/taglines.txt';
        if (!file_exists($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $taglines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $taglines[] = $trimmed;
        }
        return $taglines;
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

        // Find sysop user ID by real name (admin only), fallback to first admin
        $adminUserId = 1;
        if (!empty($sysopName)) {
            $sysopStmt = $this->db->prepare("
                SELECT id
                FROM users
                WHERE is_admin = TRUE AND LOWER(real_name) = LOWER(?)
                ORDER BY id
                LIMIT 1
            ");
            $sysopStmt->execute([$sysopName]);
            $sysopUser = $sysopStmt->fetch();
            if ($sysopUser && !empty($sysopUser['id'])) {
                $adminUserId = $sysopUser['id'];
            }
        }

        if ($adminUserId === 1) {
            $adminStmt = $this->db->prepare("SELECT id FROM users WHERE is_admin = TRUE ORDER BY id LIMIT 1");
            $adminStmt->execute();
            $admin = $adminStmt->fetch();
            $adminUserId = $admin ? $admin['id'] : 1;
        }

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
                INSERT INTO users (username, password_hash, email, real_name, location, created_at, is_active)
                VALUES (?, ?, ?, ?, ?, NOW(), TRUE)
            ");

            $userStmt->execute([
                $pendingUser['username'],
                $pendingUser['password_hash'],
                $pendingUser['email'],
                $pendingUser['real_name'],
                $pendingUser['location'] ?? null
            ]);
            
            $newUserId = $this->db->lastInsertId();
            
            // Create default user settings
            $settingsStmt = $this->db->prepare("
                INSERT INTO user_settings (user_id, messages_per_page)
                VALUES (?, 25)
            ");
            $settingsStmt->execute([$newUserId]);

            // Create default echoarea subscriptions
            $subscriptionManager = new EchoareaSubscriptionManager();
            $subscriptionManager->createDefaultSubscriptions($newUserId);

            // Remove the pending user record since they're now a real user
            $deleteStmt = $this->db->prepare("DELETE FROM pending_users WHERE id = ?");
            $deleteStmt->execute([$pendingUserId]);

            $this->db->commit();
            
            // Send welcome netmail to new user
            $this->sendWelcomeMessage($newUserId, $pendingUser['username'], $pendingUser['real_name']);

            try {
                $credits = BbsConfig::getConfig()['credits'] ?? [];
                $approvalBonus = isset($credits['approval_bonus']) ? (int)$credits['approval_bonus'] : 1000;
                if ($approvalBonus > 0) {
                    UserCredit::transact(
                        (int)$newUserId,
                        $approvalBonus,
                        'New user approval bonus',
                        null,
                        UserCredit::TYPE_SYSTEM_REWARD
                    );
                }
            } catch (\Throwable $e) {
                error_log('[CREDITS] Failed to grant approval bonus: ' . $e->getMessage());
            }
            
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
        $defaultMessage .= "Welcome to the community!";
        
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
                SELECT em.*, ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain
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
        return \BinktermPHP\Config::getSiteUrl() . '/shared/' . $shareKey;
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
    private function generateNetmailKludges($fromAddress, $toAddress, $fromName, $toName, $subject, $replyToId = null, $replyToAddress = null)
    {
        $kludgeLines = [];
        
        // Add CHRS kludge for UTF-8 encoding
        $kludgeLines[] = "\x01CHRS: UTF-8 4";

        // Add TZUTC kludge line for netmail
        $tzutc = \generateTzutc();
        $kludgeLines[] = "\x01TZUTC: {$tzutc}";

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
        
        // Add reply address information - REPLYADDR is always the sender
        $kludgeLines[] = "\x01REPLYADDR {$fromAddress}";

        // Add REPLYTO only if reply-to address is different from sender
        if (!empty($replyToAddress) && $replyToAddress !== $fromAddress) {
            $kludgeLines[] = "\x01REPLYTO {$replyToAddress}";
        }
        
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

        // Add CHRS kludge for UTF-8 encoding
        $kludgeLines[] = "\x01CHRS: UTF-8 4 ";

        // Add TZUTC kludge line for echomail
        $tzutc = \generateTzutc();
        $kludgeLines[] = "\x01TZUTC: {$tzutc}";

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
        $messagesById = [];
        $missingParentIds = [];

        // Index existing messages and collect missing parent IDs
        foreach ($messages as $message) {
            $messagesById[$message['id']] = $message;

            // If message has a reply_to_id but we don't have the parent, mark it as missing
            if (!empty($message['reply_to_id']) && !isset($messagesById[$message['reply_to_id']])) {
                $missingParentIds[$message['reply_to_id']] = true;
            }
        }

        $currentIds = array_keys($messagesById);

        // Load missing parent messages
        if (!empty($missingParentIds)) {
            $parentIds = array_keys($missingParentIds);
            $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
            $stmt = $this->db->prepare("
                SELECT em.id, em.from_name, em.from_address, em.to_name,
                       em.subject, em.date_received, em.date_written, em.echoarea_id,
                       em.message_id, em.reply_to_id,
                       ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE em.id IN ({$placeholders})
            ");

            $params = [$userId, $userId, $userId];
            $params = array_merge($params, $parentIds);

            $stmt->execute($params);
            $parentMessages = $stmt->fetchAll();

            foreach ($parentMessages as $parentMessage) {
                if (!isset($messagesById[$parentMessage['id']])) {
                    $messages[] = $parentMessage;
                    $messagesById[$parentMessage['id']] = $parentMessage;
                }
            }
        }

        // Load missing child messages (messages that reply to current messages)
        if (!empty($currentIds)) {
            $placeholders = implode(',', array_fill(0, count($currentIds), '?'));
            $stmt = $this->db->prepare("
                SELECT em.id, em.from_name, em.from_address, em.to_name,
                       em.subject, em.date_received, em.date_written, em.echoarea_id,
                       em.message_id, em.reply_to_id,
                       ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE em.reply_to_id IN ({$placeholders})
                LIMIT 100
            ");

            $params = [$userId, $userId, $userId];
            $params = array_merge($params, $currentIds);

            $stmt->execute($params);
            $childMessages = $stmt->fetchAll();

            foreach ($childMessages as $childMessage) {
                if (!isset($messagesById[$childMessage['id']])) {
                    $messages[] = $childMessage;
                    $messagesById[$childMessage['id']] = $childMessage;
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
        $dateField = self::ECHOMAIL_DATE_FIELD;
        $stmt = $this->db->prepare("
            SELECT em.id, em.from_name, em.from_address, em.to_name,
                   em.subject, em.date_received, em.date_written, em.echoarea_id,
                   em.message_id, em.reply_to_id,
                   ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                   CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                   CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                   CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
            FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
            LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
            LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
            WHERE ea.id IN ($placeholders) AND ea.is_active = TRUE{$filterClause}
            ORDER BY CASE WHEN em.{$dateField} > NOW() THEN 0 ELSE 1 END, em.{$dateField} DESC
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
        //error_log("DEBUG: Page $page, got " . count($pageMessages) . " page messages");
        
        // For now, just use the page messages without complex threading
        $allMessages = $pageMessages;
        
        // Build threading relationships
        $threads = $this->buildMessageThreads($allMessages);
        
        // Debug: log thread info
        //error_log("DEBUG: Built " . count($threads) . " threads from " . count($allMessages) . " messages");
        
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
        //error_log("DEBUG: Using " . count($threads) . " threads for display");
        
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

        // Clean message data for proper JSON encoding
        $cleanMessages = [];
        foreach ($messages as $message) {
            $cleanMessage = $this->cleanMessageForJson($message);
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
    public function getThreadedEchomail($echoareaTag = null,$domain, $page = 1, $limit = null, $userId = null, $filter = 'all')
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

        // First, get the total count of root messages (threads) for pagination
        $totalThreads = 0;
        if ($echoareaTag) {
            $countStmt = $this->db->prepare("
                SELECT COUNT(*) as total
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE ea.tag = ?{$filterClause} AND ea.domain = ? AND em.reply_to_id IS NULL
            ");
            $countParams = [$userId, $userId, $echoareaTag];
            foreach ($filterParams as $param) {
                $countParams[] = $param;
            }
            $countParams[] = $domain;
            $countStmt->execute($countParams);
            $totalThreads = $countStmt->fetch()['total'];
        } else {
            $countStmt = $this->db->prepare("
                SELECT COUNT(*) as total
                FROM echomail em
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE em.reply_to_id IS NULL{$filterClause}
            ");
            $countParams = [$userId, $userId];
            foreach ($filterParams as $param) {
                $countParams[] = $param;
            }
            $countStmt->execute($countParams);
            $totalThreads = $countStmt->fetch()['total'];
        }

        // Get root messages for the current page
        $rootOffset = ($page - 1) * $limit;
        $dateField = self::ECHOMAIL_DATE_FIELD;

        if ($echoareaTag) {
            // Get root messages (threads) for the current page
            $stmt = $this->db->prepare("
                SELECT em.id, em.from_name, em.from_address, em.to_name,
                       em.subject, em.date_received, em.date_written, em.echoarea_id,
                       em.message_id, em.reply_to_id,
                       ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE ea.tag = ?{$filterClause} AND ea.domain = ? AND em.reply_to_id IS NULL
                ORDER BY CASE WHEN em.{$dateField} > NOW() THEN 0 ELSE 1 END, em.{$dateField} DESC
                LIMIT ? OFFSET ?
            ");
            $params = [$userId, $userId, $userId, $echoareaTag];
            foreach ($filterParams as $param) {
                $params[] = $param;
            }
            $params[] = $domain;
            $params[] = $limit;
            $params[] = $rootOffset;
            $stmt->execute($params);
        } else {
            // For "all messages" view, get root messages for the current page
            $stmt = $this->db->prepare("
                SELECT em.id, em.from_name, em.from_address, em.to_name,
                       em.subject, em.date_received, em.date_written, em.echoarea_id,
                       em.message_id, em.reply_to_id,
                       ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE em.reply_to_id IS NULL{$filterClause}
                ORDER BY CASE WHEN em.{$dateField} > NOW() THEN 0 ELSE 1 END, em.{$dateField} DESC
                LIMIT ? OFFSET ?
            ");
            $params = [$userId, $userId, $userId];
            foreach ($filterParams as $param) {
                $params[] = $param;
            }
            $params[] = $limit;
            $params[] = $rootOffset;
            $stmt->execute($params);
        }

        $rootMessages = $stmt->fetchAll();

        // Now load all child messages for these root messages recursively
        $allMessages = $this->loadThreadChildren($rootMessages, $userId);

        // Build threading relationships
        $threads = $this->buildMessageThreads($allMessages);

        // Sort threads by most recent message in each thread
        usort($threads, function($a, $b) {
            $aLatest = $this->getLatestMessageInThread($a);
            $bLatest = $this->getLatestMessageInThread($b);
            return strtotime($bLatest['date_received']) - strtotime($aLatest['date_received']);
        });

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

        // Clean message data for proper JSON encoding
        $cleanMessages = [];
        foreach ($messages as $message) {
            $cleanMessage = $this->cleanMessageForJson($message);
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
     * Load all child messages for given root messages recursively
     */
    private function loadThreadChildren($rootMessages, $userId)
    {
        if (empty($rootMessages)) {
            return [];
        }

        $allMessages = $rootMessages;
        $messageIds = array_column($rootMessages, 'id');

        // Recursively load children until no more children are found
        $currentLevelIds = $messageIds;
        $maxDepth = 50; // Prevent infinite loops
        $depth = 0;

        while (!empty($currentLevelIds) && $depth < $maxDepth) {
            $placeholders = implode(',', array_fill(0, count($currentLevelIds), '?'));

            $stmt = $this->db->prepare("
                SELECT em.id, em.from_name, em.from_address, em.to_name,
                       em.subject, em.date_received, em.date_written, em.echoarea_id,
                       em.message_id, em.reply_to_id,
                       ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE em.reply_to_id IN ({$placeholders})
            ");

            $params = [$userId, $userId, $userId];
            $params = array_merge($params, $currentLevelIds);
            $stmt->execute($params);

            $children = $stmt->fetchAll();

            if (empty($children)) {
                break;
            }

            // Add children to all messages
            $allMessages = array_merge($allMessages, $children);

            // Prepare for next level
            $currentLevelIds = array_column($children, 'id');
            $depth++;
        }

        return $allMessages;
    }

    /**
     * Build message threads using reply_to_id relationships
     */
    private function buildMessageThreads($messages)
    {
        $messagesById = [];
        $messagesByParentId = [];
        $rootMessages = [];

        // Index messages by their ID and build reply relationships using reply_to_id
        foreach ($messages as $message) {
            $id = $message['id'];
            $messagesById[$id] = $message;

            // Use reply_to_id column instead of parsing kludges
            $replyToId = $message['reply_to_id'] ?? null;

            if ($replyToId) {
                $messagesByParentId[$replyToId][] = $message;
            } else {
                // No reply_to_id, this is a root message
                $rootMessages[] = $message;
            }
        }

        // Build thread trees
        $threads = [];
        foreach ($rootMessages as $root) {
            $thread = $this->buildThreadTree($root, $messagesByParentId);
            $threads[] = $thread;
        }

        // Handle orphaned replies (replies that don't have parent messages in result set)
        foreach ($messagesByParentId as $parentId => $replies) {
            if (!isset($messagesById[$parentId])) {
                // Parent not found in result set, treat each orphaned reply as a separate thread
                foreach ($replies as $orphan) {
                    $thread = $this->buildThreadTree($orphan, $messagesByParentId);
                    $threads[] = $thread;
                }
            }
        }

        return $threads;
    }
    
    /**
     * Recursively build a thread tree
     */
    private function buildThreadTree($message, $messagesByParentId)
    {
        $messageId = $message['id'];
        $thread = [
            'message' => $message,
            'replies' => []
        ];

        if (isset($messagesByParentId[$messageId])) {
            foreach ($messagesByParentId[$messageId] as $reply) {
                $thread['replies'][] = $this->buildThreadTree($reply, $messagesByParentId);
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

        // Get system's configured FidoNet addresses
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $systemAddress = $binkpConfig->getSystemAddress();
            $myAddresses = $binkpConfig->getMyAddresses();
            $myAddresses[] = $systemAddress; // Include main system address
        } catch (\Exception $e) {
            $systemAddress = null;
            $myAddresses = [];
        }

        // Build the WHERE clause based on filter
        // Show messages where user is sender OR recipient (must match name AND to_address must be one of our addresses)
        if (!empty($myAddresses)) {
            $addressPlaceholders = implode(',', array_fill(0, count($myAddresses), '?'));
            $whereClause = "WHERE (n.user_id = ? OR ((LOWER(n.to_name) = LOWER(?) OR LOWER(n.to_name) = LOWER(?)) AND n.to_address IN ($addressPlaceholders)))";
            $params = [$userId, $user['username'], $user['real_name']];
            $params = array_merge($params, $myAddresses);
        } else {
            // Fallback if no addresses configured - only show sent messages
            $whereClause = "WHERE n.user_id = ?";
            $params = [$userId];
        }

        if ($filter === 'unread') {
            // Show only unread messages TO this user (not FROM this user)
            $whereClause .= " AND mrs.read_at IS NULL AND LOWER(n.from_name) != LOWER(?) AND LOWER(n.from_name) != LOWER(?)";
            $params[] = $user['username'];
            $params[] = $user['real_name'];
        } elseif ($filter === 'sent') {
            // Show only messages sent by this user (check from_name since user_id is unreliable for received messages)
            $whereClause = "WHERE (LOWER(n.from_name) = LOWER(?) OR LOWER(n.from_name) = LOWER(?))";
            $params = [$user['username'], $user['real_name']];
        } elseif ($filter === 'received' && !empty($myAddresses)) {
            // Show only messages received by this user (must match name AND to_address must be one of our addresses)
            $whereClause = "WHERE (LOWER(n.to_name) = LOWER(?) OR LOWER(n.to_name) = LOWER(?)) AND n.to_address IN ($addressPlaceholders) AND n.user_id != ?";
            $params = [$user['username'], $user['real_name']];
            $params = array_merge($params, $myAddresses, [$userId]);
        }

        // Get all messages first
        $stmt = $this->db->prepare("
            SELECT n.id, n.from_name, n.from_address, n.to_name, n.to_address,
                   n.subject, n.date_received, n.user_id, n.date_written,
                   n.attributes, n.is_sent, n.reply_to_id,
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

            // Add domain information for netmail from/to addresses
            try {
                $binkpCfg = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
                if (!empty($cleanMessage['from_address'])) {
                    $cleanMessage['from_domain'] = $binkpCfg->getDomainByAddress($cleanMessage['from_address']) ?: null;
                }
                if (!empty($cleanMessage['to_address'])) {
                    $cleanMessage['to_domain'] = $binkpCfg->getDomainByAddress($cleanMessage['to_address']) ?: null;
                }
            } catch (\Exception $e) {
                // Ignore errors, domains are optional
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
     * Get thread context for specific messages efficiently using reply_to_id
     */
    private function getThreadContextForMessages($pageMessages, $userId, $echoareaIds, $filterClause, $filterParams)
    {
        // Start with the page messages
        $allMessages = $pageMessages;
        $messageIds = array_column($pageMessages, 'id');

        // Collect parent IDs (messages referenced by reply_to_id)
        $parentIds = [];
        foreach ($pageMessages as $msg) {
            if (!empty($msg['reply_to_id']) && !in_array($msg['reply_to_id'], $messageIds)) {
                $parentIds[] = $msg['reply_to_id'];
            }
        }

        // Get parent messages if needed
        if (!empty($parentIds)) {
            $parentIds = array_unique($parentIds);
            $placeholders = str_repeat('?,', count($parentIds) - 1) . '?';
            $echoareaPlaceholders = str_repeat('?,', count($echoareaIds) - 1) . '?';

            $parentStmt = $this->db->prepare("
                SELECT em.id, em.from_name, em.from_address, em.to_name,
                       em.subject, em.date_received, em.date_written, em.echoarea_id,
                       em.message_id, em.reply_to_id,
                       ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE ea.id IN ($echoareaPlaceholders) AND ea.is_active = TRUE{$filterClause}
                AND em.id IN ($placeholders)
            ");

            $params = [$userId, $userId, $userId];
            $params = array_merge($params, $echoareaIds);
            foreach ($filterParams as $param) {
                $params[] = $param;
            }
            $params = array_merge($params, $parentIds);

            $parentStmt->execute($params);
            $parentMessages = $parentStmt->fetchAll();

            foreach ($parentMessages as $parentMsg) {
                if (!in_array($parentMsg['id'], $messageIds)) {
                    $allMessages[] = $parentMsg;
                    $messageIds[] = $parentMsg['id'];
                }
            }
        }

        // Get child messages (messages that reply to our page messages)
        if (!empty($messageIds)) {
            $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
            $echoareaPlaceholders = str_repeat('?,', count($echoareaIds) - 1) . '?';

            $childStmt = $this->db->prepare("
                SELECT em.id, em.from_name, em.from_address, em.to_name,
                       em.subject, em.date_received, em.date_written, em.echoarea_id,
                       em.message_id, em.reply_to_id,
                       ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE ea.id IN ($echoareaPlaceholders) AND ea.is_active = TRUE{$filterClause}
                AND em.reply_to_id IN ($placeholders)
                LIMIT 100
            ");

            $params = [$userId, $userId, $userId];
            $params = array_merge($params, $echoareaIds);
            foreach ($filterParams as $param) {
                $params[] = $param;
            }
            $originalMessageIds = array_column($pageMessages, 'id');
            $params = array_merge($params, $originalMessageIds);

            $childStmt->execute($params);
            $childMessages = $childStmt->fetchAll();

            $currentMessageIds = array_column($allMessages, 'id');
            foreach ($childMessages as $childMsg) {
                if (!in_array($childMsg['id'], $currentMessageIds)) {
                    $allMessages[] = $childMsg;
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
        $messageText .= "Welcome to the community!\n\n";
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

