<?php

namespace BinktermPHP\TelnetServer;

/**
 * Chooses the terminal interaction shell for a given session.
 */
class TerminalShellFactory
{
    public static function create(BbsSession $server, array $state): TerminalShellInterface
    {
        // Sysop force-shell overrides user preference entirely.
        if (\BinktermPHP\BbsConfig::getTerminalForceShell()) {
            return \BinktermPHP\BbsConfig::getTerminalDefaultShell() === 'line'
                ? new LineShell($server)
                : new TuiShell($server);
        }

        $mode = strtolower((string)($state['term_shell_mode'] ?? 'auto'));
        if ($mode === 'line') {
            return new LineShell($server);
        }
        if ($mode === 'tui') {
            return new TuiShell($server);
        }
        // 'auto': fall through to sysop default
        return \BinktermPHP\BbsConfig::getTerminalDefaultShell() === 'line'
            ? new LineShell($server)
            : new TuiShell($server);
    }
}
