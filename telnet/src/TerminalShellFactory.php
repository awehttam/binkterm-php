<?php

namespace BinktermPHP\TelnetServer;

/**
 * Chooses the terminal interaction shell for a given session.
 */
class TerminalShellFactory
{
    public static function create(BbsSession $server, array $state): TerminalShellInterface
    {
        $mode = strtolower((string)($state['term_shell_mode'] ?? 'auto'));
        if ($mode === 'line') {
            return new LineShell($server);
        }
        if ($mode === 'tui') {
            return new TuiShell($server);
        }

        $rows = (int)($state['rows'] ?? 24);
        $cols = (int)($state['cols'] ?? 80);
        if ($rows < 16 || $cols < 60) {
            return new LineShell($server);
        }

        return new TuiShell($server);
    }
}
