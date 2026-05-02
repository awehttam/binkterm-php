<?php

namespace BinktermPHP\TelnetServer;

/**
 * Full-featured BBS settings screen for the telnet/SSH terminal.
 *
 * Mirrors the web settings page across tabs for Terminal, Display,
 * Messaging, Profile, and Account — using the reusable {@see AnsiTabComponent} and
 * {@see AnsiForm} components.  Notifications are omitted because sound
 * delivery is not available over a text terminal connection.
 *
 * The AI tab is conditionally included when MCP is enabled on the system.
 */
class SettingsHandler
{
    private BbsSession $server;
    private string     $apiBase;

    /** @var TerminalSettingsHandler Reused for the detection wizard action. */
    private TerminalSettingsHandler $terminalHandler;

    public function __construct(BbsSession $server, string $apiBase)
    {
        $this->server          = $server;
        $this->apiBase         = $apiBase;
        $this->terminalHandler = new TerminalSettingsHandler($server, $apiBase);
    }

    // ── Public entry point ────────────────────────────────────────────────────

    /**
     * Show the tabbed settings UI and save if the user confirms.
     */
    public function show($conn, array &$state, string $session): void
    {
        $locale = $state['locale'] ?? 'en';

        // ── Load current settings ────────────────────────────────────────────
        $userResp = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/user/settings', null, $session);
        $settings = $userResp['data']['settings'] ?? [];
        $profileResp = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/user/profile', null, $session);
        $profile = $profileResp['data']['profile'] ?? [];

        // Merge terminal settings (stored separately)
        $termResp   = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/user/terminal-settings', null, $session);
        $termSettings = $termResp['data']['settings'] ?? [];

        // ── Fetch supporting data for select options ─────────────────────────
        $taglines = $this->fetchTaglines($session);
        $mcpInfo  = $this->fetchMcpInfo($session);

        // ── Build fields ─────────────────────────────────────────────────────

        // --- Terminal tab ---
        $charsetField = new AnsiSelectField(
            'terminal_charset',
            $this->t('ui.terminalserver.settings.terminal.charset', 'Character Set', $locale),
            ['utf8' => 'UTF-8', 'cp437' => 'CP437 (DOS/ANSI)', 'ascii' => 'ASCII'],
            $termSettings['terminal_charset'] ?? $state['terminal_charset'] ?? 'utf8'
        );
        $colorField = new AnsiToggleField(
            'terminal_ansi_color',
            $this->t('ui.terminalserver.settings.terminal.ansi_color', 'ANSI Color', $locale),
            ($termSettings['terminal_ansi_color'] ?? $state['terminal_ansi_color'] ?? 'yes') !== 'no'
        );
        $wizardAction = new AnsiActionField(
            'run_wizard',
            $this->t('ui.terminalserver.settings.terminal.run_wizard', 'Run Detection Wizard', $locale),
            function ($conn, array &$state, string $session, BbsSession $server) use ($charsetField, $colorField) {
                $this->terminalHandler->runDetectionWizard($conn, $state, $session);
                // Sync select/toggle back from updated state
                $charsetField->setValue($state['terminal_charset'] ?? 'utf8');
                $colorField->setValue($state['terminal_ansi_color'] !== 'no');
                return null;
            }
        );

        $terminalForm = new AnsiForm();
        $terminalForm->addField($charsetField)
                     ->addField($colorField)
                     ->addField($wizardAction);

        // --- Display tab ---
        $perPageField = new AnsiSelectField(
            'messages_per_page',
            $this->t('ui.terminalserver.settings.display.messages_per_page', 'Messages Per Page', $locale),
            ['10' => '10', '25' => '25', '50' => '50', '100' => '100', '250' => '250', '500' => '500'],
            (string)($settings['messages_per_page'] ?? 25)
        );
        $timezoneField = new AnsiSelectField(
            'timezone',
            $this->t('ui.terminalserver.settings.display.timezone', 'Timezone', $locale),
            $this->buildTimezoneOptions(),
            $settings['timezone'] ?? 'UTC'
        );
        $localeOptions = $this->buildLocaleOptions($locale);
        $localeField = new AnsiSelectField(
            'locale',
            $this->t('ui.terminalserver.settings.display.language', 'Language', $locale),
            $localeOptions,
            $settings['locale'] ?? 'en'
        );
        $dateFormatField = new AnsiSelectField(
            'date_format',
            $this->t('ui.terminalserver.settings.display.date_format', 'Date Format', $locale),
            [
                'en-US' => 'MM/DD/YYYY (en-US)',
                'en-GB' => 'DD/MM/YYYY (en-GB)',
                'en-CA' => 'YYYY-MM-DD (en-CA)',
                'de-DE' => 'DD.MM.YYYY (de-DE)',
                'fr-FR' => 'DD/MM/YYYY (fr-FR)',
                'es-ES' => 'DD/MM/YYYY (es-ES)',
            ],
            $settings['date_format'] ?? 'en-US'
        );
        $echoListField = new AnsiSelectField(
            'default_echo_list',
            $this->t('ui.terminalserver.settings.display.default_echo_list', 'Default Echo List', $locale),
            [
                'system_choice' => $this->t('ui.terminalserver.settings.display.echo_list_system', 'System Default', $locale),
                'reader'        => $this->t('ui.terminalserver.settings.display.echo_list_reader',  'Reader',         $locale),
                'echolist'      => $this->t('ui.terminalserver.settings.display.echo_list_all',     'All Areas',      $locale),
            ],
            $settings['default_echo_list'] ?? 'system_choice'
        );

        $displayForm = new AnsiForm();
        $displayForm->addField($perPageField)
                    ->addField($timezoneField)
                    ->addField($localeField)
                    ->addField($dateFormatField)
                    ->addField($echoListField);

        // --- Messaging tab ---
        $taglineOptions = array_merge(
            [
                ''         => $this->t('ui.terminalserver.settings.messaging.tagline_none',   '(No tagline)', $locale),
                '__random__' => $this->t('ui.terminalserver.settings.messaging.tagline_random', '(Random)',     $locale),
            ],
            $taglines
        );
        $taglineField = new AnsiSelectField(
            'default_tagline',
            $this->t('ui.terminalserver.settings.messaging.tagline', 'Default Tagline', $locale),
            $taglineOptions,
            $settings['default_tagline'] ?? ''
        );
        $signatureField = new AnsiTextField(
            'signature_text',
            $this->t('ui.terminalserver.settings.messaging.signature', 'Signature', $locale),
            $settings['signature_text'] ?? '',
            4,    // maxLines — opens full-screen editor
            800,  // maxChars (4 lines × ~200 chars)
            $this->t('ui.terminalserver.settings.messaging.signature_hint', 'ENTER to edit (4 lines max)', $locale),
            [
                'title' => 'EDITING SIGNATURE',
                'shortcuts' => 'Ctrl+K=Help  Ctrl+Z=Save Changes  Ctrl+C=Cancel',
                'saved' => 'Signature changes saved.',
                'help_title' => 'SIGNATURE EDITOR HELP',
                'help_save' => 'Ctrl+Z = Save changes',
                'help_cancel' => 'Ctrl+C = Cancel and discard changes',
            ]
        );
        $threadedEchoField = new AnsiToggleField(
            'threaded_view',
            $this->t('ui.terminalserver.settings.messaging.threaded_echo', 'Threaded Echomail View', $locale),
            (bool)($settings['threaded_view'] ?? false)
        );
        $threadedNetField = new AnsiToggleField(
            'netmail_threaded_view',
            $this->t('ui.terminalserver.settings.messaging.threaded_net', 'Threaded Netmail View', $locale),
            (bool)($settings['netmail_threaded_view'] ?? false)
        );
        $quoteColorField = new AnsiToggleField(
            'quote_coloring',
            $this->t('ui.terminalserver.settings.messaging.quote_coloring', 'Color Quoted Text', $locale),
            (bool)($settings['quote_coloring'] ?? true)
        );
        $registeredFeatureLabel = $this->t('ui.base.admin.registered_feature', 'Registered Feature', $locale);
        $forwardNetmailLabel = $this->t('ui.terminalserver.settings.messaging.forward_netmail', 'Forward Netmail to Email', $locale);
        $digestLabel = $this->t('ui.terminalserver.settings.messaging.echomail_digest', 'Echomail Digest', $locale);
        if (!(bool)($settings['license_valid'] ?? false)) {
            $forwardNetmailLabel .= ' (' . $registeredFeatureLabel . ')';
            $digestLabel .= ' (' . $registeredFeatureLabel . ')';
        }

        $forwardNetmailField = new AnsiToggleField(
            'forward_netmail_email',
            $forwardNetmailLabel,
            (bool)($settings['forward_netmail_email'] ?? false)
        );
        $forwardNetmailField->setEnabled((bool)($settings['license_valid'] ?? false));

        $digestField = new AnsiSelectField(
            'echomail_digest',
            $digestLabel,
            [
                'none'   => $this->t('ui.terminalserver.settings.messaging.digest_none',   'None',   $locale),
                'daily'  => $this->t('ui.terminalserver.settings.messaging.digest_daily',  'Daily',  $locale),
                'weekly' => $this->t('ui.terminalserver.settings.messaging.digest_weekly', 'Weekly', $locale),
            ],
            $settings['echomail_digest'] ?? 'none'
        );
        $digestField->setEnabled((bool)($settings['license_valid'] ?? false));

        $badgeModeField = new AnsiSelectField(
            'echomail_badge_mode',
            $this->t('ui.terminalserver.settings.messaging.echomail_badge_mode', 'New Echomail Badge', $locale),
            [
                'new'    => $this->t('ui.terminalserver.settings.messaging.badge_mode_new',    'New since last visit', $locale),
                'unread' => $this->t('ui.terminalserver.settings.messaging.badge_mode_unread', 'Total unread',         $locale),
            ],
            $settings['echomail_badge_mode'] ?? 'new'
        );

        $messagingForm = new AnsiForm();
        $messagingForm->addField($signatureField)
                      ->addField($taglineField)
                      ->addField($threadedEchoField)
                      ->addField($threadedNetField)
                      ->addField($quoteColorField)
                      ->addField($forwardNetmailField)
                      ->addField($digestField)
                      ->addField($badgeModeField);

        // --- Profile tab ---
        $emailField = new AnsiTextField(
            'email',
            $this->t('ui.terminalserver.settings.profile.email', 'Email Address', $locale),
            (string)($profile['email'] ?? ''),
            1,
            255,
            $this->t('ui.terminalserver.settings.profile.email_hint', 'ENTER to edit email address', $locale)
        );
        $locationField = new AnsiTextField(
            'location',
            $this->t('ui.terminalserver.settings.profile.location', 'Location', $locale),
            (string)($profile['location'] ?? ''),
            1,
            255,
            $this->t('ui.terminalserver.settings.profile.location_hint', 'ENTER to edit location', $locale)
        );
        $aboutMeField = new AnsiTextField(
            'about_me',
            $this->t('ui.terminalserver.settings.profile.about_me', 'About Me', $locale),
            (string)($profile['about_me'] ?? ''),
            8,
            4000,
            $this->t('ui.terminalserver.settings.profile.about_me_hint', 'ENTER to edit profile text', $locale),
            [
                'title' => 'EDITING PROFILE',
                'shortcuts' => 'Ctrl+K=Help  Ctrl+Z=Save Changes  Ctrl+C=Cancel',
                'saved' => 'Profile text saved.',
                'help_title' => 'PROFILE EDITOR HELP',
                'help_save' => 'Ctrl+Z = Save changes',
                'help_cancel' => 'Ctrl+C = Cancel and discard changes',
            ]
        );

        $profileForm = new AnsiForm();
        $profileForm->addField($emailField)
                    ->addField($locationField)
                    ->addField($aboutMeField);

        // --- Account tab ---
        $changePasswordAction = new AnsiActionField(
            'change_password',
            $this->t('ui.terminalserver.settings.account.change_password', 'Change Password', $locale),
            function ($conn, array &$state, string $session, BbsSession $server) {
                return $this->doChangePassword($conn, $state, $session, $server);
            }
        );
        $viewSessionsAction = new AnsiActionField(
            'view_sessions',
            $this->t('ui.terminalserver.settings.account.view_sessions', 'View Active Sessions', $locale),
            function ($conn, array &$state, string $session, BbsSession $server) {
                return $this->doViewSessions($conn, $state, $session, $server);
            }
        );
        $resetOnboardingAction = new AnsiActionField(
            'reset_onboarding',
            $this->t('ui.terminalserver.settings.account.reset_onboarding', 'Reset Echomail Onboarding', $locale),
            function ($conn, array &$state, string $session, BbsSession $server) {
                return $this->doResetOnboarding($conn, $state, $session, $server);
            }
        );

        $accountForm = new AnsiForm();
        $accountForm->addField($changePasswordAction)
                    ->addField($viewSessionsAction)
                    ->addField($resetOnboardingAction);

        // ── Build tabs ───────────────────────────────────────────────────────
        $tabs = new AnsiTabComponent(
            $this->t('ui.terminalserver.settings.tab_title', 'BBS Settings', $locale),
            $this->server
        );
        $tabs->addTab($this->t('ui.terminalserver.settings.tab_terminal',  'Terminal',  $locale), $terminalForm);
        $tabs->addTab($this->t('ui.terminalserver.settings.tab_display',  'Display',   $locale), $displayForm);
        $tabs->addTab($this->t('ui.terminalserver.settings.tab_messaging','Messaging', $locale), $messagingForm);
        $tabs->addTab($this->t('ui.terminalserver.settings.tab_profile',  'Profile',   $locale), $profileForm);
        $tabs->addTab($this->t('ui.terminalserver.settings.tab_account',  'Account',   $locale), $accountForm);

        // Conditionally add AI tab
        if (!empty($mcpInfo['mcp_enabled'])) {
            $aiForm = $this->buildAiForm($mcpInfo, $session, $locale);
            $tabs->addTab($this->t('ui.terminalserver.settings.tab_ai', 'AI', $locale), $aiForm);
        }

        // ── Run UI ───────────────────────────────────────────────────────────
        while (true) {
            $result = $tabs->show($conn, $state, $session);

            if ($result === 'save') {
                $this->performSave(
                    $conn, $state, $session,
                    $charsetField, $colorField,
                    $displayForm, $messagingForm, $profileForm
                );
                continue;
            }

            if ($result === 'discard') {
                $this->showMessage($conn, $state,
                    $this->t('ui.terminalserver.settings.discarded', 'Changes discarded.', $locale),
                    TelnetUtils::ANSI_DIM
                );
            }

            return;
        }
    }

    // ── Save logic ────────────────────────────────────────────────────────────

    /**
     * Persist all changes from the terminal, display, and messaging tabs.
     */
    private function performSave(
        $conn,
        array &$state,
        string $session,
        AnsiSelectField $charsetField,
        AnsiToggleField $colorField,
        AnsiForm $displayForm,
        AnsiForm $messagingForm,
        AnsiForm $profileForm
    ): void {
        $locale    = $state['locale'] ?? 'en';
        $csrfToken = $state['csrf_token'] ?? null;
        $allOk     = true;
        $errors    = [];

        // ── Terminal settings ────────────────────────────────────────────────
        $newCharset    = (string)$charsetField->getValue();
        $newAnsiColor  = $colorField->getValue() ? 'yes' : 'no';

        $termResp = TelnetUtils::apiRequest(
            $this->apiBase,
            'POST',
            '/api/user/terminal-settings',
            ['terminal_charset' => $newCharset, 'terminal_ansi_color' => $newAnsiColor],
            $session,
            3,
            $csrfToken
        );
        if (($termResp['status'] ?? 0) === 200) {
            // Apply immediately so the rest of the session uses the new settings
            $state['terminal_charset']    = $newCharset;
            $state['terminal_ansi_color'] = $newAnsiColor;
            $this->server->applyTerminalSettings($state);
        } else {
            $allOk = false;
            $errors[] = $this->formatApiError(
                'terminal settings',
                $termResp['status'] ?? 0,
                $termResp['data'] ?? [],
                $termResp['error'] ?? null
            );
        }

        // ── User settings (display + messaging combined) ──────────────────────
        $payload = array_merge(
            $displayForm->getValues(),
            $messagingForm->getValues()
        );

        // Coerce booleans to strings for PostgreSQL
        foreach ($payload as $k => $v) {
            if (is_bool($v)) { $payload[$k] = $v ? 'true' : 'false'; }
        }

        // Enforce signature line limit
        if (isset($payload['signature_text'])) {
            $sigLines = explode("\n", str_replace("\r\n", "\n", $payload['signature_text']));
            $payload['signature_text'] = implode("\n", array_slice($sigLines, 0, 4));
        }

        $userResp = TelnetUtils::apiRequest(
            $this->apiBase,
            'POST',
            '/api/user/settings',
            ['settings' => $payload],
            $session,
            3,
            $csrfToken
        );
        if (($userResp['status'] ?? 0) !== 200) {
            $allOk = false;
            $errors[] = $this->formatApiError(
                'user settings',
                $userResp['status'] ?? 0,
                $userResp['data'] ?? [],
                $userResp['error'] ?? null
            );
        }

        $profileResp = TelnetUtils::apiRequest(
            $this->apiBase,
            'POST',
            '/api/user/profile',
            $profileForm->getValues(),
            $session,
            3,
            $csrfToken
        );
        if (($profileResp['status'] ?? 0) !== 200) {
            $allOk = false;
            $errors[] = $this->formatApiError(
                'profile',
                $profileResp['status'] ?? 0,
                $profileResp['data'] ?? [],
                $profileResp['error'] ?? null
            );
        }

        // Update locale in state immediately if changed
        if (isset($payload['locale'])) {
            $state['locale'] = $payload['locale'];
        }

        // ── Feedback ─────────────────────────────────────────────────────────
        if ($allOk) {
            $this->showMessage($conn, $state,
                $this->t('ui.terminalserver.settings.saved', 'Settings saved.', $locale),
                TelnetUtils::ANSI_GREEN
            );
        } else {
            foreach ($errors as $error) {
                $this->server->logInfo('Settings save failure: ' . $error);
            }
            $this->showMessage($conn, $state,
                $this->t('ui.terminalserver.settings.save_failed', 'Warning: could not save settings.', $locale),
                TelnetUtils::ANSI_YELLOW
            );
        }
    }

    private function formatApiError(string $scope, int $status, array $data, ?string $transportError): string
    {
        $detail = '';
        if (!empty($data['error'])) {
            $detail = (string)$data['error'];
        } elseif (!empty($data['message'])) {
            $detail = (string)$data['message'];
        } elseif (!empty($data['raw']) && is_string($data['raw'])) {
            $detail = trim($data['raw']);
        } elseif (!empty($transportError)) {
            $detail = $transportError;
        }

        $message = ucfirst($scope) . ' failed';
        if ($status > 0) {
            $message .= ' (HTTP ' . $status . ')';
        }
        if ($detail !== '') {
            $message .= ': ' . preg_replace('/\s+/', ' ', $detail);
        }

        return $message;
    }

    // ── Account actions ───────────────────────────────────────────────────────

    /**
     * Interactive password-change flow.
     */
    private function doChangePassword($conn, array &$state, string $session, BbsSession $server): ?string
    {
        $locale    = $state['locale'] ?? 'en';
        $csrfToken = $state['csrf_token'] ?? null;

        $server->safeWrite($conn, "\033[2J\033[H");
        $server->writeLine($conn, TelnetUtils::colorize(
            $this->t('ui.terminalserver.settings.account.change_password', 'Change Password', $locale),
            TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
        ));
        $server->writeLine($conn, '');

        $oldPw = $server->prompt(
            $conn, $state,
            TelnetUtils::colorize(
                $this->t('ui.terminalserver.settings.account.old_password_prompt', 'Current password: ', $locale),
                TelnetUtils::ANSI_CYAN
            ),
            false  // no echo
        );
        if ($oldPw === null) { return 'quit'; }

        $newPw = $server->prompt(
            $conn, $state,
            TelnetUtils::colorize(
                $this->t('ui.terminalserver.settings.account.new_password_prompt', 'New password: ', $locale),
                TelnetUtils::ANSI_CYAN
            ),
            false
        );
        if ($newPw === null) { return 'quit'; }

        $confirmPw = $server->prompt(
            $conn, $state,
            TelnetUtils::colorize(
                $this->t('ui.terminalserver.settings.account.confirm_password_prompt', 'Confirm new password: ', $locale),
                TelnetUtils::ANSI_CYAN
            ),
            false
        );
        if ($confirmPw === null) { return 'quit'; }

        $server->writeLine($conn, '');

        if ($newPw !== $confirmPw) {
            $server->logInfo('Password change failed: confirmation mismatch');
            $server->writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.settings.account.password_mismatch', 'Passwords do not match.', $locale),
                TelnetUtils::ANSI_YELLOW
            ));
        } else {
            $resp = TelnetUtils::apiRequest(
                $this->apiBase,
                'POST',
                '/api/user/change-password',
                ['old_password' => $oldPw, 'new_password' => $newPw],
                $session,
                3,
                $csrfToken
            );
            if (($resp['status'] ?? 0) === 200) {
                $server->logInfo('Password changed successfully');
                $server->writeLine($conn, TelnetUtils::colorize(
                    $this->t('ui.terminalserver.settings.account.password_changed', 'Password changed successfully.', $locale),
                    TelnetUtils::ANSI_GREEN
                ));
            } else {
                $server->logInfo('Password change failed: ' . $this->formatApiError(
                    'change password',
                    $resp['status'] ?? 0,
                    $resp['data'] ?? [],
                    $resp['error'] ?? null
                ));
                $msg = $resp['data']['error'] ?? $this->t(
                    'ui.terminalserver.settings.account.password_failed',
                    'Failed to change password.',
                    $locale
                );
                $server->writeLine($conn, TelnetUtils::colorize($msg, TelnetUtils::ANSI_YELLOW));
            }
        }

        $server->writeLine($conn, '');
        $server->prompt($conn, $state,
            TelnetUtils::colorize(
                $this->t('ui.terminalserver.detect.press_enter', 'Press Enter to continue...', $locale),
                TelnetUtils::ANSI_DIM
            ),
            true
        );
        return null;
    }

    /**
     * Show a summary of active sessions with option to revoke individual ones.
     */
    private function doViewSessions($conn, array &$state, string $session, BbsSession $server): ?string
    {
        $locale    = $state['locale'] ?? 'en';
        $csrfToken = $state['csrf_token'] ?? null;

        while (true) {
            $server->safeWrite($conn, "\033[2J\033[H");
            $server->writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.settings.account.sessions_title', 'Active Sessions', $locale),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            ));
            $server->writeLine($conn, '');

            $resp     = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/user/sessions', null, $session);
            $sessions = $resp['data']['sessions'] ?? [];

            if (empty($sessions)) {
                $server->writeLine($conn, TelnetUtils::colorize(
                    $this->t('ui.terminalserver.settings.account.no_sessions', '  No active sessions found.', $locale),
                    TelnetUtils::ANSI_DIM
                ));
            } else {
                foreach ($sessions as $i => $s) {
                    $num     = $i + 1;
                    $isCurr  = !empty($s['is_current']) ? ' (current)' : '';
                    $created = $s['created_at'] ?? 'unknown';
                    $ip      = $s['ip_address'] ?? '?';
                    $server->writeLine($conn, "  {$num}) {$ip}  {$created}{$isCurr}");
                }
            }

            $server->writeLine($conn, '');
            $server->writeLine($conn,
                $this->t('ui.terminalserver.settings.account.sessions_hint',
                    'Enter session number to revoke, or Q to return: ', $locale)
            );

            $choice = $server->prompt($conn, $state,
                TelnetUtils::colorize('> ', TelnetUtils::ANSI_CYAN), true);

            if ($choice === null || strtolower(trim($choice)) === 'q' || trim($choice) === '') {
                return null;
            }

            $num = (int)trim($choice);
            if ($num > 0 && isset($sessions[$num - 1])) {
                $sid  = $sessions[$num - 1]['id'] ?? null;
                if ($sid !== null) {
                    $r = TelnetUtils::apiRequest(
                        $this->apiBase, 'DELETE', '/api/user/sessions/' . $sid,
                        null, $session, 3, $csrfToken
                    );
                    if (($r['status'] ?? 0) === 200) {
                        $server->writeLine($conn, TelnetUtils::colorize('  Session revoked.', TelnetUtils::ANSI_GREEN));
                    } else {
                        $server->writeLine($conn, TelnetUtils::colorize('  Failed to revoke session.', TelnetUtils::ANSI_YELLOW));
                    }
                    usleep(800000); // brief pause so the user can see the result
                }
            }
        }
    }

    /**
     * Reset echomail onboarding (interest selection wizard).
     */
    private function doResetOnboarding($conn, array &$state, string $session, BbsSession $server): ?string
    {
        $locale    = $state['locale'] ?? 'en';
        $csrfToken = $state['csrf_token'] ?? null;

        $resp = TelnetUtils::apiRequest(
            $this->apiBase, 'POST', '/api/user/reset-onboarding',
            [], $session, 3, $csrfToken
        );

        $server->writeLine($conn, '');
        if (($resp['status'] ?? 0) === 200) {
            $server->writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.settings.account.onboarding_reset',
                    'Echomail onboarding reset. You will be guided through interest selection on next visit.', $locale),
                TelnetUtils::ANSI_GREEN
            ));
        } else {
            $server->writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.settings.account.onboarding_reset_failed',
                    'Failed to reset onboarding.', $locale),
                TelnetUtils::ANSI_YELLOW
            ));
        }

        $server->writeLine($conn, '');
        $server->prompt($conn, $state,
            TelnetUtils::colorize(
                $this->t('ui.terminalserver.detect.press_enter', 'Press Enter to continue...', $locale),
                TelnetUtils::ANSI_DIM
            ),
            true
        );
        return null;
    }

    // ── AI tab ────────────────────────────────────────────────────────────────

    /**
     * Build the AI/MCP tab form with read-only status and action buttons.
     */
    private function buildAiForm(array $mcpInfo, string $session, string $locale): AnsiForm
    {
        $hasKey    = !empty($mcpInfo['has_key']);
        $keyPreview = $mcpInfo['key_preview'] ?? '';

        $generateAction = new AnsiActionField(
            'mcp_generate',
            $hasKey
                ? $this->t('ui.terminalserver.settings.ai.regenerate_key', 'Regenerate MCP Key', $locale)
                : $this->t('ui.terminalserver.settings.ai.generate_key',   'Generate MCP Key',   $locale),
            function ($conn, array &$state, string $session, BbsSession $server) use ($hasKey) {
                return $this->doMcpGenerate($conn, $state, $session, $server, $hasKey);
            }
        );

        $revokeAction = new AnsiActionField(
            'mcp_revoke',
            $this->t('ui.terminalserver.settings.ai.revoke_key', 'Revoke MCP Key', $locale),
            function ($conn, array &$state, string $session, BbsSession $server) {
                return $this->doMcpRevoke($conn, $state, $session, $server);
            }
        );
        $revokeAction->setEnabled($hasKey);

        $statusField = new AnsiActionField(
            'mcp_status',
            $hasKey
                ? $this->t('ui.terminalserver.settings.ai.mcp_key_exists', 'Key active: ' . $keyPreview, $locale)
                : $this->t('ui.terminalserver.settings.ai.mcp_no_key', 'No key generated yet', $locale),
            function ($conn, array &$state, string $session, BbsSession $server) {
                return null; // read-only display — ENTER does nothing
            }
        );
        $statusField->setEnabled(false);

        $form = new AnsiForm();
        $form->addField($statusField)
             ->addField($generateAction)
             ->addField($revokeAction);
        return $form;
    }

    private function doMcpGenerate($conn, array &$state, string $session, BbsSession $server, bool $isRegenerate): ?string
    {
        $locale    = $state['locale'] ?? 'en';
        $csrfToken = $state['csrf_token'] ?? null;

        $server->safeWrite($conn, "\033[2J\033[H");
        $endpoint = $isRegenerate ? '/api/user/mcp-key/regenerate' : '/api/user/mcp-key/generate';

        if ($isRegenerate) {
            $confirm = $server->prompt($conn, $state,
                TelnetUtils::colorize(
                    $this->t('ui.terminalserver.settings.ai.regenerate_confirm',
                        'Regenerating will invalidate the existing key. Continue? (Y/N): ', $locale),
                    TelnetUtils::ANSI_CYAN
                ),
                true
            );
            if ($confirm === null) { return 'quit'; }
            if (strtolower(trim($confirm)) !== 'y') { return null; }
        }

        $resp = TelnetUtils::apiRequest($this->apiBase, 'POST', $endpoint, [], $session, 3, $csrfToken);
        $server->writeLine($conn, '');

        if (($resp['status'] ?? 0) === 200) {
            $key = $resp['data']['key'] ?? '(unavailable)';
            $server->writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.settings.ai.key_generated', 'MCP key generated. Copy it now — it will not be shown again:', $locale),
                TelnetUtils::ANSI_GREEN
            ));
            $server->writeLine($conn, '');
            $server->writeLine($conn, TelnetUtils::colorize("  {$key}", TelnetUtils::ANSI_YELLOW . TelnetUtils::ANSI_BOLD));
        } else {
            $server->writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.settings.ai.generate_failed', 'Failed to generate key.', $locale),
                TelnetUtils::ANSI_YELLOW
            ));
        }

        $server->writeLine($conn, '');
        $server->prompt($conn, $state,
            TelnetUtils::colorize(
                $this->t('ui.terminalserver.detect.press_enter', 'Press Enter to continue...', $locale),
                TelnetUtils::ANSI_DIM
            ),
            true
        );
        return null;
    }

    private function doMcpRevoke($conn, array &$state, string $session, BbsSession $server): ?string
    {
        $locale    = $state['locale'] ?? 'en';
        $csrfToken = $state['csrf_token'] ?? null;

        $confirm = $server->prompt($conn, $state,
            TelnetUtils::colorize(
                $this->t('ui.terminalserver.settings.ai.revoke_confirm',
                    'Revoke MCP key? Any connected AI client will be disconnected. (Y/N): ', $locale),
                TelnetUtils::ANSI_CYAN
            ),
            true
        );
        if ($confirm === null) { return 'quit'; }
        if (strtolower(trim($confirm)) !== 'y') { return null; }

        $resp = TelnetUtils::apiRequest($this->apiBase, 'DELETE', '/api/user/mcp-key', null, $session, 3, $csrfToken);
        $server->writeLine($conn, '');

        if (($resp['status'] ?? 0) === 200) {
            $server->writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.settings.ai.key_revoked', 'MCP key revoked.', $locale),
                TelnetUtils::ANSI_GREEN
            ));
        } else {
            $server->writeLine($conn, TelnetUtils::colorize(
                $this->t('ui.terminalserver.settings.ai.revoke_failed', 'Failed to revoke key.', $locale),
                TelnetUtils::ANSI_YELLOW
            ));
        }

        $server->writeLine($conn, '');
        $server->prompt($conn, $state,
            TelnetUtils::colorize(
                $this->t('ui.terminalserver.detect.press_enter', 'Press Enter to continue...', $locale),
                TelnetUtils::ANSI_DIM
            ),
            true
        );
        return null;
    }

    // ── Data fetching helpers ─────────────────────────────────────────────────

    /**
     * Fetch the user's available taglines from the API.
     * Returns a map of tagline_id => tagline_text (truncated for display).
     *
     * @return array<string, string>
     */
    private function fetchTaglines(string $session): array
    {
        $resp = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/taglines', null, $session);
        $raw  = $resp['data']['taglines'] ?? [];
        $out  = [];
        foreach ($raw as $t) {
            $id   = (string)($t['id'] ?? '');
            $text = (string)($t['text'] ?? '');
            if ($id !== '' && $text !== '') {
                $out[$id] = mb_substr($text, 0, 50);
            }
        }
        return $out;
    }

    /**
     * Fetch MCP status for the current user.
     *
     * @return array{mcp_enabled: bool, has_key: bool, key_preview: string}
     */
    private function fetchMcpInfo(string $session): array
    {
        $resp = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/user/mcp-key', null, $session);
        if (($resp['status'] ?? 0) !== 200) {
            return ['mcp_enabled' => false, 'has_key' => false, 'key_preview' => ''];
        }
        return [
            'mcp_enabled' => true,
            'has_key'     => (bool)($resp['data']['has_key'] ?? false),
            'key_preview' => (string)($resp['data']['key_preview'] ?? ''),
        ];
    }

    // ── Option list builders ──────────────────────────────────────────────────

    /**
     * Common timezone list for the Display tab select field.
     *
     * @return array<string, string>
     */
    private function buildTimezoneOptions(): array
    {
        return [
            'UTC'                  => 'UTC',
            'America/New_York'     => 'Eastern (New York)',
            'America/Chicago'      => 'Central (Chicago)',
            'America/Denver'       => 'Mountain (Denver)',
            'America/Los_Angeles'  => 'Pacific (Los Angeles)',
            'America/Anchorage'    => 'Alaska (Anchorage)',
            'Pacific/Honolulu'     => 'Hawaii (Honolulu)',
            'America/Toronto'      => 'Eastern Canada (Toronto)',
            'America/Vancouver'    => 'Pacific Canada (Vancouver)',
            'America/Sao_Paulo'    => 'Brazil (Sao Paulo)',
            'Europe/London'        => 'UK (London)',
            'Europe/Paris'         => 'Central Europe (Paris)',
            'Europe/Berlin'        => 'Central Europe (Berlin)',
            'Europe/Helsinki'      => 'Eastern Europe (Helsinki)',
            'Europe/Moscow'        => 'Russia (Moscow)',
            'Asia/Tokyo'           => 'Japan (Tokyo)',
            'Asia/Shanghai'        => 'China (Shanghai)',
            'Asia/Kolkata'         => 'India (Kolkata)',
            'Asia/Dubai'           => 'Gulf (Dubai)',
            'Asia/Singapore'       => 'Singapore',
            'Australia/Sydney'     => 'Australia Eastern (Sydney)',
            'Australia/Perth'      => 'Australia Western (Perth)',
            'Pacific/Auckland'     => 'New Zealand (Auckland)',
            'Africa/Johannesburg'  => 'South Africa (Johannesburg)',
        ];
    }

    /**
     * Build the locale / language select options.
     * Labels are always shown in the language's own name.
     *
     * @return array<string, string>
     */
    private function buildLocaleOptions(string $currentLocale): array
    {
        return [
            'en' => 'English',
            'fr' => 'Français',
            'es' => 'Español',
        ];
    }

    // ── Utility ───────────────────────────────────────────────────────────────

    /**
     * Display a centered modal-style status dialog briefly, then return.
     */
    private function showMessage($conn, array $state, string $message, string $color = ''): void
    {
        $cols = max(40, (int)($state['cols'] ?? 80));
        $rows = max(12, (int)($state['rows'] ?? 24));
        $chars = $this->server->getTerminalLineDrawingChars();

        $plainMessage = $this->server->encodeForTerminal($message);
        $footer = $this->t('ui.terminalserver.server.continuing', 'Returning to settings...', $state['locale'] ?? 'en');
        $contentWidth = min(max(strlen($plainMessage), strlen($footer), 24), max(24, $cols - 10));
        $boxWidth = min($cols - 4, $contentWidth + 4);
        $innerWidth = $boxWidth - 4;
        $leftPad = str_repeat(' ', max(0, (int)floor(($cols - $boxWidth) / 2)));
        $topPadCount = max(0, (int)floor(($rows - 7) / 2));

        $topBorder = $this->server->encodeForTerminal($chars['tl'] . str_repeat($chars['h_bold'], $boxWidth - 2) . $chars['tr']);
        $divider = $this->server->encodeForTerminal($chars['l_tee'] . str_repeat($chars['h'], $boxWidth - 2) . $chars['r_tee']);
        $bottomBorder = $this->server->encodeForTerminal($chars['bl'] . str_repeat($chars['h_bold'], $boxWidth - 2) . $chars['br']);
        $vertical = $this->server->encodeForTerminal($chars['v']);

        $fit = static function (string $text, int $width): string {
            if (strlen($text) > $width) {
                return substr($text, 0, $width);
            }
            return str_pad($text, $width, ' ', STR_PAD_BOTH);
        };

        $this->server->safeWrite($conn, "\033[2J\033[H");
        if ($topPadCount > 0) {
            $this->server->safeWrite($conn, str_repeat("\r\n", $topPadCount));
        }

        $this->server->writeLine($conn, $leftPad . $this->server->colorizeForTerminal($topBorder, TelnetUtils::ANSI_BLUE . TelnetUtils::ANSI_BOLD));
        $this->server->writeLine($conn, $leftPad . $this->server->colorizeForTerminal($divider, TelnetUtils::ANSI_BLUE));

        $messageLine = $this->server->colorizeForTerminal($vertical, TelnetUtils::ANSI_BLUE)
            . ' '
            . ($color !== '' ? TelnetUtils::colorize($fit($plainMessage, $innerWidth), $color) : $fit($plainMessage, $innerWidth))
            . ' '
            . $this->server->colorizeForTerminal($vertical, TelnetUtils::ANSI_BLUE);
        $this->server->writeLine($conn, $leftPad . $messageLine);

        $footerLine = $this->server->colorizeForTerminal($vertical, TelnetUtils::ANSI_BLUE)
            . ' '
            . $this->server->colorizeForTerminal($fit($footer, $innerWidth), TelnetUtils::ANSI_DIM)
            . ' '
            . $this->server->colorizeForTerminal($vertical, TelnetUtils::ANSI_BLUE);
        $this->server->writeLine($conn, $leftPad . $footerLine);

        $this->server->writeLine($conn, $leftPad . $this->server->colorizeForTerminal($divider, TelnetUtils::ANSI_BLUE));
        $this->server->writeLine($conn, $leftPad . $this->server->colorizeForTerminal($bottomBorder, TelnetUtils::ANSI_BLUE . TelnetUtils::ANSI_BOLD));
        usleep(1400000);
    }

    /**
     * Translate a terminal-server UI key, falling back to a plain English string.
     *
     * This overload of BbsSession::t() forwards to the session instance but
     * accepts $locale as the last positional argument without the $params array,
     * matching the common call pattern used throughout the settings handler.
     *
     * @param string       $key      i18n key.
     * @param string       $fallback English fallback string.
     * @param string|array $localeOrParams  Locale string or params array.
     * @param string       $locale   Locale string (when $localeOrParams is params).
     */
    private function t(string $key, string $fallback, string|array $localeOrParams = [], string $locale = ''): string
    {
        if (is_string($localeOrParams)) {
            // Called as t($key, $fallback, $locale)
            return $this->server->t($key, $fallback, [], $localeOrParams);
        }
        // Called as t($key, $fallback, $params, $locale)
        return $this->server->t($key, $fallback, $localeOrParams, $locale);
    }
}
