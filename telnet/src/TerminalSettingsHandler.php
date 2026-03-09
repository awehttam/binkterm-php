<?php

namespace BinktermPHP\TelnetServer;

/**
 * TerminalSettingsHandler — terminal capability detection wizard and settings page.
 *
 * Detects UTF-8 vs CP437 charset support and ANSI color capability by showing
 * the user test characters and asking confirmation questions. Results are saved
 * as user meta preferences via the API and applied to the current session.
 */
class TerminalSettingsHandler
{
    private BbsSession $server;
    private string $apiBase;

    public function __construct(BbsSession $server, string $apiBase)
    {
        $this->server  = $server;
        $this->apiBase = $apiBase;
    }

    /**
     * Load saved terminal settings from the API and apply them to state.
     */
    public function loadSettings($conn, array &$state, string $session): void
    {
        $response = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/user/terminal-settings', null, $session);
        if (($response['status'] ?? 0) === 200 && isset($response['data']['settings'])) {
            $settings = $response['data']['settings'];
            $state['terminal_charset']    = $settings['terminal_charset'] ?? null;
            $state['terminal_ansi_color'] = $settings['terminal_ansi_color'] ?? null;
        } else {
            $state['terminal_charset']    = null;
            $state['terminal_ansi_color'] = null;
        }
        $this->server->applyTerminalSettings($state);
    }

    /**
     * Save terminal settings via the API.
     */
    private function saveSettings(array $settings, string $session, ?string $csrfToken = null): bool
    {
        $response = TelnetUtils::apiRequest(
            $this->apiBase,
            'POST',
            '/api/user/terminal-settings',
            $settings,
            $session,
            3,
            $csrfToken
        );
        return ($response['status'] ?? 0) === 200;
    }

    /**
     * Interactive terminal capability detection wizard.
     *
     * Tests UTF-8 charset support and ANSI color capability, saves results,
     * and applies them to the current session state.
     */
    public function runDetectionWizard($conn, array &$state, string $session): void
    {
        $locale = $state['locale'] ?? 'en';

        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.detect.title', '=== Terminal Setup ===', [], $locale),
            TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
        ));
        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.detect.intro',
            'BBS will now test your terminal to ensure content displays correctly.', [], $locale));
        TelnetUtils::writeLine($conn, '');

        // --- UTF-8 / charset test ---
        TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.detect.charset_intro',
            'Character set test:', [], $locale));
        TelnetUtils::writeLine($conn, '');

        $testChars = "\xe2\x86\x90 \xe2\x86\x92 \xe2\x9c\x93 \xe2\x9c\x97 \xc3\xa9 \xc3\xb1"; // ← → ✓ ✗ é ñ
        TelnetUtils::writeLine($conn, '  ' . $testChars);
        TelnetUtils::writeLine($conn, '');

        $charsetQ = TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.detect.charset_question',
                'Do the above appear as arrows, checkmarks, and accented letters? (Y/N): ', [], $locale),
            TelnetUtils::ANSI_CYAN
        );
        $answer = $this->server->prompt($conn, $state, $charsetQ, true);
        if ($answer === null) {
            return;
        }
        $charset = (strtolower(trim($answer)) === 'y') ? 'utf8' : 'cp437';
        $state['terminal_charset'] = $charset;

        if ($charset === 'utf8') {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.detect.charset_utf8', 'UTF-8 character set enabled.', [], $locale),
                TelnetUtils::ANSI_GREEN
            ));
        } else {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.detect.charset_cp437', 'CP437 (DOS/ANSI) character set enabled.', [], $locale),
                TelnetUtils::ANSI_GREEN
            ));
        }
        TelnetUtils::writeLine($conn, '');

        // --- ANSI color test ---
        TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.detect.ansi_intro',
            'Color test:', [], $locale));
        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, '  '
            . TelnetUtils::colorize('RED ', TelnetUtils::ANSI_RED)
            . TelnetUtils::colorize('GREEN ', TelnetUtils::ANSI_GREEN)
            . TelnetUtils::colorize('CYAN ', TelnetUtils::ANSI_CYAN)
            . TelnetUtils::colorize('YELLOW', TelnetUtils::ANSI_YELLOW)
        );
        TelnetUtils::writeLine($conn, '');

        $colorQ = TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.detect.ansi_question',
                'Do the words above appear in different colors? (Y/N): ', [], $locale),
            TelnetUtils::ANSI_CYAN
        );
        $colorAnswer = $this->server->prompt($conn, $state, $colorQ, true);
        if ($colorAnswer === null) {
            return;
        }
        $ansiColor = (strtolower(trim($colorAnswer)) === 'y') ? 'yes' : 'no';
        $state['terminal_ansi_color'] = $ansiColor;

        if ($ansiColor === 'yes') {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.detect.ansi_yes', 'ANSI color enabled.', [], $locale),
                TelnetUtils::ANSI_GREEN
            ));
        } else {
            TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.detect.ansi_no',
                'ANSI color disabled.', [], $locale));
        }

        // Save settings
        $saved = $this->saveSettings([
            'terminal_charset'    => $charset,
            'terminal_ansi_color' => $ansiColor,
        ], $session, $state['csrf_token'] ?? null);

        TelnetUtils::writeLine($conn, '');
        if ($saved) {
            TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.detect.complete',
                'Terminal setup complete. Settings saved.', [], $locale));
        } else {
            TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.settings.save_failed',
                'Warning: could not save settings.', [], $locale));
        }
        TelnetUtils::writeLine($conn, '');

        // Apply to current session immediately
        $this->server->applyTerminalSettings($state);

        $this->server->prompt($conn, $state,
            TelnetUtils::colorize($this->server->t('ui.terminalserver.detect.press_enter',
                'Press Enter to continue...', [], $locale), TelnetUtils::ANSI_CYAN),
            true
        );
    }

    /**
     * Terminal settings page — view and change current settings.
     */
    public function show($conn, array &$state, string $session): void
    {
        $locale = $state['locale'] ?? 'en';

        while (true) {
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.settings.title', '=== Terminal Settings ===', [], $locale),
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            ));
            TelnetUtils::writeLine($conn, '');

            $charsetVal = $state['terminal_charset'] ?? null;
            $colorVal   = $state['terminal_ansi_color'] ?? null;
            $notSet     = $this->server->t('ui.terminalserver.settings.not_set', 'Not configured', [], $locale);

            TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.settings.charset_label',
                'Character set : {value}', ['value' => $charsetVal ?? $notSet], $locale));
            TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.settings.ansi_label',
                'ANSI color    : {value}', ['value' => $colorVal ?? $notSet], $locale));
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.settings.menu_detect',
                'D) Run detection wizard', [], $locale));
            TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.settings.menu_charset',
                'C) Change character set manually', [], $locale));
            TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.settings.menu_ansi',
                'A) Toggle ANSI color', [], $locale));
            TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.settings.menu_quit',
                'Q) Return to main menu', [], $locale));
            TelnetUtils::writeLine($conn, '');

            $choice = $this->server->prompt($conn, $state,
                TelnetUtils::colorize('> ', TelnetUtils::ANSI_CYAN), true);
            if ($choice === null) {
                return;
            }
            $choice = strtolower(trim($choice));

            if ($choice === 'q' || $choice === '') {
                return;
            }

            if ($choice === 'd') {
                $this->runDetectionWizard($conn, $state, $session);
            } elseif ($choice === 'c') {
                $this->manualCharset($conn, $state, $session, $locale);
            } elseif ($choice === 'a') {
                $this->toggleAnsiColor($conn, $state, $session, $locale);
            }
        }
    }

    /**
     * Manually select character set.
     */
    private function manualCharset($conn, array &$state, string $session, string $locale): void
    {
        TelnetUtils::writeLine($conn, '');
        $prompt = TelnetUtils::colorize(
            $this->server->t('ui.terminalserver.settings.charset_prompt',
                'Select: (U)TF-8, (C)P437, (A)SCII: ', [], $locale),
            TelnetUtils::ANSI_CYAN
        );
        $answer = $this->server->prompt($conn, $state, $prompt, true);
        if ($answer === null) {
            return;
        }
        $charsetMap = ['u' => 'utf8', 'c' => 'cp437', 'a' => 'ascii'];
        $charset    = $charsetMap[strtolower(trim($answer))] ?? null;
        if ($charset === null) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.settings.invalid_choice', 'Invalid choice.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
            return;
        }
        $state['terminal_charset'] = $charset;
        $saved = $this->saveSettings([
            'terminal_charset' => $charset,
            'terminal_ansi_color' => $state['terminal_ansi_color'] ?? 'yes',
        ], $session, $state['csrf_token'] ?? null);
        $this->server->applyTerminalSettings($state);
        if ($saved) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.settings.saved', 'Settings saved.', [], $locale),
                TelnetUtils::ANSI_GREEN
            ));
        } else {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.settings.save_failed', 'Failed to save settings.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
        }
    }

    /**
     * Toggle ANSI color on/off.
     */
    private function toggleAnsiColor($conn, array &$state, string $session, string $locale): void
    {
        $current = ($state['terminal_ansi_color'] ?? 'yes') === 'yes' ? 'yes' : 'no';
        $new     = $current === 'yes' ? 'no' : 'yes';
        $state['terminal_ansi_color'] = $new;
        $saved = $this->saveSettings([
            'terminal_charset' => $state['terminal_charset'] ?? 'utf8',
            'terminal_ansi_color' => $new,
        ], $session, $state['csrf_token'] ?? null);
        $this->server->applyTerminalSettings($state);
        $label = $new === 'yes'
            ? $this->server->t('ui.terminalserver.detect.ansi_yes', 'ANSI color enabled.', [], $locale)
            : $this->server->t('ui.terminalserver.detect.ansi_no', 'ANSI color disabled.', [], $locale);
        TelnetUtils::writeLine($conn, TelnetUtils::colorize($label, TelnetUtils::ANSI_GREEN));
        if (!$saved) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.settings.save_failed', 'Failed to save settings.', [], $locale),
                TelnetUtils::ANSI_YELLOW
            ));
        }
    }
}
