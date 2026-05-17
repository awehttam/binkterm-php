<?php

namespace BinktermPHP\TelnetServer;

/**
 * Full-screen local chat client for terminal sessions.
 *
 * Features:
 * - room selection from an in-chat navigation pane
 * - online user list
 * - room and direct-message conversations
 * - unread badges for rooms and DMs during the current session
 * - incremental polling via /api/chat/poll
 * - scrollback with older-message fetch
 * - admin moderation shortcuts from the online-users pane
 * - optional multiline compose via the shared full-screen editor
 */
class ChatHandler
{
    private const FOCUS_NAV = 'nav';
    private const FOCUS_MESSAGES = 'messages';
    private const FOCUS_USERS = 'users';
    private const FOCUS_INPUT = 'input';

    private BbsSession $server;
    private string $apiBase;
    private int $localPseudoId = -1;

    public function __construct(BbsSession $server, string $apiBase)
    {
        $this->server = $server;
        $this->apiBase = $apiBase;
    }

    /**
     * @param resource $conn
     */
    public function show($conn, array &$state, string $session): void
    {
        if (!\BinktermPHP\BbsConfig::isFeatureEnabled('chat')) {
            $this->showInfo($conn, $state, $this->t('ui.terminalserver.chat.feature_disabled', 'Local chat is disabled.', $state));
            return;
        }

        $chat = $this->createState($state);
        $this->refreshRooms($chat, $session, $state);
        $this->refreshOnlineUsers($chat, $session);
        $this->refreshCursorAnchor($chat, $session, $state);
        $this->ensureActiveTarget($chat, $session, $state);

        if ($chat['active_target'] === null) {
            $this->showInfo($conn, $state, $this->t('ui.terminalserver.chat.no_targets', 'No chat rooms or users are available right now.', $state));
            return;
        }

        $chat['last_room_refresh'] = time();
        $chat['last_online_refresh'] = 0;
        $chat['last_poll_refresh'] = 0;
        $chat['last_active_refresh'] = 0;
        $chat['last_render_size'] = [0, 0];
        $chat['dirty'] = true;
        $chat['input_dirty'] = false;

        while (true) {
            $now = microtime(true);

            if (($now - $chat['last_active_refresh']) >= 1.0) {
                $this->refreshActiveConversation($chat, $session, $state, $conn);
                $chat['last_active_refresh'] = $now;
            }
            if (($now - $chat['last_poll_refresh']) >= 5.0) {
                $this->pollMessages($chat, $session, $state, $conn);
                $chat['last_poll_refresh'] = $now;
            }
            if (($now - $chat['last_online_refresh']) >= 12.0) {
                $this->refreshOnlineUsers($chat, $session);
                $chat['last_online_refresh'] = $now;
                $chat['dirty'] = true;
            }
            if (($now - $chat['last_room_refresh']) >= 45.0) {
                $this->refreshRooms($chat, $session, $state, false);
                $chat['last_room_refresh'] = $now;
                $chat['dirty'] = true;
            }

            $cols = (int)($state['cols'] ?? 80);
            $rows = (int)($state['rows'] ?? 24);
            if ($chat['last_render_size'][0] !== $cols || $chat['last_render_size'][1] !== $rows) {
                $chat['dirty'] = true;
            }

            if ($chat['dirty']) {
                $this->render($conn, $state, $chat);
                $chat['dirty'] = false;
                $chat['input_dirty'] = false;
                $chat['last_render_size'] = [$cols, $rows];
            } elseif (!empty($chat['input_dirty'])) {
                $this->renderInputPaneOnly($conn, $state, $chat);
                $chat['input_dirty'] = false;
            }

            [$key, $timedOut, $shouldDisconnect] = $this->server->readKeyWithTimeout($conn, $state, 250);
            if ($shouldDisconnect || $key === null) {
                return;
            }
            if ($timedOut || $key === '') {
                continue;
            }

            if ($key === 'CTRL_C') {
                return;
            }

            $handled = $this->handleGlobalKey($conn, $state, $session, $chat, $key);
            if ($handled === 'exit') {
                return;
            }
            if ($handled) {
                continue;
            }

            switch ($chat['focus']) {
                case self::FOCUS_NAV:
                    $this->handleNavigationKey($chat, $session, $state, $key);
                    break;

                case self::FOCUS_MESSAGES:
                    $this->handleMessageKey($chat, $session, $state, $key);
                    break;

                case self::FOCUS_USERS:
                    $this->handleUsersKey($chat, $session, $state, $key);
                    break;

                case self::FOCUS_INPUT:
                default:
                    $this->handleInputKey($conn, $chat, $session, $state, $key);
                    break;
            }
        }
    }

    private function createState(array $state): array
    {
        return [
            'focus' => self::FOCUS_INPUT,
            'rooms' => [],
            'room_map' => [],
            'online_users' => [],
            'online_map' => [],
            'dm_users' => [],
            'dm_unread' => [],
            'room_unread' => [],
            'conversations' => [],
            'active_target' => null,
            'nav_selected_key' => null,
            'users_selected_id' => null,
            'message_scroll_offset' => 0,
            'input' => '',
            'input_cursor' => 0,
            'status' => '',
            'status_color' => TelnetUtils::ANSI_DIM,
            'status_until' => 0,
            'last_seen_message_id' => 0,
            'last_room_refresh' => 0,
            'last_online_refresh' => 0,
            'last_poll_refresh' => 0,
            'last_active_refresh' => 0,
            'last_render_size' => [0, 0],
            'layout' => [],
            'dirty' => true,
            'username' => (string)($state['username'] ?? ''),
            'user_id' => (int)($state['user_id'] ?? 0),
            'is_admin' => !empty($state['is_admin']),
        ];
    }

    private function handleGlobalKey($conn, array &$state, string $session, array &$chat, string $key)
    {
        $focusOrder = $this->getFocusOrder($chat);
        if ($key === 'TAB') {
            $currentIndex = array_search($chat['focus'], $focusOrder, true);
            $chat['focus'] = $focusOrder[($currentIndex === false ? 0 : (($currentIndex + 1) % count($focusOrder)))];
            $chat['dirty'] = true;
            return true;
        }
        if ($key === 'SHIFT_TAB') {
            $currentIndex = array_search($chat['focus'], $focusOrder, true);
            if ($currentIndex === false) {
                $chat['focus'] = $focusOrder[0];
            } else {
                $chat['focus'] = $focusOrder[($currentIndex - 1 + count($focusOrder)) % count($focusOrder)];
            }
            $chat['dirty'] = true;
            return true;
        }

        if (($key === 'CHAR:r' || $key === 'CHAR:R') && $chat['focus'] !== self::FOCUS_INPUT) {
            $this->refreshRooms($chat, $session, $state);
            $this->refreshOnlineUsers($chat, $session);
            $this->setStatus($chat, $this->t('ui.terminalserver.chat.refreshed', 'Chat lists refreshed.', $state), TelnetUtils::ANSI_GREEN);
            return true;
        }

        if ($key === 'CTRL_K') {
            $this->showHelp($conn, $state, $chat);
            $chat['dirty'] = true;
            return true;
        }

        return false;
    }

    private function handleNavigationKey(array &$chat, string $session, array &$state, string $key): void
    {
        $items = $this->buildNavItems($chat);
        if ($items === []) {
            return;
        }

        $selectedIndex = $this->findNavIndex($items, $chat['nav_selected_key'] ?? '');
        if ($selectedIndex < 0) {
            $selectedIndex = 0;
        }

        if ($key === 'UP') {
            $selectedIndex = max(0, $selectedIndex - 1);
        } elseif ($key === 'DOWN') {
            $selectedIndex = min(count($items) - 1, $selectedIndex + 1);
        } elseif ($key === 'HOME') {
            $selectedIndex = 0;
        } elseif ($key === 'END') {
            $selectedIndex = count($items) - 1;
        } elseif ($key === 'ENTER') {
            $target = $items[$selectedIndex];
            $this->openTarget($chat, $session, $state, [
                'type' => $target['target_type'],
                'id' => $target['target_id'],
                'label' => $target['label'],
            ]);
        } else {
            return;
        }

        $chat['nav_selected_key'] = $items[$selectedIndex]['key'];
        $chat['dirty'] = true;
    }

    private function handleMessageKey(array &$chat, string $session, array &$state, string $key): void
    {
        $layout = $chat['layout'];
        $page = max(1, (int)($layout['message_content_height'] ?? 8) - 2);

        if ($key === 'PGUP' || $key === 'UP') {
            $chat['message_scroll_offset'] += $page;
            $this->maybeFetchOlderMessages($chat, $session, $state, $layout['message_content_height'] ?? 8);
            $chat['dirty'] = true;
            return;
        }

        if ($key === 'PGDOWN' || $key === 'DOWN') {
            $chat['message_scroll_offset'] = max(0, $chat['message_scroll_offset'] - $page);
            $chat['dirty'] = true;
            return;
        }

        if ($key === 'HOME') {
            $chat['message_scroll_offset'] = PHP_INT_MAX;
            $this->maybeFetchOlderMessages($chat, $session, $state, $layout['message_content_height'] ?? 8);
            $chat['dirty'] = true;
            return;
        }

        if ($key === 'END') {
            $chat['message_scroll_offset'] = 0;
            $chat['dirty'] = true;
        }
    }

    private function handleUsersKey(array &$chat, string $session, array &$state, string $key): void
    {
        $users = array_values($chat['online_users']);
        if ($users === []) {
            return;
        }

        $selectedId = $chat['users_selected_id'];
        $selectedIndex = 0;
        foreach ($users as $index => $user) {
            if ((int)$user['user_id'] === (int)$selectedId) {
                $selectedIndex = $index;
                break;
            }
        }

        if ($key === 'UP') {
            $selectedIndex = max(0, $selectedIndex - 1);
        } elseif ($key === 'DOWN') {
            $selectedIndex = min(count($users) - 1, $selectedIndex + 1);
        } elseif ($key === 'HOME') {
            $selectedIndex = 0;
        } elseif ($key === 'END') {
            $selectedIndex = count($users) - 1;
        } elseif ($key === 'ENTER') {
            $selected = $users[$selectedIndex];
            $this->ensureDmUser($chat, (int)$selected['user_id'], (string)$selected['username']);
            $this->openTarget($chat, $session, $state, [
                'type' => 'dm',
                'id' => (int)$selected['user_id'],
                'label' => (string)$selected['username'],
            ]);
            $chat['focus'] = self::FOCUS_INPUT;
            return;
        } elseif (($key === 'CHAR:k' || $key === 'CHAR:K' || $key === 'CHAR:b' || $key === 'CHAR:B')
            && $chat['is_admin']
            && ($chat['active_target']['type'] ?? '') === 'room'
        ) {
            $selected = $users[$selectedIndex];
            $action = ($key === 'CHAR:k' || $key === 'CHAR:K') ? 'kick' : 'ban';
            $this->moderateUser($chat, $session, $state, $action, $selected);
            return;
        } else {
            return;
        }

        $chat['users_selected_id'] = (int)$users[$selectedIndex]['user_id'];
        $chat['dirty'] = true;
    }

    private function handleInputKey($conn, array &$chat, string $session, array &$state, string $key): void
    {
        $input = (string)$chat['input'];
        $cursor = (int)$chat['input_cursor'];

        if ($key === 'LEFT') {
            $chat['input_cursor'] = max(0, $cursor - 1);
            $this->markInputDirty($chat);
            return;
        }
        if ($key === 'RIGHT') {
            $chat['input_cursor'] = min(strlen($input), $cursor + 1);
            $this->markInputDirty($chat);
            return;
        }
        if ($key === 'HOME') {
            $chat['input_cursor'] = 0;
            $this->markInputDirty($chat);
            return;
        }
        if ($key === 'END') {
            $chat['input_cursor'] = strlen($input);
            $this->markInputDirty($chat);
            return;
        }
        if ($key === 'BACKSPACE') {
            if ($cursor > 0) {
                $chat['input'] = substr($input, 0, $cursor - 1) . substr($input, $cursor);
                $chat['input_cursor'] = $cursor - 1;
                $this->markInputDirty($chat);
            }
            return;
        }
        if ($key === 'DELETE') {
            if ($cursor < strlen($input)) {
                $chat['input'] = substr($input, 0, $cursor) . substr($input, $cursor + 1);
                $this->markInputDirty($chat);
            }
            return;
        }
        if ($key === 'CTRL_E') {
            $multiline = $this->server->readMultiline(
                $conn,
                $state,
                (int)($state['cols'] ?? 80),
                (string)$chat['input'],
                [
                    'title' => $this->t('ui.terminalserver.chat.editor.title', 'LOCAL CHAT COMPOSER', $state),
                    'saved' => $this->t('ui.terminalserver.chat.editor.saved', 'Message saved and ready to send.', $state),
                    'shortcuts' => $this->t('ui.terminalserver.chat.editor.shortcuts', 'Ctrl+K=Help  Ctrl+Z=Send  Ctrl+C=Cancel', $state),
                ]
            );
            if ($multiline !== '') {
                $chat['input'] = $multiline;
                $chat['input_cursor'] = strlen($multiline);
                $this->sendCurrentInput($chat, $session, $state);
            }
            $chat['dirty'] = true;
            return;
        }
        if ($key === 'ENTER') {
            $this->sendCurrentInput($chat, $session, $state);
            return;
        }
        if (str_starts_with($key, 'CHAR:')) {
            $char = substr($key, 5);
            $chat['input'] = substr($input, 0, $cursor) . $char . substr($input, $cursor);
            $chat['input_cursor'] = $cursor + 1;
            $this->markInputDirty($chat);
        }
    }

    private function sendCurrentInput(array &$chat, string $session, array &$state): void
    {
        $body = trim((string)$chat['input']);
        if ($body === '') {
            $this->setStatus($chat, $this->t('ui.terminalserver.chat.empty_input', 'Type a message before sending.', $state), TelnetUtils::ANSI_YELLOW);
            return;
        }

        $payload = ['body' => $body];
        if (($chat['active_target']['type'] ?? '') === 'room') {
            $payload['room_id'] = (int)$chat['active_target']['id'];
        } else {
            $payload['to_user_id'] = (int)$chat['active_target']['id'];
        }

        $response = $this->apiRequest('POST', '/api/chat/send', $payload, $session, $state);
        if (($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300 && !empty($response['data']['success'])) {
            $localMessage = $response['data']['local_message'] ?? null;
            if (is_array($localMessage)) {
                if (($localMessage['type'] ?? '') === 'local') {
                    $localMessage['type'] = (string)($chat['active_target']['type'] ?? 'room');
                    if (($chat['active_target']['type'] ?? '') === 'room') {
                        $localMessage['room_id'] = (int)($chat['active_target']['id'] ?? 0);
                    } else {
                        $localMessage['to_user_id'] = (int)($chat['active_target']['id'] ?? 0);
                    }
                }
                $this->normalizeMessage($localMessage);
                if (!isset($localMessage['id']) || !$localMessage['id']) {
                    $localMessage['id'] = $this->localPseudoId--;
                }
                $this->appendMessageToState($chat, $localMessage, true);
            }
            if (!empty($response['data']['message_id'])) {
                $chat['last_seen_message_id'] = max($chat['last_seen_message_id'], (int)$response['data']['message_id']);
            }
            $chat['input'] = '';
            $chat['input_cursor'] = 0;
            $chat['message_scroll_offset'] = 0;
            $this->setStatus($chat, $this->t('ui.terminalserver.chat.sent', 'Message sent.', $state), TelnetUtils::ANSI_GREEN);
            return;
        }

        $error = (string)($response['data']['error'] ?? $this->t('ui.terminalserver.chat.send_failed', 'Failed to send message.', $state));
        $this->setStatus($chat, $error, TelnetUtils::ANSI_RED, 8);
    }

    private function moderateUser(array &$chat, string $session, array &$state, string $action, array $user): void
    {
        if (($chat['active_target']['type'] ?? '') !== 'room') {
            return;
        }

        $response = $this->apiRequest('POST', '/api/chat/moderate', [
            'room_id' => (int)$chat['active_target']['id'],
            'user_id' => (int)$user['user_id'],
            'action' => $action,
        ], $session, $state);

        if (($response['status'] ?? 0) >= 200 && ($response['status'] ?? 0) < 300 && !empty($response['data']['success'])) {
            $label = $action === 'ban'
                ? $this->t('ui.terminalserver.chat.user_banned', '{username} was banned from the room.', $state, ['username' => (string)$user['username']])
                : $this->t('ui.terminalserver.chat.user_kicked', '{username} was kicked from the room.', $state, ['username' => (string)$user['username']]);
            $local = [
                'id' => $this->localPseudoId--,
                'type' => 'room',
                'room_id' => (int)$chat['active_target']['id'],
                'from_user_id' => null,
                'from_username' => 'System',
                'body' => $label,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ];
            $this->appendMessageToState($chat, $local, true);
            $this->setStatus($chat, $label, TelnetUtils::ANSI_GREEN, 6);
            return;
        }

        $error = (string)($response['data']['error'] ?? $this->t('ui.terminalserver.chat.moderation_failed', 'Moderation request failed.', $state));
        $this->setStatus($chat, $error, TelnetUtils::ANSI_RED, 8);
    }

    private function ensureActiveTarget(array &$chat, string $session, array &$state): void
    {
        if ($chat['active_target'] !== null) {
            return;
        }

        if ($chat['rooms'] !== []) {
            $room = $chat['rooms'][0];
            $this->openTarget($chat, $session, $state, [
                'type' => 'room',
                'id' => (int)$room['id'],
                'label' => (string)$room['name'],
            ]);
            return;
        }

        if ($chat['online_users'] !== []) {
            $user = array_values($chat['online_users'])[0];
            $this->ensureDmUser($chat, (int)$user['user_id'], (string)$user['username']);
            $this->openTarget($chat, $session, $state, [
                'type' => 'dm',
                'id' => (int)$user['user_id'],
                'label' => (string)$user['username'],
            ]);
        }
    }

    private function openTarget(array &$chat, string $session, array &$state, array $target): void
    {
        $chat['active_target'] = $target;
        $chat['nav_selected_key'] = $this->targetKey($target['type'], (int)$target['id']);
        $chat['message_scroll_offset'] = 0;

        if ($target['type'] === 'room') {
            $chat['room_unread'][(int)$target['id']] = 0;
        } else {
            $chat['dm_unread'][(int)$target['id']] = 0;
        }

        $key = $this->targetKey($target['type'], (int)$target['id']);
        if (empty($chat['conversations'][$key]['loaded'])) {
            $chat['conversations'][$key] = [
                'loaded' => true,
                'has_more' => false,
                'messages' => [],
            ];
            $this->loadConversation($chat, $session, $state, $target['type'], (int)$target['id']);
        }

        if ($target['type'] === 'dm') {
            $chat['users_selected_id'] = (int)$target['id'];
        }

        $chat['dirty'] = true;
    }

    private function loadConversation(array &$chat, string $session, array &$state, string $type, int $id, ?int $beforeId = null): void
    {
        $path = $type === 'room'
            ? '/api/chat/messages?room_id=' . $id . '&limit=80'
            : '/api/chat/messages?dm_user_id=' . $id . '&limit=80';
        if ($beforeId !== null) {
            $path .= '&before_id=' . $beforeId;
        }

        $response = $this->apiRequest('GET', $path, null, $session, $state);
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            $this->setStatus($chat, (string)($response['data']['error'] ?? $this->t('ui.terminalserver.chat.load_failed', 'Failed to load chat messages.', $state)), TelnetUtils::ANSI_RED, 8);
            return;
        }

        $messages = $response['data']['messages'] ?? [];
        foreach ($messages as &$message) {
            $this->normalizeMessage($message);
            if (($message['id'] ?? 0) > 0) {
                $chat['last_seen_message_id'] = max($chat['last_seen_message_id'], (int)$message['id']);
            }
        }
        unset($message);

        $targetKey = $this->targetKey($type, $id);
        $conversation = $chat['conversations'][$targetKey] ?? ['loaded' => true, 'messages' => [], 'has_more' => false];

        if ($beforeId !== null) {
            $existing = $conversation['messages'] ?? [];
            $conversation['messages'] = array_merge($messages, $existing);
        } else {
            $conversation['messages'] = $messages;
        }
        $conversation['loaded'] = true;
        $conversation['has_more'] = !empty($response['data']['has_more']);
        $chat['conversations'][$targetKey] = $conversation;
        $chat['dirty'] = true;
    }

    private function refreshRooms(array &$chat, string $session, array &$state, bool $showErrors = true): void
    {
        $response = $this->apiRequest('GET', '/api/chat/rooms', null, $session, $state);
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            if ($showErrors) {
                $this->setStatus($chat, (string)($response['data']['error'] ?? $this->t('ui.terminalserver.chat.rooms_failed', 'Failed to load chat rooms.', $state)), TelnetUtils::ANSI_RED, 8);
            }
            return;
        }

        $chat['rooms'] = [];
        $chat['room_map'] = [];
        foreach (($response['data']['rooms'] ?? []) as $room) {
            $room = [
                'id' => (int)($room['id'] ?? 0),
                'name' => (string)($room['name'] ?? ''),
                'description' => (string)($room['description'] ?? ''),
            ];
            if ($room['id'] <= 0) {
                continue;
            }
            $chat['rooms'][] = $room;
            $chat['room_map'][$room['id']] = $room;
            $chat['room_unread'][$room['id']] = (int)($chat['room_unread'][$room['id']] ?? 0);
        }

        if (($chat['active_target']['type'] ?? '') === 'room') {
            $activeRoomId = (int)$chat['active_target']['id'];
            if (!isset($chat['room_map'][$activeRoomId]) && $chat['rooms'] !== []) {
                $room = $chat['rooms'][0];
                $this->openTarget($chat, $session, $state, ['type' => 'room', 'id' => $room['id'], 'label' => $room['name']]);
            }
        }

        $chat['dirty'] = true;
    }

    private function refreshCursorAnchor(array &$chat, string $session, array &$state): void
    {
        $response = $this->apiRequest('GET', '/api/chat/cursor', null, $session, $state);
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            return;
        }

        $chat['last_seen_message_id'] = max(
            (int)$chat['last_seen_message_id'],
            (int)($response['data']['max_id'] ?? 0)
        );
    }

    private function refreshOnlineUsers(array &$chat, string $session): void
    {
        $response = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/chat/online', null, $session);
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            return;
        }

        $chat['online_users'] = [];
        $chat['online_map'] = [];
        foreach (($response['data']['users'] ?? []) as $user) {
            $normalized = [
                'user_id' => (int)($user['user_id'] ?? 0),
                'username' => (string)($user['username'] ?? ''),
                'location' => (string)($user['location'] ?? ''),
                'is_bot' => !empty($user['is_bot']),
            ];
            if ($normalized['user_id'] <= 0) {
                continue;
            }
            $chat['online_users'][$normalized['user_id']] = $normalized;
            $chat['online_map'][$normalized['user_id']] = $normalized;
            $this->ensureDmUser($chat, $normalized['user_id'], $normalized['username']);
        }

        if ($chat['users_selected_id'] === null && $chat['online_users'] !== []) {
            $chat['users_selected_id'] = (int)array_values($chat['online_users'])[0]['user_id'];
        }
    }

    private function pollMessages(array &$chat, string $session, array &$state, $conn): void
    {
        $sinceId = (int)$chat['last_seen_message_id'];
        $response = $this->apiRequest('GET', '/api/chat/poll?since_id=' . $sinceId, null, $session, $state);
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            return;
        }

        $messages = $response['data']['messages'] ?? [];
        foreach ($messages as $message) {
            $this->normalizeMessage($message);
            $messageId = (int)($message['id'] ?? 0);
            if ($messageId > 0) {
                $chat['last_seen_message_id'] = max($chat['last_seen_message_id'], $messageId);
            }

            if (($message['type'] ?? '') === 'dm') {
                $otherId = (int)($message['from_user_id'] ?? 0);
                $otherUsername = (string)($message['from_username'] ?? '');
                if ($otherId > 0) {
                    $this->ensureDmUser($chat, $otherId, $otherUsername);
                }
            }

            $matchedActive = $this->messageMatchesActiveTarget($chat, $message);
            if ($matchedActive) {
                // The visible pane is refreshed from direct /api/chat/messages
                // snapshots. Do not let the global catch-up poll mutate the
                // active conversation or it can replay stale backlog into view.
                continue;
            }

            $this->appendMessageToState($chat, $message, false);
            if (!$matchedActive) {
                if (($message['type'] ?? '') === 'room') {
                    $roomId = (int)($message['room_id'] ?? 0);
                    $chat['room_unread'][$roomId] = (int)($chat['room_unread'][$roomId] ?? 0) + 1;
                } elseif (($message['type'] ?? '') === 'dm') {
                    $otherId = (int)($message['from_user_id'] ?? 0);
                    $chat['dm_unread'][$otherId] = (int)($chat['dm_unread'][$otherId] ?? 0) + 1;
                }
                $this->maybeBeepForMessage($conn, $chat, $message);
            } else {
                if ($chat['message_scroll_offset'] === 0) {
                    $chat['dirty'] = true;
                } else {
                    $this->maybeBeepForMessage($conn, $chat, $message);
                }
            }
        }
    }

    private function messageMatchesActiveTarget(array $chat, array $message): bool
    {
        $active = $chat['active_target'] ?? null;
        if (!$active) {
            return false;
        }

        $type = (string)($message['type'] ?? (($message['room_id'] ?? null) ? 'room' : 'dm'));
        $targetId = $type === 'room'
            ? (int)($message['room_id'] ?? 0)
            : (int)(($message['to_user_id'] ?? 0) === $chat['user_id'] ? ($message['from_user_id'] ?? 0) : ($message['to_user_id'] ?? 0));

        return $active['type'] === $type && (int)$active['id'] === $targetId;
    }

    private function refreshActiveConversation(array &$chat, string $session, array &$state, $conn): void
    {
        $active = $chat['active_target'];
        if (!$active) {
            return;
        }

        $path = $active['type'] === 'room'
            ? '/api/chat/messages?room_id=' . (int)$active['id'] . '&limit=100'
            : '/api/chat/messages?dm_user_id=' . (int)$active['id'] . '&limit=100';

        $response = $this->apiRequest('GET', $path, null, $session, $state);
        if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            return;
        }

        $targetKey = $this->targetKey($active['type'], (int)$active['id']);
        $snapshot = [];
        foreach (($response['data']['messages'] ?? []) as $message) {
            $this->normalizeMessage($message);
            $messageId = (int)($message['id'] ?? 0);
            if ($messageId > 0) {
                $chat['last_seen_message_id'] = max($chat['last_seen_message_id'], $messageId);
            }
            $snapshot[] = $message;
        }

        $existingMessages = $chat['conversations'][$targetKey]['messages'] ?? [];
        $existingSignature = $this->conversationSignature($existingMessages);
        $snapshotSignature = $this->conversationSignature($snapshot);
        if ($existingSignature === $snapshotSignature) {
            return;
        }

        $newIds = [];
        foreach ($snapshot as $message) {
            $fromUserId = (int)($message['from_user_id'] ?? 0);
            $messageId = (int)($message['id'] ?? 0);
            if ($messageId > 0) {
                $newIds[$messageId] = true;
            }
            if ($fromUserId > 0 && $fromUserId !== (int)$chat['user_id'] && !$this->conversationContainsId($existingMessages, $messageId)) {
                $this->maybeBeepForMessage($conn, $chat, $message);
            }
        }

        $mergedMessages = $this->mergeConversationSnapshot($existingMessages, $snapshot);

        $chat['conversations'][$targetKey] = [
            'loaded' => true,
            'has_more' => !empty($response['data']['has_more']),
            'messages' => $mergedMessages,
        ];
        $chat['dirty'] = true;
    }

    /**
     * Merge a fresh server snapshot with any already-loaded local history.
     *
     * The active-room snapshot can occasionally arrive one message behind the
     * send response. Preserve any older loaded prefix and any newer local tail
     * messages that the snapshot has not caught up to yet, while de-duplicating
     * by message ID.
     *
     * @param array<int, array<string, mixed>> $existingMessages
     * @param array<int, array<string, mixed>> $snapshot
     * @return array<int, array<string, mixed>>
     */
    private function mergeConversationSnapshot(array $existingMessages, array $snapshot): array
    {
        if ($existingMessages === []) {
            return $snapshot;
        }
        if ($snapshot === []) {
            return $existingMessages;
        }

        $firstSnapshotId = (int)($snapshot[0]['id'] ?? 0);
        $lastSnapshotId = (int)($snapshot[count($snapshot) - 1]['id'] ?? 0);
        $merged = [];
        $seenIds = [];

        foreach ($existingMessages as $existingMessage) {
            $existingId = (int)($existingMessage['id'] ?? 0);
            if ($existingId > 0 && $firstSnapshotId > 0 && $existingId < $firstSnapshotId) {
                $merged[] = $existingMessage;
                $seenIds[$existingId] = true;
            }
        }

        foreach ($snapshot as $message) {
            $messageId = (int)($message['id'] ?? 0);
            if ($messageId > 0 && isset($seenIds[$messageId])) {
                continue;
            }
            $merged[] = $message;
            if ($messageId > 0) {
                $seenIds[$messageId] = true;
            }
        }

        foreach ($existingMessages as $existingMessage) {
            $existingId = (int)($existingMessage['id'] ?? 0);

            if ($existingId > 0) {
                if (isset($seenIds[$existingId])) {
                    continue;
                }
                if ($lastSnapshotId > 0 && $existingId > $lastSnapshotId) {
                    $merged[] = $existingMessage;
                    $seenIds[$existingId] = true;
                }
                continue;
            }

            $merged[] = $existingMessage;
        }

        return $merged;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private function conversationSignature(array $messages): string
    {
        if ($messages === []) {
            return 'empty';
        }

        $ids = [];
        foreach ($messages as $message) {
            $ids[] = (string)(int)($message['id'] ?? 0);
        }

        return implode(',', $ids);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private function conversationContainsId(array $messages, int $messageId): bool
    {
        if ($messageId <= 0) {
            return false;
        }

        foreach ($messages as $message) {
            if ((int)($message['id'] ?? 0) === $messageId) {
                return true;
            }
        }

        return false;
    }

    private function maybeFetchOlderMessages(array &$chat, string $session, array &$state, int $visibleHeight): void
    {
        $active = $chat['active_target'];
        if (!$active) {
            return;
        }
        $targetKey = $this->targetKey($active['type'], (int)$active['id']);
        $conversation = $chat['conversations'][$targetKey] ?? null;
        if (!$conversation || empty($conversation['has_more']) || empty($conversation['messages'])) {
            return;
        }

        $lineCount = count($this->buildMessageLines($chat, $state, (int)($chat['layout']['message_content_width'] ?? 76), true));
        if ($chat['message_scroll_offset'] + $visibleHeight < $lineCount) {
            return;
        }

        $beforeId = (int)($conversation['messages'][0]['id'] ?? 0);
        if ($beforeId > 0) {
            $this->loadConversation($chat, $session, $state, $active['type'], (int)$active['id'], $beforeId);
        }
    }

    /**
     * Returns true when the appended message belongs to the currently active target.
     */
    private function appendMessageToState(array &$chat, array $message, bool $fromSelf): bool
    {
        $type = (string)($message['type'] ?? (($message['room_id'] ?? null) ? 'room' : 'dm'));
        $targetId = $type === 'room'
            ? (int)($message['room_id'] ?? 0)
            : (int)(($message['to_user_id'] ?? 0) === $chat['user_id'] ? ($message['from_user_id'] ?? 0) : ($message['to_user_id'] ?? 0));

        if ($type === 'dm' && $targetId > 0 && !empty($message['from_username'])) {
            $this->ensureDmUser($chat, $targetId, (string)$message['from_username']);
        }

        $targetKey = $this->targetKey($type, $targetId);
        if (!isset($chat['conversations'][$targetKey])) {
            $chat['conversations'][$targetKey] = [
                'loaded' => true,
                'has_more' => false,
                'messages' => [],
            ];
        }

        $chat['conversations'][$targetKey]['messages'][] = $message;
        $chat['dirty'] = true;

        $active = $chat['active_target'];
        if (!$active) {
            return false;
        }

        return $active['type'] === $type && (int)$active['id'] === $targetId;
    }

    private function render($conn, array &$state, array &$chat): void
    {
        $layout = $this->buildLayout((int)($state['cols'] ?? 80), (int)($state['rows'] ?? 24));
        $chat['layout'] = $layout;

        $split = new TerminalSplitScreen(
            $this->server,
            $this->t('ui.terminalserver.chat.title', 'Local Chat', $state)
                . ' - '
                . $this->getActiveTitle($chat, $state)
        );

        $navLines = $this->buildNavigationLines($chat, $state, $layout);
        $messageLines = $this->buildVisibleMessageLines($chat, $state, $layout);
        $userLines = $this->buildOnlineUserLines($chat, $state, $layout);
        $inputLines = $this->buildInputLines($chat, $state, $layout);

        if ($layout['mode'] === 'wide') {
            $split->addRow([
                ['title' => $this->paneTitle($chat, self::FOCUS_NAV, $this->t('ui.terminalserver.chat.pane.rooms', 'Rooms / DMs', $state)), 'lines' => $navLines, 'weight' => 24, 'min_width' => 20],
                ['title' => $this->paneTitle($chat, self::FOCUS_MESSAGES, $this->t('ui.terminalserver.chat.pane.messages', 'Messages', $state)), 'lines' => $messageLines, 'weight' => 76, 'min_width' => 40, 'scroll' => 'top'],
                /*
                ['title' => $this->paneTitle($chat, self::FOCUS_USERS, $this->t('ui.terminalserver.chat.pane.online', 'Online Users', $state)), 'lines' => $userLines, 'weight' => 20, 'min_width' => 20],
                */
            ]);
            $split->addRow([
                ['title' => $this->paneTitle($chat, self::FOCUS_INPUT, $this->t('ui.terminalserver.chat.pane.compose', 'Compose', $state)), 'lines' => $inputLines, 'weight' => 1, 'min_width' => 40]
            ], 7);
        } elseif ($layout['mode'] === 'stacked') {
            $split->addRow([
                ['title' => $this->paneTitle($chat, self::FOCUS_NAV, $this->t('ui.terminalserver.chat.pane.rooms', 'Rooms / DMs', $state)), 'lines' => $navLines, 'weight' => 1, 'min_width' => 32]
            ], 7);
            $split->addRow([
                ['title' => $this->paneTitle($chat, self::FOCUS_MESSAGES, $this->t('ui.terminalserver.chat.pane.messages', 'Messages', $state)), 'lines' => $messageLines, 'weight' => 1, 'min_width' => 32, 'scroll' => 'top']
            ]);
            $split->addRow([
                ['title' => $this->paneTitle($chat, self::FOCUS_INPUT, $this->t('ui.terminalserver.chat.pane.compose', 'Compose', $state)), 'lines' => $inputLines, 'weight' => 1, 'min_width' => 32]
            ], 7);
        } else {
            $split->addRow([
                ['title' => $this->paneTitle($chat, self::FOCUS_NAV, $this->t('ui.terminalserver.chat.pane.rooms', 'Rooms / DMs', $state)), 'lines' => $navLines, 'weight' => 26, 'min_width' => 20],
                ['title' => $this->paneTitle($chat, self::FOCUS_MESSAGES, $this->t('ui.terminalserver.chat.pane.messages', 'Messages', $state)), 'lines' => $messageLines, 'weight' => 74, 'min_width' => 36, 'scroll' => 'top'],
            ]);
            $split->addRow([
                ['title' => $this->paneTitle($chat, self::FOCUS_INPUT, $this->t('ui.terminalserver.chat.pane.compose', 'Compose', $state)), 'lines' => $inputLines, 'weight' => 1, 'min_width' => 40]
            ], 7);
        }

        $split->render($conn, $state);
        $layoutInfo = $split->getLastLayout();
        $chat['last_split_layout'] = $layoutInfo;
        $this->positionInputCursor($conn, $chat, $layoutInfo);
    }

    private function buildLayout(int $cols, int $rows): array
    {
        $usableWidth = max(20, $cols - 2);
        $usableHeight = max(10, $rows - 2);
        $titleRows = 2;
        $contentHeight = max(6, $usableHeight - $titleRows);

        if ($cols >= 110) {
            $topHeight = max(8, $contentHeight - 8);
            return [
                'mode' => 'wide',
                'message_content_width' => max(36, (int)floor(($usableWidth - 2) * 0.58) - 4),
                'message_content_height' => max(4, $topHeight - 4),
                'nav_content_width' => max(16, (int)floor(($usableWidth - 2) * 0.18) - 4),
                'nav_content_height' => max(4, $topHeight - 4),
                'users_content_width' => max(16, (int)floor(($usableWidth - 2) * 0.16) - 4),
                'users_content_height' => max(4, $topHeight - 4),
                'input_content_width' => max(20, $usableWidth - 4),
                'input_content_height' => 3,
            ];
        }

        if ($cols < 80) {
            $messageHeight = max(7, $contentHeight - 16);
            return [
                'mode' => 'stacked',
                'message_content_width' => max(24, $usableWidth - 4),
                'message_content_height' => max(4, $messageHeight - 4),
                'nav_content_width' => max(24, $usableWidth - 4),
                'nav_content_height' => 3,
                'users_content_width' => max(24, $usableWidth - 4),
                'users_content_height' => 0,
                'input_content_width' => max(24, $usableWidth - 4),
                'input_content_height' => 3,
            ];
        }

        $topHeight = max(8, $contentHeight - 8);
        return [
            'mode' => 'medium',
            'message_content_width' => max(32, (int)floor(($usableWidth - 1) * 0.68) - 4),
            'message_content_height' => max(4, $topHeight - 4),
            'nav_content_width' => max(16, (int)floor(($usableWidth - 1) * 0.24) - 4),
            'nav_content_height' => max(4, $topHeight - 4),
            'users_content_width' => 0,
            'users_content_height' => 0,
            'input_content_width' => max(24, $usableWidth - 4),
            'input_content_height' => 3,
        ];
    }

    private function buildNavigationLines(array $chat, array $state, array $layout): array
    {
        $items = $this->buildNavItems($chat);
        $lines = [$this->t('ui.terminalserver.chat.section.rooms', 'Rooms', $state)];
        foreach ($items as $item) {
            if ($item['target_type'] === 'room') {
                $lines[] = $this->formatNavItem($chat, $item);
            }
        }

        $lines[] = $this->t('ui.terminalserver.chat.section.direct', 'Direct Messages', $state);
        $dmCount = 0;
        foreach ($items as $item) {
            if ($item['target_type'] === 'dm') {
                $lines[] = $this->formatNavItem($chat, $item);
                $dmCount++;
            }
        }
        if ($dmCount === 0) {
            $lines[] = $this->t('ui.terminalserver.chat.none_direct', '  No DMs yet.', $state);
        }

        $lines[] = $this->t('ui.terminalserver.chat.section.online', 'Online Now', $state);
        if ($chat['online_users'] === []) {
            $lines[] = $this->t('ui.terminalserver.chat.no_one_online', '  No one else online.', $state);
        } else {
            foreach (array_slice(array_values($chat['online_users']), 0, max(1, $layout['nav_content_height'] - 6)) as $user) {
                $lines[] = '  ' . ($user['is_bot'] ? '*' : '-') . ' ' . $user['username'];
            }
        }

        return $lines;
    }

    private function formatNavItem(array $chat, array $item): string
    {
        $isSelected = ($chat['nav_selected_key'] ?? '') === $item['key'];
        $active = ($chat['active_target']['type'] ?? '') === $item['target_type']
            && (int)($chat['active_target']['id'] ?? 0) === (int)$item['target_id'];

        $prefix = $isSelected && $chat['focus'] === self::FOCUS_NAV ? '>' : ' ';
        $activeMarker = $active ? '*' : ' ';
        $badge = '';

        if ($item['target_type'] === 'room') {
            $unread = (int)($chat['room_unread'][$item['target_id']] ?? 0);
            if ($unread > 0) {
                $badge = ' (' . $unread . ')';
            }
        } else {
            $unread = (int)($chat['dm_unread'][$item['target_id']] ?? 0);
            if ($unread > 0) {
                $badge = ' (' . $unread . ')';
            }
        }

        return sprintf('%s%s %s%s', $prefix, $activeMarker, $item['label'], $badge);
    }

    private function buildOnlineUserLines(array $chat, array $state, array $layout): array
    {
        $lines = [];
        if ($chat['online_users'] === []) {
            return [$this->t('ui.terminalserver.chat.no_one_online', 'No one else online.', $state)];
        }

        foreach (array_values($chat['online_users']) as $user) {
            $selected = (int)$chat['users_selected_id'] === (int)$user['user_id'] && $chat['focus'] === self::FOCUS_USERS;
            $marker = $selected ? '>' : ' ';
            $bot = $user['is_bot'] ? '*' : ' ';
            $suffix = $user['location'] !== '' ? ' - ' . $user['location'] : '';
            $lines[] = sprintf('%s%s %s%s', $marker, $bot, $user['username'], $suffix);
        }

        if ($chat['is_admin'] && ($chat['active_target']['type'] ?? '') === 'room') {
            $lines[] = '';
            $lines[] = $this->t('ui.terminalserver.chat.moderation_hint', 'ENTER=DM  K=Kick  B=Ban', $state);
        } else {
            $lines[] = '';
            $lines[] = $this->t('ui.terminalserver.chat.dm_hint', 'ENTER opens a DM', $state);
        }

        return $lines;
    }

    private function buildVisibleMessageLines(array $chat, array $state, array $layout): array
    {
        $fullLines = $this->buildMessageLines($chat, $state, (int)$layout['message_content_width']);
        $visibleHeight = max(1, (int)$layout['message_content_height']);
        $offset = max(0, (int)$chat['message_scroll_offset']);
        $end = max(0, count($fullLines) - $offset);
        $start = max(0, $end - $visibleHeight);
        $slice = array_slice($fullLines, $start, max(0, $end - $start));

        if ($slice === []) {
            return [$this->t('ui.terminalserver.chat.no_messages', 'No chat messages yet.', $state)];
        }

        while (count($slice) < $visibleHeight) {
            array_unshift($slice, '');
        }

        return $slice;
    }

    private function buildMessageLines(array $chat, array $state, int $width, bool $ignoreOffset = false): array
    {
        $active = $chat['active_target'];
        if (!$active) {
            return [];
        }

        $targetKey = $this->targetKey($active['type'], (int)$active['id']);
        $messages = $chat['conversations'][$targetKey]['messages'] ?? [];
        $lines = [];

        foreach ($messages as $message) {
            foreach ($this->formatMessageLines($message, $chat, $state, $width) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function formatMessageLines(array $message, array $chat, array $state, int $width): array
    {
        $time = TelnetUtils::formatUserDate((string)($message['created_at'] ?? ''), $state, false);
        $from = (string)($message['from_username'] ?? $this->t('ui.terminalserver.chat.system_name', 'System', $state));
        $prefix = ' ';

        if (empty($message['from_user_id'])) {
            $prefix = '#';
        } elseif ((int)($message['from_user_id'] ?? 0) === (int)$chat['user_id']) {
            $prefix = '>';
        } elseif ($this->messageMentionsUser($message, $chat['username'])) {
            $prefix = '!';
        } elseif (($message['type'] ?? '') === 'dm') {
            $prefix = '@';
        }

        $rendered = [];
        $header = sprintf('%s[%s] %s:', $prefix, $time, $from);
        $headerLines = $this->wrapPlainLine($header, $width);
        if ($headerLines !== []) {
            $rendered = array_merge($rendered, $headerLines);
        }

        $body = (string)($message['body'] ?? '');
        if ($body !== '') {
            $bodyWidth = max(4, $width - 2);
            $bodyLines = TerminalMarkupRenderer::render('markdown', $body, $bodyWidth);
            foreach ($bodyLines as $line) {
                $rendered[] = $line === '' ? '' : '  ' . $this->server->encodeForTerminal($line);
            }
        }

        if ($rendered === []) {
            $rendered[] = $header;
        }

        $color = null;
        if ($prefix === '#') {
            $color = TelnetUtils::ANSI_YELLOW;
        } elseif ($prefix === '>') {
            $color = TelnetUtils::ANSI_CYAN;
        } elseif ($prefix === '!') {
            $color = TelnetUtils::ANSI_GREEN . TelnetUtils::ANSI_BOLD;
        } elseif ($prefix === '@') {
            $color = TelnetUtils::ANSI_MAGENTA;
        }

        if ($color !== null && $rendered !== []) {
            $rendered[0] = $this->server->colorizeForTerminal($rendered[0], $color);
        }

        return $rendered;
    }

    private function buildInputLines(array $chat, array $state, array $layout): array
    {
        $lines = [];
        $lines[] = $this->server->colorizeForTerminal($this->t(
            'ui.terminalserver.chat.input_help',
            'TAB focus  Enter send  Ctrl+E multiline  Ctrl+K help  Ctrl+C exit',
            $state
        ), TelnetUtils::ANSI_YELLOW);

        $status = ((time() <= (int)$chat['status_until']) && $chat['status'] !== '')
            ? $this->server->colorizeForTerminal((string)$chat['status'], (string)$chat['status_color'])
            : $this->server->colorizeForTerminal(
                $this->t('ui.terminalserver.chat.input_target', 'Target: {target}', $state, ['target' => $this->getActiveTitle($chat, $state)]),
                TelnetUtils::ANSI_DIM
            );
        $lines[] = $status;

        $width = max(10, (int)$layout['input_content_width'] - 2);
        [$display, ] = $this->getVisibleInputWindow((string)$chat['input'], (int)$chat['input_cursor'], $width);
        $lines[] = '> ' . $display;

        return $lines;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function getVisibleInputWindow(string $input, int $cursor, int $width): array
    {
        $length = strlen($input);
        if ($length <= $width) {
            return [$input, $cursor];
        }

        $start = max(0, $cursor - $width + 1);
        if ($start + $width > $length) {
            $start = max(0, $length - $width);
        }

        return [substr($input, $start, $width), $cursor - $start];
    }

    private function positionInputCursor($conn, array $chat, array $layoutInfo): void
    {
        $cursor = $this->buildInputCursorSequence($chat, $layoutInfo);
        if ($cursor === null) {
            TelnetUtils::setCursorVisible($conn, true);
            return;
        }

        $this->server->safeWrite($conn, $cursor . "\033[?25h");
    }

    private function renderInputPaneOnly($conn, array &$state, array &$chat): void
    {
        $layoutInfo = $chat['last_split_layout'] ?? [];
        if (empty($layoutInfo['rows'])) {
            $chat['dirty'] = true;
            return;
        }

        $inputRow = $layoutInfo['rows'][count($layoutInfo['rows']) - 1] ?? null;
        if (!$inputRow || empty($inputRow['panes'][0])) {
            $chat['dirty'] = true;
            return;
        }

        $pane = $inputRow['panes'][0];
        $layout = $chat['layout'] ?? $this->buildLayout((int)($state['cols'] ?? 80), (int)($state['rows'] ?? 24));
        $inputLines = $this->buildInputLines($chat, $state, $layout);
        $contentWidth = max(4, (int)$pane['width'] - 4);
        $contentHeight = max(1, (int)$pane['height'] - 4);
        $chars = $this->server->getTerminalLineDrawingChars();
        $borderColor = (string)($pane['pane']['border_color'] ?? TelnetUtils::ANSI_BLUE);

        $buffer = "\033[?25l";
        for ($i = 0; $i < $contentHeight; $i++) {
            $line = $inputLines[$i] ?? '';
            $rendered = $this->renderSplitPaneContentLine($line, $contentWidth, $chars, $borderColor);
            $row = (int)$pane['y'] + 3 + $i;
            $col = (int)$pane['x'];
            $buffer .= "\033[{$row};{$col}H" . $rendered;
        }

        $cursor = $this->buildInputCursorSequence($chat, $layoutInfo);
        if ($cursor !== null) {
            $buffer .= $cursor;
        }
        $buffer .= "\033[?25h";
        $this->server->safeWrite($conn, $buffer);
    }

    private function buildInputCursorSequence(array $chat, array $layoutInfo): ?string
    {
        if (empty($layoutInfo['rows'])) {
            return null;
        }

        $inputRow = $layoutInfo['rows'][count($layoutInfo['rows']) - 1] ?? null;
        if (!$inputRow || empty($inputRow['panes'][0])) {
            return null;
        }

        $pane = $inputRow['panes'][0];
        $contentRow = (int)$pane['y'] + 5;
        $contentCol = (int)$pane['x'] + 3;
        $width = max(10, (int)$pane['width'] - 6);
        [, $cursorPos] = $this->getVisibleInputWindow((string)$chat['input'], (int)$chat['input_cursor'], $width);
        $col = $contentCol + $cursorPos;
        $row = $contentRow;

        return "\033[{$row};{$col}H";
    }

    private function renderSplitPaneContentLine(string $line, int $contentWidth, array $chars, string $borderColor): string
    {
        $line = $this->server->encodeForTerminal($line);
        $visibleWidth = $this->ansiLength($line);
        if ($visibleWidth > $contentWidth) {
            $line = $this->truncateAnsiLine($line, $contentWidth);
            $visibleWidth = $this->ansiLength($line);
        }
        $padding = str_repeat(' ', max(0, $contentWidth - $visibleWidth));

        return $this->server->colorizeForTerminal($this->server->encodeForTerminal($chars['v']), $borderColor)
            . ' '
            . $line
            . $padding
            . ' '
            . $this->server->colorizeForTerminal($this->server->encodeForTerminal($chars['v']), $borderColor);
    }

    private function markInputDirty(array &$chat): void
    {
        if (!empty($chat['dirty'])) {
            return;
        }
        $chat['input_dirty'] = true;
    }

    private function ansiLength(string $text): int
    {
        return mb_strwidth($this->stripAnsi($text), 'UTF-8');
    }

    private function stripAnsi(string $text): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $text) ?? $text;
    }

    private function truncateAnsiLine(string $line, int $width): string
    {
        $result = '';
        $visible = 0;
        if (!preg_match_all('/\033\[[0-9;]*m|./us', $line, $matches)) {
            return '';
        }

        foreach ($matches[0] as $token) {
            if (str_starts_with($token, "\033[")) {
                $result .= $token;
                continue;
            }

            $charWidth = max(1, mb_strwidth($token, 'UTF-8'));
            if ($visible + $charWidth > $width) {
                break;
            }
            $result .= $token;
            $visible += $charWidth;
        }

        return $result . "\033[0m";
    }

    private function buildNavItems(array $chat): array
    {
        $items = [];
        foreach ($chat['rooms'] as $room) {
            $items[] = [
                'key' => $this->targetKey('room', (int)$room['id']),
                'target_type' => 'room',
                'target_id' => (int)$room['id'],
                'label' => (string)$room['name'],
            ];
        }

        $dmUsers = $chat['dm_users'];
        uasort($dmUsers, function (array $a, array $b) use ($chat): int {
            $aUnread = (int)($chat['dm_unread'][$a['user_id']] ?? 0);
            $bUnread = (int)($chat['dm_unread'][$b['user_id']] ?? 0);
            if ($aUnread !== $bUnread) {
                return $bUnread <=> $aUnread;
            }
            return strcasecmp((string)$a['username'], (string)$b['username']);
        });

        foreach ($dmUsers as $user) {
            $items[] = [
                'key' => $this->targetKey('dm', (int)$user['user_id']),
                'target_type' => 'dm',
                'target_id' => (int)$user['user_id'],
                'label' => (string)$user['username'],
            ];
        }

        if (($chat['nav_selected_key'] ?? null) === null && $items !== []) {
            $chat['nav_selected_key'] = $items[0]['key'];
        }

        return $items;
    }

    private function findNavIndex(array $items, string $key): int
    {
        foreach ($items as $index => $item) {
            if ($item['key'] === $key) {
                return $index;
            }
        }
        return -1;
    }

    private function getFocusOrder(array $chat): array
    {
        $layout = $chat['layout'];
        if (($layout['mode'] ?? '') === 'wide') {
            return [
                self::FOCUS_NAV,
                self::FOCUS_MESSAGES,
                // self::FOCUS_USERS,
                self::FOCUS_INPUT,
            ];
        }
        return [self::FOCUS_NAV, self::FOCUS_MESSAGES, self::FOCUS_INPUT];
    }

    private function maybeBeepForMessage($conn, array $chat, array $message): void
    {
        if (($message['type'] ?? '') === 'dm' || $this->messageMentionsUser($message, (string)$chat['username'])) {
            $this->server->safeWrite($conn, "\x07");
            return;
        }

        $active = $chat['active_target'];
        if ($active && !($active['type'] === ($message['type'] ?? '') && (int)$active['id'] === (int)($message['room_id'] ?? $message['from_user_id'] ?? 0))) {
            $this->server->safeWrite($conn, "\x07");
        }
    }

    private function showHelp($conn, array &$state, array $chat): void
    {
        $lines = [
            $this->t('ui.terminalserver.chat.help.line1', 'TAB / Shift+TAB: change focus between panes', $state),
            $this->t('ui.terminalserver.chat.help.line2', 'Navigation pane: Up/Down selects rooms and DMs, Enter opens target', $state),
            $this->t('ui.terminalserver.chat.help.line3', 'Messages pane: PgUp/PgDn scroll, older history is fetched automatically', $state),
            $this->t('ui.terminalserver.chat.help.line4', 'Online users pane: Enter opens DM', $state),
            $this->t('ui.terminalserver.chat.help.line5', 'Compose pane: Enter sends, Ctrl+E opens multiline editor', $state),
            $this->t('ui.terminalserver.chat.help.line6', 'Ctrl+C exits local chat, R refreshes lists', $state),
        ];
        if ($chat['is_admin']) {
            $lines[] = $this->t('ui.terminalserver.chat.help.line7', 'Admins: in a room, focus Online Users and press K or B to moderate.', $state);
        }

        $renderer = new TerminalBoxRenderer($this->server);
        $renderer->showPagedBox(
            $conn,
            $state,
            $this->t('ui.terminalserver.chat.help.title', 'Local Chat Help', $state),
            $lines,
            $this->t('ui.terminalserver.server.press_continue', 'Press any key to continue...', $state),
            4
        );
    }

    private function showInfo($conn, array &$state, string $message): void
    {
        $renderer = new TerminalBoxRenderer($this->server);
        $renderer->showPagedBox(
            $conn,
            $state,
            $this->t('ui.terminalserver.chat.title', 'Local Chat', $state),
            [$message],
            $this->t('ui.terminalserver.server.press_continue', 'Press any key to continue...', $state),
            4
        );
    }

    private function getActiveTitle(array $chat, array $state): string
    {
        if (empty($chat['active_target'])) {
            return $this->t('ui.terminalserver.chat.no_target_title', 'No target selected', $state);
        }

        $target = $chat['active_target'];
        if ($target['type'] === 'room') {
            return $this->t('ui.terminalserver.chat.room_title', 'Room: {name}', $state, ['name' => (string)$target['label']]);
        }

        return $this->t('ui.terminalserver.chat.dm_title', 'DM: {name}', $state, ['name' => (string)$target['label']]);
    }

    private function paneTitle(array $chat, string $focus, string $base): string
    {
        return $chat['focus'] === $focus ? '[' . $base . ']' : $base;
    }

    private function targetKey(string $type, int $id): string
    {
        return $type . ':' . $id;
    }

    private function ensureDmUser(array &$chat, int $userId, string $username): void
    {
        if ($userId <= 0 || $username === '') {
            return;
        }
        $chat['dm_users'][$userId] = [
            'user_id' => $userId,
            'username' => $username,
        ];
        $chat['dm_unread'][$userId] = (int)($chat['dm_unread'][$userId] ?? 0);
    }

    private function normalizeMessage(array &$message): void
    {
        $message['id'] = (int)($message['id'] ?? 0);
        $message['room_id'] = isset($message['room_id']) ? (int)$message['room_id'] : null;
        $message['from_user_id'] = isset($message['from_user_id']) ? (int)$message['from_user_id'] : null;
        $message['to_user_id'] = isset($message['to_user_id']) ? (int)$message['to_user_id'] : null;
        $message['from_username'] = (string)($message['from_username'] ?? 'System');
        $message['body'] = (string)($message['body'] ?? '');
        $message['type'] = (string)($message['type'] ?? ($message['room_id'] ? 'room' : 'dm'));
    }

    private function messageMentionsUser(array $message, string $username): bool
    {
        if ($username === '') {
            return false;
        }
        return stripos((string)($message['body'] ?? ''), '@' . $username) !== false;
    }

    /**
     * @return string[]
     */
    private function wrapPlainLine(string $line, int $width): array
    {
        if ($line === '') {
            return [''];
        }

        $wrapped = wordwrap($line, max(1, $width), "\n", true);
        return explode("\n", $wrapped);
    }

    private function setStatus(array &$chat, string $message, string $color = TelnetUtils::ANSI_DIM, int $seconds = 5): void
    {
        $chat['status'] = $message;
        $chat['status_color'] = $color;
        $chat['status_until'] = time() + $seconds;
        $chat['dirty'] = true;
    }

    private function apiRequest(string $method, string $path, ?array $payload, string $session, array $state): array
    {
        return TelnetUtils::apiRequest(
            $this->apiBase,
            $method,
            $path,
            $payload,
            $session,
            3,
            $state['csrf_token'] ?? null
        );
    }

    private function t(string $key, string $fallback, array $state, array $params = []): string
    {
        return $this->server->t($key, $fallback, $params, $state['locale'] ?? 'en');
    }
}
