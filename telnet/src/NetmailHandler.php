<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\TelnetServer\TelnetServer;

/**
 * NetmailHandler - Handles netmail (private messaging) functionality for telnet daemon
 *
 * Provides methods for displaying, reading, composing, and replying to netmail messages.
 * This handler encapsulates all netmail-specific functionality that was previously in
 * standalone functions within telnet_daemon.php.
 */
class NetmailHandler
{
    private const ALLOWED_SORTS = ['date_desc', 'date_asc', 'subject', 'author'];

    /** @var TelnetServer The telnet server instance */
    private BbsSession $server;

    /** @var string Base URL for API requests */
    private string $apiBase;

    /**
     * Create a new NetmailHandler instance
     *
     * @param BbsSession $server The telnet server instance for I/O operations
     * @param string $apiBase Base URL for API requests
     */
    public function __construct(BbsSession $server, string $apiBase)
    {
        $this->server = $server;
        $this->apiBase = $apiBase;
    }

    /**
     * Display netmail list with pagination and message reading
     *
     * Shows a list of netmail messages with options to:
     * - Navigate pages (n/p)
     * - Read messages by number
     * - Compose new messages (c)
     * - Reply to messages (r)
     * - Quit (q)
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array (cols, rows, etc.)
     * @param string $session Session token for authentication
     * @return void
     */
    public function show($conn, array &$state, string $session): void
    {
        $this->server->logAction($state['username'] ?? 'unknown', "Netmail: read message list");
        $shell = TerminalShellFactory::create($this->server, $state);
        $savedState = $this->loadSavedListState($session);
        $page          = $savedState['page'];
        $perPage       = MailUtils::getMessagesPerPage($state);
        $selectedIndex = 0;
        $selectedMessageId = $savedState['selected_message_id'];
        $folder        = $savedState['folder'];
        $sort          = $savedState['sort'];

        $locale             = $state['locale'] ?? 'en';
        $selectedMessageIds = [];

        while (true) {
            [$messages, $totalPages] = $this->fetchMessagesPage($session, $page, $perPage, $folder, $sort);

            if (!$messages) {
                if ($page > 1 && $totalPages > 0) {
                    $page = min($page, $totalPages);
                    $selectedIndex = 0;
                    $selectedMessageId = null;
                    continue;
                }
                $noMsgKey = $folder === 'sent'
                    ? 'ui.terminalserver.netmail.no_sent_messages'
                    : 'ui.terminalserver.netmail.no_messages';
                $noMsgFallback = $folder === 'sent' ? 'No sent netmail messages.' : 'No netmail messages.';

                // In sent folder: pressing S returns to inbox; in inbox: quit.
                if ($folder === 'sent') {
                    TelnetUtils::writeLine($conn, $this->server->t($noMsgKey, $noMsgFallback, [], $locale));
                    TelnetUtils::writeLine($conn, '');
                    TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                        $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                        TelnetUtils::ANSI_DIM
                    ));
                    $this->server->readKeyWithIdleCheck($conn, $state);
                    $folder = 'inbox';
                    $page   = 1;
                    $selectedIndex = 0;
                    $this->saveListState($session, $page, null, $folder, $sort, $state['csrf_token'] ?? null);
                    continue;
                }

                TelnetUtils::writeLine($conn, $this->server->t($noMsgKey, $noMsgFallback, [], $locale));
                return;
            }

            if ($selectedMessageId !== null) {
                $restoredIndex = $this->findMessageIndexById($messages, $selectedMessageId);
                if ($restoredIndex !== null) {
                    $selectedIndex = $restoredIndex;
                }
                $selectedMessageId = null;
            }

            if ($selectedIndex < 0 || $selectedIndex >= count($messages)) {
                $selectedIndex = 0;
            }

            if ($folder === 'sent') {
                $titleKey      = 'ui.terminalserver.netmail.sent_header';
                $titleFallback = 'Netmail Sent (page {page}/{total}):';
                $toggleLabel   = 'Inbox';
                // Show recipient instead of sender in sent folder list view.
                $displayMessages = array_map(static function(array $msg): array {
                    $msg['from_name'] = $msg['to_name'] ?? $msg['from_name'];
                    return $msg;
                }, $messages);
                $extraKeys = ['o' => 'order', 's' => 'toggle_folder'];
                $extraStatusSegments = [
                    ['text' => 'O',        'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Sort  ',  'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => '  S',       'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' ' . $toggleLabel, 'color' => TelnetUtils::ANSI_BLUE],
                ];
                $multiSelectOptions = [];
                $helpItems = [];
            } else {
                $titleKey      = 'ui.terminalserver.netmail.inbox_header';
                $titleFallback = 'Netmail Inbox (page {page}/{total}):';
                $toggleLabel   = 'Sent';
                $displayMessages = $messages;
                $extraKeys = ['m' => 'mark_selected_read', 'o' => 'order', 's' => 'toggle_folder'];
                $extraStatusSegments = [
                    ['text' => 'O',       'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Sort  ', 'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => '  S',      'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' ' . $toggleLabel, 'color' => TelnetUtils::ANSI_BLUE],
                ];
                $multiSelectOptions = [
                    'multiSelect'        => true,
                    'toggleKey'          => ' ',
                    'selectedMessageIds' => $selectedMessageIds,
                ];
                $helpItems = [
                    ['key' => 'Space', 'label' => $this->server->t('ui.terminalserver.list.help_toggle_selection', 'Toggle selection', [], $locale)],
                    ['key' => 'M',     'label' => $this->server->t('ui.terminalserver.netmail.help_mark_selected', 'Mark selected messages as read', [], $locale)],
                ];
            }

            $title  = TelnetUtils::colorize(
                $this->server->t($titleKey, $titleFallback, ['page' => $page, 'total' => $totalPages], $locale),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            );
            $shell = TerminalShellFactory::create($this->server, $state);
            $result = $shell->showMessageList($conn, $state, $title, $displayMessages, $page, $totalPages, $selectedIndex, $extraKeys, $extraStatusSegments, $multiSelectOptions, $helpItems);
            $selectedIndex = $result['selectedIndex'];
            $currentSelectedId = isset($messages[$selectedIndex]['id']) ? (int)$messages[$selectedIndex]['id'] : null;
            $this->saveListState($session, $page, $currentSelectedId, $folder, $sort, $state['csrf_token'] ?? null);

            switch ($result['action']) {
                case 'disconnect':
                    return;
                case 'quit':
                    return;
                case 'prev':
                    $page--;
                    $selectedIndex = 0;
                    break;
                case 'next':
                    $page++;
                    $selectedIndex = 0;
                    break;
                case 'compose':
                    $this->compose($conn, $state, $session, null);
                    break;
                case 'order':
                    $newSort = $this->promptForSort($conn, $state, $sort, $title, $displayMessages, $selectedIndex, $folder);
                    if ($newSort !== $sort) {
                        $sort = $newSort;
                        $page = 1;
                        $selectedIndex = 0;
                        $selectedMessageId = null;
                        $this->saveListState($session, $page, null, $folder, $sort, $state['csrf_token'] ?? null);
                    }
                    break;
                case 'toggle_folder':
                    $folder             = $folder === 'inbox' ? 'sent' : 'inbox';
                    $page               = 1;
                    $selectedIndex      = 0;
                    $selectedMessageIds = [];
                    $this->saveListState($session, $page, null, $folder, $sort, $state['csrf_token'] ?? null);
                    break;
                case 'toggle_select':
                    $messageId = isset($messages[$result['index']]['id']) ? (int)$messages[$result['index']]['id'] : 0;
                    if ($messageId > 0) {
                        if (in_array($messageId, $selectedMessageIds, true)) {
                            $selectedMessageIds = array_values(array_filter(
                                $selectedMessageIds,
                                static fn(int $id): bool => $id !== $messageId
                            ));
                        } else {
                            $selectedMessageIds[] = $messageId;
                        }
                    }
                    break;
                case 'mark_selected_read':
                    $selectedCount = count($selectedMessageIds);
                    if ($selectedCount === 0) {
                        $shell->showAlert(
                            $conn,
                            $state,
                            $this->server->t('ui.terminalserver.netmail.mark_selected_title', 'Mark Selected Read', [], $locale),
                            $this->server->t('ui.terminalserver.netmail.mark_selected_none', 'No messages are selected.', [], $locale),
                            'error'
                        );
                        break;
                    }
                    $choice = $shell->showConfirmDialog(
                        $conn,
                        $state,
                        $this->server->t('ui.terminalserver.netmail.mark_selected_title', 'Mark Selected Read', [], $locale),
                        $this->server->t('ui.terminalserver.netmail.mark_selected_prompt', 'Mark {count} selected message(s) as read?', ['count' => $selectedCount], $locale),
                        [
                            'y' => $this->server->t('ui.terminalserver.server.confirm_yes', 'Confirm', [], $locale),
                            'n' => $this->server->t('ui.terminalserver.server.confirm_no', 'Cancel', [], $locale),
                        ],
                        'n'
                    );
                    if ($choice === 'y') {
                        $markResult = $this->markSelectedMessagesRead($session, $selectedMessageIds, $locale, $state['csrf_token'] ?? null);
                        $shell->showAlert(
                            $conn,
                            $state,
                            $this->server->t('ui.terminalserver.netmail.mark_selected_title', 'Mark Selected Read', [], $locale),
                            $markResult['message'],
                            $markResult['success'] ? 'info' : 'error'
                        );
                        if ($markResult['success']) {
                            $selectedMessageIds = [];
                            $this->server->logAction($state['username'] ?? 'unknown', "Netmail: marked {$selectedCount} selected messages read");
                        }
                    }
                    break;
                case 'read':
                    [$page, $selectedIndex] = $this->displayMessage($conn, $state, $session, $page, $perPage, $totalPages, $result['index'], $folder, $sort);
                    break;
            }
        }
    }

    /**
     * Compose new netmail or reply to existing message
     *
     * Prompts user for recipient name, address, subject, and message body.
     * If replying, pre-fills recipient info and quotes original message.
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array (cols, rows, etc.)
     * @param string $session Session token for authentication
     * @param array|null $reply Reply data from original message (null for new message)
     * @return void
     */
    public function compose($conn, array &$state, string $session, ?array $reply = null): void
    {
        $hasReplyContext = $reply !== null;
        $reply = $reply ?? [];
        $replyId = (int)($reply['id'] ?? 0);
        $composeMode = $reply['compose_mode'] ?? ($hasReplyContext ? 'reply' : 'new');
        $isReply = $composeMode === 'reply';
        $isForward = $composeMode === 'forward';
        $action = match ($composeMode) {
            'reply' => "Netmail: composing reply to msg #{$replyId}",
            'forward' => "Netmail: forwarding msg #{$replyId}",
            default => "Netmail: composing new message",
        };
        $this->server->logAction($state['username'] ?? 'unknown', $action);
        $currentDraftId = 0;
        $draftToken = bin2hex(random_bytes(8));

        if (($isReply || $isForward) && !empty($reply['id'])) {
            $detail = TelnetUtils::apiRequest(
                $this->apiBase,
                'GET',
                '/api/messages/netmail/' . $reply['id'],
                null,
                $session
            );
            if (($detail['status'] ?? 0) === 200 && !empty($detail['data']['message_text'])) {
                $reply['message_text'] = $detail['data']['message_text'];
            }
        }

        if ($isForward) {
            $toNameDefault = '';
            $toAddressDefault = '';
            $subjectDefault = 'Fwd: ' . MailUtils::normalizeSubject((string)($reply['subject'] ?? ''));
        } else {
            $toNameDefault = $reply['replyto_name'] ?? $reply['from_name'] ?? '';
            $toAddressDefault = $reply['replyto_address'] ?? $reply['from_address'] ?? '';
            $subjectDefault = $isReply ? 'Re: ' . MailUtils::normalizeSubject((string)($reply['subject'] ?? '')) : '';
        }
        $selectedTagline = '';
        $initialText = '';

        if ($isReply) {
            $originalBody = $reply['message_text'] ?? '';
            $originalAuthor = $reply['from_name'] ?? 'Unknown';
            if ($originalBody !== '') {
                $initialText = MailUtils::quoteMessage($originalBody, $originalAuthor, $state);
            }
        } elseif ($isForward) {
            $originalBody       = $reply['message_text'] ?? '';
            $originalAuthor     = trim((string)($reply['from_name'] ?? 'Unknown'));
            $forwardedFromArea  = (string)($reply['_forwarded_from_area'] ?? '');
            $forwardHeader = $forwardedFromArea !== ''
                ? '--- Forwarded from ' . $forwardedFromArea . ' by ' . ($originalAuthor !== '' ? $originalAuthor : 'Unknown') . ' ---'
                : '--- Forwarded message from ' . ($originalAuthor !== '' ? $originalAuthor : 'Unknown') . ' ---';
            $initialText = $forwardHeader;
            if ($originalBody !== '') {
                $initialText .= "\n\n" . MailUtils::quoteMessage($originalBody, $originalAuthor, $state);
            }
        }

        $existingDrafts = MailUtils::getDrafts($this->apiBase, $session, 'netmail');
        if (!$isReply && !$isForward && !empty($existingDrafts)) {
            $shell = TerminalShellFactory::create($this->server, $state);
            while (true) {
                $choice = $shell->showConfirmDialog(
                    $conn,
                    $state,
                    $this->server->t('ui.terminalserver.compose.drafts_prompt_title', 'Drafts Found', [], $state['locale']),
                    $this->server->t('ui.terminalserver.compose.drafts_prompt_message', 'Resume a saved draft or start a new message?', [], $state['locale']),
                    [
                        'r' => $this->server->t('ui.terminalserver.compose.resume_draft', 'Resume Draft', [], $state['locale']),
                        'n' => $this->server->t('ui.terminalserver.compose.new_message', 'New Message', [], $state['locale']),
                        'c' => $this->server->t('ui.terminalserver.compose.cancel_compose', 'Cancel', [], $state['locale']),
                    ],
                    'n'
                );

                if ($choice === 'c') {
                    return;
                }
                if ($choice === 'n') {
                    break;
                }

                $shell = TerminalShellFactory::create($this->server, $state);
                $picked = MailUtils::pickDraft(
                    $conn,
                    $state,
                    $this->server,
                    $shell,
                    $this->apiBase,
                    $session,
                    'netmail',
                    $state['csrf_token'] ?? null
                );
                if ($picked === null) {
                    return;
                }
                if (($picked['action'] ?? '') !== 'resume' || !is_array($picked['draft'] ?? null)) {
                    continue;
                }

                $draft = $picked['draft'];
                $currentDraftId = (int)($draft['id'] ?? 0);
                $toNameDefault = (string)($draft['to_name'] ?? $toNameDefault);
                $toAddressDefault = (string)($draft['to_address'] ?? $toAddressDefault);
                $subjectDefault = (string)($draft['subject'] ?? $subjectDefault);
                $initialText = (string)($draft['message_text'] ?? $initialText);
                if (is_array($draft['meta'] ?? null)) {
                    $selectedTagline = (string)($draft['meta']['tagline'] ?? $selectedTagline);
                    $draftToken = (string)($draft['meta']['terminal_draft_token'] ?? $draftToken);
                }
                if (!empty($draft['reply_to_id'])) {
                    $reply['id'] = (int)$draft['reply_to_id'];
                }
                break;
            }
        }

        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        $shell = TerminalShellFactory::create($this->server, $state);

        // Helper: redraw the compose header after returning from the picker
        $redrawHeader = function() use ($conn, $state): void {
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        };

        // --- To Name ---
        $toNameLabel = $this->server->t('ui.terminalserver.compose.to_name', 'To Name: ', [], $state['locale']);
        $toNameBase  = TelnetUtils::colorize($toNameLabel, TelnetUtils::ANSI_CYAN);

        $buildToNamePrompt = function(string $default) use ($toNameBase): string {
            return $toNameBase . ' ';
        };

        $toName = null;
        while ($toName === null) {
            $input = $shell->promptText(
                $conn,
                $state,
                $this->server->t('ui.terminalserver.netmail.compose_title', 'Compose Netmail', [], $state['locale']),
                $buildToNamePrompt($toNameDefault),
                ['prefill' => $toNameDefault, 'footer_hint' => '?=Address Book', 'inline_prompt' => true]
            );
            if ($input === null) {
                return;
            }
            if (trim($input) === '?') {
                $picked = $shell->showAddressPicker($conn, $state, $this->apiBase, $session);
                $redrawHeader();
                if ($picked !== null) {
                    $toNameDefault    = $picked['name'];
                    $toAddressDefault = $picked['address'];
                }
                continue; // re-show To Name prompt with the picked default
            }
            $resolved = trim($input) !== '' ? trim($input) : $toNameDefault;
            if ($resolved === '') {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.compose.no_recipient', 'Recipient name required. Message cancelled.', [], $state['locale']),
                    TelnetUtils::ANSI_YELLOW
                ));
                return;
            }
            $toName = $resolved;
        }

        // --- To Address ---
        $toAddressLabel = $this->server->t('ui.terminalserver.compose.to_address', 'To Address: ', [], $state['locale']);
        $toAddressBase  = TelnetUtils::colorize($toAddressLabel, TelnetUtils::ANSI_CYAN);

        $buildToAddressPrompt = function(string $default) use ($toAddressBase): string {
            return $toAddressBase . ' ';
        };

        $toAddress = null;
        while ($toAddress === null) {
            $input = $shell->promptText(
                $conn,
                $state,
                $this->server->t('ui.terminalserver.netmail.compose_title', 'Compose Netmail', [], $state['locale']),
                $buildToAddressPrompt($toAddressDefault),
                ['prefill' => $toAddressDefault, 'footer_hint' => '?=Address Book', 'inline_prompt' => true]
            );
            if ($input === null) {
                return;
            }
            if (trim($input) === '?') {
                $picked = $shell->showAddressPicker($conn, $state, $this->apiBase, $session);
                $redrawHeader();
                if ($picked !== null) {
                    $toAddressDefault = $picked['address'];
                }
                continue; // re-show To Address prompt with the picked default
            }
            $toAddress = trim($input) !== '' ? trim($input) : $toAddressDefault;
        }

        $subjectPrompt = TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.subject', 'Subject: ', [], $state['locale']), TelnetUtils::ANSI_CYAN);
        $subject = $shell->promptText(
            $conn,
            $state,
            $this->server->t('ui.terminalserver.netmail.compose_title', 'Compose Netmail', [], $state['locale']),
            $subjectPrompt,
            ['prefill' => $subjectDefault, 'inline_prompt' => true]
        );
        if ($subject === null) {
            return;
        }
        if ($subject === '' && $subjectDefault !== '') {
            $subject = $subjectDefault;
        }

        // TelnetUtils::writeLine($conn, '');
        // TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.enter_message', 'Enter your message below:', [], $state['locale']), TelnetUtils::ANSI_GREEN));

        $cols = $state['cols'] ?? 80;

        $taglines = MailUtils::getTaglines($this->apiBase, $session);
        $defaultTagline = MailUtils::getUserDefaultTagline($this->apiBase, $session);
        if (!empty($taglines)) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.select_tagline', 'Select a tagline:', [], $state['locale']), TelnetUtils::ANSI_CYAN));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.no_tagline', ' 0) None', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
            foreach ($taglines as $idx => $tagline) {
                TelnetUtils::writeLine($conn, sprintf(' %d) %s', $idx + 1, $tagline));
            }
            $defaultIndex = 0;
            if ($selectedTagline !== '') {
                foreach ($taglines as $idx => $tagline) {
                    if (trim($tagline) === $selectedTagline) {
                        $defaultIndex = $idx + 1;
                        break;
                    }
                }
            } elseif ($defaultTagline !== '') {
                foreach ($taglines as $idx => $tagline) {
                    if (trim($tagline) === $defaultTagline) {
                        $defaultIndex = $idx + 1;
                        break;
                    }
                }
            }
            $taglineChoices = ['0) None'];
            foreach ($taglines as $idx => $tagline) {
                $taglineChoices[] = sprintf('%d) %s', $idx + 1, $tagline);
            }
            $choiceIndex = $shell->chooseFromList(
                $conn,
                $state,
                $this->server->t('ui.terminalserver.compose.tagline_title', 'Tagline', [], $state['locale']),
                $taglineChoices,
                ['selected_index' => $defaultIndex]
            );
            if ($choiceIndex === null) {
                return;
            }
            if ($choiceIndex > 0) {
                $selectedTagline = $taglines[$choiceIndex - 1] ?? '';
            }
        }

        if ($currentDraftId === 0) {
            $signature = MailUtils::getUserSignature($this->apiBase, $session);
            $initialText = MailUtils::appendSignatureToCompose($initialText, $signature);
        }

        $shell = TerminalShellFactory::create($this->server, $state);

        $saveDraftHandler = function(string $draftText) use (
            $session,
            $state,
            $toName,
            $toAddress,
            $subject,
            $selectedTagline,
            &$currentDraftId,
            $draftToken,
            $reply
        ): array {
            $payload = [
                'type' => 'netmail',
                'draft_id' => $currentDraftId > 0 ? $currentDraftId : null,
                'to_name' => $toName,
                'to_address' => $toAddress,
                'subject' => $subject,
                'message_text' => $draftText,
                'reply_to_id' => !empty($reply['id']) ? $reply['id'] : null,
                'meta' => [
                    'tagline' => $selectedTagline !== '' ? $selectedTagline : null,
                    'terminal_draft_token' => $draftToken,
                ],
            ];
            $result = MailUtils::saveDraft($this->apiBase, $session, $payload, $state['csrf_token'] ?? null);
            if (!empty($result['success']) && !empty($result['draft_id'])) {
                $currentDraftId = (int)$result['draft_id'];
            }
            return $result;
        };

        $messageText = $this->server->readMultiline($conn, $state, $cols, $initialText, [
            'save_handler' => $saveDraftHandler,
        ]);
        if ($messageText === '') {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.compose.message_cancelled', 'Message cancelled (empty).', [], $state['locale']), TelnetUtils::ANSI_YELLOW));
            return;
        }

        $payload = [
            'type' => 'netmail',
            'to_name' => $toName,
            'to_address' => $toAddress,
            'subject' => $subject,
            'message_text' => $messageText
        ];
        if (!empty($reply['id'])) {
            $payload['reply_to_id'] = $reply['id'];
        }
        if ($selectedTagline !== '') {
            $payload['tagline'] = $selectedTagline;
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.netmail.sending', 'Sending netmail...', [], $state['locale']), TelnetUtils::ANSI_CYAN));
        $result = MailUtils::sendMessage($this->apiBase, $session, $payload, $state['csrf_token'] ?? null);
        if ($result['success']) {
            if ($currentDraftId > 0) {
                MailUtils::deleteDraft($this->apiBase, $session, $currentDraftId, $state['csrf_token'] ?? null);
            }
            $this->server->logAction($state['username'] ?? 'unknown', "Netmail: sent message to {$toName} subject=\"{$subject}\"");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.netmail.send_success', '✓ Netmail sent successfully!', [], $state['locale']), TelnetUtils::ANSI_GREEN . TelnetUtils::ANSI_BOLD));
        } else {
            $this->server->logAction($state['username'] ?? 'unknown', "Netmail: failed to send to {$toName}: " . ($result['error'] ?? 'unknown'));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize($this->server->t('ui.terminalserver.netmail.send_failed', '✗ Failed to send netmail: {error}', ['error' => $result['error'] ?? 'Unknown error'], $state['locale']), TelnetUtils::ANSI_RED));
        }
        TelnetUtils::writeLine($conn, '');
    }

    /**
     * Display a single netmail message with reply option
     *
     * Shows message subject, sender, and body with pagination.
     * Offers option to reply or return to message list.
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array (cols, rows, etc.)
     * @param string $session Session token for authentication
     * @param array $msg Message summary data
     * @param int $id Message ID for fetching full details
     * @return void
     */
    private function displayMessage($conn, array &$state, string $session, int $page, int $perPage, int $totalPages, int $index, string $folder = 'inbox', string $sort = 'date_desc'): array
    {
        $shell = TerminalShellFactory::create($this->server, $state);
        while (true) {
            [$messages, $totalPages] = $this->fetchMessagesPage($session, $page, $perPage, $folder, $sort);
            $msg = $messages[$index] ?? null;
            if (!$msg) {
                return [$page, 0];
            }
            $id = $msg['id'] ?? null;
            if (!$id) {
                return [$page, $index];
            }

            $this->server->logAction($state['username'] ?? 'unknown', "Netmail: read message #{$id}");
            $detail       = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/messages/netmail/' . $id, null, $session);
            $body         = $detail['data']['message_text'] ?? '';
            $markupFormat = $detail['data']['markup_format'] ?? null;
            $attachments  = $detail['data']['attachments'] ?? [];
            $rawKludges   = ($detail['data']['kludge_lines'] ?? '') . "\n" . ($detail['data']['bottom_kludges'] ?? '');
            $kludgeLines  = TerminalMarkupRenderer::extractKludgeLines($rawKludges);
            $kludgeLines  = array_map(fn(string $line): string => $this->server->encodeForTerminal($line), $kludgeLines);
            $imageRefs    = TerminalMarkupRenderer::extractImageRefs((string)($markupFormat ?? ''), $body);
            if (!is_array($attachments)) {
                $attachments = [];
            }

            $hasAttachments = !empty($attachments);
            $isSentFolder   = $folder === 'sent';
            $isSaved        = (bool)($detail['data']['is_saved'] ?? false);

            // Closure that rebuilds all layout-dependent view components from current $state.
            // Called once on open and again whenever the terminal is resized.
            $buildView = function(array $s) use ($msg, $body, $markupFormat, $hasAttachments, $imageRefs, $isSentFolder, &$isSaved): array {
                $cols    = $s['cols'] ?? 80;
                $width   = max(10, $cols - 2);
                $charset = $this->server->getTerminalCharset();

                if ($isSentFolder) {
                    $nameLabel   = 'To:   ';
                    $nameValue   = $msg['to_name'] ?? 'Unknown';
                    $nameAddress = $msg['to_address'] ?? '';
                } else {
                    $nameLabel   = 'From: ';
                    $nameValue   = $msg['from_name'] ?? 'Unknown';
                    $nameAddress = $msg['from_address'] ?? '';
                }
                $nameLine = $nameAddress ? "{$nameValue} <{$nameAddress}>" : $nameValue;

                $segments = [
                    ['text' => 'U/D',          'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Scroll  ',    'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => 'L/R',          'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Prev/Next  ', 'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => 'R',            'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Reply  ',     'color' => TelnetUtils::ANSI_BLUE],
                ];
                $segments[] = ['text' => 'F', 'color' => TelnetUtils::ANSI_RED];
                $segments[] = ['text' => ' ' . $this->server->t('ui.terminalserver.netmail.status_forward', 'Fwd', [], $s['locale'] ?? 'en') . '  ', 'color' => TelnetUtils::ANSI_BLUE];
                $segments[] = ['text' => 'Ctrl-K', 'color' => TelnetUtils::ANSI_RED];
                $segments[] = ['text' => ' Help  ', 'color' => TelnetUtils::ANSI_BLUE];
                $segments[] = ['text' => 'Q', 'color' => TelnetUtils::ANSI_RED];
                $segments[] = ['text' => ' Quit', 'color' => TelnetUtils::ANSI_BLUE];

                $wrappedLines = $markupFormat !== null
                    ? TerminalMarkupRenderer::render($markupFormat, $body, $width)
                    : TelnetUtils::wrapTextLines($body, $width);
                $wrappedLines = array_map(fn(string $line): string => $this->server->encodeForTerminal($line), $wrappedLines);

                return [
                    'headerLines'  => TelnetUtils::buildMessageHeaderBox($width, [
                        ['label' => $nameLabel, 'value' => $nameLine,                                                     'style' => 'normal'],
                        ['label' => 'Date: ',   'value' => TelnetUtils::formatUserDate($msg['date_written'] ?? '', $s),   'style' => 'dim'],
                        ['label' => 'Subj: ',   'value' => $msg['subject'] ?? 'Message',                                 'style' => 'bold'],
                    ], $charset),
                    'wrappedLines' => $wrappedLines,
                    'statusLine'   => TelnetUtils::buildStatusBar($segments, $width),
                ];
            };

            $apiBase = $this->apiBase;
            $server  = $this->server;
            $imageFn = !empty($imageRefs)
                ? static function(int $idx) use ($conn, &$state, $server, $imageRefs, $apiBase): void {
                    TelnetUtils::showSixelImageViewer($conn, $state, $server, $imageRefs[$idx], count($imageRefs), $apiBase);
                }
                : null;

            $view   = $buildView($state);
            $locale = $state['locale'] ?? 'en';
            $helpItems = [
                ['key' => 'PgUp / PgDn', 'label' => $this->server->t('ui.terminalserver.message.help_page',      'Scroll one page',             [], $locale)],
                ['key' => 'H',           'label' => $this->server->t('ui.terminalserver.message.help_headers',   'View message headers',         [], $locale)],
                ['key' => 'X',           'label' => $this->server->t('ui.terminalserver.netmail.help_delete',    'Delete message',               [], $locale)],
                ['key' => 'B',           'label' => $this->server->t('ui.terminalserver.netmail.help_bookmark',  'Bookmark / unsave message',    [], $locale)],
                ['key' => 'T',           'label' => $this->server->t('ui.terminalserver.netmail.help_text_dl',   'Download as .txt (ZMODEM)',    [], $locale)],
                ['key' => 'E',           'label' => $this->server->t('ui.terminalserver.netmail.help_email_fwd', 'Forward to my email address',  [], $locale)],
            ];
            $helpItems[] = ['key' => 'F', 'label' => $this->server->t('ui.terminalserver.netmail.help_forward_ftn', 'Forward to another FTN address', [], $locale)];
            if ($hasAttachments) {
                $helpItems[] = ['key' => 'Z', 'label' => $this->server->t('ui.terminalserver.message.help_download', 'Download attachment (ZMODEM)', [], $locale)];
            }
            if (!empty($imageRefs)) {
                $helpItems[] = ['key' => 'I', 'label' => $this->server->t('ui.terminalserver.message.help_images', 'View inline image(s)', [], $locale)];
            }

            $viewerExtraKeys = ['x' => 'delete', 'DELETE' => 'delete', 'b' => 'save', 't' => 'textdownload', 'e' => 'emailforward'];
            $viewerExtraKeys['f'] = 'forward';

            $shell = TerminalShellFactory::create($this->server, $state);
            $result = $shell->showMessageViewer(
                $conn,
                $state,
                $view['headerLines'],
                $view['wrappedLines'],
                $view['statusLine'],
                $state['rows'] ?? 24,
                0,
                true,
                $kludgeLines,
                $buildView,
                $imageRefs,
                $imageFn,
                $viewerExtraKeys,
                $helpItems,
                ['help_overlay' => TelnetUtils::getDefaultStyleProfile()['help_overlay']]
            );

            switch ($result['action']) {
                case 'quit':
                    return [$page, $index];
                case 'prev':
                    if ($index > 0) { $index--; break; }
                    if ($page > 1)  { $page--; $index = max(0, $perPage - 1); }
                    break;
                case 'next':
                    if ($index < count($messages) - 1) { $index++; break; }
                    if ($page < $totalPages)            { $page++; $index = 0; }
                    break;
                case 'reply':
                    TelnetUtils::safeWrite($conn, "\033[2J\033[H");
                    $replyData = $detail['data'] ?? $msg;
                    if ($isSentFolder) {
                        // When replying from Sent folder, pre-fill the original recipient.
                        $replyData = array_merge($replyData, [
                            'from_name'    => $msg['to_name'] ?? '',
                            'from_address' => $msg['to_address'] ?? '',
                        ]);
                    }
                    $this->compose($conn, $state, $session, $replyData);
                    TelnetUtils::setCursorVisible($conn, true);
                    return [$page, $index];
                case 'forward':
                    TelnetUtils::safeWrite($conn, "\033[2J\033[H");
                    $forwardData = $detail['data'] ?? $msg;
                    $forwardData['compose_mode'] = 'forward';
                    unset($forwardData['replyto_name'], $forwardData['replyto_address']);
                    $this->compose($conn, $state, $session, $forwardData);
                    TelnetUtils::setCursorVisible($conn, true);
                    return [$page, $index];
                case 'download':
                    $this->downloadAttachment($conn, $state, $attachments);
                    TelnetUtils::setCursorVisible($conn, true);
                    break;
                case 'textdownload':
                    $this->downloadAsText($conn, $state, $session, (int)$id, $msg['subject'] ?? 'message');
                    TelnetUtils::setCursorVisible($conn, true);
                    break;
                case 'delete':
                    $deleted = $this->confirmAndDeleteMessage($conn, $state, $session, (int)$id);
                    if ($deleted) {
                        return [$page, max(0, $index - 1)];
                    }
                    break;
                case 'emailforward':
                    $csrfToken = $state['csrf_token'] ?? null;
                    $shell->showWorkingOverlay($conn, $state, 'Forwarding message to email...');
                    $fwdResult = TelnetUtils::apiRequest($this->apiBase, 'POST', '/api/messages/netmail/' . $id . '/forward-email', null, $session, 3, $csrfToken);
                    if ($fwdResult['data']['success'] ?? false) {
                        $shell->showAlert($conn, $state, 'Email Forward', 'Forwarded to your email address.', 'info');
                    } else {
                        $errMsg = $fwdResult['data']['error'] ?? 'Failed to forward message.';
                        $shell->showAlert($conn, $state, 'Email Forward', $errMsg, 'error');
                    }
                    break;
                case 'save':
                    $csrfToken = $state['csrf_token'] ?? null;
                    if ($isSaved) {
                        TelnetUtils::apiRequest($this->apiBase, 'DELETE', '/api/messages/netmail/' . $id . '/save', null, $session, 3, $csrfToken);
                        $confirmMsg = 'Message removed from saved.';
                    } else {
                        TelnetUtils::apiRequest($this->apiBase, 'POST', '/api/messages/netmail/' . $id . '/save', null, $session, 3, $csrfToken);
                        $confirmMsg = 'Message saved.';
                    }
                    $isSaved = !$isSaved;
                    $detail['data']['is_saved'] = $isSaved;
                    $shell->showAlert($conn, $state, 'Bookmark', $confirmMsg, 'info');
                    break;
            }
        }
    }

    /**
     * Fetch a page of netmail messages.
     *
     * @param string $folder 'inbox' or 'sent'
     * @return array [messages, totalPages]
     */
    private function fetchMessagesPage(string $session, int $page, int $perPage, string $folder = 'inbox', string $sort = 'date_desc'): array
    {
        $filter = $folder === 'sent' ? 'sent' : 'all';
        $sort = $this->normalizeSort($sort);
        $response = TelnetUtils::apiRequest(
            $this->apiBase,
            'GET',
            '/api/messages/netmail?page=' . $page . '&per_page=' . $perPage . '&filter=' . $filter . '&sort=' . urlencode($sort),
            null,
            $session
        );
        $allMessages = $response['data']['messages'] ?? [];
        $pagination = $response['data']['pagination'] ?? [];
        $totalPages = $pagination['pages'] ?? 1;
        $messages = array_slice($allMessages, 0, $perPage);

        return [$messages, (int)$totalPages];
    }

    /**
     * Load saved netmail list state from user meta.
     *
     * @return array{page:int, selected_message_id:?int, folder:string, sort:string}
     */
    private function loadSavedListState(string $session): array
    {
        $response = TelnetUtils::apiRequest(
            $this->apiBase,
            'GET',
            '/api/user/terminal-mail-state',
            null,
            $session
        );

        $settings = $response['data']['settings'] ?? [];
        $page = (int)($settings['terminal_netmail_page'] ?? 1);
        $selectedId = (int)($settings['terminal_netmail_selected_message_id'] ?? 0);
        $savedFolder = (string)($settings['terminal_netmail_folder'] ?? 'inbox');
        $sort = $this->normalizeSort(is_string($settings['terminal_netmail_sort'] ?? null) ? $settings['terminal_netmail_sort'] : null);
        $folder = in_array($savedFolder, ['inbox', 'sent'], true) ? $savedFolder : 'inbox';

        return [
            'page' => max(1, $page),
            'selected_message_id' => $selectedId > 0 ? $selectedId : null,
            'folder' => $folder,
            'sort' => $sort,
        ];
    }

    /**
     * Save netmail list state to user meta.
     */
    private function saveListState(string $session, int $page, ?int $selectedMessageId, string $folder = 'inbox', string $sort = 'date_desc', ?string $csrfToken = null): void
    {
        $payload = [
            'terminal_netmail_page' => max(1, $page),
            'terminal_netmail_selected_message_id' => $selectedMessageId,
            'terminal_netmail_folder' => $folder,
            'terminal_netmail_sort' => $this->normalizeSort($sort),
        ];

        TelnetUtils::apiRequest(
            $this->apiBase,
            'POST',
            '/api/user/terminal-mail-state',
            $payload,
            $session,
            3,
            $csrfToken
        );
    }

    /**
     * Find index of a message id in the current message page.
     */
    private function findMessageIndexById(array $messages, int $messageId): ?int
    {
        foreach ($messages as $idx => $msg) {
            if ((int)($msg['id'] ?? 0) === $messageId) {
                return $idx;
            }
        }

        return null;
    }

    private function normalizeSort(?string $sort): string
    {
        return in_array($sort, self::ALLOWED_SORTS, true) ? $sort : 'date_desc';
    }

    private function promptForSort(
        $conn,
        array &$state,
        string $currentSort,
        string $title,
        array $messages,
        int $selectedIndex,
        string $folder
    ): string {
        $locale = $state['locale'] ?? 'en';
        $currentSort = $this->normalizeSort($currentSort);
        $sortLabels = [
            'date_desc' => $this->server->t('ui.terminalserver.echomail.sort_newest', 'Newest', [], $locale),
            'date_asc' => $this->server->t('ui.terminalserver.echomail.sort_oldest', 'Oldest', [], $locale),
            'subject' => $this->server->t('ui.terminalserver.echomail.sort_subject', 'Subject', [], $locale),
            'author' => $this->server->t('ui.terminalserver.echomail.sort_author', 'Author', [], $locale),
        ];
        $sortKeys = [
            'date_desc' => '1',
            'date_asc' => '2',
            'subject' => '3',
            'author' => '4',
        ];
        $choiceToSort = array_flip($sortKeys);
        $toggleLabel = $folder === 'sent' ? 'Inbox' : 'Sent';
        $redrawFn = function (array &$dialogState) use ($conn, $title, $messages, $selectedIndex, $toggleLabel): void {
            TelnetUtils::renderMessageListScreen(
                $conn,
                $dialogState,
                $this->server,
                $title,
                $messages,
                $selectedIndex,
                [
                    ['text' => 'O', 'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' Sort  ', 'color' => TelnetUtils::ANSI_BLUE],
                    ['text' => 'S', 'color' => TelnetUtils::ANSI_RED],
                    ['text' => ' ' . $toggleLabel, 'color' => TelnetUtils::ANSI_BLUE],
                ]
            );
        };

        $shell = TerminalShellFactory::create($this->server, $state);
        $choice = $shell->showConfirmDialog(
            $conn,
            $state,
            $this->server->t('ui.terminalserver.echomail.sort_title', 'Sort Order', [], $locale),
            $this->server->t(
                'ui.terminalserver.echomail.sort_prompt',
                'Current: {sort}',
                ['sort' => $sortLabels[$currentSort] ?? $sortLabels['date_desc']],
                $locale
            ),
            [
                '1' => $sortLabels['date_desc'],
                '2' => $sortLabels['date_asc'],
                '3' => $sortLabels['subject'],
                '4' => $sortLabels['author'],
                'q' => $this->server->t('ui.terminalserver.server.cancel', 'Cancel', [], $locale),
            ],
            $sortKeys[$currentSort] ?? '1',
            $redrawFn
        );

        if ($choice === 'q') {
            return $currentSort;
        }

        return $this->normalizeSort($choiceToSort[$choice] ?? $currentSort);
    }

    /**
     * Prompt for delete confirmation and delete the message if confirmed.
     *
     * @return bool True if the message was successfully deleted.
     */
    private function confirmAndDeleteMessage($conn, array &$state, string $session, int $id): bool
    {
        $locale = $state['locale'] ?? 'en';

        $shell = TerminalShellFactory::create($this->server, $state);
        $choice = $shell->showConfirmDialog(
            $conn, $state,
            $this->server->t('ui.terminalserver.netmail.delete_confirm_title', 'Delete Message?', [], $locale),
            $this->server->t('ui.terminalserver.netmail.delete_confirm_body', 'This cannot be undone.', [], $locale),
            ['y' => $this->server->t('ui.terminalserver.server.confirm_yes', 'Confirm', [], $locale),
             'n' => $this->server->t('ui.terminalserver.server.confirm_no',  'Cancel',  [], $locale)],
            'n'
        );

        if ($choice !== 'y') {
            return false;
        }

        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.netmail.deleting', 'Deleting...', [], $locale),
            TelnetUtils::ANSI_CYAN
        ));

        $result = TelnetUtils::apiRequest(
            $this->apiBase,
            'DELETE',
            '/api/messages/netmail/' . $id,
            null,
            $session,
            3,
            $state['csrf_token'] ?? null
        );

        if (($result['status'] ?? 0) === 200 && !empty($result['data']['success'])) {
            $this->server->logAction($state['username'] ?? 'unknown', "Netmail: deleted message #{$id}");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.netmail.delete_success', '✓ Message deleted.', [], $locale),
                TelnetUtils::ANSI_GREEN . TelnetUtils::ANSI_BOLD
            ));
        } else {
            $error = $result['data']['error'] ?? ($result['error'] ?? 'Unknown error');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.netmail.delete_failed', '✗ Failed to delete: {error}', ['error' => $error], $locale),
                TelnetUtils::ANSI_RED
            ));
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_DIM
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return false;
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
            TelnetUtils::ANSI_DIM
        ));
        $this->server->readKeyWithIdleCheck($conn, $state);
        return true;
    }

    /**
     * Prompt for an attachment (if needed) and download it via ZMODEM.
     */
    private function downloadAttachment($conn, array &$state, array $attachments): void
    {
        $locale = $state['locale'] ?? 'en';
        if (empty($attachments)) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.netmail.attachments_none', 'No file attachments on this message.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_DIM
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        if (!ZmodemTransfer::canDownload()) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.files.transfer_unavailable',
                    'ZMODEM disabled: install lrzsz (sz/rz) on the server to enable transfers.',
                    [],
                    $locale
                ),
                TelnetUtils::ANSI_YELLOW
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_DIM
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        $selected = null;
        if (count($attachments) === 1) {
            $selected = $attachments[0];
        } else {
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.netmail.attachments_header', 'Attachments:', [], $locale),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            ));
            TelnetUtils::writeLine($conn, '');
            foreach ($attachments as $idx => $attachment) {
                $num = $idx + 1;
                $name = (string)($attachment['filename'] ?? 'file');
                $size = $this->formatSize((int)($attachment['filesize'] ?? 0));
                TelnetUtils::writeLine($conn, sprintf(' %d) %s (%s)', $num, $name, $size));
            }
            TelnetUtils::writeLine($conn, '');
            $choice = trim((string)$this->server->prompt(
                $conn,
                $state,
                TelnetUtils::colorize(
                    $this->server->t(
                        'ui.terminalserver.netmail.attachment_download_prompt',
                        'Attachment # to download (Enter to cancel): ',
                        [],
                        $locale
                    ),
                    TelnetUtils::ANSI_YELLOW
                ),
                true
            ));
            if ($choice === '' || !ctype_digit($choice)) {
                return;
            }
            $index = (int)$choice - 1;
            if (!isset($attachments[$index])) {
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.files.invalid_selection', 'Invalid selection.', [], $locale),
                    TelnetUtils::ANSI_RED
                ));
                sleep(1);
                return;
            }
            $selected = $attachments[$index];
        }

        $storagePath = (string)($selected['storage_path'] ?? '');
        $name = (string)($selected['filename'] ?? 'file');
        if ($this->isDebugUniqueAttachmentNameEnabled()) {
            $name = $this->buildDebugUniqueAttachmentName($name, (int)($selected['message_id'] ?? 0));
        }
        if ($storagePath === '' || !is_file($storagePath)) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.files.download_error', 'File not found on server.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
            sleep(2);
            return;
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.files.download_starting', 'Starting ZMODEM download: {name}', ['name' => $name], $locale),
            TelnetUtils::ANSI_CYAN
        ));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.files.download_hint', 'Start ZMODEM receive in your terminal now...', [], $locale),
            TelnetUtils::ANSI_DIM
        ));
        sleep(1);

        $ok = ZmodemTransfer::send($conn, $storagePath, $name, !($state['isSsh'] ?? false));
        TelnetUtils::writeLine($conn, '');
        if ($ok) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.files.download_done', 'Transfer complete.', [], $locale),
                TelnetUtils::ANSI_GREEN
            ));
        } else {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.files.download_failed', 'Transfer failed or was cancelled.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
        }

        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
            TelnetUtils::ANSI_DIM
        ));
        $this->server->readKeyWithIdleCheck($conn, $state);
    }

    /**
     * Download the current netmail message as a plain-text .txt file via ZMODEM.
     *
     * @param resource $conn
     * @param array    $state
     * @param string   $session
     * @param int      $id      Message ID
     * @param string   $subject Message subject (used to derive the filename)
     */
    private function downloadAsText($conn, array &$state, string $session, int $id, string $subject): void
    {
        $locale = $state['locale'] ?? 'en';

        if (!ZmodemTransfer::canDownload()) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.files.transfer_unavailable',
                    'ZMODEM disabled: install lrzsz (sz/rz) on the server to enable transfers.',
                    [],
                    $locale
                ),
                TelnetUtils::ANSI_YELLOW
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_DIM
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        $response = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/messages/netmail/' . $id . '/download', null, $session);
        $content  = $response['data']['raw'] ?? null;

        if ($response['status'] !== 200 || $content === null || $content === '') {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.netmail.text_download_fetch_failed',
                    'Could not fetch message text.',
                    [],
                    $locale
                ),
                TelnetUtils::ANSI_RED
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_DIM
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $subject);
        $safeName = trim((string)$safeName, '_');
        if ($safeName === '') {
            $safeName = 'message';
        }
        $filename = $safeName . '.txt';
        $tmpPath  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'binkterm_' . uniqid() . '_' . $filename;

        if (file_put_contents($tmpPath, $content) === false) {
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t(
                    'ui.terminalserver.netmail.text_download_fetch_failed',
                    'Could not fetch message text.',
                    [],
                    $locale
                ),
                TelnetUtils::ANSI_RED
            ));
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
                TelnetUtils::ANSI_DIM
            ));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.files.download_starting', 'Starting ZMODEM download: {name}', ['name' => $filename], $locale),
            TelnetUtils::ANSI_CYAN
        ));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.files.download_hint', 'Start ZMODEM receive in your terminal now...', [], $locale),
            TelnetUtils::ANSI_DIM
        ));
        sleep(1);

        $ok = ZmodemTransfer::send($conn, $tmpPath, $filename, !($state['isSsh'] ?? false));
        @unlink($tmpPath);

        TelnetUtils::writeLine($conn, '');
        if ($ok) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.files.download_done', 'Transfer complete.', [], $locale),
                TelnetUtils::ANSI_GREEN
            ));
        } else {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.files.download_failed', 'Transfer failed or was cancelled.', [], $locale),
                TelnetUtils::ANSI_RED
            ));
        }

        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $locale),
            TelnetUtils::ANSI_DIM
        ));
        $this->server->readKeyWithIdleCheck($conn, $state);
    }

    /**
     * Format a byte count as a human-readable size string.
     */
    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }

    /**
     * Mark a selected set of netmail messages as read for the current user.
     *
     * @param int[] $messageIds
     * @return array{success:bool,message:string}
     */
    private function markSelectedMessagesRead(string $session, array $messageIds, string $locale, ?string $csrfToken): array
    {
        $messageIds = array_values(array_unique(array_filter(array_map('intval', $messageIds), static fn(int $id): bool => $id > 0)));

        if ($messageIds === []) {
            return [
                'success' => false,
                'message' => $this->server->t('ui.terminalserver.netmail.mark_selected_none', 'No messages are selected.', [], $locale),
            ];
        }

        $result = TelnetUtils::apiRequest(
            $this->apiBase,
            'POST',
            '/api/messages/netmail/read',
            ['messageIds' => $messageIds],
            $session,
            3,
            $csrfToken
        );

        if (($result['status'] ?? 0) === 200 && !empty($result['data']['success'])) {
            return [
                'success' => true,
                'message' => $this->server->t('ui.terminalserver.netmail.mark_selected_success', 'Selected messages marked as read.', [], $locale),
            ];
        }

        return [
            'success' => false,
            'message' => $this->server->t('ui.terminalserver.netmail.mark_selected_failed', 'Failed to mark selected messages as read.', [], $locale),
        ];
    }

    /**
     * Debug switch: force unique outbound ZMODEM filename for attachments.
     */
    private function isDebugUniqueAttachmentNameEnabled(): bool
    {
        $val = (string)\BinktermPHP\Config::env('TELNET_ZMODEM_DEBUG_UNIQUE_NAMES', 'false');
        return in_array(strtolower($val), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Build a unique filename while preserving extension for receiver-side testing.
     */
    private function buildDebugUniqueAttachmentName(string $name, int $messageId): string
    {
        $info = pathinfo($name);
        $base = (string)($info['filename'] ?? 'file');
        $ext  = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';
        $suffix = '_dbg_nm' . ($messageId > 0 ? $messageId : 0) . '_' . gmdate('YmdHis');
        return $base . $suffix . $ext;
    }

}
