<?php

namespace BinktermPHP\TelnetServer;

/**
 * Chooses the terminal interaction shell for a given session.
 */
class TerminalShellFactory
{
    public static function create(BbsSession $server, array $state): TerminalShellInterface
    {
        $buildShell = static function (string $shell) use ($server): TerminalShellInterface {
            $resolved = \BinktermPHP\BbsConfig::normalizeTerminalShell($shell);
            $instance = \BinktermPHP\TerminalShellRegistry::createShell($resolved, $server);
            if ($instance instanceof TerminalShellInterface) {
                return $instance;
            }

            return new TuiShell($server);
        };

        // Sysop force-shell overrides user preference entirely.
        if (\BinktermPHP\BbsConfig::getTerminalForceShell()) {
            return $buildShell(\BinktermPHP\BbsConfig::getTerminalDefaultShell());
        }

        $mode = strtolower((string)($state['term_shell_mode'] ?? 'auto'));
        if ($mode !== '' && $mode !== 'auto') {
            return $buildShell(\BinktermPHP\BbsConfig::normalizeTerminalShell($mode));
        }
        // 'auto': fall through to sysop default
        return $buildShell(\BinktermPHP\BbsConfig::getTerminalDefaultShell());
    }
}
