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

    /** Max commands per node per hour (rate limiting). */
    private int $maxCommandsPerHour;

    public function __construct()
    {
        $this->db             = Database::getInstance()->getPdo();
        $this->logger         = new Logger(Config::getLogPath('packetbbs.log'), Logger::LEVEL_INFO, false);
        $this->sessionRepo    = new PacketBbsSession();
        $this->messageHandler = new MessageHandler();

        $cfg = BbsConfig::getConfig()['packet_bbs'] ?? [];
        $this->sessionTimeout     = (int)($cfg['session_timeout_minutes'] ?? 15);
        $this->maxCommandsPerHour = (int)($cfg['max_commands_per_hour'] ?? 60);
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
            ]);
            $session = $this->sessionRepo->load($nodeId);
            return 'Session expired. LOGIN again.';
        }

        $command = trim($command);
        if ($command === '') {
            return 'Send HELP.';
        }

        // If in compose mode, route all input through the compose handler
        if ($session['menu_state'] === 'compose_netmail' || $session['menu_state'] === 'compose_echomail') {
            return $this->handleComposeLine($session, $nodeId, $command, $renderer);
        }

        // Parse command verb and arguments
        $parts = preg_split('/\s+/', $command, 2);
        $verb  = strtoupper($parts[0]);
        $args  = trim($parts[1] ?? '');

        switch ($verb) {
            case 'HELP':
            case '?':
                return $renderer->renderHelp($args, $this->getBbsName());

            case 'LOGIN':
                return $this->handleLogin($session, $nodeId, $args, $interface);

            case 'Q':
            case 'QUIT':
                return $this->handleQuit($nodeId);

            case 'WHO':
                return $this->handleWho($session, $renderer);

            case 'N':
            case 'MAIL':
            case 'NM':
            case 'NETMAIL':
                return $this->handleNetmailList($session, $nodeId, 1, $renderer);

            case 'NR':
                return $this->handleNetmailRead($session, (int)$args, $renderer);

            case 'NRP':
                return $this->handleNetmailReply($session, $nodeId, (int)$args, $renderer);

            case 'NS':
            case 'S':
            case 'SEND':
                return $this->handleNetmailCompose($session, $nodeId, $args, $renderer);

            case 'E':
            case 'AREAS':
                return $this->handleEchoareaList($session, $renderer);

            case 'ER':
            case 'AREA':
                return $this->handleEchomailList($session, $nodeId, trim($args), 1, $renderer);

            case 'EM':
                return $this->handleEchomailRead($session, (int)$args, $renderer);

            case 'EMR':
                return $this->handleEchomailReply($session, $nodeId, (int)$args, $renderer);

            case 'EP':
            case 'POST':
                return $this->handleEchomailPost($session, $nodeId, $args, $renderer);

            case 'R':
            case 'READ':
                return $this->handleReadAny($session, (int)$args, $renderer);

            case 'RP':
            case 'REPLY':
                return $this->handleReplyAny($session, $nodeId, (int)$args, $renderer);

            case 'M':
            case 'MORE':
                return $this->handleMore($session, $nodeId, $renderer);

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
     * Accumulates body lines. A lone '.' or '/SEND' submits; 'CANCEL' or
     * '/CANCEL' aborts.
     */
    private function handleComposeLine(array $session, string $nodeId, string $line, PacketBbsTextRenderer $renderer): string
    {
        $control = strtoupper(trim($line));
        if ($control === 'CANCEL' || $control === '/CANCEL') {
            $this->sessionRepo->update($nodeId, [
                'menu_state'   => 'main',
                'compose_buffer' => null,
                'compose_type'   => null,
                'compose_meta'   => null,
            ]);
            return 'Cancelled.';
        }

        if (trim($line) === '.' || $control === '/SEND') {
            return $this->submitCompose($session, $nodeId);
        }

        // Append line to buffer
        $buffer = ($session['compose_buffer'] ?? '') . $line . "\n";

        // Guard against excessively large messages (FTN ~16KB body limit)
        if (strlen($buffer) > 15000) {
            return 'Too long. /SEND or /CANCEL.';
        }

        $this->sessionRepo->update($nodeId, ['compose_buffer' => $buffer]);
        return 'OK';
    }

    private function submitCompose(array $session, string $nodeId): string
    {
        $meta   = json_decode($session['compose_meta'] ?? '{}', true) ?? [];
        $body   = trim($session['compose_buffer'] ?? '');
        $type   = $session['compose_type'] ?? '';

        $this->sessionRepo->update($nodeId, [
            'menu_state'   => 'main',
            'compose_buffer' => null,
            'compose_type'   => null,
            'compose_meta'   => null,
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

    private function handleLogin(array $session, string $nodeId, string $args, string $interface): string
    {
        $parts = preg_split('/\s+/', $args, 2);
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            return 'Use: LOGIN <user> <code>';
        }

        $username = $parts[0];
        $code     = $parts[1];

        // Rate limit check before any database lookup.
        $rateLimit = new PacketBbsLoginRateLimit();
        if (!$rateLimit->check($nodeId)) {
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
            $rateLimit->recordFailure($nodeId);
            $this->logger->warning(sprintf('login failed (user not found) node=%s user=%s', $nodeId, $username));
            return 'Login failed.';
        }

        $meta         = new UserMeta();
        $totpEnabled  = $meta->getValue((int)$user['id'], 'packet_bbs_totp_enabled');
        $totpSecret   = $meta->getValue((int)$user['id'], 'packet_bbs_totp_secret');

        if ($totpEnabled !== '1' || !$totpSecret) {
            // Deliberate: do not expose which users have TOTP enrolled.
            $this->logger->warning(sprintf(
                'login failed (totp not enrolled) node=%s user=%s',
                $nodeId,
                $username
            ));
            return 'No PacketBBS auth. Enable in web settings.';
        }

        // Verify the submitted code — never log the code itself.
        if (!PacketBbsTotp::verifyCode($totpSecret, $code)) {
            $rateLimit->recordFailure($nodeId);
            $this->logger->warning(sprintf('login failed (invalid code) node=%s user=%s', $nodeId, $username));
            return 'Login failed.';
        }

        $rateLimit->recordSuccess($nodeId);

        $this->sessionRepo->update($nodeId, [
            'user_id'            => $user['id'],
            'menu_state'         => 'main',
            'pagination_cursor'  => 1,
            'pagination_context' => null,
            'compose_buffer'     => null,
            'compose_type'       => null,
            'compose_meta'       => null,
        ]);

        $this->logger->info(sprintf('login ok node=%s user=%s', $nodeId, $user['username']));
        return sprintf('Hi %s. HELP for commands.', $user['username']);
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

    private function handleNetmailList(array $session, string $nodeId, int $page, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }

        $limit  = $renderer->getPageSize();
        $result = $this->messageHandler->getNetmail($session['user_id'], $page, $limit);
        $messages = $result['messages'] ?? [];
        $total    = $result['pagination']['total_pages'] ?? 1;

        if ($page > max(1, (int)$total)) {
            return 'End.';
        }

        $this->sessionRepo->update($nodeId, [
            'pagination_cursor'  => $page,
            'pagination_context' => json_encode(['type' => 'netmail']),
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

    private function handleNetmailRead(array $session, int $id, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }
        if ($id <= 0) {
            return 'Use: R <id>';
        }

        $msg = $this->messageHandler->getMessage($id, 'netmail', $session['user_id']);
        if (!$msg) {
            return sprintf('No message %d.', $id);
        }

        return $renderer->renderNetmailMessage($msg);
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

        // args = "<to_username_or_name> <subject words...>"
        $parts = preg_split('/\s+/', $args, 2);
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            return 'Use: SEND <user> <subject>';
        }

        $toName = $parts[0];
        $subject = $parts[1];

        // Look up recipient to get their FidoNet address
        $toAddress = $this->resolveUserAddress($toName);

        $this->startCompose($nodeId, 'netmail', [
            'to_name'    => $toName,
            'to_address' => $toAddress,
            'subject'    => $subject,
            'reply_to'   => null,
        ]);

        return $renderer->renderComposePrompt('netmail', $toName, $subject);
    }

    /**
     * Look up a user's FidoNet address by username or real name.
     * Falls back to the system address if the user has none.
     */
    private function resolveUserAddress(string $nameOrUsername): string
    {
        $stmt = $this->db->prepare(
            'SELECT fidonet_address FROM users
             WHERE LOWER(username) = LOWER(?) OR LOWER(real_name) = LOWER(?)
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

    // -------------------------------------------------------------------------
    // Compose submission
    // -------------------------------------------------------------------------

    private function handleEchoareaList(array $session, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }

        $stmt = $this->db->prepare(
            'SELECT e.tag, e.domain, e.description
             FROM echoareas e
             JOIN user_echoarea_subscriptions s ON s.echoarea_id = e.id
             WHERE s.user_id = ? AND s.is_active = TRUE AND e.is_active = TRUE
             ORDER BY e.tag ASC'
        );
        $stmt->execute([$session['user_id']]);
        $areas = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $renderer->renderEchoareaList($areas);
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
        $total    = $result['pagination']['total_pages'] ?? 1;

        if ($page > max(1, (int)$total)) {
            return 'End.';
        }

        $this->sessionRepo->update($nodeId, [
            'pagination_cursor'  => $page,
            'pagination_context' => json_encode(['type' => 'echomail', 'area' => $this->formatAreaIdentifier($tag, $domain)]),
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

    private function handleEchomailRead(array $session, int $id, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }
        if ($id <= 0) {
            return 'Use: R <id>';
        }

        $msg = $this->messageHandler->getMessage($id, 'echomail', $session['user_id']);
        if (!$msg) {
            return sprintf('No message %d.', $id);
        }

        return $renderer->renderEchomailMessage($msg);
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

        $parts = preg_split('/\s+/', $args, 2);
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            return 'Use: POST <tag> <subject>';
        }

        $area    = $parts[0];
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

        return $renderer->renderComposePrompt('echomail', $this->formatAreaIdentifier($tag, $domain), $subject);
    }

    private function handleReadAny(array $session, int $id, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }
        if ($id <= 0) {
            return 'Use: R <id>';
        }

        $msg = $this->messageHandler->getMessage($id, 'netmail', $session['user_id']);
        if ($msg) {
            return $renderer->renderNetmailMessage($msg);
        }

        $msg = $this->messageHandler->getMessage($id, 'echomail', $session['user_id']);
        if ($msg) {
            return $renderer->renderEchomailMessage($msg);
        }

        return sprintf('No message %d.', $id);
    }

    private function handleReplyAny(array $session, string $nodeId, int $id, PacketBbsTextRenderer $renderer): string
    {
        if ($err = $this->requireLogin($session)) {
            return $err;
        }
        if ($id <= 0) {
            return 'Use: RP <id>';
        }

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

        if (($ctx['type'] ?? '') === 'netmail') {
            return $this->handleNetmailList($session, $nodeId, $page, $renderer);
        }

        if (($ctx['type'] ?? '') === 'echomail' && !empty($ctx['area'])) {
            return $this->handleEchomailList($session, $nodeId, $ctx['area'], $page, $renderer);
        }

        return 'No more.';
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
            $ids = implode(',', array_column($rows, 'id'));
            $this->db->exec("UPDATE packet_bbs_outbound_queue SET sent_at = NOW() WHERE id IN ($ids)");
        }

        return array_map(fn($r) => ['id' => (int)$r['id'], 'payload' => $r['payload']], $rows);
    }
}
