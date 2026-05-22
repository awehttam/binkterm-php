<?php

namespace BinktermPHP\TelnetServer;

/**
 * TerminalSettingsHandler — terminal capability detection wizard support.
 *
 * Detects UTF-8 vs CP437 charset support and ANSI color capability by showing
 * the user test characters and asking confirmation questions. Results are saved
 * as user meta preferences via the API and applied to the current session.
 *
 * The standalone terminal settings menu previously housed here has been retired
 * in favor of the abstraction-backed tabbed settings UI in SettingsHandler.
 * The detection wizard itself intentionally remains a raw prompt flow because it
 * must function before shell-specific widgets can be trusted to render
 * correctly.
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
    /**
     * @param array|null $preloaded  Pre-fetched terminal settings array (skips API call when provided).
     */
    public function loadSettings($conn, array &$state, string $session, ?array $preloaded = null): void
    {
        if ($preloaded !== null) {
            $state['terminal_charset']    = $preloaded['terminal_charset']    ?? null;
            $state['terminal_ansi_color'] = $preloaded['terminal_ansi_color'] ?? null;
            $state['term_shell_mode']     = $preloaded['term_shell_mode']     ?? null;
        } else {
            $response = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/user/terminal-settings', null, $session);
            if (($response['status'] ?? 0) === 200 && isset($response['data']['settings'])) {
                $settings = $response['data']['settings'];
                $state['terminal_charset']    = $settings['terminal_charset'] ?? null;
                $state['terminal_ansi_color'] = $settings['terminal_ansi_color'] ?? null;
                $state['term_shell_mode']     = $settings['term_shell_mode']     ?? null;
            } else {
                $state['terminal_charset']    = null;
                $state['terminal_ansi_color'] = null;
                $state['term_shell_mode']     = null;
            }
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
     * This flow intentionally bypasses the shell abstraction and uses direct
     * prompt/write output. The wizard exists specifically to determine whether
     * shell-specific rendering can be trusted at all, so it must stay on the
     * lowest-common-denominator prompt path for every shell.
     *
     * Tests UTF-8 charset support and ANSI color capability, saves results,
     * and applies them to the current session state.
     */
    public function runDetectionWizard($conn, array &$state, string $session): void
    {
        $locale = $state['locale'] ?? 'en';
        $previousAnsiEnabled = ($state['terminal_ansi_color'] ?? 'yes') !== 'no';

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

        if (strtolower(trim($answer)) === 'y') {
            $charset = 'utf8';
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.detect.charset_utf8', 'UTF-8 character set enabled.', [], $locale),
                TelnetUtils::ANSI_GREEN
            ));
        } else {
            // UTF-8 not supported — test for CP437 box-drawing support
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.detect.charset_cp437_intro',
                'CP437 box-drawing test:', [], $locale));
            TelnetUtils::writeLine($conn, '');
            // Raw CP437 bytes: ┌───┐ / │   │ / └───┘
            TelnetUtils::writeLine($conn, '  ' . "\xda\xc4\xc4\xc4\xbf");
            TelnetUtils::writeLine($conn, '  ' . "\xb3   \xb3");
            TelnetUtils::writeLine($conn, '  ' . "\xc0\xc4\xc4\xc4\xd9");
            TelnetUtils::writeLine($conn, '');

            $cp437Q = TelnetUtils::colorize(
                $this->server->t('ui.terminalserver.detect.charset_cp437_question',
                    'Do the above appear as a box drawn with lines and corners? (Y/N): ', [], $locale),
                TelnetUtils::ANSI_CYAN
            );
            $cp437Answer = $this->server->prompt($conn, $state, $cp437Q, true);
            if ($cp437Answer === null) {
                return;
            }

            if (strtolower(trim($cp437Answer)) === 'y') {
                $charset = 'cp437';
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.detect.charset_cp437', 'CP437 (DOS/ANSI) character set enabled.', [], $locale),
                    TelnetUtils::ANSI_GREEN
                ));
            } else {
                $charset = 'ascii';
                TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                    $this->server->t('ui.terminalserver.detect.charset_ascii', 'ASCII mode enabled.', [], $locale),
                    TelnetUtils::ANSI_GREEN
                ));
            }
        }

        $state['terminal_charset'] = $charset;
        TelnetUtils::writeLine($conn, '');

        // --- ANSI color test ---
        TelnetUtils::writeLine($conn, $this->server->t('ui.terminalserver.detect.ansi_intro',
            'Color test:', [], $locale));
        TelnetUtils::writeLine($conn, '');
        // Always force color rendering for the test sample so users can answer accurately.
        TelnetUtils::setAnsiColorEnabled(true);
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
            TelnetUtils::setAnsiColorEnabled($previousAnsiEnabled);
            return;
        }
        $ansiColor = (strtolower(trim($colorAnswer)) === 'y') ? 'yes' : 'no';
        $state['terminal_ansi_color'] = $ansiColor;
        TelnetUtils::setAnsiColorEnabled($ansiColor === 'yes');

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
}
