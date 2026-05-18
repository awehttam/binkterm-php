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

namespace BinktermPHP\PacketBbs;

use BinktermPHP\BbsConfig;
use BinktermPHP\Auth;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Binkp\Logger;
use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\Chat\ChatMessageService;
use BinktermPHP\MessageHandler;
use BinktermPHP\UserMeta;

/**
 * Entry point for all packet radio BBS commands arriving from remote bridges.
 *
 * Each call to handleCommand() corresponds to one radio packet / line from
 * the operator. Session state is persisted in packet_bbs_sessions so the
 * gateway is stateless between HTTP requests.
 */
class PacketBbsGateway
{
    private \PDO $db;
    private Logger $logger;
    private PacketBbsSession $sessionRepo;
    private MessageHandler $messageHandler;

    /** Inactivity before a session is considered expired (minutes). */
    private int $sessionTimeout;

    public function __construct()
    {
        $this->db             = Database::getInstance()->getPdo();
        $this->logger         = new Logger(Config::getLogPath('packetbbs.log'), Logger::LEVEL_INFO, false);
        $this->sessionRepo    = new PacketBbsSession();
        $this->messageHandler = new MessageHandler();

        $cfg = BbsConfig::getConfig()['packet_bbs'] ?? [];
        $this->sessionTimeout = (int)($cfg['session_timeout_minutes'] ?? 15);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Handle a single text command from a radio node.
     *
     * Returns a plain-text response suitable for transmission back to the node.
     */
    public function handleCommand(string $nodeId, string $interface, string $command, ?string $bridgeNodeId = null): string
    {
        $authNodeId = $bridgeNodeId !== null && $bridgeNodeId !== '' ? $bridgeNodeId : $nodeId;
        $this->logger->info(sprintf(
            'cmd node=%s bridge=%s if=%s cmd=%s',
            $nodeId,
            $authNodeId,
            $interface,
            substr($command, 0, 80)
        ));

        // Reject commands only when the bridge device itself is not registered.
        if (!$this->isKnownNode($authNodeId)) {
            $this->logger->warning(sprintf('rejected unknown bridge node=%s', $authNodeId));
            return 'This node is not registered with this BBS. Contact the sysop to be added.';
        }

        // Update last_seen on the registered bridge device node.
        $this->db->prepare(
            'UPDATE packet_bbs_nodes SET last_seen_at = NOW() WHERE node_id = ?'
        )->execute([$authNodeId]);

        // Opportunistic cleanup (5% of requests): sessions and login attempt records.
        if (rand(1, 100) <= 5) {
            $this->sessionRepo->cleanExpired($this->sessionTimeout);
            (new PacketBbsLoginRateLimit())->cleanOld();
        }

        $session = $this->sessionRepo->getOrCreate($nodeId);
        $renderer = new PacketBbsTextRenderer($interface);
        $state = $this->getSessionState($session);

        // Check session expiry: if last_activity is older than timeout and user_id is set,
        // clear auth state so they must re-login (but keep session row so context is preserved)
        if ($session['user_id'] && $this->isExpired($session)) {
            $this->sessionRepo->update($nodeId, [
                'user_id'            => null,
                'menu_state'         => 'main',
                'pagination_cursor'  => 1,
                'pagination_context' => null,
                'compose_buffer'     => null,
                'compose_type'       => null,
                'compose_meta'       => null,
                'session_state'      => [],
            ]);
            $session = $this->sessionRepo->load($nodeId);
            return 'Session expired. LOGIN again.';
        }

        $command = trim($command);
        if ($command === '') {
            return empty($session['user_id'])
                ? $renderer->renderAbout($this->getBbsName(), Config::getSiteUrl())
                : 'Send HELP.';
        }

        // If in chat mode, route all input through the chat handler
        if ($session['menu_state'] === 'chat') {
            return $this->handleChatInput($session, $nodeId, $command, $renderer);
        }

        // If in compose mode, route all input through the compose handler
        if ($session['menu_state'] === 'compose_netmail' || $session['menu_state'] === 'compose_echomail') {
            return $this->handleComposeLine($session, $nodeId, $command, $renderer);
        }

        if ($this->hasActiveFlow($state)) {
            return $this->handleFlowInput($session, $nodeId, $command, $renderer);
        }

        // Parse command verb and arguments
        $parts = preg_split('/\s+/', $command, 2);
        $verb  = strtoupper($parts[0]);
        $args  = trim($parts[1] ?? '');

        switch ($verb) {
            case 'H':
            case 'HELP':
            case '?':
                if (empty($session['user_id'])) {
                    return $renderer->renderAbout($this->getBbsName(), Config::getSiteUrl());
                }
                return $renderer->renderHelp($args, $this->getBbsName(), $state);

            case 'ABOUT':
                return $renderer->renderAbout($this->getBbsName(), Config::getSiteUrl());

            case 'HELPFULL':
            case 'FULLHELP':
            case 'HELPFUL':
                return $renderer->renderHelp($verb, $this->getBbsName(), $state);

            case 'L':
            case 'LOGIN':
                return $this->handleLogin($session, $nodeId, $args, $interface, $renderer);

            case 'BU':
            case 'BULLETINS':
                return $this->handleBulletins($session, $nodeId, $args, $renderer);

            case 'Q':
            case 'QUIT':
                // Q exits the current context; QUIT always ends the session.
                if ($verb === 'Q') {
                    $state = $this->getSessionState($session);
                    // Fallback: if somehow in chat mode here, exit the room.
                    if ($session['menu_state'] === 'chat' || !empty($state['current_chat_room'])) {
                        return $this->exitChatRoom($nodeId, $state);
                    }
                    if (!empty($state['current_area'])) {
                        $areaName = (string)($state['current_area']['display']
                            ?? $state['current_area']['tag']
                            ?? 'area');
                        unset($state['current_area'], $state['current_list'], $state['current_message']);
                        $this->sessionRepo->update($nodeId, ['session_state' => $state]);
                        return sprintf('Left %s. HELP for commands.', strtoupper($areaName));
                    }
                }
                return $this->handleQuit($nodeId);

            case 'W':
            case 'WHO':
                return $this->handleWho($session, $renderer);

            case 'U':
            case 'STATUS':
                return $renderer->renderStatus($state);

            case 'N':
            case 'MAIL':
            case 'NM':
            case 'NETMAIL':
                return $this->handleNetmailList($session, $nodeId, 1, $renderer);

            case 'NR':
                return $this->handleNetmailRead($session, $nodeId, (int)$args, $renderer);

            case 'NRP':
                return $this->handleNetmailReply($session, $nodeId, (int)$args, $renderer);

            case 'NS':
            case 'S':
            case 'SEND':
                return $this->handleNetmailCompose($session, $nodeId, $args, $renderer);

            case 'A':
            case 'E':
            case 'AREAS':
            case 'T':
            case 'ER':
            case 'AREA':
                return $this->handleAreaCommand($session, $nodeId, $verb, $args, $renderer);

            case 'EM':
                return $this->handleEchomailRead($session, $nodeId, (int)$args, $renderer);

            case 'EMR':
                return $this->handleEchomailReply($session, $nodeId, (int)$args, $renderer);

            case 'EP':
            case 'POST':
                return $this->handleEchomailPost($session, $nodeId, $args, $renderer);

            case 'R':
            case 'READ':
                return $this->handleReadAny($session, $nodeId, (int)$args, $renderer);

            case 'Y':
            case 'RP':
            case 'REPLY':
                return $this->handleReplyAny($session, $nodeId, (int)$args, $renderer);

            case 'M':
            case 'MORE':
                return $this->handleMore($session, $nodeId, $renderer);

            case 'B':
            case 'P':
            case 'PREV':
                return $this->handlePrev($session, $nodeId, $renderer);

            case 'C':
            case 'CHAT':
                return $this->handleChat($session, $nodeId, $args, $renderer);

            case 'WEB':
            case 'WEBSITE':
                return $this->handleWebsite();

            default:
                return 'Unknown. Send HELP.';
        }
    }

    /**
     * Check whether a node_id exists in the sysop-managed nodes table.
     */
    private function isKnownNode(string $nodeId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM packet_bbs_nodes WHERE node_id = ?');
        $stmt->execute([$nodeId]);
        return (bool)$stmt->fetch();
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    private function getSessionState(array $session): array
    {
        $state = $session['session_state'] ?? [];
        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string,mixed> $state
     */
    private function saveSessionState(string $nodeId, array $state): void
    {
        $this->sessionRepo->update($nodeId, ['session_state' => $state]);
    }

    /**
     * @param array<string,mixed> $state
     */
    private function hasActiveFlow(array $state): bool
    {
        return !empty($state['active_flow']['type']);
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $currentArea
     */
    private function setCurrentAreaInState(array $state, array $currentArea): array
    {
        $tag = strtoupper(trim((string)($currentArea['tag'] ?? '')));
        $domain = strtolower(trim((string)($currentArea['domain'] ?? '')));
        $display = $domain !== '' ? $tag . '@' . $domain : $tag;
        $state['current_area'] = [
            'tag' => $tag,
            'domain' => $domain !== '' ? $domain : null,
            'display' => $display,
        ];
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function clearActiveFlow(array $state): array
    {
        unset($state['active_flow']);
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     */
    private function beginPostSubjectFlow(array $state, string $areaTag, string $areaDomain = ''): array
    {
        $display = $this->formatAreaIdentifier($areaTag, $areaDomain);
        $state['active_flow'] = [
            'type' => 'post',
            'step' => 'await_subject',
            'target_display' => $display,
            'target_area_tag' => strtoupper($areaTag),
            'target_area_domain' => $areaDomain !== '' ? strtolower($areaDomain) : null,
            'body_lines' => 0,
        ];
        return $state;
    }

    // -------------------------------------------------------------------------
    // Command handlers
    // -------------------------------------------------------------------------

    private function isExpired(array $session): bool
    {
        if (empty($session['last_activity_at'])) {
            return false;
        }
        try {
            $last    = new \DateTime($session['last_activity_at']);
            $now     = new \DateTime('now', new \DateTimeZone('UTC'));
            $diffMin = ($now->getTimestamp() - $last->getTimestamp()) / 60;
            return $diffMin > $this->sessionTimeout;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Handle a line of input while in compose mode.
     *
     * Accumulates body lines. A lone '.' or '/SEND' ('/S') submits; 'CANCEL',
     * '/CANCEL', or '/C' aborts.
     */
    private function handleComposeLine(array $session, string $nodeId, string $line, PacketBbsTextRenderer $renderer): string
    {
        $state = $this->getSessionState($session);
        $control = strtoupper(trim($line));
        if ($control === 'CANCEL' || $control === '/CANCEL' || $control === '/C') {
            $state = $this->clearActiveFlow($state);
            $this->sessionRepo->update($nodeId, [
                'menu_state'   => 'main',
                'compose_buffer' => null,
                'compose_type'   => null,
                'compose_meta'   => null,
                'session_state'  => $state,
            ]);
            return 'Cancelled.';
        }

        if (trim($line) === '.' || $control === '/SEND' || $control === '/S') {
            return $this->submitCompose($session, $nodeId);
        }

        // Append line to buffer
        $buffer = ($session['compose_buffer'] ?? '') . $line . "\n";

        // Guard against excessively large messages (FTN ~16KB body limit)
        if (strlen($buffer) > 15000) {
            return 'Too long. /S or /C.';
        }

        $this->sessionRepo->update($nodeId, ['compose_buffer' => $buffer]);
        if ($this->hasActiveFlow($state)) {
            $state['active_flow']['body_lines'] = substr_count($buffer, "\n");
            $this->saveSessionState($nodeId, $state);
            return '+';
        }
        return 'OK';
    }

    private function handleFlowInput(array $session, string $nodeId, string $line, PacketBbsTextRenderer $renderer): string
    {
        $state = $this->getSessionState($session);
        $flow = $state['active_flow'] ?? [];
        $control = strtoupper(trim($line));

        if ($control === 'CANCEL' || $control === '/CANCEL' || $control === '/C') {
            $state = $this->clearActiveFlow($state);
            $this->sessionRepo->update($nodeId, [
                'menu_state' => 'main',
                'compose_buffer' => null,
                'compose_type' => null,
                'compose_meta' => null,
                'session_state' => $state,
            ]);
            return 'Cancelled.';
        }

        if (($flow['type'] ?? '') === 'post' && ($flow['step'] ?? '') === 'await_subject') {
            $subject = trim($line);
            if ($subject === '') {
                return 'Subj?';
            }

            $tag = (string)($flow['target_area_tag'] ?? '');
            $domain = (string)($flow['target_area_domain'] ?? '');
            $display = $this->formatAreaIdentifier($tag, $domain);

            $state['active_flow']['step'] = 'await_body';
            $state['active_flow']['subject'] = $subject;
            $state['active_flow']['target_display'] = $display;

            $this->startCompose($nodeId, 'echomail', [
                'tag'      => $tag,
                'domain'   => $domain,
                'to_name'  => 'All',
                'subject'  => $subject,
                'reply_to' => null,
            ]);
            $this->saveSessionState($nodeId, $state);

            return "Msg:\n/SEND (/S) or /CANCEL (/C)";
        }

        return 'Unknown. H';
    }

    private function submitCompose(array $session, string $nodeId): string
    {
        $meta   = is_array($session['compose_meta'] ?? null) ? $session['compose_meta'] : (json_decode($session['compose_meta'] ?? '{}', true) ?? []);
        $body   = trim($session['compose_buffer'] ?? '');
        $type   = $session['compose_type'] ?? '';
        $state  = $this->clearActiveFlow($this->getSessionState($session));

        $this->sessionRepo->update($nodeId, [
            'menu_state'   => 'main',
            'compose_buffer' => null,
            'compose_type'   => null,
            'compose_meta'   => null,
            'session_state'  => $state,
        ]);

        if ($body === '') {
            return 'Empty. Not sent.';
        }

        try {
            if ($type === 'netmail') {
                $id = $this->messageHandler->sendNetmail(
                    $session['user_id'],
                    $meta['to_address'] ?? '',
                    $meta['to_name'] ?? '',
                    $meta['subject'] ?? '(no subject)',
                    $body,
                    null,
                    $meta['reply_to'] ?? null
                );
                if ($id === false) {
                    return 'Send failed.';
                }
                return sprintf('Sent #%d.', $id);
            }

            if ($type === 'echomail') {
                $id = $this->messageHandler->postEchomail(
                    $session['user_id'],
                    $meta['tag'] ?? '',
                    (string)($meta['domain'] ?? ''),
                    $meta['to_name'] ?? 'All',
                    $meta['subject'] ?? '(no subject)',
                    $body,
                    $meta['reply_to'] ?? null
                );
                if ($id === false) {
                    return 'Post failed.';
                }
                $state = $this->setCurrentAreaInState($state, [
                    'tag' => (string)($meta['tag'] ?? ''),
                    'domain' => (string)($meta['domain'] ?? ''),
                ]);
                $this->saveSessionState($nodeId, $state);
                return sprintf('Posted to %s.', $this->formatAreaIdentifier((string)($meta['tag'] ?? '?'), (string)($meta['domain'] ?? '')));
            }
        } catch (\Exception $e) {
            $this->logger->error('compose submit error: ' . $e->getMessage());
            return $this->formatComposeError($e);
        }

        return 'Send failed.';
    }

    private function formatAreaIdentifier(string $tag, string $domain = ''): string
    {
        $tag = strtoupper(trim($tag));
        $domain = strtolower(trim($domain));
        return $domain !== '' ? $tag . '@' . $domain : $tag;
    }

    private function formatComposeError(\Exception $e): string
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'echo area not found')) {
            return 'Area missing. Try AREAS.';
        }
        if (str_contains($message, 'sending address') || str_contains($message, 'missing uplink')) {
            return 'No route for area. Ask sysop.';
        }
        if (str_contains($message, 'delivery system unavailable')) {
            return 'Delivery down. Try later.';
        }
        if (str_contains($message, 'user not found')) {
            return 'User missing.';
        }

        return 'Send failed. Ask sysop.';
    }

    private function handleLogin(array $session, string $nodeId, string $args, string $interface, PacketBbsTextRenderer $renderer): string
    {
        $parts = preg_split('/\s+/', $args, 2);
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            return 'Use: LOGIN <user> <code>';
        }

        $username = $parts[0];
        $code     = $parts[1];

        // Rate limit check before any database lookup — blocks by both node and username.
        $rateLimit = new PacketBbsLoginRateLimit();
        if (!$rateLimit->check($nodeId, $username)) {
            $this->logger->warning(sprintf('login rate limited node=%s user=%s', $nodeId, $username));
            return 'Too many tries. Wait a bit.';
        }

        // Look up the user by username (case-insensitive).
        $stmt = $this->db->prepare(
            'SELECT id, username FROM users WHERE LOWER(username) = LOWER(?) AND is_active = TRUE LIMIT 1'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            $rateLimit->recordFailure($nodeId, $username);
            $this->logger->warning(sprintf('login failed (user not found) node=%s user=%s', $nodeId, $username));
            return 'Login failed.';
        }

        $meta         = new UserMeta();
        $totpEnabled  = $meta->getValue((int)$user['id'], 'packet_bbs_totp_enabled');
        $totpSecret   = $meta->getValue((int)$user['id'], 'packet_bbs_totp_secret');

        if ($totpEnabled !== '1' || !$totpSecret) {
            $rateLimit->recordFailure($nodeId, $username);
            $this->logger->warning(sprintf(
                'login failed (totp not enrolled) node=%s user=%s',
                $nodeId,
                $username
            ));
            return 'Login failed.';
        }

        // Verify the submitted code — never log the code itself.
        if (!PacketBbsTotp::verifyCode($totpSecret, $code, $this->db, (int)$user['id'])) {
            $rateLimit->recordFailure($nodeId, $username);
            $this->logger->warning(sprintf('login failed (invalid code) node=%s user=%s', $nodeId, $username));
            return 'Login failed.';
        }

        $rateLimit->recordSuccess($nodeId, $username);

        $this->sessionRepo->update($nodeId, [
            'user_id'            => $user['id'],
            'menu_state'         => 'main',
            'pagination_cursor'  => 1,
            'pagination_context' => null,
            'compose_buffer'     => null,
            'compose_type'       => null,
            'compose_meta'       => null,
            'session_state'      => [],
        ]);

        $this->logger->info(sprintf('login ok node=%s user=%s', $nodeId, $user['username']));

        $response = sprintf('Hi %s. HELP for commands.', $user['username']);
        $unread = (new \BinktermPHP\BulletinManager())->getUnreadBulletins((int)$user['id']);
        if (!empty($unread)) {
            $response .= "\n" . $renderer->renderLoginBulletinNotice($unread);
        }
        return $response;
    }

    private function handleBulletins(array $session, string $nodeId, string $args, PacketBbsTextRenderer $renderer): string
    {
        $userId  = (int)($session['user_id'] ?? 0);
        $manager = new \BinktermPHP\BulletinManager();

        if ($args !== '' && ctype_digit($args)) {
            $id        = (int)$args;
            $bulletins = $manager->getActiveBulletins($userId > 0 ? $userId : null);
            $bulletin  = null;
            foreach ($bulletins as $b) {
                if ((int)$b['id'] === $id) {
                    $bulletin = $b;
                    break;
                }
            }
            if (!$bulletin) {
                return "Bulletin #$id not found.";
            }
            if ($userId > 0) {
                $manager->markRead($userId, $id);
            }
            return $renderer->renderBulletin($bulletin);
        }

        return $renderer->renderBulletinList($manager->getActiveBulletins($userId > 0 ? $userId : null));
    }

    private function handleQuit(string $nodeId): string
    {
        $this->sessionRepo->destroy($nodeId);
        return sprintf('73 de %s', $this->getBbsName());
    }

    private function getBbsName(): string
    {
        try {
            $name = BinkpConfig::getInstance()->getSystemName();
            if ($name) {
                return $name;
            }
        } catch (\Exception $e) {
            // fall through
        }
        return 'BinktermPHP BBS';
    }

    private function handleWebsite(): string
    {
        return sprintf('Website: %s', Config::getSiteUrl());
    }

    private function handleWho(array $session, PacketBbsTextRenderer $renderer): string
    {
        $cfg = BbsConfig::getConfig()['packet_bbs'] ?? [];
        $guestAllowed = (bool)($cfg['allow_guest_who'] ?? true);

        if (!$session['user_id'] && !$guestAllowed) {
            return 'Login first: LOGIN <user> <code>';
        }

        $users = (new Auth())->getOnlineUsers(15);
        return $renderer->renderWho($users);
    }

    private function handleAreaCommand(array $session, string $nodeId, string $verb, string $args, PacketBbsTextRenderer $renderer): string
    {
        $args = trim($args);

        if ($args === '') {
            return $this->handleEchoareaList($session, $nodeId, '', 1, $renderer);
        }

        $echoarea = $this->resolveSubscribedEchoarea((int)$session['user_id'], $args);
        if ($echoarea) {
            $area = $this->formatAreaIdentifier((string)$echoarea['tag'], (string)($echoarea['domain'] ?? ''));
            return $this->handleEchomailList($session, $nodeId, $area, 1, $renderer);
        }

        if ($verb === 'AREA') {
            return sprintf('No area %s.', strtoupper($args));
        }

        return $this->handleEchoareaList($session, $nodeId, $args, 1, $renderer);
    }

    private function handleNetmailList(array $session, string $nodeId, int $page, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }

        $limit  = $renderer->getPageSize();
        $result = $this->messageHandler->getNetmail($session['user_id'], $page, $limit);
        $messages = $result['messages'] ?? [];
        $total    = $result['pagination']['pages'] ?? 1;

        if ($page > max(1, (int)$total)) {
            return 'End.';
        }

        $state = $this->clearActiveFlow($this->getSessionState($session));
        unset($state['current_area'], $state['current_message']);
        $this->sessionRepo->update($nodeId, [
            'pagination_cursor'  => $page,
            'pagination_context' => json_encode(['type' => 'netmail']),
            'session_state'      => [
                ...$state,
                'current_list' => [
                    'type' => 'netmail',
                    'page' => $page,
                    'total_pages' => (int)$total,
                ],
            ],
        ]);

        return $renderer->renderNetmailList($messages, $page, $total);
    }

    /**
     * Return an error string if the session is not authenticated, null otherwise.
     */
    private function requireLogin(array $session): ?string
    {
        if (empty($session['user_id'])) {
            return 'Not logged in. LOGIN <user> <code>';
        }
        return null;
    }

    private function handleNetmailRead(array $session, string $nodeId, int $id, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }
        if ($id <= 0) {
            $currentMessage = $this->getCurrentMessageState($session);
            if (empty($currentMessage['id'])) {
                return 'Use: R <id>';
            }
            $id = (int)$currentMessage['id'];
            $msgType = (string)($currentMessage['type'] ?? 'netmail');
            $msg = $this->messageHandler->getMessage($id, $msgType === 'echomail' ? 'echomail' : 'netmail', $session['user_id']);
            if ($msg) {
                return $this->renderMessageWithPagination($msg, $msgType === 'echomail' ? 'echomail' : 'netmail', $id, $nodeId, $renderer);
            }
            return 'No current message.';
        }

        $msg = $this->messageHandler->getMessage($id, 'netmail', $session['user_id']);
        if (!$msg) {
            return sprintf('No message %d.', $id);
        }

        return $this->renderMessageWithPagination($msg, 'netmail', $id, $nodeId, $renderer);
    }

    private function handleNetmailReply(array $session, string $nodeId, int $replyToId, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }
        if ($replyToId <= 0) {
            return 'Use: RP <id>';
        }

        $orig = $this->messageHandler->getMessage($replyToId, 'netmail', $session['user_id']);
        if (!$orig) {
            return sprintf('No message %d.', $replyToId);
        }

        $toName    = $orig['from_name'] ?? '?';
        $toAddress = $orig['from_address'] ?? '';
        $subject   = 'Re: ' . ($orig['subject'] ?? '');

        $this->startCompose($nodeId, 'netmail', [
            'to_name'    => $toName,
            'to_address' => $toAddress,
            'subject'    => $subject,
            'reply_to'   => $replyToId,
        ]);

        return $renderer->renderComposePrompt('netmail reply', $toName, $subject);
    }

    /**
     * Enter compose mode by writing the state into the session.
     *
     * @param array<string,mixed> $meta
     */
    private function startCompose(string $nodeId, string $type, array $meta): void
    {
        $this->sessionRepo->update($nodeId, [
            'menu_state'   => 'compose_' . $type,
            'compose_buffer' => '',
            'compose_type'   => $type,
            'compose_meta'   => $meta,
        ]);
    }

    private function handleNetmailCompose(array $session, string $nodeId, string $args, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }

        // args = "<to_username_or_name_or_ftn_address> <subject words...>"
        $parts = preg_split('/\s+/', $args, 2);
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            return 'Use: SEND <user|addr> <subject>';
        }

        [$toName, $toAddress] = $this->resolveNetmailRecipient($parts[0]);
        $subject = $parts[1];

        $this->startCompose($nodeId, 'netmail', [
            'to_name'    => $toName,
            'to_address' => $toAddress,
            'subject'    => $subject,
            'reply_to'   => null,
        ]);

        return $renderer->renderComposePrompt('netmail', $toName, $subject);
    }

    /**
     * Resolve the PacketBBS SEND destination into a display name and FTN address.
     *
     * Accepts either a raw FTN address or a local username/real name. For local
     * users, the stored FTN address is preferred and the system address remains
     * the fallback when the user has no specific AKA configured.
     *
     * @return array{0:string,1:string}
     */
    private function resolveNetmailRecipient(string $destination): array
    {
        $destination = trim($destination);
        if ($this->isFidonetAddress($destination)) {
            return [$destination, $destination];
        }

        return [$destination, $this->resolveUserAddress($destination)];
    }

    /**
     * Look up a user's FidoNet address by username or real name.
     * Falls back to the system address if the user has none.
     */
    private function resolveUserAddress(string $nameOrUsername): string
    {
        $stmt = $this->db->prepare(
            'SELECT fidonet_address FROM users
             WHERE (LOWER(username) = LOWER(?) OR LOWER(real_name) = LOWER(?))
             AND is_active = TRUE
             LIMIT 1'
        );
        $stmt->execute([$nameOrUsername, $nameOrUsername]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row && !empty($row['fidonet_address'])) {
            return $row['fidonet_address'];
        }

        // Use the BBS system address as the routing destination for local users
        try {
            return BinkpConfig::getInstance()->getSystemAddress() ?? '';
        } catch (\Exception $e) {
            return '';
        }
    }

    private function isFidonetAddress(string $address): bool
    {
        return preg_match('/^\d+:\d+\/\d+(?:\.\d+)?(?:@\w+)?$/', trim($address)) === 1;
    }

    // -------------------------------------------------------------------------
    // Compose submission
    // -------------------------------------------------------------------------

    private function handleEchoareaList(array $session, string $nodeId, string $search, int $page, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }

        $search = trim($search);
        $limit  = $renderer->getPageSize();
        $offset = ($page - 1) * $limit;

        if ($search !== '') {
            $like = '%' . $search . '%';
            $countStmt = $this->db->prepare(
                'SELECT COUNT(*) FROM echoareas e
                 JOIN user_echoarea_subscriptions s ON s.echoarea_id = e.id
                 WHERE s.user_id = ? AND s.is_active = TRUE AND e.is_active = TRUE
                   AND (LOWER(e.tag) LIKE LOWER(?) OR LOWER(e.description) LIKE LOWER(?) OR LOWER(COALESCE(e.domain,\'\')) LIKE LOWER(?))'
            );
            $countStmt->execute([$session['user_id'], $like, $like, $like]);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $this->db->prepare(
                'SELECT e.tag, e.domain, e.description
                 FROM echoareas e
                 JOIN user_echoarea_subscriptions s ON s.echoarea_id = e.id
                 WHERE s.user_id = ? AND s.is_active = TRUE AND e.is_active = TRUE
                   AND (LOWER(e.tag) LIKE LOWER(?) OR LOWER(e.description) LIKE LOWER(?) OR LOWER(COALESCE(e.domain,\'\')) LIKE LOWER(?))
                 ORDER BY e.tag ASC
                 LIMIT ? OFFSET ?'
            );
            $stmt->execute([$session['user_id'], $like, $like, $like, $limit, $offset]);
        } else {
            $countStmt = $this->db->prepare(
                'SELECT COUNT(*) FROM echoareas e
                 JOIN user_echoarea_subscriptions s ON s.echoarea_id = e.id
                 WHERE s.user_id = ? AND s.is_active = TRUE AND e.is_active = TRUE'
            );
            $countStmt->execute([$session['user_id']]);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $this->db->prepare(
                'SELECT e.tag, e.domain, e.description
                 FROM echoareas e
                 JOIN user_echoarea_subscriptions s ON s.echoarea_id = e.id
                 WHERE s.user_id = ? AND s.is_active = TRUE AND e.is_active = TRUE
                 ORDER BY e.tag ASC
                 LIMIT ? OFFSET ?'
            );
            $stmt->execute([$session['user_id'], $limit, $offset]);
        }

        $areas      = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $totalPages = max(1, (int)ceil($total / $limit));

        if ($page > $totalPages) {
            return 'End.';
        }

        $this->sessionRepo->update($nodeId, [
            'pagination_cursor'  => $page,
            'pagination_context' => json_encode([
                'type'   => 'areas',
                'search' => $search !== '' ? $search : null,
            ]),
            'session_state'      => [
                ...$this->clearActiveFlow($this->getSessionState($session)),
                'current_list' => [
                    'type' => 'areas',
                    'page' => $page,
                    'total_pages' => $totalPages,
                    'search' => $search !== '' ? $search : null,
                ],
            ],
        ]);

        return $renderer->renderEchoareaList($areas, $search !== '' ? $search : null, $page, $totalPages);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function handleEchomailList(array $session, string $nodeId, string $area, int $page, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }
        if ($area === '') {
            return 'Use: AREA <tag>';
        }

        $echoarea = $this->resolveSubscribedEchoarea((int)$session['user_id'], $area);
        if (!$echoarea) {
            return sprintf('No area %s.', strtoupper($area));
        }

        $tag    = (string)$echoarea['tag'];
        $domain = (string)($echoarea['domain'] ?? '');

        $limit  = $renderer->getPageSize();
        $result = $this->messageHandler->getEchomail($tag, $domain, $page, $limit, $session['user_id']);
        $messages = $result['messages'] ?? [];
        $total    = $result['pagination']['pages'] ?? 1;

        if ($page > max(1, (int)$total)) {
            return 'End.';
        }

        $this->sessionRepo->update($nodeId, [
            'pagination_cursor'  => $page,
            'pagination_context' => json_encode(['type' => 'echomail', 'area' => $this->formatAreaIdentifier($tag, $domain)]),
            'session_state'      => $this->setCurrentAreaInState([
                ...$this->clearActiveFlow($this->getSessionState($session)),
                'current_list' => [
                    'type' => 'echomail',
                    'page' => $page,
                    'total_pages' => (int)$total,
                    'area' => $this->formatAreaIdentifier($tag, $domain),
                ],
            ], ['tag' => $tag, 'domain' => $domain]),
        ]);

        return $renderer->renderEchomailList($messages, $this->formatAreaIdentifier($tag, $domain), $page, $total);
    }

    /**
     * Resolve a subscribed echoarea from either TAG or TAG@domain input.
     *
     * @return array{id:int,tag:string,domain:string|null}|null
     */
    private function resolveSubscribedEchoarea(int $userId, string $area): ?array
    {
        $area = strtoupper(trim($area));
        if ($area === '') {
            return null;
        }

        $parts  = explode('@', $area, 2);
        $tag    = trim($parts[0] ?? '');
        $domain = strtolower(trim($parts[1] ?? ''));

        if ($tag === '') {
            return null;
        }

        if ($domain !== '') {
            $stmt = $this->db->prepare(
                'SELECT e.id, e.tag, e.domain
                 FROM echoareas e
                 JOIN user_echoarea_subscriptions s ON s.echoarea_id = e.id
                 WHERE UPPER(e.tag) = ? AND LOWER(COALESCE(e.domain, \'\')) = ?
                   AND s.user_id = ? AND s.is_active = TRUE AND e.is_active = TRUE
                 LIMIT 1'
            );
            $stmt->execute([$tag, $domain, $userId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        }

        $stmt = $this->db->prepare(
            'SELECT e.id, e.tag, e.domain
             FROM echoareas e
             JOIN user_echoarea_subscriptions s ON s.echoarea_id = e.id
             WHERE UPPER(e.tag) = ?
               AND s.user_id = ? AND s.is_active = TRUE AND e.is_active = TRUE
             ORDER BY CASE WHEN e.domain IS NULL OR e.domain = \'\' THEN 0 ELSE 1 END, e.domain ASC
             LIMIT 1'
        );
        $stmt->execute([$tag, $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function handleEchomailRead(array $session, string $nodeId, int $id, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }
        if ($id <= 0) {
            $currentMessage = $this->getCurrentMessageState($session);
            if (empty($currentMessage['id'])) {
                return 'Use: R <id>';
            }
            $id = (int)$currentMessage['id'];
            $msgType = (string)($currentMessage['type'] ?? 'echomail');
            $msg = $this->messageHandler->getMessage($id, $msgType === 'netmail' ? 'netmail' : 'echomail', $session['user_id']);
            if ($msg) {
                return $this->renderMessageWithPagination($msg, $msgType === 'netmail' ? 'netmail' : 'echomail', $id, $nodeId, $renderer);
            }
            return 'No current message.';
        }

        $msg = $this->messageHandler->getMessage($id, 'echomail', $session['user_id']);
        if (!$msg) {
            return sprintf('No message %d.', $id);
        }

        return $this->renderMessageWithPagination($msg, 'echomail', $id, $nodeId, $renderer);
    }

    private function handleEchomailReply(array $session, string $nodeId, int $replyToId, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }
        if ($replyToId <= 0) {
            return 'Use: RP <id>';
        }

        $orig = $this->messageHandler->getMessage($replyToId, 'echomail', $session['user_id']);
        if (!$orig) {
            return sprintf('No message %d.', $replyToId);
        }

        $tag     = $orig['tag'] ?? $orig['echoarea_tag'] ?? $orig['echoarea'] ?? '';
        $domain  = (string)($orig['domain'] ?? $orig['echoarea_domain'] ?? '');
        $subject = 'Re: ' . ($orig['subject'] ?? '');
        $toName  = $orig['from_name'] ?? 'All';

        $this->startCompose($nodeId, 'echomail', [
            'tag'      => $tag,
            'domain'   => $domain,
            'to_name'  => $toName,
            'subject'  => $subject,
            'reply_to' => $replyToId,
        ]);

        return $renderer->renderComposePrompt('echomail reply', sprintf('%s in %s', $toName, strtoupper($tag)), $subject);
    }

    private function handleEchomailPost(array $session, string $nodeId, string $args, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }

        $args = trim($args);
        $state = $this->getSessionState($session);
        $currentArea = $state['current_area'] ?? null;

        if ($args === '') {
            if (!is_array($currentArea) || empty($currentArea['tag'])) {
                return 'Use: POST <tag> <subject>';
            }

            $state = $this->beginPostSubjectFlow(
                $state,
                (string)$currentArea['tag'],
                (string)($currentArea['domain'] ?? '')
            );
            $this->saveSessionState($nodeId, $state);
            return 'Subj?';
        }

        if (is_array($currentArea) && !empty($currentArea['tag'])) {
            $parts = preg_split('/\s+/', $args, 2);
            $explicitArea = count($parts) >= 2 ? $this->resolveSubscribedEchoarea((int)$session['user_id'], (string)$parts[0]) : null;

            if (!$explicitArea) {
                $tag = (string)$currentArea['tag'];
                $domain = (string)($currentArea['domain'] ?? '');
                $this->startCompose($nodeId, 'echomail', [
                    'tag'      => $tag,
                    'domain'   => $domain,
                    'to_name'  => 'All',
                    'subject'  => $args,
                    'reply_to' => null,
                ]);

                $state = $this->setCurrentAreaInState(
                    $this->beginPostSubjectFlow($this->clearActiveFlow($state), $tag, $domain),
                    ['tag' => $tag, 'domain' => $domain]
                );
                $state['active_flow']['step'] = 'await_body';
                $state['active_flow']['subject'] = $args;
                $this->saveSessionState($nodeId, $state);

                return $renderer->renderComposePrompt('echomail', $this->formatAreaIdentifier($tag, $domain), $args);
            }
        }

        $parts = preg_split('/\s+/', $args, 2);
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            return 'Use: POST <tag> <subject>';
        }

        $area = $parts[0];
        $subject = $parts[1];

        $echoarea = $this->resolveSubscribedEchoarea((int)$session['user_id'], $area);
        if (!$echoarea) {
            return sprintf('No access to %s. AREAS lists yours.', strtoupper($area));
        }
        $tag    = (string)$echoarea['tag'];
        $domain = (string)($echoarea['domain'] ?? '');

        $this->startCompose($nodeId, 'echomail', [
            'tag'      => $tag,
            'domain'   => $domain,
            'to_name'  => 'All',
            'subject'  => $subject,
            'reply_to' => null,
        ]);

        $state = $this->setCurrentAreaInState(
            $this->beginPostSubjectFlow($this->clearActiveFlow($state), $tag, $domain),
            ['tag' => $tag, 'domain' => $domain]
        );
        $state['active_flow']['step'] = 'await_body';
        $state['active_flow']['subject'] = $subject;
        $this->saveSessionState($nodeId, $state);

        return $renderer->renderComposePrompt('echomail', $this->formatAreaIdentifier($tag, $domain), $subject);
    }

    private function handleReadAny(array $session, string $nodeId, int $id, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }
        if ($id <= 0) {
            $state = $this->getSessionState($session);
            $currentMessage = $state['current_message'] ?? null;
            if (!is_array($currentMessage) || empty($currentMessage['id'])) {
                return 'Use: R <id>';
            }

            $id = (int)$currentMessage['id'];
            $type = (string)($currentMessage['type'] ?? '');
            if ($type === 'netmail') {
                $msg = $this->messageHandler->getMessage($id, 'netmail', $session['user_id']);
                if ($msg) {
                    return $this->renderMessageWithPagination($msg, 'netmail', $id, $nodeId, $renderer);
                }
            } elseif ($type === 'echomail') {
                $msg = $this->messageHandler->getMessage($id, 'echomail', $session['user_id']);
                if ($msg) {
                    return $this->renderMessageWithPagination($msg, 'echomail', $id, $nodeId, $renderer);
                }
            }

            return 'No current message.';
        }

        $state = $this->getSessionState($session);
        if ($this->isNetmailContext($state)) {
            $msg = $this->messageHandler->getMessage($id, 'netmail', $session['user_id']);
            if ($msg) {
                return $this->renderMessageWithPagination($msg, 'netmail', $id, $nodeId, $renderer);
            }
            return sprintf('No message %d.', $id);
        }

        if ($this->isEchomailContext($state)) {
            $msg = $this->messageHandler->getMessage($id, 'echomail', $session['user_id']);
            if ($msg) {
                return $this->renderMessageWithPagination($msg, 'echomail', $id, $nodeId, $renderer);
            }
            return sprintf('No message %d.', $id);
        }

        // No established context — try netmail then echomail.
        $msg = $this->messageHandler->getMessage($id, 'netmail', $session['user_id']);
        if ($msg) {
            return $this->renderMessageWithPagination($msg, 'netmail', $id, $nodeId, $renderer);
        }
        $msg = $this->messageHandler->getMessage($id, 'echomail', $session['user_id']);
        if ($msg) {
            return $this->renderMessageWithPagination($msg, 'echomail', $id, $nodeId, $renderer);
        }

        return sprintf('No message %d.', $id);
    }

    /** @param array<string,mixed> $state */
    private function isNetmailContext(array $state): bool
    {
        if (!empty($state['current_area'])) {
            return false;
        }
        $listType = $state['current_list']['type'] ?? '';
        if ($listType === 'netmail') {
            return true;
        }
        return $listType === 'message' && ($state['current_message']['type'] ?? '') === 'netmail';
    }

    /** @param array<string,mixed> $state */
    private function isEchomailContext(array $state): bool
    {
        if (!empty($state['current_area'])) {
            return true;
        }
        return ($state['current_list']['type'] ?? '') === 'echomail';
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    private function getCurrentMessageState(array $session): array
    {
        $state = $this->getSessionState($session);
        $currentMessage = $state['current_message'] ?? [];
        return is_array($currentMessage) ? $currentMessage : [];
    }

    /**
     * Render a message, setting up body pagination context if the body exceeds the threshold.
     */
    private function renderMessageWithPagination(array $msg, string $type, int $id, string $nodeId, PacketBbsTextRenderer $renderer): string
    {
        $totalPages = $renderer->countBodyPages($msg['message_text'] ?? '');
        $session = $this->sessionRepo->load($nodeId) ?? [];
        $state = $this->clearActiveFlow($this->getSessionState($session));
        $state['current_message'] = [
            'id' => $id,
            'type' => $type,
        ];
        if ($type === 'echomail') {
            $state = $this->setCurrentAreaInState($state, [
                'tag' => (string)($msg['tag'] ?? $msg['echoarea_tag'] ?? ''),
                'domain' => (string)($msg['domain'] ?? $msg['echoarea_domain'] ?? ''),
            ]);
        }

        if ($totalPages > 1) {
            $this->sessionRepo->update($nodeId, [
                'pagination_cursor'  => 1,
                'pagination_context' => json_encode(['type' => 'message', 'msg_type' => $type, 'id' => $id]),
                'session_state'      => [
                    ...$state,
                    'current_list' => [
                        'type' => 'message',
                        'page' => 1,
                        'total_pages' => $totalPages,
                    ],
                ],
            ]);
            return $type === 'netmail'
                ? $renderer->renderNetmailMessage($msg, 1)
                : $renderer->renderEchomailMessage($msg, 1);
        }

        $this->saveSessionState($nodeId, [
            ...$state,
            'current_list' => [
                'type' => 'message',
                'page' => 1,
                'total_pages' => 1,
            ],
        ]);

        return $type === 'netmail'
            ? $renderer->renderNetmailMessage($msg)
            : $renderer->renderEchomailMessage($msg);
    }

    private function handleReplyAny(array $session, string $nodeId, int $id, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }
        if ($id <= 0) {
            $state = $this->getSessionState($session);
            $currentMessage = $state['current_message'] ?? null;
            if (is_array($currentMessage) && !empty($currentMessage['id'])) {
                $id = (int)$currentMessage['id'];
                $type = (string)($currentMessage['type'] ?? '');
                if ($type === 'netmail') {
                    return $this->handleNetmailReply($session, $nodeId, $id, $renderer);
                }
                if ($type === 'echomail') {
                    return $this->handleEchomailReply($session, $nodeId, $id, $renderer);
                }
            }
            return 'Use: RP <id>';
        }

        $state = $this->getSessionState($session);
        if ($this->isNetmailContext($state)) {
            $msg = $this->messageHandler->getMessage($id, 'netmail', $session['user_id']);
            if ($msg) {
                return $this->handleNetmailReply($session, $nodeId, $id, $renderer);
            }
            return sprintf('No message %d.', $id);
        }

        if ($this->isEchomailContext($state)) {
            $msg = $this->messageHandler->getMessage($id, 'echomail', $session['user_id']);
            if ($msg) {
                return $this->handleEchomailReply($session, $nodeId, $id, $renderer);
            }
            return sprintf('No message %d.', $id);
        }

        // No established context — try netmail then echomail.
        $msg = $this->messageHandler->getMessage($id, 'netmail', $session['user_id']);
        if ($msg) {
            return $this->handleNetmailReply($session, $nodeId, $id, $renderer);
        }
        $msg = $this->messageHandler->getMessage($id, 'echomail', $session['user_id']);
        if ($msg) {
            return $this->handleEchomailReply($session, $nodeId, $id, $renderer);
        }

        return sprintf('No message %d.', $id);
    }

    private function handleMore(array $session, string $nodeId, PacketBbsTextRenderer $renderer): string
    {
        if (!$session['pagination_context']) {
            return 'No more. Try MAIL or AREA <tag>.';
        }

        $ctx  = json_decode($session['pagination_context'], true);
        $page = (int)($session['pagination_cursor'] ?? 1) + 1;

        if (($ctx['type'] ?? '') === 'areas') {
            return $this->handleEchoareaList($session, $nodeId, (string)($ctx['search'] ?? ''), $page, $renderer);
        }

        if (($ctx['type'] ?? '') === 'netmail') {
            return $this->handleNetmailList($session, $nodeId, $page, $renderer);
        }

        if (($ctx['type'] ?? '') === 'echomail' && !empty($ctx['area'])) {
            return $this->handleEchomailList($session, $nodeId, $ctx['area'], $page, $renderer);
        }

        if (($ctx['type'] ?? '') === 'message') {
            $msgId   = (int)($ctx['id'] ?? 0);
            $msgType = $ctx['msg_type'] ?? 'netmail';

            if ($msgId <= 0) {
                return 'No more.';
            }

            $msg = $this->messageHandler->getMessage($msgId, $msgType, $session['user_id']);
            if (!$msg) {
                return 'Message not found.';
            }

            $totalPages = $renderer->countBodyPages($msg['message_text'] ?? '');
            if ($page > $totalPages) {
                $state = $this->getSessionState($session);
                if (isset($state['current_list'])) {
                    $state['current_list']['page'] = $totalPages;
                    $state['current_list']['total_pages'] = $totalPages;
                }
                $this->sessionRepo->update($nodeId, [
                    'pagination_context' => null,
                    'session_state' => $state,
                ]);
                return 'End.';
            }

            $state = $this->getSessionState($session);
            if (isset($state['current_list'])) {
                $state['current_list']['page'] = $page;
                $state['current_list']['total_pages'] = $totalPages;
            }
            $this->sessionRepo->update($nodeId, [
                'pagination_cursor' => $page,
                'session_state' => $state,
            ]);

            return $msgType === 'netmail'
                ? $renderer->renderNetmailMessage($msg, $page)
                : $renderer->renderEchomailMessage($msg, $page);
        }

        return 'No more.';
    }

    private function handlePrev(array $session, string $nodeId, PacketBbsTextRenderer $renderer): string
    {
        if (!$session['pagination_context']) {
            return 'No context. Try MAIL or AREA <tag>.';
        }

        $ctx  = json_decode($session['pagination_context'], true);
        $page = (int)($session['pagination_cursor'] ?? 1) - 1;

        if ($page < 1) {
            return 'Already at first page.';
        }

        if (($ctx['type'] ?? '') === 'areas') {
            return $this->handleEchoareaList($session, $nodeId, (string)($ctx['search'] ?? ''), $page, $renderer);
        }

        if (($ctx['type'] ?? '') === 'netmail') {
            return $this->handleNetmailList($session, $nodeId, $page, $renderer);
        }

        if (($ctx['type'] ?? '') === 'echomail' && !empty($ctx['area'])) {
            return $this->handleEchomailList($session, $nodeId, $ctx['area'], $page, $renderer);
        }

        if (($ctx['type'] ?? '') === 'message') {
            $msgId   = (int)($ctx['id'] ?? 0);
            $msgType = $ctx['msg_type'] ?? 'netmail';

            if ($msgId <= 0) {
                return 'No context.';
            }

            $msg = $this->messageHandler->getMessage($msgId, $msgType, $session['user_id']);
            if (!$msg) {
                return 'Message not found.';
            }

            $state = $this->getSessionState($session);
            if (isset($state['current_list'])) {
                $state['current_list']['page'] = $page;
            }
            $this->sessionRepo->update($nodeId, [
                'pagination_cursor' => $page,
                'session_state' => $state,
            ]);

            return $msgType === 'netmail'
                ? $renderer->renderNetmailMessage($msg, $page)
                : $renderer->renderEchomailMessage($msg, $page);
        }

        return 'No context.';
    }

    // -------------------------------------------------------------------------
    // Chat handlers
    // -------------------------------------------------------------------------

    /**
     * Enter a chat room. With no args, enters the default (first active) room.
     */
    private function handleChat(array $session, string $nodeId, string $args, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }
        return $this->enterChatRoom($session, $nodeId, trim($args), $renderer);
    }

    /**
     * Route input while menu_state === 'chat'. Escape commands are handled
     * explicitly; everything else is posted to the current room.
     */
    private function handleChatInput(array $session, string $nodeId, string $command, PacketBbsTextRenderer $renderer): string
    {
        $state = $this->getSessionState($session);
        $room  = $state['current_chat_room'] ?? null;
        $dm    = $state['current_chat_dm'] ?? null;

        if (!is_array($room) && !is_array($dm)) {
            $this->sessionRepo->update($nodeId, ['menu_state' => 'main']);
            return 'Chat session lost. CHAT to re-enter.';
        }

        $parts = preg_split('/\s+/', $command, 2);
        $verb  = strtoupper($parts[0]);
        $args  = trim($parts[1] ?? '');

        switch ($verb) {
            case 'Q':
            case '/C':
                return $this->exitChatRoom($nodeId, $state);

            case 'QUIT':
                return $this->handleQuit($nodeId);

            case 'W':
            case 'WHO':
                return $this->handleWho($session, $renderer);

            case 'U':
            case 'STATUS':
                return $renderer->renderStatus($state);

            case 'H':
            case 'HELP':
            case '?':
                return $renderer->renderHelp('CHAT', $this->getBbsName(), $state);

            case 'M':
            case 'MORE':
                return $this->handleChatMore($session, $nodeId, $state, $renderer);

            case 'B':
            case 'P':
            case 'PREV':
                return $this->handleChatPrev($session, $nodeId, $state, $renderer);

            case 'L':
            case 'LOGIN':
                return 'Already in chat. Q to exit first.';

            case 'C':
            case 'CHAT':
                if ($args !== '') {
                    return $this->enterChatRoom($session, $nodeId, $args, $renderer);
                }
                // Refresh at latest page
                $messages = $this->chatFetch($state, (int)$session['user_id'], 1, $renderer->getPageSize());
                $this->sessionRepo->update($nodeId, ['pagination_cursor' => 1]);
                return $renderer->renderChatMessages($messages['rows'], $this->chatDisplayName($state), 1, $messages['total_pages']);
        }

        // Everything else: post to the current context (room or DM)
        if ($dm !== null) {
            return $this->postDmMessage($session, $nodeId, $command, (int)$dm['user_id'], (string)$dm['username']);
        }
        return $this->postChatMessage($session, $nodeId, $command, (int)$room['id'], (string)$room['name']);
    }

    /**
     * Enter a named room (or the default room when $name is empty), setting
     * menu_state to 'chat' and showing recent messages.
     */
    private function enterChatRoom(array $session, string $nodeId, string $name, PacketBbsTextRenderer $renderer): string
    {
        $room = $this->resolveChatRoom($name);
        if (!$room) {
            if ($name !== '') {
                $target = $this->resolveUser($name);
                if ($target) {
                    return $this->enterDm($session, $nodeId, (int)$target['id'], (string)$target['username'], $renderer);
                }
                return sprintf('No room or user "%s".', $this->truncateForChat($name, 20));
            }
            return 'No chat rooms available.';
        }

        $state = $this->getSessionState($session);
        $state['current_chat_room'] = ['id' => (int)$room['id'], 'name' => (string)$room['name']];
        unset($state['current_chat_dm']);

        $messages = $this->fetchChatMessages((int)$room['id'], 1, $renderer->getPageSize());

        $this->sessionRepo->update($nodeId, [
            'menu_state'         => 'chat',
            'pagination_cursor'  => 1,
            'pagination_context' => json_encode(['type' => 'chat', 'room_id' => (int)$room['id']]),
            'session_state'      => $state,
        ]);

        return $renderer->renderChatMessages($messages['rows'], (string)$room['name'], 1, $messages['total_pages']);
    }

    /**
     * Resolve a chat room by name (case-insensitive), or return the first
     * active room when $name is empty.
     *
     * @return array{id:int,name:string}|null
     */
    private function resolveChatRoom(string $name): ?array
    {
        if ($name === '') {
            $stmt = $this->db->prepare(
                'SELECT id, name FROM chat_rooms WHERE is_active = TRUE ORDER BY id ASC LIMIT 1'
            );
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare(
                'SELECT id, name FROM chat_rooms WHERE is_active = TRUE AND LOWER(name) = LOWER(?) LIMIT 1'
            );
            $stmt->execute([trim($name)]);
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Fetch a page of chat messages for a room, newest first (page 1 = latest),
     * then reverse so they display oldest-to-newest within the page.
     *
     * @return array{rows:array<int,array<string,mixed>>,total_pages:int}
     */
    private function fetchChatMessages(int $roomId, int $page, int $pageSize): array
    {
        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM chat_messages WHERE room_id = ?');
        $countStmt->execute([$roomId]);
        $total      = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / $pageSize));

        $offset = ($page - 1) * $pageSize;
        $stmt   = $this->db->prepare(
            'SELECT cm.id, cm.body, cm.created_at, u.username
             FROM chat_messages cm
             JOIN users u ON u.id = cm.from_user_id
             WHERE cm.room_id = ?
             ORDER BY cm.created_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([$roomId, $pageSize, $offset]);
        $rows = array_reverse($stmt->fetchAll(\PDO::FETCH_ASSOC));

        return ['rows' => $rows, 'total_pages' => $totalPages];
    }

    /**
     * Post a message to the current chat room on behalf of the logged-in user.
     */
    private function postChatMessage(array $session, string $nodeId, string $text, int $roomId, string $roomName): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'Empty.';
        }
        if (mb_strlen($text) > 500) {
            return 'Too long (500 char max).';
        }

        try {
            (new ChatMessageService())->sendMessage((int)$session['user_id'], $roomId, null, $text);
            return 'OK';
        } catch (\RuntimeException $e) {
            if (str_contains(strtolower($e->getMessage()), 'blocked')) {
                return 'Banned from this room.';
            }
            $this->logger->error('packetbbs chat post error: ' . $e->getMessage());
            return 'Post failed.';
        }
    }

    /** Show older chat history (higher page offset). */
    private function handleChatMore(array $session, string $nodeId, array $state, PacketBbsTextRenderer $renderer): string
    {
        $page     = (int)($session['pagination_cursor'] ?? 1) + 1;
        $messages = $this->chatFetch($state, (int)$session['user_id'], $page, $renderer->getPageSize());

        if ($page > $messages['total_pages']) {
            return 'End of history.';
        }

        $this->sessionRepo->update($nodeId, ['pagination_cursor' => $page]);
        return $renderer->renderChatMessages($messages['rows'], $this->chatDisplayName($state), $page, $messages['total_pages']);
    }

    /** Show newer chat history (lower page offset, towards page 1 = latest). */
    private function handleChatPrev(array $session, string $nodeId, array $state, PacketBbsTextRenderer $renderer): string
    {
        $current = (int)($session['pagination_cursor'] ?? 1);
        if ($current <= 1) {
            return 'At latest.';
        }

        $page     = $current - 1;
        $messages = $this->chatFetch($state, (int)$session['user_id'], $page, $renderer->getPageSize());

        $this->sessionRepo->update($nodeId, ['pagination_cursor' => $page]);
        return $renderer->renderChatMessages($messages['rows'], $this->chatDisplayName($state), $page, $messages['total_pages']);
    }

    /** Truncate helper used in chat context (no renderer available). */
    private function truncateForChat(string $str, int $max): string
    {
        if (mb_strlen($str) <= $max) {
            return $str;
        }
        return mb_substr($str, 0, $max - 1) . '~';
    }

    /**
     * Exit chat/DM mode, clear context from session state, and return a
     * confirmation. Used both by the chat-input handler and as a fallback in
     * the main Q dispatcher.
     */
    private function exitChatRoom(string $nodeId, array $state): string
    {
        $displayName = $this->chatDisplayName($state);
        unset($state['current_chat_room'], $state['current_chat_dm']);
        $this->sessionRepo->update($nodeId, [
            'menu_state'         => 'main',
            'pagination_context' => null,
            'session_state'      => $state,
        ]);
        return sprintf('Left %s.', $displayName);
    }

    /**
     * Enter a DM conversation with another user, setting menu_state to 'chat'
     * and showing recent message history.
     */
    private function enterDm(array $session, string $nodeId, int $toUserId, string $toUsername, PacketBbsTextRenderer $renderer): string
    {
        $fromUserId = (int)$session['user_id'];
        if ($toUserId === $fromUserId) {
            return 'Cannot DM yourself.';
        }

        $state = $this->getSessionState($session);
        $state['current_chat_dm'] = ['user_id' => $toUserId, 'username' => $toUsername];
        unset($state['current_chat_room']);

        $messages = $this->fetchDmMessages($fromUserId, $toUserId, 1, $renderer->getPageSize());

        $this->sessionRepo->update($nodeId, [
            'menu_state'         => 'chat',
            'pagination_cursor'  => 1,
            'pagination_context' => json_encode(['type' => 'dm', 'to_user_id' => $toUserId]),
            'session_state'      => $state,
        ]);

        return $renderer->renderChatMessages($messages['rows'], 'DM:' . $toUsername, 1, $messages['total_pages']);
    }

    /**
     * Fetch paginated DM history between two users (most recent first per page,
     * displayed oldest-first within the page).
     *
     * @return array{rows:array<int,array<string,mixed>>,total_pages:int}
     */
    private function fetchDmMessages(int $userId1, int $userId2, int $page, int $pageSize): array
    {
        $offset = ($page - 1) * $pageSize;

        $countStmt = $this->db->prepare("
            SELECT COUNT(*) AS total
            FROM chat_messages
            WHERE room_id IS NULL
              AND ((from_user_id = ? AND to_user_id = ?) OR (from_user_id = ? AND to_user_id = ?))
        ");
        $countStmt->execute([$userId1, $userId2, $userId2, $userId1]);
        $total      = (int)($countStmt->fetchColumn() ?: 0);
        $totalPages = max(1, (int)ceil($total / $pageSize));

        $stmt = $this->db->prepare("
            SELECT cm.id, cm.body, cm.created_at, u.username
            FROM chat_messages cm
            JOIN users u ON u.id = cm.from_user_id
            WHERE cm.room_id IS NULL
              AND ((cm.from_user_id = ? AND cm.to_user_id = ?) OR (cm.from_user_id = ? AND cm.to_user_id = ?))
            ORDER BY cm.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId1, $userId2, $userId2, $userId1, $pageSize, $offset]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return ['rows' => array_reverse($rows), 'total_pages' => $totalPages];
    }

    /**
     * Send a direct message to another user via ChatMessageService.
     */
    private function postDmMessage(array $session, string $nodeId, string $text, int $toUserId, string $toUsername): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'Empty.';
        }
        if (mb_strlen($text) > 500) {
            return 'Too long (500 char max).';
        }

        try {
            (new ChatMessageService())->sendMessage((int)$session['user_id'], null, $toUserId, $text);
            return 'OK';
        } catch (\RuntimeException $e) {
            $this->logger->error('packetbbs dm post error: ' . $e->getMessage());
            return 'Send failed.';
        }
    }

    /**
     * Resolve a user by username for DM entry.
     *
     * @return array{id:int,username:string}|null
     */
    private function resolveUser(string $username): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, username FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1'
        );
        $stmt->execute([trim($username)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Fetch messages for the current chat context (room or DM), abstracting
     * which underlying query to use.
     *
     * @return array{rows:array<int,array<string,mixed>>,total_pages:int}
     */
    private function chatFetch(array $state, int $fromUserId, int $page, int $pageSize): array
    {
        if (!empty($state['current_chat_dm'])) {
            return $this->fetchDmMessages($fromUserId, (int)$state['current_chat_dm']['user_id'], $page, $pageSize);
        }
        return $this->fetchChatMessages((int)$state['current_chat_room']['id'], $page, $pageSize);
    }

    /**
     * Return the display name for the current chat context (room name or "DM:username").
     */
    private function chatDisplayName(array $state): string
    {
        if (!empty($state['current_chat_dm'])) {
            return 'DM:' . (string)$state['current_chat_dm']['username'];
        }
        return (string)($state['current_chat_room']['name'] ?? 'chat');
    }

    /**
     * Return and mark-delivered any queued outbound messages for a node.
     *
     * @return array<int,array{id:int,payload:string}>
     */
    public function getPendingMessages(string $nodeId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, payload FROM packet_bbs_outbound_queue
             WHERE node_id = ? AND sent_at IS NULL
             ORDER BY id ASC
             LIMIT 20"
        );
        $stmt->execute([$nodeId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            $ids = implode(',', array_map('intval', array_column($rows, 'id')));
            $this->db->exec("UPDATE packet_bbs_outbound_queue SET sent_at = NOW() WHERE id IN ($ids)");
        }

        return array_map(fn($r) => ['id' => (int)$r['id'], 'payload' => $r['payload']], $rows);
    }
}
