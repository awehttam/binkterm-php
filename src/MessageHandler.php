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
    // .env ECHOMAIL_ORDER_DATE options: 'received' or 'written'
    private const ECHOMAIL_DATE_FIELD_DEFAULT = 'date_received';

    private $db;
    private $pendingImmediateOutboundPolls = [];
    private \BinktermPHP\Binkp\Logger $logger;

    public function __construct()
    {
        $this->db     = Database::getInstance()->getPdo();
        $this->logger = new \BinktermPHP\Binkp\Logger(Config::getLogPath('server.log'), \BinktermPHP\Binkp\Logger::LEVEL_INFO, false);
    }

    private function getEchomailDateField(): string
    {
        $raw = strtolower(trim((string)Config::env('ECHOMAIL_ORDER_DATE', 'received')));
        if ($raw === 'written' || $raw === 'date_written') {
            // date_written ordering is only available to admins; non-admins always use date_received
            $currentUser = (new Auth())->getCurrentUser();
            if (!$currentUser || empty($currentUser['is_admin'])) {
                return 'date_received';
            }
            return 'date_written';
        }
        if ($raw === 'received' || $raw === 'date_received') {
            return 'date_received';
        }
        return self::ECHOMAIL_DATE_FIELD_DEFAULT;
    }

    /**
     * Builds the SQL fragment used to hide echomail messages ignored by a user.
     *
     * @param int|null $userId
     * @param string $messageAlias
     * @return array{sql:string,params:array<int,int>}
     */
    public function buildEchomailIgnoreFilter(?int $userId, string $messageAlias = 'em'): array
    {
        if (empty($userId)) {
            return ['sql' => '', 'params' => []];
        }

        return [
            'sql' => " AND NOT EXISTS (
                SELECT 1
                FROM user_echomail_ignore_rules ueir
                WHERE ueir.user_id = ?
                  AND ueir.sender_name = {$messageAlias}.from_name
                  AND COALESCE(ueir.sender_address, '') = COALESCE({$messageAlias}.from_address, '')
                  AND (
                      ueir.subject_contains = ''
                      OR POSITION(LOWER(ueir.subject_contains) IN LOWER(COALESCE({$messageAlias}.subject, ''))) > 0
                  )
            )",
            'params' => [(int)$userId]
        ];
    }

    /**
     * Creates or updates an echomail ignore rule for a user.
     */
    public function createEchomailIgnoreRule(int $userId, string $senderName, string $senderAddress, string $subjectContains): bool
    {
        $senderName = trim($senderName);
        $senderAddress = trim($senderAddress);
        $subjectContains = trim($subjectContains);

        if ($userId <= 0 || $senderName === '') {
            return false;
        }

        $stmt = $this->db->prepare("
            INSERT INTO user_echomail_ignore_rules (user_id, sender_name, sender_address, subject_contains)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (user_id, sender_name, sender_address, subject_contains)
            DO UPDATE SET sender_name = EXCLUDED.sender_name
            RETURNING id
        ");
        $stmt->execute([$userId, $senderName, $senderAddress, $subjectContains]);

        return (bool)$stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Checks whether a single echomail message is hidden by an ignore rule.
     */
    public function isEchomailIgnoredForUser(int $userId, string $senderName, string $senderAddress, string $subject): bool
    {
        if ($userId <= 0 || $senderName === '') {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT 1
            FROM user_echomail_ignore_rules
            WHERE user_id = ?
              AND sender_name = ?
              AND COALESCE(sender_address, '') = COALESCE(?, '')
              AND (
                  subject_contains = ''
                  OR POSITION(LOWER(subject_contains) IN LOWER(COALESCE(?, ''))) > 0
              )
            LIMIT 1
        ");
        $stmt->execute([$userId, $senderName, $senderAddress, $subject]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Lists saved echomail ignore rules for a user.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getEchomailIgnoreRules(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT id, sender_name, sender_address, subject_contains, created_at
            FROM user_echomail_ignore_rules
            WHERE user_id = ?
            ORDER BY created_at DESC, id DESC
        ");
        $stmt->execute([$userId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Deletes an echomail ignore rule owned by a user.
     */
    public function deleteEchomailIgnoreRule(int $userId, int $ruleId): bool
    {
        if ($userId <= 0 || $ruleId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare("
            DELETE FROM user_echomail_ignore_rules
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$ruleId, $userId]);

        return $stmt->rowCount() > 0;
    }

    public function getNetmail($userId, $page = 1, $limit = null, $filter = 'all', $threaded = false, $sort = 'date_desc')
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
            return $this->getThreadedNetmail($userId, $page, $limit, $filter, $sort);
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
            // Show only messages sent by this user from this system (check from_name AND from_address)
            if (!empty($myAddresses)) {
                $addressPlaceholders = implode(',', array_fill(0, count($myAddresses), '?'));
                $whereClause = "WHERE (LOWER(n.from_name) = LOWER(?) OR LOWER(n.from_name) = LOWER(?)) AND n.from_address IN ($addressPlaceholders)";
                $params = [$user['username'], $user['real_name']];
                $params = array_merge($params, $myAddresses);
            } else {
                // Fallback if no addresses configured - just check name
                $whereClause = "WHERE (LOWER(n.from_name) = LOWER(?) OR LOWER(n.from_name) = LOWER(?))";
                $params = [$user['username'], $user['real_name']];
            }
        } elseif ($filter === 'received' && !empty($myAddresses)) {
            // Show only messages received by this user (must match name AND to_address must be one of our addresses)
            $whereClause = "WHERE (LOWER(n.to_name) = LOWER(?) OR LOWER(n.to_name) = LOWER(?)) AND n.to_address IN ($addressPlaceholders) AND n.user_id != ?";
            $params = [$user['username'], $user['real_name']];
            $params = array_merge($params, $myAddresses, [$userId]);
        } elseif ($filter === 'saved') {
            // Show only messages saved by this user
            $whereClause .= " AND sav.id IS NOT NULL";
        }

        // Filter out soft-deleted messages
        // If user is sender, exclude messages deleted by sender
        // If user is recipient, exclude messages deleted by recipient
        $whereClause .= " AND NOT ((n.user_id = ? AND n.deleted_by_sender = TRUE) OR
                                   ((LOWER(n.to_name) = LOWER(?) OR LOWER(n.to_name) = LOWER(?)) AND n.deleted_by_recipient = TRUE))";
        $params[] = $userId;
        $params[] = $user['username'];
        $params[] = $user['real_name'];

        // Build ORDER BY clause based on sort parameter
        $orderBy = match($sort) {
            'date_asc' => "n.date_received ASC",
            'subject'  => "n.subject ASC",
            'author'   => "n.from_name ASC",
            default    => "CASE WHEN n.date_received > NOW() THEN 0 ELSE 1 END, n.date_received DESC",
        };

        $stmt = $this->db->prepare("
            SELECT n.id, n.from_name, n.from_address, n.to_name, n.to_address,
                   n.subject, n.date_received, n.user_id, n.date_written,
                   n.attributes, n.is_sent, n.reply_to_id, n.is_freq,
                   CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                   EXISTS(SELECT 1 FROM files WHERE message_id = n.id AND message_type = 'netmail') as has_attachment,
                   CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
            FROM netmail n
            LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
            LEFT JOIN saved_messages sav ON (sav.message_id = n.id AND sav.message_type = 'netmail' AND sav.user_id = ?)
            $whereClause
            ORDER BY {$orderBy}
            LIMIT ? OFFSET ?
        ");
        
        // Insert userId twice at the beginning for the two LEFT JOINs (mrs and sav), then add existing params
        $allParams = [$userId, $userId];
        foreach ($params as $param) {
            $allParams[] = $param;
        }
        $allParams[] = $limit;
        $allParams[] = $offset;

        $stmt->execute($allParams);
        $messages = $stmt->fetchAll();

        // Get total count with same filter - need to include the LEFT JOINs for unread/saved filters
        $countAllParams = [$userId, $userId];
        foreach ($params as $param) {
            $countAllParams[] = $param;
        }

        $countStmt = $this->db->prepare("
            SELECT COUNT(*) as total
            FROM netmail n
            LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
            LEFT JOIN saved_messages sav ON (sav.message_id = n.id AND sav.message_type = 'netmail' AND sav.user_id = ?)
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

    public function getEchomail($echoareaTag = null, $domain = null, $page = 1, $limit = null, $userId = null, $filter = 'all', $threaded = false, $checkSubscriptions = true, $sort = 'date_desc')
    {
        // Check subscription access if user is specified and subscription checking is enabled
        if ($userId && $checkSubscriptions && $echoareaTag) {
            $subscriptionManager = new EchoareaSubscriptionManager();

            // Get echoarea ID from tag
            if (empty($domain)) {
                $stmt = $this->db->prepare("SELECT id FROM echoareas WHERE tag = ? AND (domain IS NULL OR domain = '') AND is_active = TRUE");
                $stmt->execute([$echoareaTag]);
            } else {
                $stmt = $this->db->prepare("SELECT id FROM echoareas WHERE tag = ? AND domain = ? AND is_active = TRUE");
                $stmt->execute([$echoareaTag, $domain]);
            }
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
                    'error_code' => 'errors.messages.echomail.stats.subscription_required',
                    'error' => 'Subscription required for this echo area'
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
            return $this->getThreadedEchomail($echoareaTag, $domain, $page, $limit, $userId, $filter, $sort);
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
        // Hide future-dated messages until their date_written has passed
        $filterClause .= " AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC'))";
        $ignoreFilter = $this->buildEchomailIgnoreFilter($userId, 'em');
        $filterClause .= $ignoreFilter['sql'];
        $moderationFilter = $this->buildModerationVisibilityFilter($userId, 'em');
        $filterClause .= $moderationFilter['sql'];
        foreach ($moderationFilter['params'] as $p) {
            $filterParams[] = $p;
        }

        $dateField = $this->getEchomailDateField();

        // Build ORDER BY clause based on sort parameter
        $orderBy = match($sort) {
            'date_asc' => "em.{$dateField} ASC",
            'subject'  => "em.subject ASC",
            'author'   => "em.from_name ASC",
            default    => "CASE WHEN em.{$dateField} > NOW() THEN 0 ELSE 1 END, em.{$dateField} DESC",
        };

        if ($echoareaTag) {
            // Build domain filter condition
            $domainCondition = empty($domain) ? "(ea.domain IS NULL OR ea.domain = '')" : "ea.domain = ?";
            //error_log("DEBUG getEchomail: tag=$echoareaTag, domain='$domain', domainCondition=$domainCondition");

            $stmt = $this->db->prepare("
                SELECT em.id, em.from_name, em.from_address, em.to_name,
                       em.subject, em.date_received, em.date_written, em.echoarea_id,
                       em.message_id, em.reply_to_id,
                       ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                       COALESCE(NULLIF(em.art_format, ''), NULLIF(ea.art_format_hint, '')) as art_format,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE ea.tag = ?{$filterClause} AND {$domainCondition}
                ORDER BY {$orderBy}
                LIMIT ? OFFSET ?
            ");
            $params = [$userId, $userId, $userId, $echoareaTag];
            foreach ($filterParams as $param) {
                $params[] = $param;
            }
            foreach ($ignoreFilter['params'] as $param) {
                $params[] = $param;
            }
            if (!empty($domain)) {
                $params[] = $domain;
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
                WHERE ea.tag = ?{$filterClause} AND {$domainCondition}
            ");
            $countParams = [$userId, $userId, $echoareaTag];
            foreach ($filterParams as $param) {
                $countParams[] = $param;
            }
            foreach ($ignoreFilter['params'] as $param) {
                $countParams[] = $param;
            }
            if (!empty($domain)) {
                $countParams[] = $domain;
            }
            $countStmt->execute($countParams);
        } else {
            $stmt = $this->db->prepare("
                SELECT em.id, em.from_name, em.from_address, em.to_name,
                       em.subject, em.date_received, em.date_written, em.echoarea_id,
                       em.message_id, em.reply_to_id,
                       ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                       COALESCE(NULLIF(em.art_format, ''), NULLIF(ea.art_format_hint, '')) as art_format,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE 1=1{$filterClause}
                ORDER BY {$orderBy}
                LIMIT ? OFFSET ?
            ");
            $params = [$userId, $userId, $userId];
            foreach ($filterParams as $param) {
                $params[] = $param;
            }
            foreach ($ignoreFilter['params'] as $param) {
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
            foreach ($ignoreFilter['params'] as $param) {
                $countParams[] = $param;
            }
            $countStmt->execute($countParams);
        }

        $total = $countStmt->fetch()['total'];
        
        // Get unread count for the current user
        $unreadCount = 0;
        if ($userId) {
            if ($echoareaTag) {
                $domainCondition = empty($domain) ? "(ea.domain IS NULL OR ea.domain = '')" : "ea.domain = ?";
                $unreadCountStmt = $this->db->prepare("
                    SELECT COUNT(*) as count FROM echomail em
                    JOIN echoareas ea ON em.echoarea_id = ea.id
                    LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                    WHERE ea.tag = ? AND mrs.read_at IS NULL AND {$domainCondition}
                      AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC')){$ignoreFilter['sql']}
                ");
                $unreadParams = [$userId, $echoareaTag];
                if (!empty($domain)) {
                    $unreadParams[] = $domain;
                }
                foreach ($ignoreFilter['params'] as $param) {
                    $unreadParams[] = $param;
                }
                $unreadCountStmt->execute($unreadParams);
            } else {
                $unreadCountStmt = $this->db->prepare("
                    SELECT COUNT(*) as count FROM echomail em
                    LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                    WHERE mrs.read_at IS NULL
                      AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC')){$ignoreFilter['sql']}
                ");
                $unreadParams = [$userId];
                foreach ($ignoreFilter['params'] as $param) {
                    $unreadParams[] = $param;
                }
                $unreadCountStmt->execute($unreadParams);
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
    public function getEchomailFromSubscribedAreas($userId, $page = 1, $limit = null, $filter = 'all', $threaded = false, $sort = 'date_desc')
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
            return $this->getThreadedEchomailFromSubscribedAreas($userId, $page, $limit, $filter, $subscribedEchoareas, $sort);
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
        // Hide future-dated messages until their date_written has passed
        $filterClause .= " AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC'))";
        $ignoreFilter = $this->buildEchomailIgnoreFilter($userId, 'em');
        $filterClause .= $ignoreFilter['sql'];
        $moderationFilter = $this->buildModerationVisibilityFilter($userId, 'em');
        $filterClause .= $moderationFilter['sql'];
        foreach ($moderationFilter['params'] as $p) {
            $filterParams[] = $p;
        }

        // Create IN clause for subscribed echoareas
        $echoareaIds = array_column($subscribedEchoareas, 'id');
        $placeholders = str_repeat('?,', count($echoareaIds) - 1) . '?';

        $dateField = $this->getEchomailDateField();

        // Build ORDER BY clause based on sort parameter
        $orderBy = match($sort) {
            'date_asc' => "em.{$dateField} ASC",
            'subject'  => "em.subject ASC",
            'author'   => "em.from_name ASC",
            default    => "CASE WHEN em.{$dateField} > NOW() THEN 0 ELSE 1 END, em.{$dateField} DESC",
        };

        $stmt = $this->db->prepare("
            SELECT em.id, em.from_name, em.from_address, em.to_name,
                   em.subject, em.date_received, em.date_written, em.echoarea_id,
                   em.message_id, em.reply_to_id,
                   ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                   COALESCE(NULLIF(em.art_format, ''), NULLIF(ea.art_format_hint, '')) as art_format,
                   CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                   CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                   CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
            FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
            LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
            LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
            WHERE ea.id IN ($placeholders) AND ea.is_active = TRUE{$filterClause}
            ORDER BY {$orderBy}
            LIMIT ? OFFSET ?
        ");

        $params = [$userId, $userId, $userId];
        $params = array_merge($params, $echoareaIds);
        foreach ($filterParams as $param) {
            $params[] = $param;
        }
        foreach ($ignoreFilter['params'] as $param) {
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
        foreach ($ignoreFilter['params'] as $param) {
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
                  AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC')){$ignoreFilter['sql']}
            ");
            $unreadParams = [$userId];
            $unreadParams = array_merge($unreadParams, $echoareaIds);
            foreach ($ignoreFilter['params'] as $param) {
                $unreadParams[] = $param;
            }
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

    /**
     * Get paginated echomail from all echo areas belonging to an interest.
     *
     * @param int      $userId     Authenticated user ID
     * @param int      $interestId Interest to load messages for
     * @param int      $page       Page number (1-based)
     * @param int|null $limit      Messages per page (null = use user setting)
     * @param string   $filter     'all'|'unread'|'read'|'tome'|'saved'
     * @param string   $sort       'date_desc'|'date_asc'|'subject'|'author'
     * @return array{messages: array, unreadCount: int, pagination: array}
     */
    public function getEchomailFromInterest(int $userId, int $interestId, int $page = 1, ?int $limit = null, string $filter = 'all', string $sort = 'date_desc'): array
    {
        $manager     = new \BinktermPHP\InterestManager();
        $echoareaIds = $manager->getUserSubscribedInterestEchoareaIds($interestId, $userId);

        if (empty($echoareaIds)) {
            return [
                'messages'    => [],
                'unreadCount' => 0,
                'pagination'  => ['page' => 1, 'limit' => 25, 'total' => 0, 'pages' => 0],
            ];
        }

        $user    = $this->getUserById($userId);
        $isAdmin = $user && !empty($user['is_admin']);

        if ($limit === null) {
            $settings = $this->getUserSettings($userId);
            $limit    = $settings['messages_per_page'] ?? 25;
        }

        $offset       = ($page - 1) * $limit;
        $filterClause = '';
        $filterParams = [];

        if ($filter === 'unread') {
            $filterClause = ' AND mrs.read_at IS NULL';
        } elseif ($filter === 'read') {
            $filterClause = ' AND mrs.read_at IS NOT NULL';
        } elseif ($filter === 'tome') {
            if ($user) {
                $filterClause   = ' AND (LOWER(em.to_name) = LOWER(?) OR LOWER(em.to_name) = LOWER(?))';
                $filterParams[] = $user['username'];
                $filterParams[] = $user['real_name'];
            }
        } elseif ($filter === 'saved') {
            $filterClause = ' AND sav.id IS NOT NULL';
        }

        $ignoreFilter     = $this->buildEchomailIgnoreFilter($userId, 'em');
        $moderationFilter = $this->buildModerationVisibilityFilter($userId, 'em');
        $sysopClause      = $isAdmin ? '' : ' AND ea.is_sysop_only = FALSE';
        $echoPH           = implode(',', array_fill(0, count($echoareaIds), '?'));
        $dateField    = $this->getEchomailDateField();

        // Only use UNION path when filter === 'all' and there are associated file areas
        $fileareaIds  = [];
        $useUnion     = false;
        if ($filter === 'all') {
            $fileareaIds = $manager->getInterestFileareaIds($interestId);
            $useUnion    = !empty($fileareaIds);
        }

        if ($useUnion) {
            $filePH       = implode(',', array_fill(0, count($fileareaIds), '?'));
            $unionOrderBy = match ($sort) {
                'date_asc' => 'sort_date ASC',
                'subject'  => 'subject ASC',
                'author'   => 'from_name ASC',
                default    => 'CASE WHEN sort_date > NOW() THEN 0 ELSE 1 END, sort_date DESC',
            };

            $stmt = $this->db->prepare("
                SELECT item_type, id, from_name, from_address, to_name, subject, sort_date,
                       date_received, date_written, echoarea_id, file_area_id,
                       area_tag, area_color, area_domain, art_format,
                       is_read, is_shared, is_saved, filename, filesize, short_description,
                       message_id, reply_to_id, uploader_name
                FROM (
                    SELECT
                        'message'::text AS item_type,
                        em.id,
                        em.from_name,
                        em.from_address,
                        em.to_name,
                        em.subject,
                        em.{$dateField} AS sort_date,
                        em.date_received,
                        em.date_written,
                        em.echoarea_id,
                        NULL::integer AS file_area_id,
                        ea.tag AS area_tag,
                        ea.color AS area_color,
                        ea.domain AS area_domain,
                        COALESCE(NULLIF(em.art_format,''), NULLIF(ea.art_format_hint,'')) AS art_format,
                        CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END AS is_read,
                        CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END AS is_shared,
                        CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END AS is_saved,
                        NULL::text AS filename,
                        NULL::bigint AS filesize,
                        NULL::text AS short_description,
                        em.message_id,
                        em.reply_to_id,
                        NULL::text AS uploader_name
                    FROM echomail em
                    JOIN echoareas ea ON em.echoarea_id = ea.id
                    LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                    LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                    LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                    WHERE ea.id IN ({$echoPH}) AND ea.is_active = TRUE{$sysopClause}{$ignoreFilter['sql']}{$moderationFilter['sql']}

                    UNION ALL

                    SELECT
                        'file'::text AS item_type,
                        f.id,
                        fa.tag AS from_name,
                        NULL::text AS from_address,
                        NULL::text AS to_name,
                        f.filename AS subject,
                        f.created_at AS sort_date,
                        f.created_at AS date_received,
                        NULL::timestamptz AS date_written,
                        NULL::integer AS echoarea_id,
                        f.file_area_id,
                        fa.tag AS area_tag,
                        '#6c757d'::text AS area_color,
                        fa.domain AS area_domain,
                        NULL::text AS art_format,
                        1::integer AS is_read,
                        0::integer AS is_shared,
                        0::integer AS is_saved,
                        f.filename,
                        f.filesize,
                        f.short_description,
                        NULL::text AS message_id,
                        NULL::integer AS reply_to_id,
                        COALESCE(u.real_name, f.uploaded_from_address) AS uploader_name
                    FROM files f
                    JOIN file_areas fa ON f.file_area_id = fa.id
                    LEFT JOIN users u ON u.id = f.owner_id
                    WHERE f.file_area_id IN ({$filePH})
                      AND f.status = 'approved'
                      AND fa.is_active = TRUE
                ) combined
                ORDER BY {$unionOrderBy}
                LIMIT ? OFFSET ?
            ");

            $params = [$userId, $userId, $userId];
            $params = array_merge($params, $echoareaIds);
            foreach ($ignoreFilter['params'] as $param) {
                $params[] = $param;
            }
            foreach ($moderationFilter['params'] as $param) {
                $params[] = $param;
            }
            $params = array_merge($params, $fileareaIds);
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $countStmt = $this->db->prepare("
                SELECT COUNT(*) AS total FROM (
                    SELECT em.id
                    FROM echomail em
                    JOIN echoareas ea ON em.echoarea_id = ea.id
                    LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                    LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                    WHERE ea.id IN ({$echoPH}) AND ea.is_active = TRUE{$sysopClause}{$ignoreFilter['sql']}{$moderationFilter['sql']}
                    UNION ALL
                    SELECT f.id
                    FROM files f
                    JOIN file_areas fa ON f.file_area_id = fa.id
                    WHERE f.file_area_id IN ({$filePH})
                      AND f.status = 'approved'
                      AND fa.is_active = TRUE
                ) combined
            ");
            $countParams = [$userId, $userId];
            $countParams = array_merge($countParams, $echoareaIds);
            foreach ($ignoreFilter['params'] as $param) {
                $countParams[] = $param;
            }
            foreach ($moderationFilter['params'] as $param) {
                $countParams[] = $param;
            }
            $countParams = array_merge($countParams, $fileareaIds);
            $countStmt->execute($countParams);
            $total = $countStmt->fetch()['total'];
        } else {
            // Messages-only path (original behavior)
            $placeholders = $echoPH;

            $orderBy = match ($sort) {
                'date_asc' => "em.{$dateField} ASC",
                'subject'  => 'em.subject ASC',
                'author'   => 'em.from_name ASC',
                default    => "CASE WHEN em.{$dateField} > NOW() THEN 0 ELSE 1 END, em.{$dateField} DESC",
            };

            $stmt = $this->db->prepare("
                SELECT em.id, em.from_name, em.from_address, em.to_name,
                       em.subject, em.date_received, em.date_written, em.echoarea_id,
                       em.message_id, em.reply_to_id,
                       ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                       COALESCE(NULLIF(em.art_format, ''), NULLIF(ea.art_format_hint, '')) as art_format,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE ea.id IN ($placeholders) AND ea.is_active = TRUE{$sysopClause}{$filterClause}{$ignoreFilter['sql']}{$moderationFilter['sql']}
                ORDER BY {$orderBy}
                LIMIT ? OFFSET ?
            ");

            $params = [$userId, $userId, $userId];
            $params = array_merge($params, $echoareaIds, $filterParams);
            foreach ($ignoreFilter['params'] as $param) {
                $params[] = $param;
            }
            foreach ($moderationFilter['params'] as $param) {
                $params[] = $param;
            }
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $countStmt = $this->db->prepare("
                SELECT COUNT(*) as total FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE ea.id IN ($placeholders) AND ea.is_active = TRUE{$sysopClause}{$filterClause}{$ignoreFilter['sql']}{$moderationFilter['sql']}
            ");
            $countParams = [$userId, $userId];
            $countParams = array_merge($countParams, $echoareaIds, $filterParams);
            foreach ($ignoreFilter['params'] as $param) {
                $countParams[] = $param;
            }
            foreach ($moderationFilter['params'] as $param) {
                $countParams[] = $param;
            }
            $countStmt->execute($countParams);
            $total = $countStmt->fetch()['total'];
        }

        $unreadStmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
            WHERE ea.id IN ({$echoPH}) AND ea.is_active = TRUE{$sysopClause} AND mrs.read_at IS NULL{$ignoreFilter['sql']}
        ");
        $unreadParams = [$userId];
        $unreadParams = array_merge($unreadParams, $echoareaIds);
        foreach ($ignoreFilter['params'] as $param) {
            $unreadParams[] = $param;
        }
        $unreadStmt->execute($unreadParams);
        $unreadCount = $unreadStmt->fetch()['count'];

        $cleanMessages = [];
        foreach ($rows as $row) {
            if ($useUnion && ($row['item_type'] ?? 'message') === 'file') {
                $cleanMessages[] = [
                    'type'              => 'file',
                    'id'                => (int)$row['id'],
                    'filename'          => $row['filename'],
                    'short_description' => $row['short_description'],
                    'filesize'          => (int)$row['filesize'],
                    'date_received'     => $row['date_received'],
                    'file_area_id'      => (int)$row['file_area_id'],
                    'file_area_tag'     => $row['area_tag'],
                    'file_area_domain'  => $row['area_domain'],
                    'uploader_name'     => $row['uploader_name'] ?? null,
                ];
            } else {
                // The UNION query uses area_tag/area_color/area_domain aliases;
                // remap them to the echoarea/echoarea_color/echoarea_domain keys
                // that the JS expects before passing to cleanMessageForJson.
                if ($useUnion) {
                    $row['echoarea']        = $row['area_tag']    ?? null;
                    $row['echoarea_color']  = $row['area_color']  ?? null;
                    $row['echoarea_domain'] = $row['area_domain'] ?? null;
                }
                $cleanMessage = $this->cleanMessageForJson($row);
                $replyToData  = null;
                if (!empty($row['kludge_lines'])) {
                    $replyToData = $this->parseEchomailReplyToKludge($row['kludge_lines']);
                }
                if (!$replyToData) {
                    $replyToData = $this->parseReplyToKludge($row);
                }
                if ($replyToData && isset($replyToData['address'])) {
                    $cleanMessage['replyto_address'] = $replyToData['address'];
                    $cleanMessage['replyto_name']    = $replyToData['name'] ?? null;
                }
                $cleanMessage['type'] = 'message';
                $cleanMessages[]      = $cleanMessage;
            }
        }

        return [
            'messages'    => $cleanMessages,
            'unreadCount' => $unreadCount,
            'pagination'  => [
                'page'  => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
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
            // Use the same visibility rules as getNetmail('all') so any message shown
            // in the inbox is also retrievable by the single-message API.
            if (!$user) {
                return null;
            }

            // Get system's configured FidoNet addresses
            try {
                $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
                $myAddresses = $binkpConfig->getMyAddresses();
                $systemAddress = $binkpConfig->getSystemAddress();
                if ($systemAddress !== '') {
                    $myAddresses[] = $systemAddress;
                }
                $myAddresses = array_values(array_unique(array_filter($myAddresses, static function ($value) {
                    return trim((string)$value) !== '';
                })));
            } catch (\Exception $e) {
                $myAddresses = [];
            }

            if (!empty($myAddresses)) {
                $addressPlaceholders = implode(',', array_fill(0, count($myAddresses), '?'));
                $stmt = $this->db->prepare("
                    SELECT n.*, CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                    FROM netmail n
                    LEFT JOIN saved_messages sav ON (sav.message_id = n.id AND sav.message_type = 'netmail' AND sav.user_id = ?)
                    WHERE n.id = ?
                      AND (
                        n.user_id = ?
                        OR ((LOWER(n.to_name) = LOWER(?) OR LOWER(n.to_name) = LOWER(?)) AND n.to_address IN ($addressPlaceholders))
                      )
                      AND NOT ((n.user_id = ? AND n.deleted_by_sender = TRUE) OR
                               ((LOWER(n.to_name) = LOWER(?) OR LOWER(n.to_name) = LOWER(?)) AND n.deleted_by_recipient = TRUE))
                ");
                $params = [$userId, $messageId, $userId, $user['username'], $user['real_name']];
                $params = array_merge($params, $myAddresses);
                $params[] = $userId;
                $params[] = $user['username'];
                $params[] = $user['real_name'];
                $stmt->execute($params);
            } else {
                // Fallback if no addresses are configured - mirror the inbox fallback.
                $stmt = $this->db->prepare("
                    SELECT n.*, CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                    FROM netmail n
                    LEFT JOIN saved_messages sav ON (sav.message_id = n.id AND sav.message_type = 'netmail' AND sav.user_id = ?)
                    WHERE n.id = ?
                      AND n.user_id = ?
                      AND n.deleted_by_sender = FALSE
                ");
                $stmt->execute([$userId, $messageId, $userId]);
            }
        } else {
            // Echomail is public, so no user restriction needed
            $ignoreFilter     = $this->buildEchomailIgnoreFilter($userId, 'em');
            $moderationFilter = $this->buildModerationVisibilityFilter($userId, 'em');
            $stmt = $this->db->prepare("
                SELECT em.*,
                       COALESCE(NULLIF(em.art_format, ''), NULLIF(ea.art_format_hint, '')) as art_format,
                       ea.tag as echoarea, ea.domain as domain, ea.color as echoarea_color, ea.is_sysop_only as is_sysop_only,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                WHERE em.id = ?{$ignoreFilter['sql']}{$moderationFilter['sql']}
            ");
            $params = [$userId, $userId, $messageId];
            foreach ($ignoreFilter['params'] as $param) {
                $params[] = $param;
            }
            foreach ($moderationFilter['params'] as $param) {
                $params[] = $param;
            }
            $stmt->execute($params);
        }
        
        $message = $stmt->fetch();

        if ($message) {
            if ($type === 'echomail' && !empty($message['is_sysop_only']) && !$isAdmin) {
                return null;
            }

            $rawMessageBytes = $message['raw_message_bytes'] ?? null;
            $rawMessageCharset = $message['message_charset'] ?? null;
            $rawArtFormat = $message['art_format'] ?? null;

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

            // Add system names from nodelist for address tooltips
            $message['from_system_name'] = $this->lookupSystemName($message['from_address'] ?? null);
            if ($type === 'netmail') {
                $message['to_system_name'] = $this->lookupSystemName($message['to_address'] ?? null);
            }

            $message = $this->appendRawMessagePayload($message, $rawMessageBytes, $rawMessageCharset, $rawArtFormat);
            $message = $this->appendMarkdownRendering($message);
            $message = $this->appendRipRendering($message);
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
    public function sendNetmail($fromUserId, $toAddress, $toName, $subject, $messageText, $fromName = null, $replyToId = null, $crashmail = false, $tagline = null, $attachment = null, $markupType = null, $isFreq = false, $charset = null)
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

        //$messageText = $this->applyUserSignatureAndTagline($messageText, $fromUserId, $tagline ?? null);

        $markupAllowed = null;
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            if ($markupType !== null && $binkpConfig->isMarkdownAllowedForDestination($toAddress)) {
                $markupAllowed = $markupType;
            }
        } catch (\Exception $e) {
            $markupAllowed = null;
        }

        // Special case: if sending to "sysop" at our local system, route to local sysop user
        if (!empty($toName) && strtolower($toName) === 'sysop') {
            try {
                $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
                // Only route locally if destination is one of our addresses (or no address specified)
                if (empty($toAddress) || $binkpConfig->isMyAddress($toAddress)) {
                    return $this->sendLocalSysopMessage($fromUserId, $subject, $messageText, $fromName, $replyToId, $tagline ?? null, $markupAllowed, $attachment);
                }
            } catch (\Exception $e) {
                // If we can't get config, fall through to normal send
            }
        }

        // Use provided fromName or resolve sender identity from netmail posting policy
        $senderName = $fromName ?: $this->resolveNetmailPostingName($user, (string)$toAddress);

        // Get the appropriate origin address for the destination network
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();

            // Use the "me" address from the uplink that will route this message
            $originAddress = $binkpConfig->getOriginAddressByDestination($toAddress);

            if (!$originAddress) {
                // No uplink can route to this destination - fall back to default
                $originAddress = $binkpConfig->getSystemAddress();
                $this->logger->warning("[NETMAIL] No specific uplink for destination {$toAddress}, using system address");
            }
        } catch (\Exception $e) {
            throw new \Exception('Cannot determine origin address: ' . $e->getMessage());
        }

        // Verify outbound directory is writable before accepting the message
        // (only needed if message will be spooled, i.e., not local delivery)
        if ($toAddress !== $originAddress) {
            $outboundPath = $binkpConfig->getOutboundPath();
            if (!is_dir($outboundPath) || !is_writable($outboundPath)) {
                $this->logger->error("[NETMAIL] Outbound directory not writable: {$outboundPath}");
                throw new \Exception('Message delivery system unavailable. Please try again later.');
            }
        }

        // Attachment to a remote node requires crashmail (direct delivery ensures the file
        // arrives with the message).  Local delivery handles attachments differently — the
        // file is stored directly into the recipient's private file area, so crashmail is
        // not required or meaningful.
        if ($attachment !== null && $toAddress !== $originAddress && !$crashmail) {
            @unlink($attachment['file_path'] ?? '');
            throw new \Exception('File attachments require crashmail (direct delivery) to be enabled.');
        }

        // For file attachments, the FidoNet convention is that the subject IS the filename
        if ($attachment !== null) {
            $subject = $attachment['filename'];
        }

        // For crashmail, verify destination can be resolved via nodelist before accepting
        if ($crashmail && $toAddress !== $originAddress) {
            $crashmailService = new \BinktermPHP\Crashmail\CrashmailService();
            $routeInfo = $crashmailService->resolveDestination($toAddress);

            if (empty($routeInfo['hostname'])) {
                $this->logger->warning("[NETMAIL] Crashmail destination not resolvable: {$toAddress}");
                throw new \Exception("Cannot send crashmail to {$toAddress}: destination not found in nodelist. The node may not have an internet address listed, or may not exist. Try sending without crashmail to route via your hub.");
            }
        }

        // Determine target packet charset. If the caller supplied an explicit charset, use it.
        // Otherwise, when replying, honour the original message's charset so legacy CP437 /
        // ISO-8859 recipients receive correctly-encoded text. Falls back to UTF-8 when the
        // charset is unknown or the body contains characters that can't be represented.
        if ($charset !== null) {
            $packetCharset = strtoupper($charset);
            // Verify we can actually encode in the requested charset; fall back on failure
            if ($packetCharset !== 'UTF-8') {
                $testConvert = @iconv('UTF-8', $packetCharset . '//IGNORE', $messageText);
                if ($testConvert === false || strlen($testConvert) === 0) {
                    $packetCharset = 'UTF-8';
                }
            }
        } else {
            // Determine default charset: check per-uplink override, then BBS global default.
            // Use getUplinkForDestination() — the same routing logic used to pick the origin
            // address — so the charset lookup is always consistent with packet routing.
            $defaultCharset = \BinktermPHP\BbsConfig::getOutgoingCharset();
            $uplink = $binkpConfig->getUplinkForDestination($toAddress);
            if ($uplink && !empty($uplink['default_charset'])) {
                $defaultCharset = strtoupper($uplink['default_charset']);
            }
            $packetCharset = $defaultCharset;
            if (!empty($replyToId)) {
                $csStmt = $this->db->prepare("SELECT message_charset FROM netmail WHERE id = ?");
                $csStmt->execute([$replyToId]);
                $originalCharset = $csStmt->fetchColumn();
                if ($originalCharset) {
                    $candidate = strtoupper($originalCharset);
                    $testConvert = @iconv('UTF-8', $candidate . '//IGNORE', $messageText);
                    if ($testConvert !== false && strlen($testConvert) > 0) {
                        $packetCharset = $candidate;
                    }
                }
            }
        }

        // Generate kludges for this netmail
        $kludgeLines = $this->generateNetmailKludges($originAddress, $toAddress, $senderName, $toName, $subject, $replyToId, null, $markupAllowed, $packetCharset);

        // Extract MSGID from generated kludges to ensure consistency
        // The kludges contain the authoritative MSGID that will be sent in packets
        $msgId = null;
        if (preg_match('/\x01MSGID:\s*(.+?)$/m', $kludgeLines, $matches)) {
            $msgId = trim($matches[1]);
        }

        $finalMessageText = $this->applyUserSignatureAndTagline($messageText, $fromUserId, $tagline);
        $storage = $this->prepareLocalMessageStorage($finalMessageText);

        $stmt = $this->db->prepare("
            INSERT INTO netmail (user_id, from_address, to_address, from_name, to_name, subject, message_text, raw_message_bytes, message_charset, art_format, date_written, is_sent, reply_to_id, message_id, kludge_lines, bottom_kludges, is_freq, freq_status)
            VALUES (:user_id, :from_address, :to_address, :from_name, :to_name, :subject, :message_text, :raw_message_bytes, :message_charset, :art_format, NOW(), FALSE, :reply_to_id, :message_id, :kludge_lines, NULL, :is_freq, :freq_status)
            RETURNING id
        ");

        $stmt->bindValue(':user_id', $fromUserId, \PDO::PARAM_INT);
        $stmt->bindValue(':from_address', $originAddress);
        $stmt->bindValue(':to_address', $toAddress);
        $stmt->bindValue(':from_name', $senderName);
        $stmt->bindValue(':to_name', $toName);
        $stmt->bindValue(':subject', $subject);
        $stmt->bindValue(':message_text', $storage['message_text']);
        $stmt->bindValue(':raw_message_bytes', $storage['raw_message_bytes'] !== '' ? $storage['raw_message_bytes'] : null, $storage['raw_message_bytes'] !== '' ? \PDO::PARAM_LOB : \PDO::PARAM_NULL);
        $stmt->bindValue(':message_charset', $storage['message_charset']);
        $stmt->bindValue(':art_format', $storage['art_format']);
        $stmt->bindValue(':reply_to_id', $replyToId, $replyToId !== null ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
        $stmt->bindValue(':message_id', $msgId);
        $stmt->bindValue(':kludge_lines', $kludgeLines);
        $stmt->bindValue(':is_freq', $isFreq ? 'true' : 'false');
        $stmt->bindValue(':freq_status', $isFreq ? 'pending' : null, $isFreq ? \PDO::PARAM_STR : \PDO::PARAM_NULL);

        $stmt->execute();
        $insertedRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        $messageId = $insertedRow ? (int)$insertedRow['id'] : 0;

        if ($messageId > 0) {
            // Store attachment path and set FILE_ATTACH attribute when a file is attached
            $recipientUser = null;
            if ($attachment !== null) {
                if ($toAddress === $originAddress) {
                    // Local delivery: store directly into the recipient's private file area.
                    // Look up recipient by to_name; fall back to sysop if not found.
                    $recipientStmt = $this->db->prepare("
                        SELECT id FROM users
                        WHERE LOWER(real_name) = LOWER(?) OR LOWER(username) = LOWER(?)
                        LIMIT 1
                    ");
                    $recipientStmt->execute([$toName, $toName]);
                    $recipientUser = $recipientStmt->fetch();

                    if (!$recipientUser) {
                        // Fall back to sysop
                        try {
                            $sysopName = $binkpConfig->getSystemSysop();
                            $sysopStmt = $this->db->prepare("
                                SELECT id FROM users
                                WHERE LOWER(real_name) = LOWER(?) OR LOWER(username) = LOWER(?)
                                LIMIT 1
                            ");
                            $sysopStmt->execute([$sysopName, $sysopName]);
                            $recipientUser = $sysopStmt->fetch();
                        } catch (\Exception $e) {
                            $recipientUser = null;
                        }
                    }

                    if ($recipientUser) {
                        // Make a copy for the sender before moving the file to the recipient's area.
                        $senderCopyPath = null;
                        $senderIsDifferent = (int)$recipientUser['id'] !== (int)$fromUserId;
                        if ($senderIsDifferent) {
                            $senderCopyPath = $attachment['file_path'] . '.sendercopy';
                            if (!copy($attachment['file_path'], $senderCopyPath)) {
                                $this->logger->error("[NETMAIL] Failed to copy attachment for sender copy on message {$messageId}; sender will not have a copy.");
                                $senderCopyPath = null;
                            }
                        }

                        try {
                            $fileAreaManager = new \BinktermPHP\FileAreaManager();
                            $fileAreaManager->storeNetmailAttachment(
                                (int)$recipientUser['id'],
                                $attachment['file_path'],
                                $attachment['filename'],
                                $messageId,
                                $originAddress
                            );
                        } catch (\Exception $e) {
                            $this->logger->error("[NETMAIL] Failed to store local attachment for message {$messageId}: " . $e->getMessage());
                            @unlink($attachment['file_path']);
                            @unlink($senderCopyPath);
                            $senderCopyPath = null;
                        }

                        // Store a copy in the sender's private area
                        if ($senderCopyPath !== null) {
                            try {
                                $fileAreaManager = new \BinktermPHP\FileAreaManager();
                                $fileAreaManager->storeNetmailAttachment(
                                    (int)$fromUserId,
                                    $senderCopyPath,
                                    $attachment['filename'],
                                    $messageId,
                                    $originAddress,
                                    'netmail_sent',
                                    "Sent to {$toName}"
                                );
                            } catch (\Exception $e) {
                                $this->logger->error("[NETMAIL] Failed to store sender copy for message {$messageId}: " . $e->getMessage());
                                @unlink($senderCopyPath);
                            }
                        }
                    } else {
                        $this->logger->warning("[NETMAIL] Could not find recipient '{$toName}' for local attachment on message {$messageId}; file dropped.");
                        @unlink($attachment['file_path']);
                    }
                } else {
                    // Remote delivery: record outbound attachment path and FILE_ATTACH attribute
                    $fileAttachAttr = \BinktermPHP\Crashmail\CrashmailService::ATTR_PRIVATE
                        | \BinktermPHP\Crashmail\CrashmailService::ATTR_FILE_ATTACH
                        | \BinktermPHP\Crashmail\CrashmailService::ATTR_LOCAL;
                    $attStmt = $this->db->prepare("
                        UPDATE netmail SET outbound_attachment_path = ?, attributes = ?
                        WHERE id = ?
                    ");
                    $attStmt->execute([$attachment['file_path'], $fileAttachAttr, $messageId]);
                }
            }

            // Forward to recipient's email if they have forwarding enabled and this is local delivery.
            // For local delivery the recipient is different from the sender.
            if ($toAddress === $originAddress) {
                // If $recipientUser was not resolved during attachment handling (no attachment),
                // look it up now so the email forwarding hook has a user ID to work with.
                if ($recipientUser === null) {
                    $fwdRecipStmt = $this->db->prepare("
                        SELECT id FROM users
                        WHERE LOWER(real_name) = LOWER(?) OR LOWER(username) = LOWER(?)
                        LIMIT 1
                    ");
                    $fwdRecipStmt->execute([$toName, $toName]);
                    $recipientUser = $fwdRecipStmt->fetch() ?: null;
                }

                if (isset($recipientUser['id']) && (int)$recipientUser['id'] !== (int)$fromUserId) {
                    // Gather attachment paths from the files table for this message
                    $fwdAttachments = [];
                    if ($attachment !== null) {
                        $attStmt = $this->db->prepare(
                            "SELECT storage_path, filename FROM files WHERE message_id = ? AND message_type = 'netmail' AND owner_id = ? AND subfolder = 'attachments' LIMIT 5"
                        );
                        $attStmt->execute([$messageId, (int)$recipientUser['id']]);
                        foreach ($attStmt->fetchAll() as $row) {
                            if (!empty($row['storage_path']) && file_exists($row['storage_path'])) {
                                $fwdAttachments[] = ['path' => $row['storage_path'], 'filename' => $row['filename']];
                            }
                        }
                    }
                    \BinktermPHP\Mail::maybeForwardNetmail(
                        (int)$recipientUser['id'],
                        $senderName,
                        $originAddress,
                        $subject,
                        $finalMessageText,
                        $fwdAttachments
                    );
                }
            }

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
                        $this->logger->warning("[NETMAIL] Crashmail queue failed, falling back to normal delivery: " . $e->getMessage());
                        $this->spoolOutboundNetmail($messageId);
                    }
                } else {
                    // Normal delivery via hub routing
                    $this->spoolOutboundNetmail($messageId);
                }
            }
        }

        return $messageId > 0;
    }

    private function sendLocalSysopMessage($fromUserId, $subject, $messageText, $fromName = null, $replyToId = null, $tagline = null, $markupType = null, $attachment = null)
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

        $finalMessageText = $this->applyUserSignatureAndTagline($messageText, $fromUserId, $tagline);
        $storage = $this->prepareLocalMessageStorage($finalMessageText);

        // Generate kludges for this local netmail
        $kludgeLines = $this->generateNetmailKludges($systemAddress, $systemAddress, $senderName, $sysopName, $subject, $replyToId, null, $markupType);

        // Extract MSGID from generated kludges to ensure consistency
        $msgId = null;
        if (preg_match('/\x01MSGID:\s*(.+?)$/m', $kludgeLines, $matches)) {
            $msgId = trim($matches[1]);
        }

        // Create local netmail message to sysop — is_sent = FALSE marks it as received
        // (inbox) from the sysop's perspective; no outbound spooling occurs for local delivery.
        $stmt = $this->db->prepare("
            INSERT INTO netmail (user_id, from_address, to_address, from_name, to_name, subject, message_text, raw_message_bytes, message_charset, art_format, date_written, is_sent, reply_to_id, message_id, kludge_lines, bottom_kludges)
            VALUES (:user_id, :from_address, :to_address, :from_name, :to_name, :subject, :message_text, :raw_message_bytes, :message_charset, :art_format, NOW(), FALSE, :reply_to_id, :message_id, :kludge_lines, NULL)
            RETURNING id
        ");

        $stmt->bindValue(':user_id', $fromUserId, \PDO::PARAM_INT);
        $stmt->bindValue(':from_address', $systemAddress);
        $stmt->bindValue(':to_address', $systemAddress);
        $stmt->bindValue(':from_name', $senderName);
        $stmt->bindValue(':to_name', $sysopName);
        $stmt->bindValue(':subject', $subject);
        $stmt->bindValue(':message_text', $storage['message_text']);
        $stmt->bindValue(':raw_message_bytes', $storage['raw_message_bytes'] !== '' ? $storage['raw_message_bytes'] : null, $storage['raw_message_bytes'] !== '' ? \PDO::PARAM_LOB : \PDO::PARAM_NULL);
        $stmt->bindValue(':message_charset', $storage['message_charset']);
        $stmt->bindValue(':art_format', $storage['art_format']);
        $stmt->bindValue(':reply_to_id', $replyToId, $replyToId !== null ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
        $stmt->bindValue(':message_id', $msgId);
        $stmt->bindValue(':kludge_lines', $kludgeLines);

        $stmt->execute();
        $insertedRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        $messageId = $insertedRow ? (int)$insertedRow['id'] : 0;

        if ($messageId > 0) {
            // Store attachment into sysop's private file area if provided
            if ($attachment !== null) {
                try {
                    $fileAreaManager = new \BinktermPHP\FileAreaManager();
                    $fileAreaManager->storeNetmailAttachment(
                        (int)$sysopUser['id'],
                        $attachment['file_path'],
                        $attachment['filename'],
                        $messageId,
                        $systemAddress
                    );
                } catch (\Exception $e) {
                    $this->logger->error("[NETMAIL] Failed to store local sysop attachment for message {$messageId}: " . $e->getMessage());
                    @unlink($attachment['file_path']);
                }
            }

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

        return $messageId > 0;
    }

    /**
     * @param bool $skipCredits If true, skip awarding credits (used for cross-posted copies)
     */
    public function postEchomail($fromUserId, $echoareaTag, $domain, $toName, $subject, $messageText, $replyToId = null, $tagline = null, $skipCredits = false, $markupType = null, $prependKludges = '', $tearlineComponent = null, $charset = null)
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
                $this->logger->error("[ECHOMAIL] Outbound directory not writable: {$outboundPath}");
                throw new \Exception('Message delivery system unavailable. Please try again later.');
            }
        }

        // Generate kludges for this echomail
        $fromName = $this->resolveEchomailPostingName($user, $echoarea, (string)$domain);
        $toName = $toName ?: 'All';
        $markupAllowed = null;
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            if ($markupType !== null && ($isLocalArea || $binkpConfig->isMarkdownAllowedForDomain($domain))) {
                $markupAllowed = $markupType;
            }
        } catch (\Exception $e) {
            $markupAllowed = null;
        }

        // Determine target packet charset. If the caller supplied an explicit charset (e.g.
        // from the compose form's encoding selector), use it. Otherwise, when replying,
        // honour the original message's charset so legacy CP437 / ISO-8859 areas receive
        // correctly-encoded text. Falls back to UTF-8 when the charset is unknown or the
        // body contains characters that can't be represented.
        if ($charset !== null) {
            $packetCharset = strtoupper($charset);
            if ($packetCharset !== 'UTF-8') {
                $testConvert = @iconv('UTF-8', $packetCharset . '//IGNORE', $messageText);
                if ($testConvert === false || strlen($testConvert) === 0) {
                    $packetCharset = 'UTF-8';
                }
            }
        } else {
            // Local areas are stored only in the database (no FTN packet encoding), so UTF-8 is
            // always the right choice regardless of the BBS-wide or per-uplink charset default.
            if ($isLocalArea) {
                $defaultCharset = 'UTF-8';
            } else {
                $defaultCharset = \BinktermPHP\BbsConfig::getOutgoingCharset();
                $uplink = $binkpConfig->getUplinkByDomain((string)$domain);
                if ($uplink && !empty($uplink['default_charset'])) {
                    $defaultCharset = strtoupper($uplink['default_charset']);
                }
            }
            $packetCharset = $defaultCharset;
            if (!empty($replyToId)) {
                $csStmt = $this->db->prepare("SELECT message_charset FROM echomail WHERE id = ?");
                $csStmt->execute([$replyToId]);
                $originalCharset = $csStmt->fetchColumn();
                if ($originalCharset) {
                    $candidate = strtoupper($originalCharset);
                    $testConvert = @iconv('UTF-8', $candidate . '//IGNORE', $messageText);
                    if ($testConvert !== false && strlen($testConvert) > 0) {
                        $packetCharset = $candidate;
                    }
                }
            }
        }

        $kludgeLines = $prependKludges . $this->generateEchomailKludges($myAddress, $fromName, $toName, $subject, $echoareaTag, $replyToId, $markupAllowed, $domain, $packetCharset);

        // Extract MSGID from generated kludges to ensure consistency
        // The kludges contain the authoritative MSGID that will be sent in packets
        $msgId = null;
        if (preg_match('/\x01MSGID:\s*(.+?)$/m', $kludgeLines, $matches)) {
            $msgId = trim($matches[1]);
        }

        $finalMessageText = $this->applyUserSignatureAndTagline($messageText, $fromUserId, $tagline);
        $storage = $this->prepareLocalMessageStorage($finalMessageText);

        // Determine whether this post needs to be held for moderation.
        // Moderation only applies to networked areas when the threshold is > 0
        // (threshold 0 = feature disabled). Admins always bypass.
        $moderationThreshold = \BinktermPHP\BbsConfig::getEchomailModerationThreshold();
        $needsModeration = false;
        if ($moderationThreshold > 0 && !$isLocalArea && empty($user['is_admin'])) {
            $needsModeration = empty($user['can_post_netecho_unmoderated']);
        }
        $moderationStatus = $needsModeration ? 'pending' : 'approved';

        $stmt = $this->db->prepare("
            INSERT INTO echomail (echoarea_id, from_address, from_name, to_name, subject, message_text, raw_message_bytes, message_charset, art_format, date_written, reply_to_id, message_id, origin_line, kludge_lines, bottom_kludges, tearline_component, user_id, moderation_status)
            VALUES (:echoarea_id, :from_address, :from_name, :to_name, :subject, :message_text, :raw_message_bytes, :message_charset, :art_format, NOW(), :reply_to_id, :message_id, :origin_line, :kludge_lines, NULL, :tearline_component, :user_id, :moderation_status)
            RETURNING id
        ");

        $stmt->bindValue(':echoarea_id', $echoarea['id'], \PDO::PARAM_INT);
        $stmt->bindValue(':from_address', $myAddress);
        $stmt->bindValue(':from_name', $fromName);
        $stmt->bindValue(':to_name', $toName);
        $stmt->bindValue(':subject', $subject);
        $stmt->bindValue(':message_text', $storage['message_text']);
        $stmt->bindValue(':raw_message_bytes', $storage['raw_message_bytes'] !== '' ? $storage['raw_message_bytes'] : null, $storage['raw_message_bytes'] !== '' ? \PDO::PARAM_LOB : \PDO::PARAM_NULL);
        $stmt->bindValue(':message_charset', $storage['message_charset']);
        $stmt->bindValue(':art_format', $storage['art_format']);
        $stmt->bindValue(':reply_to_id', $replyToId, $replyToId !== null ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
        $stmt->bindValue(':message_id', $msgId);
        $stmt->bindValue(':origin_line', null, \PDO::PARAM_NULL);
        $stmt->bindValue(':kludge_lines', $kludgeLines);
        $stmt->bindValue(':tearline_component', $tearlineComponent);
        $stmt->bindValue(':user_id', $fromUserId, \PDO::PARAM_INT);
        $stmt->bindValue(':moderation_status', $moderationStatus);

        $stmt->execute();
        $insertedRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        $messageId = $insertedRow ? (int)$insertedRow['id'] : 0;

        if ($messageId > 0) {
            if (!$skipCredits) {
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
                        $this->logger->error('[CREDITS] Echomail reward failed.');
                    }
                }
            }
            $this->incrementEchoareaCount(
                $echoarea['id'],
                $needsModeration ? null : $subject,
                $needsModeration ? null : $fromName
            );

            if ($needsModeration) {
                $this->logger->info("[ECHOMAIL] Message #{$messageId} held for moderation (user {$fromUserId}, area {$echoareaTag})");
                return 'pending';
            }

            $this->spoolOutboundEchomail($messageId, $echoareaTag, $domain);
        }

        return $messageId > 0;
    }

    /**
     * Approve a pending echomail message: mark it approved, spool it for
     * transmission, and auto-promote the author if they have reached the
     * configured moderation threshold.
     *
     * @param int $messageId
     * @return bool True on success, false if the message was not found or not pending.
     */
    public function approveEchomail(int $messageId): bool
    {
        $stmt = $this->db->prepare("
            SELECT em.*, ea.tag AS echoarea_tag, ea.domain AS echoarea_domain, ea.is_local
            FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            WHERE em.id = ? AND em.moderation_status = 'pending'
        ");
        $stmt->execute([$messageId]);
        $message = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$message) {
            return false;
        }

        $updateStmt = $this->db->prepare("
            UPDATE echomail SET moderation_status = 'approved' WHERE id = ?
        ");
        $updateStmt->execute([$messageId]);

        // Update the cached last-post info now that this message is visible.
        // Only overwrite if this message is newer than whatever is currently cached.
        $dateReceived = $message['date_received'] ?? date('Y-m-d H:i:s');
        $lpStmt = $this->db->prepare("
            UPDATE echoareas
            SET last_post_subject = ?,
                last_post_author  = ?,
                last_post_date    = ?
            WHERE id = ?
              AND (last_post_date IS NULL OR last_post_date <= ?)
        ");
        $lpStmt->execute([
            mb_substr($message['subject'] ?? '', 0, 255),
            mb_substr($message['from_name'] ?? '', 0, 100),
            $dateReceived,
            $message['echoarea_id'],
            $dateReceived,
        ]);

        $echoareaTag = $message['echoarea_tag'];
        $domain      = $message['echoarea_domain'] ?? '';

        $this->spoolOutboundEchomail($messageId, $echoareaTag, $domain);

        // Check whether the author should be auto-promoted
        $userId = $message['user_id'] ? (int)$message['user_id'] : null;
        if ($userId) {
            $this->checkAndPromoteEchomailUser($userId);
        }

        return true;
    }

    /**
     * Reject a pending echomail message: mark it rejected so it is permanently
     * suppressed and never transmitted.
     *
     * @param int $messageId
     * @return bool True on success, false if not found or not pending.
     */
    public function rejectEchomail(int $messageId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE echomail SET moderation_status = 'rejected'
            WHERE id = ? AND moderation_status = 'pending'
        ");
        $stmt->execute([$messageId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * If the user has accumulated at least the configured number of approved
     * networked echomail posts, flip can_post_netecho_unmoderated to TRUE.
     *
     * @param int $userId
     */
    private function checkAndPromoteEchomailUser(int $userId): void
    {
        $threshold = \BinktermPHP\BbsConfig::getEchomailModerationThreshold();
        if ($threshold <= 0) {
            return;
        }

        // Check if already promoted
        $flagStmt = $this->db->prepare("
            SELECT can_post_netecho_unmoderated FROM users WHERE id = ?
        ");
        $flagStmt->execute([$userId]);
        $flag = $flagStmt->fetchColumn();
        if ($flag === true || $flag === 't' || $flag === '1') {
            return;
        }

        $countStmt = $this->db->prepare("
            SELECT COUNT(*) FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            WHERE em.user_id = ?
              AND em.moderation_status = 'approved'
              AND ea.is_local = FALSE
        ");
        $countStmt->execute([$userId]);
        $approvedCount = (int)$countStmt->fetchColumn();

        if ($approvedCount >= $threshold) {
            $promoteStmt = $this->db->prepare("
                UPDATE users SET can_post_netecho_unmoderated = TRUE WHERE id = ?
            ");
            $promoteStmt->execute([$userId]);
            $this->logger->info("[MODERATION] User #{$userId} auto-promoted to unmoderated echomail posting ({$approvedCount} approved posts >= threshold {$threshold})");
        }
    }

    /**
     * Build a SQL fragment and parameter list to enforce echomail moderation
     * visibility: approved messages are visible to all; pending messages are
     * visible only to their author; rejected messages are never shown.
     *
     * @param int|null $userId  The current user's ID (0 or null = unauthenticated).
     * @param string   $alias   The echomail table alias used in the query (default 'em').
     * @return array{sql: string, params: array}
     */
    public function buildModerationVisibilityFilter(?int $userId, string $alias = 'em'): array
    {
        if ($userId) {
            return [
                'sql'    => " AND ({$alias}.moderation_status = 'approved' OR ({$alias}.moderation_status = 'pending' AND {$alias}.user_id = ?))",
                'params' => [$userId],
            ];
        }
        return [
            'sql'    => " AND {$alias}.moderation_status = 'approved'",
            'params' => [],
        ];
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

    private function prepareLocalMessageStorage(string $messageText): array
    {
        return [
            'message_text' => $messageText,
            'raw_message_bytes' => $messageText,
            'message_charset' => 'UTF-8',
            'art_format' => ArtFormatDetector::detectArtFormat($messageText, 'UTF-8'),
        ];
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

    /**
     * Parse a tag@domain echoarea identifier and append the matching SQL conditions.
     * Accepts either "tag@domain" or plain "tag" (domain defaults to 'fidonet').
     *
     * @param string $echoarea Tag-only or "tag@domain" string
     * @param string &$sql     SQL string to append conditions to
     * @param array  &$params  Bind-parameter array to append values to
     */
    private function appendEchoareaCondition(string $echoarea, string &$sql, array &$params): void
    {
        if (str_contains($echoarea, '@')) {
            [$tag, $domain] = explode('@', $echoarea, 2);
        } else {
            $tag = $echoarea;
            $domain = null;
        }
        $sql .= " AND ea.tag = ?";
        $params[] = $tag;
        if ($domain !== null) {
            $sql .= " AND ea.domain = ?";
            $params[] = $domain;
        }
    }

    /**
     * Build a SQL WHERE fragment for text-based message searches.
     * Returns [null, []] when no text search terms are present (date-only searches).
     *
     * @param string $query General search query used when $searchParams has no text fields
     * @param array $searchParams Field-specific params: keys 'from_name', 'subject', 'body', 'date_from', 'date_to'
     * @param string $tableAlias Table alias prefix, e.g. 'em.' or ''
     * @return array [string|null $whereFragment, array $bindParams]
     */
    private function buildSearchWhereFragment($query, $searchParams, $tableAlias = '')
    {
        $conditions = [];
        $params = [];

        $hasFieldSearch = !empty($searchParams['from_name']) || !empty($searchParams['subject']) || !empty($searchParams['body']) || !empty($searchParams['message_id']);

        if ($hasFieldSearch) {
            if (!empty($searchParams['from_name'])) {
                $conditions[] = $tableAlias . 'from_name ILIKE ?';
                $params[] = '%' . $searchParams['from_name'] . '%';
            }
            if (!empty($searchParams['subject'])) {
                $conditions[] = $tableAlias . 'subject ILIKE ?';
                $params[] = '%' . $searchParams['subject'] . '%';
            }
            if (!empty($searchParams['body'])) {
                $conditions[] = $tableAlias . 'message_text ILIKE ?';
                $params[] = '%' . $searchParams['body'] . '%';
            }
            if (!empty($searchParams['message_id'])) {
                $conditions[] = $tableAlias . 'message_id ILIKE ?';
                $params[] = '%' . $searchParams['message_id'] . '%';
            }
            return ['(' . implode(' AND ', $conditions) . ')', $params];
        }

        if ($query !== '') {
            $searchTerm = '%' . $query . '%';
            return [
                '(' . $tableAlias . 'subject ILIKE ? OR ' . $tableAlias . 'message_text ILIKE ? OR ' . $tableAlias . 'from_name ILIKE ?)',
                [$searchTerm, $searchTerm, $searchTerm]
            ];
        }

        // No text search terms — caller handles date-only searches
        return [null, []];
    }

    /**
     * Build SQL conditions for date range filtering.
     * Date arithmetic is done in PHP to avoid PDO/pgsql issues with ?::cast syntax.
     *
     * @param array $searchParams Keys 'date_from' and/or 'date_to' (YYYY-MM-DD strings)
     * @param string $dateColumn Fully-qualified column name, e.g. 'em.date_received'
     * @return array [array $conditions, array $bindParams]
     */
    private function buildDateRangeConditions($searchParams, $dateColumn)
    {
        $conditions = [];
        $params = [];

        if (!empty($searchParams['date_from'])) {
            $conditions[] = "{$dateColumn} >= ?";
            $params[] = $searchParams['date_from'] . ' 00:00:00';
        }
        if (!empty($searchParams['date_to'])) {
            // Advance by one day so the range is inclusive of the end date
            $dateTo = new \DateTime($searchParams['date_to']);
            $dateTo->modify('+1 day');
            $conditions[] = "{$dateColumn} < ?";
            $params[] = $dateTo->format('Y-m-d') . ' 00:00:00';
        }

        return [$conditions, $params];
    }

    /**
     * Search messages by query or field-specific parameters.
     *
     * @param string $query General search query (used when $searchParams is empty)
     * @param string|null $type 'echomail' or 'netmail'
     * @param string|null $echoarea Echo area tag to restrict search
     * @param int|null $userId User ID for permission checking
     * @param array $searchParams Field-specific search: keys 'from_name', 'subject', 'body', 'date_from', 'date_to'
     * @return array
     */
    public function searchMessages($query, $type = null, $echoarea = null, $userId = null, $searchParams = [])
    {
        if ($type === 'netmail') {
            if ($userId === null) {
                // If no user ID provided, return empty results for privacy
                return [];
            }
            [$whereFragment, $searchBindParams] = $this->buildSearchWhereFragment($query, $searchParams, '');
            [$dateConditions, $dateParams] = $this->buildDateRangeConditions($searchParams, 'date_received');

            $sql = "SELECT id, from_name, from_address, to_name, to_address,
                           subject, date_received, date_written, message_id, reply_to_id,
                           art_format, message_charset, user_id,
                           deleted_by_sender, deleted_by_recipient
                    FROM netmail WHERE user_id = ?";
            $params = [$userId];

            if ($whereFragment !== null) {
                $sql .= " AND {$whereFragment}";
                $params = array_merge($params, $searchBindParams);
            }
            foreach ($dateConditions as $cond) {
                $sql .= " AND {$cond}";
            }
            $params = array_merge($params, $dateParams);

            $sql .= " ORDER BY CASE WHEN date_received > NOW() THEN 0 ELSE 1 END, date_received DESC LIMIT 50";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        } else {
            $dateField = $this->getEchomailDateField();
            $isAdmin = false;
            if ($userId) {
                $user = $this->getUserById($userId);
                $isAdmin = $user && !empty($user['is_admin']);
            }
            [$whereFragment, $searchBindParams] = $this->buildSearchWhereFragment($query, $searchParams, 'em.');
            [$dateConditions, $dateParams] = $this->buildDateRangeConditions($searchParams, "em.{$dateField}");

            $sql = "
                SELECT em.id, em.from_name, em.from_address, em.to_name,
                       em.subject, em.date_received, em.date_written, em.echoarea_id,
                       em.message_id, em.reply_to_id,
                       COALESCE(NULLIF(em.art_format, ''), NULLIF(ea.art_format_hint, '')) as art_format,
                       ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                WHERE 1=1
            ";
            $params = [];

            if ($whereFragment !== null) {
                $sql .= " AND {$whereFragment}";
                $params = array_merge($params, $searchBindParams);
            }
            foreach ($dateConditions as $cond) {
                $sql .= " AND {$cond}";
            }
            $params = array_merge($params, $dateParams);

            if (!$isAdmin) {
                $sql .= " AND COALESCE(ea.is_sysop_only, FALSE) = FALSE";
            }

            if ($echoarea) {
                $this->appendEchoareaCondition($echoarea, $sql, $params);
            }

            $sql .= " ORDER BY CASE WHEN em.{$dateField} > NOW() THEN 0 ELSE 1 END, em.{$dateField} DESC LIMIT 200";

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
     * @param array $searchParams Field-specific search: keys 'from_name', 'subject', 'body', 'date_from', 'date_to'
     * @return array Array with filter counts
     */
    public function getSearchFilterCounts($query, $echoarea = null, $userId = null, $searchParams = [])
    {
        $isAdmin = false;
        $userRealName = null;
        if ($userId) {
            $user = $this->getUserById($userId);
            $isAdmin = $user && !empty($user['is_admin']);
            $userRealName = $user['real_name'] ?? null;
        }

        $dateField = $this->getEchomailDateField();
        [$whereFragment, $searchBindParams] = $this->buildSearchWhereFragment($query, $searchParams, 'em.');
        [$dateConditions, $dateParams] = $this->buildDateRangeConditions($searchParams, "em.{$dateField}");

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
            WHERE ea.is_active = TRUE
        ";

        $params = [$userRealName, $userId, $userId];

        if ($whereFragment !== null) {
            $sql .= " AND {$whereFragment}";
            $params = array_merge($params, $searchBindParams);
        }
        foreach ($dateConditions as $cond) {
            $sql .= " AND {$cond}";
        }
        $params = array_merge($params, $dateParams);

        if (!$isAdmin) {
            $sql .= " AND COALESCE(ea.is_sysop_only, FALSE) = FALSE";
        }

        if ($echoarea) {
            $this->appendEchoareaCondition($echoarea, $sql, $params);
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
     * @param array $searchParams Field-specific search: keys 'from_name', 'subject', 'body', 'date_from', 'date_to'
     * @return array Array of echo areas with their search result counts
     */
    public function getSearchResultCounts($query, $echoarea = null, $userId = null, $searchParams = [])
    {
        $isAdmin = false;
        if ($userId) {
            $user = $this->getUserById($userId);
            $isAdmin = $user && !empty($user['is_admin']);
        }

        $dateField = $this->getEchomailDateField();
        [$whereFragment, $searchBindParams] = $this->buildSearchWhereFragment($query, $searchParams, 'em.');
        [$dateConditions, $dateParams] = $this->buildDateRangeConditions($searchParams, "em.{$dateField}");

        // Build the ON clause for the LEFT JOIN
        $joinConditions = ['em.echoarea_id = ea.id'];
        $params = [];
        if ($whereFragment !== null) {
            $joinConditions[] = $whereFragment;
            $params = array_merge($params, $searchBindParams);
        }
        foreach ($dateConditions as $cond) {
            $joinConditions[] = $cond;
        }
        $params = array_merge($params, $dateParams);

        $joinClause = implode(' AND ', $joinConditions);

        $sql = "
            SELECT
                ea.id,
                ea.tag,
                ea.domain,
                COUNT(em.id) as message_count
            FROM echoareas ea
            LEFT JOIN echomail em ON {$joinClause}
            WHERE ea.is_active = TRUE
        ";

        if (!$isAdmin) {
            $sql .= " AND COALESCE(ea.is_sysop_only, FALSE) = FALSE";
        }

        if ($echoarea) {
            $this->appendEchoareaCondition($echoarea, $sql, $params);
        }

        $sql .= " GROUP BY ea.id, ea.tag, ea.domain ORDER BY ea.tag";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Get filter counts (all, unread, read, tome, saved) for a specific set of message IDs.
     * Much faster than re-running the full search query — used after searchMessages() returns results.
     *
     * @param array $messageIds Array of echomail IDs already fetched by searchMessages()
     * @param int|null $userId
     * @return array
     */
    public function getSearchFilterCountsByIds($messageIds, $userId)
    {
        if (empty($messageIds)) {
            return ['all' => 0, 'unread' => 0, 'read' => 0, 'tome' => 0, 'saved' => 0, 'drafts' => 0];
        }

        $userRealName = null;
        if ($userId) {
            $user = $this->getUserById($userId);
            $userRealName = $user['real_name'] ?? null;
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));

        $sql = "
            SELECT
                COUNT(*) as all_count,
                COUNT(*) FILTER (WHERE mr.id IS NULL) as unread_count,
                COUNT(*) FILTER (WHERE mr.id IS NOT NULL) as read_count,
                COUNT(*) FILTER (WHERE em.to_name = ?) as tome_count,
                COUNT(*) FILTER (WHERE sm.message_id IS NOT NULL) as saved_count
            FROM echomail em
            LEFT JOIN message_read_status mr ON mr.message_id = em.id
                AND mr.message_type = 'echomail'
                AND mr.user_id = ?
            LEFT JOIN saved_messages sm ON sm.message_id = em.id
                AND sm.message_type = 'echomail'
                AND sm.user_id = ?
            WHERE em.id IN ({$placeholders})
        ";

        $params = array_merge([$userRealName, $userId, $userId], $messageIds);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        return [
            'all' => (int)$result['all_count'],
            'unread' => (int)$result['unread_count'],
            'read' => (int)$result['read_count'],
            'tome' => (int)$result['tome_count'],
            'saved' => (int)$result['saved_count'],
            'drafts' => 0
        ];
    }

    private function getUserById($userId)
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }

    private function getEchoareaByTag($tag, $domain)
    {
        if (empty($domain)) {
            $stmt = $this->db->prepare("SELECT * FROM echoareas WHERE tag = ? AND (domain IS NULL OR domain = '') AND is_active = TRUE");
            $stmt->execute([$tag]);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM echoareas WHERE tag = ? AND domain = ? AND is_active = TRUE");
            $stmt->execute([$tag, $domain]);
        }
        return $stmt->fetch();
    }

    /**
     * Resolve the display/sender name used for outbound echomail posting.
     *
     * Priority:
     * 1) Echo area override (posting_name_policy on echoareas)
     * 2) Uplink policy by domain (posting_name_policy on uplink config)
     * 3) Default: real_name
     */
    private function resolveEchomailPostingName(array $user, array $echoarea, string $domain): string
    {
        $realName = trim((string)($user['real_name'] ?? ''));
        $username = trim((string)($user['username'] ?? ''));

        $selectByPolicy = static function (string $policy) use ($realName, $username): string {
            if ($policy === 'username') {
                return $username !== '' ? $username : $realName;
            }
            return $realName !== '' ? $realName : $username;
        };

        $echoPolicy = strtolower(trim((string)($echoarea['posting_name_policy'] ?? '')));
        if (in_array($echoPolicy, ['real_name', 'username'], true)) {
            return $selectByPolicy($echoPolicy);
        }

        $uplinkPolicy = 'real_name';
        if ($domain !== '') {
            try {
                $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
                $uplinkPolicy = $binkpConfig->getPostingNamePolicyForDomain($domain);
            } catch (\Throwable $e) {
                $uplinkPolicy = 'real_name';
            }
        }

        return $selectByPolicy($uplinkPolicy);
    }

    /**
     * Resolve the display/sender name used for outbound netmail posting.
     *
     * Priority:
     * 1) Uplink policy for destination routing
     * 2) Default: real_name
     */
    private function resolveNetmailPostingName(array $user, string $toAddress): string
    {
        $realName = trim((string)($user['real_name'] ?? ''));
        $username = trim((string)($user['username'] ?? ''));

        $selectByPolicy = static function (string $policy) use ($realName, $username): string {
            if ($policy === 'username') {
                return $username !== '' ? $username : $realName;
            }
            return $realName !== '' ? $realName : $username;
        };

        if (trim($toAddress) === '') {
            return $selectByPolicy('real_name');
        }

        $policy = 'real_name';
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $policy = $binkpConfig->getPostingNamePolicyForDestination($toAddress);
        } catch (\Throwable $e) {
            $policy = 'real_name';
        }

        return $selectByPolicy($policy);
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

        // Soft delete: mark as deleted by sender or recipient
        if ($isSender) {
            $updateStmt = $this->db->prepare("UPDATE netmail SET deleted_by_sender = TRUE WHERE id = ?");
            $updateStmt->execute([$messageId]);
        } else {
            $updateStmt = $this->db->prepare("UPDATE netmail SET deleted_by_recipient = TRUE WHERE id = ?");
            $updateStmt->execute([$messageId]);
        }

        // If both parties have deleted it, permanently delete the record
        $checkStmt = $this->db->prepare("SELECT deleted_by_sender, deleted_by_recipient FROM netmail WHERE id = ?");
        $checkStmt->execute([$messageId]);
        $flags = $checkStmt->fetch();

        if ($flags && $flags['deleted_by_sender'] && $flags['deleted_by_recipient']) {
            $deleteStmt = $this->db->prepare("DELETE FROM netmail WHERE id = ?");
            $deleteStmt->execute([$messageId]);
        }

        return true;
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

            // Advance the dashboard badge watermark (only moves forward, never back).
            $this->db->prepare("
                UPDATE user_echoarea_subscriptions ues
                SET last_read_id = ?
                WHERE ues.user_id = ?
                  AND ues.echoarea_id = (SELECT echoarea_id FROM echomail WHERE id = ?)
                  AND ues.is_active = TRUE
                  AND (ues.last_read_id IS NULL OR ues.last_read_id < ?)
            ")->execute([$messageId, $userId, $messageId, $messageId]);
        }
    }

    /**
     * @param string|null $subject  When non-null, also updates last_post_* columns.
     * @param string|null $fromName When non-null, also updates last_post_* columns.
     */
    private function incrementEchoareaCount(int $echoareaId, ?string $subject = null, ?string $fromName = null): void
    {
        if ($subject !== null && $fromName !== null) {
            $stmt = $this->db->prepare("
                UPDATE echoareas
                SET message_count     = message_count + 1,
                    last_post_subject = ?,
                    last_post_author  = ?,
                    last_post_date    = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                mb_substr($subject, 0, 255),
                mb_substr($fromName, 0, 100),
                $echoareaId,
            ]);
        } else {
            $stmt = $this->db->prepare("UPDATE echoareas SET message_count = message_count + 1 WHERE id = ?");
            $stmt->execute([$echoareaId]);
        }
    }

    private function spoolOutboundNetmail($messageId)
    {
        $stmt = $this->db->prepare("SELECT * FROM netmail WHERE id = ?");
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();

        if (!$message) {
            return false;
        }

        if (!empty($message['spooled_at'])) {
            $spooledAt = (string)$message['spooled_at'];
            $complaint = "[SPOOL] REFUSING to respool netmail #{$messageId}: already spooled at {$spooledAt}. This is a bug, not a retry path.";
            $this->logger->error($complaint);
            \BinktermPHP\Admin\AdminDaemonClient::log('ERROR', 'duplicate netmail spool prevented', [
                'message_id' => $messageId,
                'spooled_at' => $spooledAt,
            ]);
            return false;
        }

        // Extract message details for logging
        $fromName = $message['from_name'] ?? 'unknown';
        $fromAddr = $message['from_address'] ?? 'unknown';
        $toName = $message['to_name'] ?? 'unknown';
        $toAddr = $message['to_address'] ?? 'unknown';
        $subject = $message['subject'] ?? '(no subject)';

        //error_log("[SPOOL] Spooling netmail #{$messageId}: from=\"{$fromName}\" <{$fromAddr}> to=\"{$toName}\" <{$toAddr}>, subject=\"{$subject}\"");

        try {
            $binkdProcessor = new BinkdProcessor();

            // Set netmail attributes: PRIVATE always set; FILE_REQUEST (0x0800) for FREQs
            // is_freq comes back from PostgreSQL as 't'/'f'; avoid !empty() which treats 'f' as truthy
            $message['attributes'] = 0x0001;
            $isFreqMsg = in_array($message['is_freq'], [true, 't', '1', 1, 'true'], true);
            if ($isFreqMsg) {
                $message['attributes'] |= 0x0800;
            }

            // Get the uplink that handles routing for this destination
            // The packet must be addressed to the hub/uplink, not the final destination
            // The final destination is preserved in the message headers and INTL kludge
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $uplink = $binkpConfig->getUplinkForDestination($toAddr);

            if ($uplink) {
                $routeAddress = $uplink['address'];
                //error_log("[SPOOL] Routing netmail through uplink {$routeAddress} for destination {$toAddr}");
            } else {
                // No uplink found - try direct delivery (for local or crash mail)
                $routeAddress = $toAddr;
                //error_log("[SPOOL] No uplink found for {$toAddr}, attempting direct delivery");
            }

            // Create outbound packet routed through the uplink (or direct if no uplink)
            $packetFile = $binkdProcessor->createOutboundPacket([$message], $routeAddress);
            $packetName = basename($packetFile);

            if ($uplink) {
                $this->queueImmediateOutboundPoll($routeAddress, "netmail #{$messageId}");
            }

            // Mark the message as spooled so duplicate spool attempts fail loudly.
            $this->db->prepare("UPDATE netmail SET is_sent = TRUE, spooled_at = CURRENT_TIMESTAMP WHERE id = ?")
                     ->execute([$messageId]);

            \BinktermPHP\Admin\AdminDaemonClient::log('INFO', 'netmail sent', [
                'from'    => "{$fromName} <{$fromAddr}>",
                'to'      => "{$toName} <{$toAddr}>",
                'subject' => $subject,
                'msgid'   => $message['message_id'] ?? '',
                'packet'  => $packetName,
            ]);

            //error_log("[SPOOL] Netmail #{$messageId} spooled to packet {$packetName} (routed via {$routeAddress})");
            return true;
        } catch (\Exception $e) {
            $this->logger->error("[SPOOL] Failed to spool netmail #{$messageId} (from=\"{$fromName}\" subject=\"{$subject}\"): " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Deliver a FREQ response file to a requesting node.
     *
     * Strategy:
     *  1. If crashmail is enabled and the destination is directly resolvable,
     *     queue a FILE_ATTACH crashmail (we connect to them).
     *  2. Otherwise, write a FILE_ATTACH netmail packet + the file into a
     *     per-node hold directory (data/outbound/hold/<address>/) so that
     *     both sides can deliver it — either when they connect to us or when
     *     we poll them. Also send a plain notification netmail via hub routing
     *     telling them to connect and collect their files.
     *
     * Note: routed FILE_ATTACH netmail is intentionally avoided because hubs
     * typically strip file attachments from forwarded messages.
     *
     * @param string $toAddress FTN address of the requesting node
     * @param string $filePath  Absolute path to the staged file to deliver
     * @param string $filename  Filename as presented to the recipient
     * @throws \Exception on configuration or unrecoverable delivery failure
     */
    public function deliverFreqResponse(string $toAddress, string $filePath, string $filename): void
    {
        $binkpConfig   = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $sysopName     = $binkpConfig->getSystemSysop() ?: 'Sysop';
        $originAddress = $binkpConfig->getOriginAddressByDestination($toAddress)
                      ?: $binkpConfig->getSystemAddress();

        if (!$originAddress) {
            throw new \Exception("Cannot determine origin address for FREQ response to {$toAddress}");
        }
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \Exception("FREQ response file not readable: {$filePath}");
        }

        // Try crashmail (direct delivery) first
        if ($binkpConfig->getCrashmailEnabled()) {
            $crashService = new \BinktermPHP\Crashmail\CrashmailService();
            $routeInfo    = $crashService->resolveDestination($toAddress);
            if (!empty($routeInfo['hostname'])) {
                $netmailId = $this->insertFreqFileAttachNetmail(
                    $originAddress, $toAddress, $sysopName, $filePath, $filename
                );
                $crashService->queueCrashmail($netmailId);
                $this->logger->info("[FREQ] Response to {$toAddress} queued for crashmail: {$filename}");
                return;
            }
        }

        // Fallback: write FILE_ATTACH packet + file to per-node hold directory.
        // Delivered when either side initiates a session with the requesting node.
        $this->spoolFreqAttachToHold($originAddress, $toAddress, $sysopName, $filePath, $filename);
        $this->sendFreqPickupNotification($originAddress, $toAddress, $sysopName, $filename);
        $this->logger->info("[FREQ] Response to {$toAddress} staged in hold for pick-up: {$filename}");
    }

    /**
     * Insert a FILE_ATTACH netmail record and return its ID.
     * Used by both the crashmail and hold delivery paths.
     */
    private function insertFreqFileAttachNetmail(
        string $originAddress,
        string $toAddress,
        string $sysopName,
        string $filePath,
        string $filename
    ): int {
        $attributes = \BinktermPHP\Crashmail\CrashmailService::ATTR_PRIVATE
                    | \BinktermPHP\Crashmail\CrashmailService::ATTR_FILE_ATTACH
                    | \BinktermPHP\Crashmail\CrashmailService::ATTR_LOCAL;

        $kludgeLines = $this->generateNetmailKludges(
            $originAddress, $toAddress, $sysopName, 'Sysop', $filename, null
        );
        $msgId = null;
        if (preg_match('/\x01MSGID:\s*(.+?)$/m', $kludgeLines, $matches)) {
            $msgId = trim($matches[1]);
        }

        $stmt = $this->db->prepare("
            INSERT INTO netmail
                (user_id, from_address, to_address, from_name, to_name, subject,
                 message_text, message_charset, date_written, is_sent, message_id,
                 kludge_lines, attributes, outbound_attachment_path, is_freq, freq_status)
            VALUES
                (NULL, :from_address, :to_address, :from_name, :to_name, :subject,
                 '', 'UTF-8', NOW(), FALSE, :message_id,
                 :kludge_lines, :attributes, :attachment_path, FALSE, NULL)
            RETURNING id
        ");
        $stmt->execute([
            ':from_address'    => $originAddress,
            ':to_address'      => $toAddress,
            ':from_name'       => $sysopName,
            ':to_name'         => 'Sysop',
            ':subject'         => $filename,
            ':message_id'      => $msgId,
            ':kludge_lines'    => $kludgeLines,
            ':attributes'      => $attributes,
            ':attachment_path' => $filePath,
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : 0;
    }

    /**
     * Write a FILE_ATTACH netmail packet and the attached file into a per-node
     * hold directory (data/outbound/hold/<sanitized-address>/).
     * BinkpSession::sendHoldFiles() delivers these when a session occurs with
     * the destination node, regardless of which side initiated the connection.
     */
    private function spoolFreqAttachToHold(
        string $originAddress,
        string $toAddress,
        string $sysopName,
        string $filePath,
        string $filename
    ): void {
        $holdDir = $this->getNodeHoldDir($toAddress);

        // Insert the netmail record so it appears in the sender's sent items
        $this->insertFreqFileAttachNetmail($originAddress, $toAddress, $sysopName, $filePath, $filename);

        // Create the outbound packet in the hold directory
        $packetPath = $holdDir . '/' . substr(uniqid(), -8) . '.pkt';
        $message = [
            'from_name'    => $sysopName,
            'from_address' => $originAddress,
            'to_name'      => 'Sysop',
            'to_address'   => $toAddress,
            'subject'      => $filename,
            'message_text' => '',
            'date_written' => date('D, d M Y H:i:s O'),
            'attributes'   => \BinktermPHP\Crashmail\CrashmailService::ATTR_PRIVATE
                            | \BinktermPHP\Crashmail\CrashmailService::ATTR_FILE_ATTACH
                            | \BinktermPHP\Crashmail\CrashmailService::ATTR_LOCAL,
            'kludge_lines' => '',
        ];
        $processor = new \BinktermPHP\BinkdProcessor();
        $processor->createOutboundPacket([$message], $toAddress, $packetPath);

        // Copy the attachment file to the hold directory so sendHoldFiles() can send both
        $destFile = $holdDir . '/' . $filename;
        if (!copy($filePath, $destFile)) {
            throw new \RuntimeException("Failed to copy FREQ attachment to hold directory: {$destFile}");
        }
    }

    /**
     * Return (and create if needed) the per-node hold directory for a given FTN address.
     * Address characters that are not alphanumeric are replaced with underscores.
     */
    private function getNodeHoldDir(string $address): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9]/', '_', $address);
        $dir  = \BinktermPHP\Config::BINKD_OUTBOUND . '/hold/' . $safe;
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /**
     * Send a plain notification netmail (via hub routing) telling a node that
     * their FREQ was fulfilled but requires a direct session to pick up the files.
     */
    private function sendFreqPickupNotification(
        string $originAddress,
        string $toAddress,
        string $sysopName,
        string $filename
    ): void {
        $subject = 'File Request Processed - Pick Up Required';
        $body    = "Your file request for {$filename} has been processed.\n\n"
                 . "The requested file(s) could not be delivered directly because your system\n"
                 . "is not directly reachable from here. The file(s) are queued and waiting\n"
                 . "for you to connect to us ({$originAddress}) to pick them up.\n\n"
                 . "Please arrange a direct binkp session with {$originAddress} to collect\n"
                 . "your files.\n\n"
                 . "--- {$sysopName} @ {$originAddress}\n";

        $kludgeLines = $this->generateNetmailKludges(
            $originAddress, $toAddress, $sysopName, 'Sysop', $subject, null
        );
        $msgId = null;
        if (preg_match('/\x01MSGID:\s*(.+?)$/m', $kludgeLines, $matches)) {
            $msgId = trim($matches[1]);
        }

        $stmt = $this->db->prepare("
            INSERT INTO netmail
                (user_id, from_address, to_address, from_name, to_name, subject,
                 message_text, message_charset, date_written, is_sent, message_id,
                 kludge_lines, is_freq, freq_status)
            VALUES
                (NULL, :from_address, :to_address, :from_name, :to_name, :subject,
                 :message_text, 'UTF-8', NOW(), FALSE, :message_id,
                 :kludge_lines, FALSE, NULL)
            RETURNING id
        ");
        $stmt->execute([
            ':from_address'  => $originAddress,
            ':to_address'    => $toAddress,
            ':from_name'     => $sysopName,
            ':to_name'       => 'Sysop',
            ':subject'       => $subject,
            ':message_text'  => $body,
            ':message_id'    => $msgId,
            ':kludge_lines'  => $kludgeLines,
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $notifId = $row ? (int)$row['id'] : 0;
        if ($notifId > 0) {
            $this->spoolOutboundNetmail($notifId);
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

        if (!empty($message['spooled_at'])) {
            $spooledAt = (string)$message['spooled_at'];
            $complaint = "[SPOOL] REFUSING to respool echomail #{$messageId} ({$echoareaTag}): already spooled at {$spooledAt}. This is a bug, not a retry path.";
            $this->logger->error($complaint);
            \BinktermPHP\Admin\AdminDaemonClient::log('ERROR', 'duplicate echomail spool prevented', [
                'message_id' => $messageId,
                'area'       => $echoareaTag,
                'spooled_at' => $spooledAt,
            ]);
            return false;
        }

        // Check if this is a local-only echoarea
        if (!empty($message['is_local'])) {
            //error_log("[SPOOL] Echomail #{$messageId} in local-only area {$echoareaTag} - not spooling to uplink");
            $fromName = $message['from_name'] ?? 'unknown';
            $fromAddr = $message['from_address'] ?? 'unknown';
            \BinktermPHP\Admin\AdminDaemonClient::log('INFO', 'echomail posted (local area)', [
                'area'    => $echoareaTag,
                'from'    => "{$fromName} <{$fromAddr}>",
                'to'      => $message['to_name'] ?? 'All',
                'subject' => $message['subject'] ?? '(no subject)',
                'msgid'   => $message['message_id'] ?? '',
                'packet'  => '(local)',
            ]);
            return true; // Success - message stored locally, no upstream transmission needed
        }

        // Extract message details for logging
        $fromName = $message['from_name'] ?? 'unknown';
        $fromAddr = $message['from_address'] ?? 'unknown';
        $subject = $message['subject'] ?? '(no subject)';
        $areaTag = $message['echoarea_tag'] ?? $echoareaTag;

        //error_log("[SPOOL] Spooling echomail #{$messageId}: area={$areaTag}, from=\"{$fromName}\" <{$fromAddr}>, subject=\"{$subject}\"");

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
                $this->queueImmediateOutboundPoll($uplinkAddress, "echomail #{$messageId}");

                \BinktermPHP\Admin\AdminDaemonClient::log('INFO', 'echomail posted', [
                    'area'    => $areaTag,
                    'from'    => "{$fromName} <{$fromAddr}>",
                    'to'      => $message['to_name'] ?? 'All',
                    'subject' => $subject,
                    'msgid'   => $message['message_id'] ?? '',
                    'packet'  => $packetName,
                ]);

                $this->db->prepare("UPDATE echomail SET spooled_at = CURRENT_TIMESTAMP WHERE id = ?")
                         ->execute([$messageId]);

                //error_log("[SPOOL] Echomail #{$messageId} spooled to packet {$packetName} for uplink {$uplinkAddress}");
            } else {
                $this->logger->warning("[SPOOL] No uplink address configured for echoarea {$areaTag} - message #{$messageId} not spooled");
                return false;
            }

            return true;
        } catch (\Exception $e) {
            // Log error but don't fail the message creation
            $this->logger->error("[SPOOL] Failed to spool echomail #{$messageId} (area={$areaTag}, from=\"{$fromName}\", subject=\"{$subject}\"): " . $e->getMessage());
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
        // Uplinks require a domain - return false if domain is blank/null
        if (empty($domain)) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT uplink_address FROM echoareas WHERE tag = ? AND domain = ? AND is_active = TRUE");
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
                $this->logger->error("Failed to get default uplink for domain " . $e->getMessage());
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
            $this->logger->error("Failed to get default uplink from config: " . $e->getMessage());
        }
        
        // Ultimate fallback if config fails (was '1:123/1';
        return false;
    }

    private function queueImmediateOutboundPoll(string $uplinkAddress, string $context): void
    {
        if (!isset($this->pendingImmediateOutboundPolls[$uplinkAddress])) {
            $this->pendingImmediateOutboundPolls[$uplinkAddress] = [];
        }

        $this->pendingImmediateOutboundPolls[$uplinkAddress][] = $context;
    }

    public function flushImmediateOutboundPolls(): void
    {
        if (empty($this->pendingImmediateOutboundPolls)) {
            return;
        }

        $client = null;
        try {
            $client = new \BinktermPHP\Admin\AdminDaemonClient();

            foreach (array_keys($this->pendingImmediateOutboundPolls) as $uplinkAddress) {
                // binkp_poll now runs in the background; the admin daemon spawns it
                // asynchronously so the HTTP response is not held open waiting for
                // the network connection to the uplink.  processPackets() is not
                // called here since it would run before the poll has received mail.
                $client->binkPoll($uplinkAddress);
            }
        } catch (\Throwable $e) {
            $contexts = [];
            foreach ($this->pendingImmediateOutboundPolls as $uplinkAddress => $items) {
                foreach ($items as $context) {
                    $contexts[] = "{$context} via {$uplinkAddress}";
                }
            }
            $this->logger->warning("[SPOOL] Could not trigger immediate outbound poll for " . implode(', ', $contexts) . ": " . $e->getMessage());
        } finally {
            if (isset($client) && is_object($client)) {
                try {
                    $client->close();
                } catch (\Throwable $closeEx) {
                    $this->logger->warning("[SPOOL] Error closing admin daemon client: " . $closeEx->getMessage());
                }
            }
            $this->pendingImmediateOutboundPolls = [];
        }
    }

    public function deleteEchomail($messageIds, $userId)
    {
        //error_log("MessageHandler::deleteEchomail called with messageIds: " . print_r($messageIds, true) . ", userId: $userId");
        
        // Validate input
        if (empty($messageIds) || !is_array($messageIds)) {
            $this->logger->warning("MessageHandler::deleteEchomail - Invalid input");
            return [
                'success' => false,
                'error_code' => 'errors.messages.echomail.bulk_delete.invalid_input',
                'error' => 'A non-empty message ID list is required'
            ];
        }

        // Get user info for permission checking
        $user = $this->getUserById($userId);
        //error_log("MessageHandler::deleteEchomail - Retrieved user: " . print_r($user, true));
        if (!$user) {
            $this->logger->warning("MessageHandler::deleteEchomail - User not found for ID: $userId");
            return [
                'success' => false,
                'error_code' => 'errors.messages.echomail.bulk_delete.user_not_found',
                'error' => 'User not found'
            ];
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
                
                $this->logger->debug("Permission check for message $messageId: isOwner=$isOwner, isAdmin=$isAdmin");
                $this->logger->debug("Message from_name: '{$message['from_name']}', User real_name: '{$user['real_name']}', User username: '{$user['username']}'");
                
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
                INSERT INTO user_settings (user_id, messages_per_page, threaded_view, netmail_threaded_view, default_sort, font_family, font_size, date_format, locale, default_tagline)
                VALUES (?, 25, FALSE, FALSE, 'date_desc', 'Courier New, Monaco, Consolas, monospace', 16, 'en-US', 'en', NULL)
                ON CONFLICT (user_id) DO UPDATE SET
                    messages_per_page = COALESCE(user_settings.messages_per_page, 25),
                    threaded_view = COALESCE(user_settings.threaded_view, FALSE),
                    netmail_threaded_view = COALESCE(user_settings.netmail_threaded_view, FALSE),
                    default_sort = COALESCE(user_settings.default_sort, 'date_desc'),
                    font_family = COALESCE(user_settings.font_family, 'Courier New, Monaco, Consolas, monospace'),
                    font_size = COALESCE(user_settings.font_size, 16),
                    date_format = COALESCE(user_settings.date_format, 'en-US'),
                    locale = COALESCE(user_settings.locale, 'en'),
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
                'locale' => 'en',
                'signature_text' => '',
                'default_tagline' => ''
            ];
        }

        if (empty($settings['locale'])) {
            $settings['locale'] = 'en';
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
            'remember_page_position' => 'BOOLEAN',
            'date_format' => 'STRING',
            'locale' => 'LOCALE',
            'signature_text' => 'SIGNATURE',
            'default_tagline' => 'TAGLINE',
            'forward_netmail_email' => 'BOOLEAN',
            'echomail_digest' => 'DIGEST_FREQUENCY',
            'echomail_badge_mode' => 'BADGE_MODE',
            'dashboard_layout' => 'DASHBOARD_LAYOUT',
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
                    if ($tagline === '__random__') {
                        $params[] = '__random__';
                        break;
                    }
                    if ($taglines === null) {
                        $taglines = $this->getTaglinesList();
                    }
                    $params[] = in_array($tagline, $taglines, true) ? $tagline : null;
                    break;
                case 'LOCALE':
                    $locale = str_replace('_', '-', trim((string)$value));
                    if (!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $locale)) {
                        $locale = 'en';
                    }
                    $params[] = $locale;
                    break;
                case 'DIGEST_FREQUENCY':
                    $freq = trim((string)$value);
                    $params[] = in_array($freq, ['none', 'daily', 'weekly'], true) ? $freq : 'none';
                    break;
                case 'BADGE_MODE':
                    $mode = trim((string)$value);
                    $params[] = in_array($mode, ['new', 'unread'], true) ? $mode : 'new';
                    break;
                case 'DASHBOARD_LAYOUT':
                    if ($value === null) {
                        $params[] = null;
                    } elseif (is_array($value)) {
                        $params[] = json_encode($value);
                    } elseif (is_string($value)) {
                        $decoded = json_decode($value, true);
                        $params[] = is_array($decoded) ? $value : null;
                    } else {
                        $params[] = null;
                    }
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
     * Look up the system name for a given FTN address from the nodelist.
     *
     * @param string|null $address FTN address (e.g. "1:123/456")
     * @return string|null System name or null if not found
     */
    private function lookupSystemName(?string $address): ?string
    {
        if (empty($address)) {
            return null;
        }
        try {
            if (!preg_match('/^(\d+):(\d+)\/(\d+)(?:\.(\d+))?$/', $address, $m)) {
                return null;
            }
            $zone  = (int)$m[1];
            $net   = (int)$m[2];
            $node  = (int)$m[3];
            $point = isset($m[4]) ? (int)$m[4] : 0;
            $stmt = $this->db->prepare(
                "SELECT system_name FROM nodelist WHERE zone = ? AND net = ? AND node = ? AND point = ? LIMIT 1"
            );
            $stmt->execute([$zone, $net, $node, $point]);
            $row = $stmt->fetch();
            return $row ? ($row['system_name'] ?: null) : null;
        } catch (\Exception $e) {
            return null;
        }
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

        // Mask the subject line for AreaFix/FileFix robot messages.
        // AreaFix uses the netmail subject as its password; exposing it in any
        // list or detail view would be a security issue.
        $toName   = strtolower((string)($message['to_name']   ?? ''));
        $fromName = strtolower((string)($message['from_name'] ?? ''));
        $isRobotMsg = str_contains($toName, 'areafix')   || str_contains($toName, 'filefix')
                   || str_contains($fromName, 'areafix') || str_contains($fromName, 'filefix');
        if ($isRobotMsg && isset($message['subject'])) {
            $message['subject'] = '••••••••';
        }

        $cleaned = [];
        foreach ($message as $key => $value) {
            if ($key === 'raw_message_bytes') {
                continue;
            }
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

    private function appendRawMessagePayload(array $message, $rawBytes, $charset, $artFormat): array
    {
        $message['message_bytes_b64'] = null;
        $message['message_charset'] = is_string($charset) && $charset !== '' ? $charset : null;
        $message['art_format'] = $artFormat ?: null;

        if (is_resource($rawBytes)) {
            $rawBytes = stream_get_contents($rawBytes);
        }

        if (is_string($rawBytes) && str_starts_with($rawBytes, '\\x')) {
            $decoded = @hex2bin(substr($rawBytes, 2));
            if ($decoded !== false) {
                $rawBytes = $decoded;
            }
        }

        if (is_string($rawBytes) && $rawBytes !== '') {
            $message['message_bytes_b64'] = base64_encode($rawBytes);
        }

        return $message;
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

        SysopNotificationService::sendNoticeToSysop(
            $subject,
            $messageText
        );

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
            
            // Generate referral code based on username
            $referralCode = $this->generateReferralCodeFromUsername($pendingUser['username']);

            // Create actual user account
            $userStmt = $this->db->prepare("
                INSERT INTO users (username, password_hash, email, real_name, location, created_at, is_active, referral_code, referred_by)
                VALUES (?, ?, ?, ?, ?, NOW(), TRUE, ?, ?)
                RETURNING id
            ");

            $userStmt->execute([
                $pendingUser['username'],
                $pendingUser['password_hash'],
                $pendingUser['email'],
                $pendingUser['real_name'],
                $pendingUser['location'] ?? null,
                $referralCode,
                $pendingUser['referrer_id'] ?? null
            ]);
            $insertedUser = $userStmt->fetch(\PDO::FETCH_ASSOC);
            $newUserId = $insertedUser ? (int)$insertedUser['id'] : 0;
            if ($newUserId <= 0) {
                throw new \RuntimeException('Failed to create user account');
            }
            
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
                $this->logger->error('[CREDITS] Failed to grant approval bonus: ' . $e->getMessage());
            }

            // Award referral bonus if applicable
            if ($pendingUser['referrer_id']) {
                try {
                    $creditsConfig = UserCredit::getCreditsConfig();

                    if (($creditsConfig['referral_enabled'] ?? false)
                        && ($creditsConfig['referral_bonus'] ?? 0) > 0) {

                        $referralBonus = (int)$creditsConfig['referral_bonus'];

                        UserCredit::transact(
                            (int)$pendingUser['referrer_id'],
                            $referralBonus,
                            "Referral bonus for new user: " . $pendingUser['username'],
                            (int)$newUserId,
                            UserCredit::TYPE_REFERRAL_BONUS
                        );
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('[CREDITS] Failed to grant referral bonus: ' . $e->getMessage());
                }
            }
            
            return $newUserId;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Generate referral code based on username
     * Since usernames are unique, we can use them directly as referral codes
     *
     * @param string $username
     * @return string
     */
    private function generateReferralCodeFromUsername(string $username): string
    {
        // Username is already validated as alphanumeric + underscores, which is URL-safe
        // and guaranteed unique by database constraint
        return $username;
    }

    /**
     * Reject a pending user registration
     */
    public function rejectUserRegistration($pendingUserId, $adminUserId, $reason = '')
    {
        $updateStmt = $this->db->prepare("
            UPDATE pending_users 
            SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), admin_notes = ?
            WHERE id = ? AND status = 'pending'
        ");
        
        $result = $updateStmt->execute([
            $adminUserId,      // reviewed_by
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
                        $this->logger->info("Welcome email sent successfully to {$user['email']} for user $username");
                    } else {
                        $this->logger->warning("Failed to send welcome email to {$user['email']} for user $username");
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("Error sending welcome email for user $username: " . $e->getMessage());
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
            return [
                'success' => false,
                'error_code' => 'errors.messages.shared.access_denied',
                'error' => 'Message not found or access denied'
            ];
        }

        // Check user's sharing settings
        $userSettings = $this->getUserSettings($userId);
        if (isset($userSettings['allow_sharing']) && !$userSettings['allow_sharing']) {
            return [
                'success' => false,
                'error_code' => 'errors.messages.shared.sharing_disabled',
                'error' => 'Sharing is disabled for your account'
            ];
        }

        // Check if user has reached their share limit
        $shareCount = $this->getUserActiveShareCount($userId);
        $maxShares = $userSettings['max_shares_per_user'] ?? 50;
        if ($shareCount >= $maxShares) {
            return [
                'success' => false,
                'error_code' => 'errors.messages.shared.max_active_reached',
                'error' => 'Maximum number of active shares reached'
            ];
        }

        // Check if message is already shared by this user
        $existingShare = $this->getExistingShare($messageId, $messageType, $userId);
        if ($existingShare) {
            return [
                'success' => true,
                'share_key' => $existingShare['share_key'],
                'share_url' => $this->buildShareUrl(
                    $existingShare['share_key'],
                    $existingShare['area_identifier'] ?? null,
                    $existingShare['slug'] ?? null
                ),
                'existing' => true
            ];
        }

        // Generate unique share key
        $shareKey = $this->generateShareKey();

        // Build friendly slug for echomail messages
        $areaIdentifier = null;
        $slug           = null;
        if ($messageType === 'echomail' && !empty($message['subject'])) {
            $tag    = $message['echoarea'] ?? '';
            $domain = $message['domain']   ?? '';
            if ($tag !== '') {
                $areaIdentifier = $domain !== '' ? "{$tag}@{$domain}" : $tag;
                $slug           = $this->generateFriendlySlug($message['subject'], $areaIdentifier);
            }
        }

        // Simplify by using conditional SQL instead of CASE with bound parameters
        if ($expiresHours) {
            $expiresHoursValue = (int)$expiresHours;
            $stmt = $this->db->prepare("
                INSERT INTO shared_messages (message_id, message_type, shared_by_user_id, share_key, expires_at, is_public, area_identifier, slug)
                VALUES (?, ?, ?, ?, NOW() + INTERVAL '1 hour' * ?, ?, ?, ?)
            ");
            $stmt->bindValue(1, $messageId, \PDO::PARAM_INT);
            $stmt->bindValue(2, $messageType, \PDO::PARAM_STR);
            $stmt->bindValue(3, $userId, \PDO::PARAM_INT);
            $stmt->bindValue(4, $shareKey, \PDO::PARAM_STR);
            $stmt->bindValue(5, $expiresHoursValue, \PDO::PARAM_INT);
            $stmt->bindValue(6, $isPublic ? 'true' : 'false', \PDO::PARAM_STR);
            $stmt->bindValue(7, $areaIdentifier, \PDO::PARAM_STR);
            $stmt->bindValue(8, $slug, \PDO::PARAM_STR);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO shared_messages (message_id, message_type, shared_by_user_id, share_key, expires_at, is_public, area_identifier, slug)
                VALUES (?, ?, ?, ?, NULL, ?, ?, ?)
            ");
            $stmt->bindValue(1, $messageId, \PDO::PARAM_INT);
            $stmt->bindValue(2, $messageType, \PDO::PARAM_STR);
            $stmt->bindValue(3, $userId, \PDO::PARAM_INT);
            $stmt->bindValue(4, $shareKey, \PDO::PARAM_STR);
            $stmt->bindValue(5, $isPublic ? 'true' : 'false', \PDO::PARAM_STR);
            $stmt->bindValue(6, $areaIdentifier, \PDO::PARAM_STR);
            $stmt->bindValue(7, $slug, \PDO::PARAM_STR);
        }

        $result = $stmt->execute();

        if ($result) {
            return [
                'success'   => true,
                'share_key' => $shareKey,
                'share_url' => $this->buildShareUrl($shareKey, $areaIdentifier, $slug),
                'is_public' => $isPublic
            ];
        }

        return [
            'success' => false,
            'error_code' => 'errors.messages.share_create_failed',
            'error' => 'Failed to create share link'
        ];
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
            return [
                'success' => false,
                'error_code' => 'errors.messages.shared.not_found_or_expired',
                'error' => 'Share not found or expired'
            ];
        }

        // Check access permissions - ensure proper boolean conversion
        $isPublic = filter_var($share['is_public'], FILTER_VALIDATE_BOOLEAN);
        //error_log("Share access check - raw is_public: " . var_export($share['is_public'], true) . ", converted: " . var_export($isPublic, true) . ", requestingUserId: " . var_export($requestingUserId, true));
        
        if (!$isPublic && !$requestingUserId) {
            return [
                'success' => false,
                'error_code' => 'errors.messages.shared.login_required',
                'error' => 'Login required to access this share'
            ];
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
            return [
                'success' => false,
                'error_code' => 'errors.messages.shared.original_not_found',
                'error' => 'Original message not found'
            ];
        }

        // Update access statistics
        $this->updateShareAccess($share['id']);

        // Clean message for JSON encoding
        $message = $this->cleanMessageForJson($message);
        $message = $this->appendMarkdownRendering($message);

        // Add system names from nodelist for address tooltips
        $message['from_system_name'] = $this->lookupSystemName($message['from_address'] ?? null);

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
     * Assign a friendly slug to an existing share that was created before slug support.
     * If the share already has a slug, returns the existing friendly URL unchanged.
     * Only the user who created the share may call this.
     *
     * @param int    $messageId
     * @param string $messageType
     * @param int    $userId
     */
    public function generateSlugForExistingShare(int $messageId, string $messageType, int $userId): array
    {
        $share = $this->getExistingShare($messageId, $messageType, $userId);
        if (!$share) {
            return [
                'success' => false,
                'error_code' => 'errors.messages.shared.not_found',
                'error' => 'Share not found'
            ];
        }

        // Already has a slug - just return the current friendly URL
        if (!empty($share['area_identifier']) && !empty($share['slug'])) {
            return [
                'success'   => true,
                'share_url' => $this->buildShareUrl(
                    $share['share_key'],
                    $share['area_identifier'],
                    $share['slug']
                ),
                'existing' => true
            ];
        }

        // Only echomail has area context for slug generation
        if ($messageType !== 'echomail') {
            return [
                'success' => false,
                'error_code' => 'errors.messages.shared.friendly_url_only_echomail',
                'error' => 'Friendly URLs are only available for echomail shares'
            ];
        }

        // Load the message to get subject and echoarea
        $stmt = $this->db->prepare("
            SELECT em.subject, ea.tag, ea.domain
            FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            WHERE em.id = ?
        ");
        $stmt->execute([$messageId]);
        $msg = $stmt->fetch();

        if (!$msg || empty($msg['subject'])) {
            return [
                'success' => false,
                'error_code' => 'errors.messages.shared.slug_generation_failed',
                'error' => 'Cannot generate share slug for this message'
            ];
        }

        $tag            = $msg['tag']    ?? '';
        $domain         = $msg['domain'] ?? '';
        $areaIdentifier = $domain !== '' ? "{$tag}@{$domain}" : $tag;
        $slug           = $this->generateFriendlySlug($msg['subject'], $areaIdentifier);

        $stmt = $this->db->prepare("
            UPDATE shared_messages
            SET area_identifier = ?, slug = ?
            WHERE share_key = ?
        ");
        $stmt->execute([$areaIdentifier, $slug, $share['share_key']]);

        return [
            'success'   => true,
            'share_url' => $this->buildShareUrl($share['share_key'], $areaIdentifier, $slug),
            'existing'  => false
        ];
    }

    /**
     * Get shared message by friendly slug URL (/shared/{area}/{slug}).
     *
     * @param string   $areaIdentifier  e.g. "test@lovlynet"
     * @param string   $slug            e.g. "hello-world"
     * @param int|null $requestingUserId
     */
    public function getSharedMessageBySlug(string $areaIdentifier, string $slug, ?int $requestingUserId = null): array
    {
        $this->cleanupExpiredShares();

        $stmt = $this->db->prepare("
            SELECT sm.*, u.username as shared_by_username, u.real_name as shared_by_real_name
            FROM shared_messages sm
            JOIN users u ON sm.shared_by_user_id = u.id
            WHERE sm.area_identifier = ?
              AND sm.slug = ?
              AND sm.is_active = TRUE
              AND (sm.expires_at IS NULL OR sm.expires_at > NOW())
        ");
        $stmt->execute([$areaIdentifier, $slug]);
        $share = $stmt->fetch();

        if (!$share) {
            return [
                'success' => false,
                'error_code' => 'errors.messages.shared.not_found_or_expired',
                'error' => 'Share not found or expired'
            ];
        }

        // Delegate to the core lookup logic via share_key
        return $this->getSharedMessage($share['share_key'], $requestingUserId);
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
            $areaId = $share['area_identifier'] ?? null;
            $slug   = $share['slug'] ?? null;
            $result[] = [
                'share_key'        => $share['share_key'],
                'share_url'        => $this->buildShareUrl($share['share_key'], $areaId, $slug),
                'has_friendly_url' => ($areaId !== null && $slug !== null),
                'created_at'       => $share['created_at'],
                'expires_at'       => $share['expires_at'],
                'is_public'        => $share['is_public'],
                'access_count'     => $share['access_count'],
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
            return [
                'success' => true,
                'message_code' => 'ui.api.messages.share_revoked'
            ];
        }

        return [
            'success' => false,
            'error_code' => 'errors.messages.share_revoke_failed',
            'error' => 'Failed to revoke share link'
        ];
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
     * Generate a URL-friendly slug from a subject, unique within the given area identifier.
     * Appends -2, -3, etc. on collision.
     */
    private function generateFriendlySlug(string $subject, string $areaIdentifier): string
    {
        $slug = strtolower($subject);
        $slug = preg_replace('/\s+/', '-', $slug);
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 100);

        if ($slug === '') {
            $slug = 'message';
        }

        $base   = $slug;
        $suffix = 2;
        $stmt   = $this->db->prepare(
            "SELECT id FROM shared_messages WHERE area_identifier = ? AND slug = ?"
        );
        while (true) {
            $stmt->execute([$areaIdentifier, $slug]);
            if (!$stmt->fetch()) {
                break;
            }
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Build share URL - prefers the friendly /shared/{area}/{slug} form when available.
     *
     * @param string      $shareKey
     * @param string|null $areaIdentifier  e.g. "test@lovlynet"
     * @param string|null $slug            e.g. "hello-world"
     */
    private function buildShareUrl(string $shareKey, ?string $areaIdentifier = null, ?string $slug = null): string
    {
        $base = \BinktermPHP\Config::getSiteUrl();
        if ($areaIdentifier !== null && $slug !== null) {
            return $base . '/shared/' . rawurlencode($areaIdentifier) . '/' . rawurlencode($slug);
        }
        return $base . '/shared/' . $shareKey;
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
     *
     * @param string $charset Target charset for the outgoing packet (e.g. 'UTF-8', 'CP437').
     *                        Determines the CHRS kludge value. Defaults to 'UTF-8'.
     */
    private function generateNetmailKludges($fromAddress, $toAddress, $fromName, $toName, $subject, $replyToId = null, $replyToAddress = null, $markupType = null, $charset = 'UTF-8')
    {
        $kludgeLines = [];

        // CHRS level per FSC-0054: 1=7-bit, 2=8-bit, 4=multi-byte (UTF-8)
        $chrsLevelMap = [
            'UTF-8'        => 4,
            'CP437'        => 2,
            'CP850'        => 2,
            'CP852'        => 2,
            'CP866'        => 2,
            'ISO-8859-1'   => 2,
            'ISO-8859-2'   => 2,
            'ISO-8859-5'   => 2,
            'WINDOWS-1250' => 4,
            'WINDOWS-1251' => 4,
            'WINDOWS-1252' => 4,
            'KOI8-R'       => 2,
            'KOI8-U'       => 2,
        ];
        $chrsCharset = strtoupper($charset);
        $chrsLevel   = $chrsLevelMap[$chrsCharset] ?? 4;
        $kludgeLines[] = "\x01CHRS: {$chrsCharset} {$chrsLevel}";

        if ($markupType === 'markdown') {
            $kludgeLines[] = "\x01MARKUP: Markdown 1.0";
        } elseif ($markupType === 'stylecodes') {
            $kludgeLines[] = "\x01MARKUP: StyleCodes 1.0";
        }

        // Add TZUTC kludge line for netmail
        $tzutc = \generateTzutc();
        $kludgeLines[] = "\x01TZUTC: {$tzutc}";

        // Add MSGID kludge (required for netmail)
        $msgId = $this->generateMessageId($fromName, $toName, $subject, $fromAddress);
        $msgidAddress = $this->buildMsgidAddress($fromAddress);
        $kludgeLines[] = "\x01MSGID: {$msgidAddress} {$msgId}";
        
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
        
        // Add INTL kludge for zone routing (required for inter-zone mail).
        // Per FTS-0001 INTL addresses must be zone:net/node only - no point suffix.
        // Points are conveyed separately via FMPT/TOPT kludges below.
        list($fromZone, $fromNetNodeRaw) = explode(':', $fromAddress);
        list($fromNet, $fromNodePoint) = explode('/', $fromNetNodeRaw);
        $fromNodeOnly = explode('.', $fromNodePoint)[0];

        list($toZone, $toNetNodeRaw) = explode(':', $toAddress);
        list($toNet, $toNodePoint) = explode('/', $toNetNodeRaw);
        $toNodeOnly = explode('.', $toNodePoint)[0];

        $kludgeLines[] = "\x01INTL {$toZone}:{$toNet}/{$toNodeOnly} {$fromZone}:{$fromNet}/{$fromNodeOnly}";

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
     *
     * @param string $charset Target charset for the outgoing packet (e.g. 'UTF-8', 'CP437').
     *                        Determines the CHRS kludge value. Defaults to 'UTF-8'.
     */
    private function generateEchomailKludges($fromAddress, $fromName, $toName, $subject, $echoareaTag, $replyToId = null, $markupType = null, $domain = null, $charset = 'UTF-8')
    {
        $kludgeLines = [];

        // CHRS level per FSC-0054: 1=7-bit, 2=8-bit, 4=multi-byte (UTF-8)
        $chrsLevelMap = [
            'UTF-8'        => 4,
            'CP437'        => 2,
            'CP850'        => 2,
            'CP852'        => 2,
            'CP866'        => 2,
            'ISO-8859-1'   => 2,
            'ISO-8859-2'   => 2,
            'ISO-8859-5'   => 2,
            'WINDOWS-1250' => 4,
            'WINDOWS-1251' => 4,
            'WINDOWS-1252' => 4,
            'KOI8-R'       => 2,
            'KOI8-U'       => 2,
        ];
        $chrsCharset = strtoupper($charset);
        $chrsLevel   = $chrsLevelMap[$chrsCharset] ?? 4;
        $kludgeLines[] = "\x01CHRS: {$chrsCharset} {$chrsLevel}";

        if ($markupType === 'markdown') {
            $kludgeLines[] = "\x01MARKUP: Markdown 1.0";
        } elseif ($markupType === 'stylecodes') {
            $kludgeLines[] = "\x01MARKUP: StyleCodes 1.0";
        }

        // Add TZUTC kludge line for echomail
        $tzutc = \generateTzutc();
        $kludgeLines[] = "\x01TZUTC: {$tzutc}";

        // Add MSGID kludge (required for echomail)
        $msgId = $this->generateMessageId($fromName, $toName, $subject, $fromAddress);
        $msgidAddress = $this->buildMsgidAddress($fromAddress, $domain);
        $kludgeLines[] = "\x01MSGID: {$msgidAddress} {$msgId}";
        
        // Add REPLY kludge if this is a reply to another message
        if (!empty($replyToId)) {
            $originalMsgId = $this->getOriginalEchomailMessageId($replyToId);
            if ($originalMsgId) {
                $kludgeLines[] = "\x01REPLY: {$originalMsgId}";
            }
        }
        
        return implode("\n", $kludgeLines);
    }

    private function buildMsgidAddress(string $fromAddress, ?string $domain = null): string
    {
        if (strpos($fromAddress, '@') !== false) {
            return $fromAddress;
        }

        $resolvedDomain = trim((string)($domain ?? ''));
        if ($resolvedDomain === '') {
            try {
                $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
                $resolvedDomain = (string)($binkpConfig->getDomainByAddress($fromAddress) ?: '');
            } catch (\Throwable $e) {
                $resolvedDomain = '';
            }
        }

        return $resolvedDomain !== '' ? $fromAddress . '@' . $resolvedDomain : $fromAddress;
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
                       COALESCE(NULLIF(em.art_format, ''), NULLIF(ea.art_format_hint, '')) as art_format,
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
                       COALESCE(NULLIF(em.art_format, ''), NULLIF(ea.art_format_hint, '')) as art_format,
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
     * Extract all kludge lines from a message into a single string for pattern matching.
     *
     * @param array $message
     * @return string
     */
    private function getKludgeText(array $message): string
    {
        $kludgeText = '';
        if (!empty($message['kludge_lines'])) {
            $kludgeText .= $message['kludge_lines'];
        }
        if (!empty($message['bottom_kludges'])) {
            $kludgeText .= ($kludgeText !== '' ? "\n" : '') . $message['bottom_kludges'];
        }

        if ($kludgeText === '') {
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

        return $kludgeText;
    }

    /**
     * Detect the MARKUP format declared in the message kludges.
     *
     * Recognises:
     *   ^AMARKUP: <format> <version>  (LSC-001 Draft 2)
     *   ^AMARKDOWN: <version>         (legacy backwards-compatibility kludge -> treated as Markdown)
     *
     * @param array $message
     * @return array{format: string, version: string}|null
     */
    private function detectMarkupKludge(array $message): ?array
    {
        $kludgeText = $this->getKludgeText($message);

        if ($kludgeText === '') {
            return null;
        }

        // LSC-001 Draft 2: ^AMARKUP: <format> <version>
        if (preg_match('/^\x01MARKUP:\s+(\S+)\s+([\d.]+)/mi', $kludgeText, $matches)) {
            return ['format' => strtolower($matches[1]), 'version' => $matches[2]];
        }

        // Legacy: ^AMARKDOWN: <version>
        if (preg_match('/^\x01MARKDOWN:\s*(\d+)/mi', $kludgeText, $matches)) {
            return ['format' => 'markdown', 'version' => $matches[1]];
        }

        return null;
    }

    /**
     * Add rendered markup data to a message if a recognised MARKUP kludge is present.
     *
     * Sets markup_format and markup_html on the message for all recognised formats.
     * For Markdown specifically, also sets the legacy is_markdown/markdown_html fields
     * for backwards compatibility with any consumers that predate the general markup system.
     *
     * Supported formats:
     *   markdown   - rendered by MarkdownRenderer
     *   stylecodes - rendered by StyleCodesRenderer (MARKUP: StyleCodes 1.0)
     *
     * @param array $message
     * @return array
     */
    private function appendMarkdownRendering(array $message): array
    {
        $markup = $this->detectMarkupKludge($message);

        if ($markup === null) {
            $message['is_markdown'] = 0;
            return $message;
        }

        $rawText   = (string)($message['message_text'] ?? '');
        $cleanText = \filterKludgeLinesPreserveEmptyLines($rawText);

        $message['markup_format'] = $markup['format'];

        switch ($markup['format']) {
            case 'markdown':
                $html = \BinktermPHP\MarkdownRenderer::toHtml($cleanText);
                $message['markup_html']      = $html;
                // Legacy fields for backwards compatibility
                $message['is_markdown']      = 1;
                $message['markdown_version'] = (int)$markup['version'];
                $message['markdown_html']    = $html;
                break;

            case 'stylecodes':
                $message['markup_html'] = \BinktermPHP\StyleCodesRenderer::toHtml($cleanText);
                $message['is_markdown'] = 0;
                break;

            default:
                // Unknown format - do not attempt rendering; display as plain text
                $message['is_markdown'] = 0;
                break;
        }

        return $message;
    }

    private function looksLikeRipScript(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $normalized);
        $ripLineCount = 0;
        $supportedCommandCount = 0;

        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            if (!str_starts_with($trimmed, '!|')) {
                continue;
            }

            $ripLineCount++;

            if (preg_match('/\|c\d{2}\b/i', $trimmed) === 1
                || preg_match('/\|L[0-9A-Z]{8,}/i', $trimmed) === 1
                || preg_match('/\|@[0-9A-Z]{4}/i', $trimmed) === 1
            ) {
                $supportedCommandCount++;
            }
        }

        return $ripLineCount > 0 && $supportedCommandCount > 0;
    }

    private function appendRipRendering(array $message): array
    {
        $message['is_rip'] = 0;
        $message['rip_script'] = null;
        $message['rip_html'] = null;

        if (($message['message_type'] ?? 'echomail') !== 'echomail') {
            return $message;
        }

        $rawText = (string)($message['message_text'] ?? '');
        if (!$this->looksLikeRipScript($rawText)) {
            return $message;
        }

        $message['rip_script'] = $rawText;
        $message['is_rip'] = 1;

        return $message;
    }

    /**
     * Get threaded echomail messages from subscribed echoareas using MSGID/REPLY relationships
     */
    private function getThreadedEchomailFromSubscribedAreas($userId, $page = 1, $limit = null, $filter = 'all', $subscribedEchoareas = null, $sort = 'date_desc')
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
        // Hide future-dated messages until their date_written has passed
        $filterClause .= " AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC'))";
        $ignoreFilter = $this->buildEchomailIgnoreFilter($userId, 'em');
        $filterClause .= $ignoreFilter['sql'];
        $moderationFilter = $this->buildModerationVisibilityFilter($userId, 'em');
        $filterClause .= $moderationFilter['sql'];
        foreach ($moderationFilter['params'] as $p) {
            $filterParams[] = $p;
        }

        // Create IN clause for subscribed echoareas
        $echoareaIds = array_column($subscribedEchoareas, 'id');
        $placeholders = str_repeat('?,', count($echoareaIds) - 1) . '?';

        // Get messages for current page using standard pagination
        $offset = ($page - 1) * $limit;
        $dateField = $this->getEchomailDateField();

        // Build ORDER BY clause based on sort parameter
        $orderBy = match($sort) {
            'date_asc' => "em.{$dateField} ASC",
            'subject'  => "em.subject ASC",
            'author'   => "em.from_name ASC",
            default    => "CASE WHEN em.{$dateField} > NOW() THEN 0 ELSE 1 END, em.{$dateField} DESC",
        };

        $stmt = $this->db->prepare("
            SELECT em.id, em.from_name, em.from_address, em.to_name,
                   em.subject, em.date_received, em.date_written, em.echoarea_id,
                   em.message_id, em.reply_to_id,
                   ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                   COALESCE(NULLIF(em.art_format, ''), NULLIF(ea.art_format_hint, '')) as art_format,
                   CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                   CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                   CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
            FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
            LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
            LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
            WHERE ea.id IN ($placeholders) AND ea.is_active = TRUE{$filterClause}
            ORDER BY {$orderBy}
            LIMIT ? OFFSET ?
        ");

        $params = [$userId, $userId, $userId];
        $params = array_merge($params, $echoareaIds);
        foreach ($filterParams as $param) {
            $params[] = $param;
        }
        foreach ($ignoreFilter['params'] as $param) {
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
        
        // Sort threads according to the requested sort order
        usort($threads, function($a, $b) use ($sort) {
            $aRoot = $a['message'];
            $bRoot = $b['message'];
            return match($sort) {
                'date_asc' => $this->getThreadSortTimestamp($a) - $this->getThreadSortTimestamp($b),
                'subject'  => strcasecmp($aRoot['subject'] ?? '', $bRoot['subject'] ?? ''),
                'author'   => strcasecmp($aRoot['from_name'] ?? '', $bRoot['from_name'] ?? ''),
                default    => $this->getThreadSortTimestamp($b) - $this->getThreadSortTimestamp($a),
            };
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
        foreach ($ignoreFilter['params'] as $param) {
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
    public function getThreadedEchomail($echoareaTag = null, $domain = null, $page = 1, $limit = null, $userId = null, $filter = 'all', $sort = 'date_desc')
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
        // Hide future-dated messages until their date_written has passed
        $filterClause .= " AND (em.date_written IS NULL OR em.date_written <= (NOW() AT TIME ZONE 'UTC'))";
        $ignoreFilter = $this->buildEchomailIgnoreFilter($userId, 'em');
        $filterClause .= $ignoreFilter['sql'];
        $moderationFilter = $this->buildModerationVisibilityFilter($userId, 'em');
        $filterClause .= $moderationFilter['sql'];
        foreach ($moderationFilter['params'] as $p) {
            $filterParams[] = $p;
        }

        // First, get the total count of root messages (threads) for pagination
        $totalThreads = 0;
        if ($echoareaTag) {
            $domainCondition = empty($domain) ? "(ea.domain IS NULL OR ea.domain = '')" : "ea.domain = ?";
            $countStmt = $this->db->prepare("
                SELECT COUNT(*) as total
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE ea.tag = ?{$filterClause} AND {$domainCondition} AND em.reply_to_id IS NULL
            ");
            $countParams = [$userId, $userId, $echoareaTag];
            foreach ($filterParams as $param) {
                $countParams[] = $param;
            }
            foreach ($ignoreFilter['params'] as $param) {
                $countParams[] = $param;
            }
            if (!empty($domain)) {
                $countParams[] = $domain;
            }
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
            foreach ($ignoreFilter['params'] as $param) {
                $countParams[] = $param;
            }
            $countStmt->execute($countParams);
            $totalThreads = $countStmt->fetch()['total'];
        }

        // Get root messages for the current page
        $rootOffset = ($page - 1) * $limit;
        $dateField = $this->getEchomailDateField();

        // Build ORDER BY clause based on sort parameter
        $orderBy = match($sort) {
            'date_asc' => "em.{$dateField} ASC",
            'subject'  => "em.subject ASC",
            'author'   => "em.from_name ASC",
            default    => "CASE WHEN em.{$dateField} > NOW() THEN 0 ELSE 1 END, em.{$dateField} DESC",
        };

        if ($echoareaTag) {
            // Get root messages (threads) for the current page
            $stmt = $this->db->prepare("
                SELECT em.id, em.from_name, em.from_address, em.to_name,
                       em.subject, em.date_received, em.date_written, em.echoarea_id,
                       em.message_id, em.reply_to_id,
                       ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                       COALESCE(NULLIF(em.art_format, ''), NULLIF(ea.art_format_hint, '')) as art_format,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE ea.tag = ?{$filterClause} AND {$domainCondition} AND em.reply_to_id IS NULL
                ORDER BY {$orderBy}
                LIMIT ? OFFSET ?
            ");
            $params = [$userId, $userId, $userId, $echoareaTag];
            foreach ($filterParams as $param) {
                $params[] = $param;
            }
            foreach ($ignoreFilter['params'] as $param) {
                $params[] = $param;
            }
            if (!empty($domain)) {
                $params[] = $domain;
            }
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
                       COALESCE(NULLIF(em.art_format, ''), NULLIF(ea.art_format_hint, '')) as art_format,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE em.reply_to_id IS NULL{$filterClause}
                ORDER BY {$orderBy}
                LIMIT ? OFFSET ?
            ");
            $params = [$userId, $userId, $userId];
            foreach ($filterParams as $param) {
                $params[] = $param;
            }
            foreach ($ignoreFilter['params'] as $param) {
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

        // Sort threads according to the requested sort order
        usort($threads, function($a, $b) use ($sort) {
            $aRoot = $a['message'];
            $bRoot = $b['message'];
            return match($sort) {
                'date_asc' => $this->getThreadSortTimestamp($a) - $this->getThreadSortTimestamp($b),
                'subject'  => strcasecmp($aRoot['subject'] ?? '', $bRoot['subject'] ?? ''),
                'author'   => strcasecmp($aRoot['from_name'] ?? '', $bRoot['from_name'] ?? ''),
                default    => $this->getThreadSortTimestamp($b) - $this->getThreadSortTimestamp($a),
            };
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
            $placeholders     = implode(',', array_fill(0, count($currentLevelIds), '?'));
            $ignoreFilter     = $this->buildEchomailIgnoreFilter($userId, 'em');
            $moderationFilter = $this->buildModerationVisibilityFilter($userId, 'em');

            $stmt = $this->db->prepare("
                SELECT em.id, em.from_name, em.from_address, em.to_name,
                       em.subject, em.date_received, em.date_written, em.echoarea_id,
                       em.message_id, em.reply_to_id,
                       ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                       COALESCE(NULLIF(em.art_format, ''), NULLIF(ea.art_format_hint, '')) as art_format,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                       CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
                FROM echomail em
                JOIN echoareas ea ON em.echoarea_id = ea.id
                LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
                LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
                LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
                WHERE em.reply_to_id IN ({$placeholders}){$ignoreFilter['sql']}{$moderationFilter['sql']}
            ");

            $params = [$userId, $userId, $userId];
            $params = array_merge($params, $currentLevelIds);
            foreach ($ignoreFilter['params'] as $param) {
                $params[] = $param;
            }
            foreach ($moderationFilter['params'] as $param) {
                $params[] = $param;
            }
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

    public function getEchomailConversation(int $messageId, ?int $userId = null): array
    {
        $selected = $this->getMessage($messageId, 'echomail', $userId);
        if (!$selected) {
            return ['messages' => [], 'unreadCount' => 0, 'threaded' => true, 'pagination' => ['page' => 1, 'limit' => 0, 'total' => 0, 'pages' => 1]];
        }

        $rootStmt = $this->db->prepare("
            WITH RECURSIVE ancestors AS (
                SELECT id, reply_to_id
                FROM echomail
                WHERE id = ?
                UNION ALL
                SELECT em.id, em.reply_to_id
                FROM echomail em
                INNER JOIN ancestors a ON a.reply_to_id = em.id
            )
            SELECT id
            FROM ancestors
            WHERE reply_to_id IS NULL
            ORDER BY id ASC
            LIMIT 1
        ");
        $rootStmt->execute([$messageId]);
        $rootId = (int)($rootStmt->fetchColumn() ?: $messageId);

        $stmt = $this->db->prepare("
            SELECT em.id, em.from_name, em.from_address, em.to_name,
                   em.subject, em.date_received, em.date_written, em.echoarea_id,
                   em.message_id, em.reply_to_id,
                   ea.tag as echoarea, ea.color as echoarea_color, ea.domain as echoarea_domain,
                   COALESCE(NULLIF(em.art_format, ''), NULLIF(ea.art_format_hint, '')) as art_format,
                   CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                   CASE WHEN sm.id IS NOT NULL THEN 1 ELSE 0 END as is_shared,
                   CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
            FROM echomail em
            JOIN echoareas ea ON em.echoarea_id = ea.id
            LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
            LEFT JOIN shared_messages sm ON (sm.message_id = em.id AND sm.message_type = 'echomail' AND sm.shared_by_user_id = ? AND sm.is_active = TRUE AND (sm.expires_at IS NULL OR sm.expires_at > NOW()))
            LEFT JOIN saved_messages sav ON (sav.message_id = em.id AND sav.message_type = 'echomail' AND sav.user_id = ?)
            WHERE em.id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $userId, $userId, $rootId]);
        $rootMessages = $stmt->fetchAll();
        if ($rootMessages === []) {
            return ['messages' => [], 'unreadCount' => 0, 'threaded' => true, 'pagination' => ['page' => 1, 'limit' => 0, 'total' => 0, 'pages' => 1]];
        }

        $allMessages = $this->loadThreadChildren($rootMessages, $userId);
        $threads = $this->buildMessageThreads($allMessages);
        $messages = $this->flattenThreadsForDisplay($threads);

        $cleanMessages = [];
        $unreadCount = 0;
        foreach ($messages as $message) {
            if (empty($message['is_read'])) {
                $unreadCount++;
            }
            $cleanMessages[] = $this->cleanMessageForJson($message);
        }

        return [
            'messages' => $cleanMessages,
            'unreadCount' => $unreadCount,
            'threaded' => true,
            'pagination' => [
                'page' => 1,
                'limit' => count($cleanMessages),
                'total' => count($cleanMessages),
                'pages' => 1
            ]
        ];
    }

    private function loadNetmailThreadChildren($rootMessages, $userId)
    {
        if (empty($rootMessages)) {
            return [];
        }

        $allMessages = $rootMessages;
        $currentLevelIds = array_column($rootMessages, 'id');
        $maxDepth = 50;
        $depth = 0;

        while (!empty($currentLevelIds) && $depth < $maxDepth) {
            $placeholders = implode(',', array_fill(0, count($currentLevelIds), '?'));

            $stmt = $this->db->prepare("
                SELECT n.id, n.from_name, n.from_address, n.to_name, n.to_address,
                       n.subject, n.date_received, n.user_id, n.date_written,
                       n.attributes, n.is_sent, n.reply_to_id, n.is_freq,
                       CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                       EXISTS(SELECT 1 FROM files WHERE message_id = n.id AND message_type = 'netmail') as has_attachment
                FROM netmail n
                LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
                WHERE n.reply_to_id IN ({$placeholders})
            ");

            $params = [$userId];
            $params = array_merge($params, $currentLevelIds);
            $stmt->execute($params);

            $children = $stmt->fetchAll();
            if (empty($children)) {
                break;
            }

            $children = array_values(array_filter($children, function ($child) use ($userId) {
                return $this->getMessage((int)$child['id'], 'netmail', $userId) !== null;
            }));
            if (empty($children)) {
                break;
            }

            $allMessages = array_merge($allMessages, $children);
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
                return $this->getMessageDateTimestamp($a['message']) - $this->getMessageDateTimestamp($b['message']);
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
            if ($this->getMessageDateTimestamp($replyLatest) > $this->getMessageDateTimestamp($latest)) {
                $latest = $replyLatest;
            }
        }
        
        return $latest;
    }

    private function getMessageDateTimestamp(array $message): int
    {
        $dateField = $this->getEchomailDateField();
        $primary = (string)($message[$dateField] ?? '');
        $fallbackField = ($dateField === 'date_written') ? 'date_received' : 'date_written';
        $fallback = (string)($message[$fallbackField] ?? '');

        $ts = strtotime($primary);
        if ($ts === false) {
            $ts = strtotime($fallback);
        }
        return ($ts === false) ? 0 : $ts;
    }

    private function getThreadSortTimestamp(array $thread): int
    {
        return $this->getMessageDateTimestamp($this->getLatestMessageInThread($thread));
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
    public function getThreadedNetmail($userId, $page = 1, $limit = null, $filter = 'all', $sort = 'date_desc')
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
            // Show only messages sent by this user from this system (check from_name AND from_address)
            if (!empty($myAddresses)) {
                $addressPlaceholders = implode(',', array_fill(0, count($myAddresses), '?'));
                $whereClause = "WHERE (LOWER(n.from_name) = LOWER(?) OR LOWER(n.from_name) = LOWER(?)) AND n.from_address IN ($addressPlaceholders)";
                $params = [$user['username'], $user['real_name']];
                $params = array_merge($params, $myAddresses);
            } else {
                // Fallback if no addresses configured - just check name
                $whereClause = "WHERE (LOWER(n.from_name) = LOWER(?) OR LOWER(n.from_name) = LOWER(?))";
                $params = [$user['username'], $user['real_name']];
            }
        } elseif ($filter === 'received' && !empty($myAddresses)) {
            // Show only messages received by this user (must match name AND to_address must be one of our addresses)
            $whereClause = "WHERE (LOWER(n.to_name) = LOWER(?) OR LOWER(n.to_name) = LOWER(?)) AND n.to_address IN ($addressPlaceholders) AND n.user_id != ?";
            $params = [$user['username'], $user['real_name']];
            $params = array_merge($params, $myAddresses, [$userId]);
        } elseif ($filter === 'saved') {
            // Show only messages saved by this user
            $whereClause .= " AND sav.id IS NOT NULL";
        }

        // Filter out soft-deleted messages
        // If user is sender, exclude messages deleted by sender
        // If user is recipient, exclude messages deleted by recipient
        $whereClause .= " AND NOT ((n.user_id = ? AND n.deleted_by_sender = TRUE) OR
                                   ((LOWER(n.to_name) = LOWER(?) OR LOWER(n.to_name) = LOWER(?)) AND n.deleted_by_recipient = TRUE))";
        $params[] = $userId;
        $params[] = $user['username'];
        $params[] = $user['real_name'];

        // Get all messages first
        $stmt = $this->db->prepare("
            SELECT n.id, n.from_name, n.from_address, n.to_name, n.to_address,
                   n.subject, n.date_received, n.user_id, n.date_written,
                   n.attributes, n.is_sent, n.reply_to_id, n.is_freq,
                   CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                   EXISTS(SELECT 1 FROM files WHERE message_id = n.id AND message_type = 'netmail') as has_attachment,
                   CASE WHEN sav.id IS NOT NULL THEN 1 ELSE 0 END as is_saved
            FROM netmail n
            LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
            LEFT JOIN saved_messages sav ON (sav.message_id = n.id AND sav.message_type = 'netmail' AND sav.user_id = ?)
            $whereClause
            ORDER BY n.date_received DESC
        ");

        // Insert userId twice at the beginning for the two LEFT JOINs (mrs and sav), then add existing params
        $allParams = [$userId, $userId];
        foreach ($params as $param) {
            $allParams[] = $param;
        }

        $stmt->execute($allParams);
        $allMessages = $stmt->fetchAll();
        
        // Build threading relationships
        $threads = $this->buildMessageThreads($allMessages);
        
        // Sort threads according to the requested sort order
        usort($threads, function($a, $b) use ($sort) {
            $aRoot = $a['message'];
            $bRoot = $b['message'];
            return match($sort) {
                'date_asc' => $this->getThreadSortTimestamp($a) - $this->getThreadSortTimestamp($b),
                'subject'  => strcasecmp($aRoot['subject'] ?? '', $bRoot['subject'] ?? ''),
                'author'   => strcasecmp($aRoot['from_name'] ?? '', $bRoot['from_name'] ?? ''),
                default    => $this->getThreadSortTimestamp($b) - $this->getThreadSortTimestamp($a),
            };
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

    public function getNetmailConversation(int $messageId, int $userId): array
    {
        $selected = $this->getMessage($messageId, 'netmail', $userId);
        if (!$selected) {
            return ['messages' => [], 'unreadCount' => 0, 'threaded' => true, 'pagination' => ['page' => 1, 'limit' => 0, 'total' => 0, 'pages' => 1]];
        }

        $rootStmt = $this->db->prepare("
            WITH RECURSIVE ancestors AS (
                SELECT id, reply_to_id
                FROM netmail
                WHERE id = ?
                UNION ALL
                SELECT n.id, n.reply_to_id
                FROM netmail n
                INNER JOIN ancestors a ON a.reply_to_id = n.id
            )
            SELECT id
            FROM ancestors
            WHERE reply_to_id IS NULL
            ORDER BY id ASC
            LIMIT 1
        ");
        $rootStmt->execute([$messageId]);
        $rootId = (int)($rootStmt->fetchColumn() ?: $messageId);

        $stmt = $this->db->prepare("
            SELECT n.id, n.from_name, n.from_address, n.to_name, n.to_address,
                   n.subject, n.date_received, n.user_id, n.date_written,
                   n.attributes, n.is_sent, n.reply_to_id, n.is_freq,
                   CASE WHEN mrs.read_at IS NOT NULL THEN 1 ELSE 0 END as is_read,
                   EXISTS(SELECT 1 FROM files WHERE message_id = n.id AND message_type = 'netmail') as has_attachment
            FROM netmail n
            LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
            WHERE n.id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId, $rootId]);
        $rootMessages = $stmt->fetchAll();
        if ($rootMessages === []) {
            return ['messages' => [], 'unreadCount' => 0, 'threaded' => true, 'pagination' => ['page' => 1, 'limit' => 0, 'total' => 0, 'pages' => 1]];
        }

        $allMessages = $this->loadNetmailThreadChildren($rootMessages, $userId);
        $threads = $this->buildMessageThreads($allMessages);
        $messages = $this->flattenThreadsForDisplay($threads);

        $cleanMessages = [];
        $unreadCount = 0;
        foreach ($messages as $message) {
            if (empty($message['is_read'])) {
                $unreadCount++;
            }

            $cleanMessage = $this->cleanMessageForJson($message);
            try {
                $binkpCfg = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
                if (!empty($cleanMessage['from_address'])) {
                    $cleanMessage['from_domain'] = $binkpCfg->getDomainByAddress($cleanMessage['from_address']) ?: null;
                }
                if (!empty($cleanMessage['to_address'])) {
                    $cleanMessage['to_domain'] = $binkpCfg->getDomainByAddress($cleanMessage['to_address']) ?: null;
                }
            } catch (\Exception $e) {
            }
            $cleanMessages[] = $cleanMessage;
        }

        return [
            'messages' => $cleanMessages,
            'unreadCount' => $unreadCount,
            'threaded' => true,
            'pagination' => [
                'page' => 1,
                'limit' => count($cleanMessages),
                'total' => count($cleanMessages),
                'pages' => 1
            ]
        ];
    }

    /**
     * Return visible AreaFix/FileFix request and response netmail for a user.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLovlyNetRequests(int $userId, string $hubAddress): array
    {
        $hubAddress = trim($hubAddress);
        if ($hubAddress === '') {
            return [];
        }

        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $myAddresses = $binkpConfig->getMyAddresses();
            $systemAddress = $binkpConfig->getSystemAddress();
            if ($systemAddress !== '') {
                $myAddresses[] = $systemAddress;
            }
            $myAddresses = array_values(array_unique(array_filter($myAddresses, static function ($value) {
                return trim((string)$value) !== '';
            })));
        } catch (\Throwable $e) {
            $myAddresses = [];
        }

        if ($myAddresses === []) {
            return [];
        }

        $addressPlaceholders = implode(',', array_fill(0, count($myAddresses), '?'));
        $sql = "
            SELECT n.id, n.user_id, n.from_name, n.from_address, n.to_name, n.to_address,
                   n.subject, n.message_text, n.date_written, n.date_received, n.is_sent,
                   n.message_id, n.kludge_lines, n.bottom_kludges,
                   CASE
                       WHEN n.from_address IN ($addressPlaceholders) THEN
                           CASE WHEN LOWER(n.to_name) LIKE 'filefix%' THEN 'file' ELSE 'echo' END
                       ELSE 'echo'
                   END AS request_type,
                   CASE
                       WHEN n.from_address IN ($addressPlaceholders) THEN 'outgoing'
                       ELSE 'incoming'
                   END AS direction
            FROM netmail n
            WHERE (
                -- Outgoing: messages we sent to the hub addressed to the robot (to_name set by us).
                (
                    n.from_address IN ($addressPlaceholders)
                    AND n.to_address = ?
                    AND LOWER(n.to_name) IN ('areafix', 'filefix')
                    AND n.deleted_by_sender = FALSE
                )
                OR
                -- Incoming: any message from the hub to us, regardless of the sender name.
                -- Robot names vary (SBBSEcho, BRoboCop, etc.) so we match on address only.
                (
                    n.from_address = ?
                    AND n.to_address IN ($addressPlaceholders)
                    AND n.deleted_by_recipient = FALSE
                )
            )
            ORDER BY COALESCE(n.date_written, n.date_received) ASC, n.id ASC
        ";

        $params = array_merge($myAddresses, $myAddresses, $myAddresses, [$hubAddress, $hubAddress], $myAddresses);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }

        foreach ($rows as $index => &$row) {
            $type = ($row['request_type'] ?? '') === 'file' ? 'file' : 'echo';
            $row['date_received'] = $this->formatApiTimestamp($row['date_received'] ?? null);
            $row['date_written'] = $this->formatApiTimestamp($row['date_written'] ?? null);
            // For AreaFix/FileFix history, prefer the server-side receive time.
            $row['timestamp'] = $row['date_received'] !== '' ? $row['date_received'] : $row['date_written'];
            $row['excerpt'] = $this->buildLovlyNetExcerpt((string)($row['message_text'] ?? ''));
            $row['status'] = ($row['direction'] ?? '') === 'incoming' ? 'response' : 'pending';
            $row['response_message_id'] = null;
            $row['linked_request_id'] = null;
            $row['reply_msgid'] = null;
            $row['normalized_message_id'] = $this->normalizeNetmailMsgId((string)($row['message_id'] ?? ''));

            if (($row['direction'] ?? '') === 'incoming') {
                $replyMsgId = $this->extractReplyFromKludge($this->buildNetmailKludgeText($row));
                $row['reply_msgid'] = is_string($replyMsgId) ? trim($replyMsgId) : null;
                $row['normalized_reply_msgid'] = $this->normalizeNetmailMsgId((string)$row['reply_msgid']);
            } else {
                $row['normalized_reply_msgid'] = null;
            }
        }
        unset($row);

        $outgoingByMsgId = [];
        foreach ($rows as $index => $row) {
            $normalizedMessageId = (string)($row['normalized_message_id'] ?? '');
            if (($row['direction'] ?? '') === 'outgoing' && $normalizedMessageId !== '') {
                $outgoingByMsgId[$normalizedMessageId] = $index;
            }
        }

        foreach ($rows as $incomingIndex => &$incomingRow) {
            if (($incomingRow['direction'] ?? '') !== 'incoming') {
                continue;
            }

            $replyMsgId = trim((string)($incomingRow['normalized_reply_msgid'] ?? ''));
            if ($replyMsgId === '' || !isset($outgoingByMsgId[$replyMsgId])) {
                continue;
            }

            $outgoingIndex = $outgoingByMsgId[$replyMsgId];
            $rows[$outgoingIndex]['status'] = 'responded';
            $rows[$outgoingIndex]['response_message_id'] = (int)$incomingRow['id'];
            $incomingRow['linked_request_id'] = (int)$rows[$outgoingIndex]['id'];
        }
        unset($incomingRow);

        $rows = array_reverse($rows);
        return array_map(function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'direction' => (string)$row['direction'],
                'request_type' => (string)$row['request_type'],
                'status' => (string)$row['status'],
                'from_name' => (string)$row['from_name'],
                'from_address' => (string)$row['from_address'],
                'to_name' => (string)$row['to_name'],
                'to_address' => (string)$row['to_address'],
                'subject' => (string)($row['subject'] ?? ''),
                'subject_hidden' => true,
                'message_text' => (string)($row['message_text'] ?? ''),
                'excerpt' => (string)($row['excerpt'] ?? ''),
                'message_id' => (string)($row['message_id'] ?? ''),
                'reply_msgid' => (string)($row['reply_msgid'] ?? ''),
                'timestamp' => (string)($row['timestamp'] ?? ''),
                'date_received' => (string)($row['date_received'] ?? ''),
                'date_written' => (string)($row['date_written'] ?? ''),
                'is_sent' => (bool)($row['is_sent'] ?? false),
                'response_message_id' => isset($row['response_message_id']) ? (int)$row['response_message_id'] : null,
                'linked_request_id' => isset($row['linked_request_id']) ? (int)$row['linked_request_id'] : null,
            ];
        }, $rows);
    }

    private function formatApiTimestamp($value): string
    {
        $raw = trim((string)($value ?? ''));
        if ($raw === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($raw))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable $e) {
            return $raw;
        }
    }

    private function buildNetmailKludgeText(array $message): string
    {
        $parts = [];
        if (!empty($message['kludge_lines'])) {
            $parts[] = (string)$message['kludge_lines'];
        }
        if (!empty($message['bottom_kludges'])) {
            $parts[] = (string)$message['bottom_kludges'];
        }
        if ($parts !== []) {
            return implode("\n", $parts);
        }

        return (string)($message['message_text'] ?? '');
    }

    private function normalizeNetmailMsgId(string $msgId): string
    {
        $normalized = trim($msgId);
        if ($normalized === '') {
            return '';
        }

        $normalized = trim($normalized, "<> \t\r\n");
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        return strtolower($normalized);
    }

    private function buildLovlyNetExcerpt(string $messageText, int $limit = 160): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($messageText)) ?? '';
        if ($normalized === '') {
            return '';
        }

        if (mb_strlen($normalized) <= $limit) {
            return $normalized;
        }

        return rtrim(mb_substr($normalized, 0, $limit - 3)) . '...';
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
                       COALESCE(NULLIF(em.art_format, ''), NULLIF(ea.art_format_hint, '')) as art_format,
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
                       COALESCE(NULLIF(em.art_format, ''), NULLIF(ea.art_format_hint, '')) as art_format,
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
            return [
                'success' => false,
                'error_code' => 'errors.reminder.user_not_found_or_logged_in',
                'error' => 'User not found or already logged in'
            ];
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
            $this->logger->error("Error sending reminder email for user " . $user['username'] . ": " . $e->getMessage());
        }

        // Update last_reminded timestamp since reminder was sent successfully
        try {
            $updateStmt = $this->db->prepare("UPDATE users SET last_reminded = NOW() WHERE id = ?");
            $updateResult = $updateStmt->execute([$user['id']]);
            $rowsUpdated = $updateStmt->rowCount();

            $this->logger->info("[REMINDER] Updated last_reminded for user ID {$user['id']} ({$user['username']}): success=" . ($updateResult ? 'true' : 'false') . ", rows_affected=$rowsUpdated");
        } catch (\Exception $e) {
            $this->logger->error("[REMINDER] Failed to update last_reminded for user {$user['username']}: " . $e->getMessage());
            $this->logger->warning("[REMINDER] This likely means the database migration v1.4.8_add_last_reminded_field.sql has not been run");
        }

        return [
            'success' => true, 
            'message_code' => 'ui.api.reminder.sent',
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

            // Encode meta (e.g. cross_post_areas) as JSON; only include the key
            // when the caller provides it so existing drafts without meta are
            // not needlessly touched.
            $metaJson = null;
            if (isset($draftData['meta']) && is_array($draftData['meta'])) {
                $metaJson = json_encode($draftData['meta']);
            }

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
                        meta = ?::jsonb,
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
                    $metaJson,
                    $existingDraft['id']
                ]);

                return ['success' => true, 'draft_id' => $existingDraft['id'], 'updated' => true];
            } else {
                // Create new draft
                $stmt = $this->db->prepare("
                    INSERT INTO drafts (user_id, type, to_address, to_name, echoarea, subject, message_text, reply_to_id, meta, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, NOW() AT TIME ZONE 'UTC', NOW() AT TIME ZONE 'UTC')
                    RETURNING id
                ");

                $stmt->execute([
                    $userId,
                    $draftData['type'],
                    $draftData['to_address'] ?? null,
                    $draftData['to_name'] ?? null,
                    $draftData['echoarea'] ?? null,
                    $draftData['subject'] ?? null,
                    $draftData['message_text'] ?? null,
                    $draftData['reply_to_id'] ?? null,
                    $metaJson
                ]);
                $insertedDraft = $stmt->fetch(\PDO::FETCH_ASSOC);
                $draftId = $insertedDraft ? (int)$insertedDraft['id'] : 0;
                return ['success' => true, 'draft_id' => $draftId, 'created' => true];
            }
        } catch (\Exception $e) {
            $this->logger->error("Error saving draft: " . $e->getMessage());
            return [
                'success' => false,
                'error_code' => 'errors.messages.drafts.save_failed',
                'error' => 'Failed to save draft'
            ];
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
            $this->logger->error("Error finding existing draft: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Decode the meta JSONB column on a draft row into a PHP array.
     *
     * @param array $row Raw row from PDO fetch
     * @return array Row with meta decoded (or null when absent)
     */
    private function decodeDraftMeta(array $row): array
    {
        if (isset($row['meta']) && is_string($row['meta'])) {
            $row['meta'] = json_decode($row['meta'], true) ?? null;
        }
        return $row;
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

            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return array_map([$this, 'decodeDraftMeta'], $rows);
        } catch (\Exception $e) {
            $this->logger->error("Error getting user drafts: " . $e->getMessage());
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
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ? $this->decodeDraftMeta($row) : null;
        } catch (\Exception $e) {
            $this->logger->error("Error getting draft: " . $e->getMessage());
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
            $this->logger->error("Error deleting draft: " . $e->getMessage());
            return [
                'success' => false,
                'error_code' => 'errors.messages.drafts.delete_failed',
                'error' => 'Failed to delete draft'
            ];
        }
    }
}
