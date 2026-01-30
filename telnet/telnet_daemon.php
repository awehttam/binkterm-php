#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Config;
use BinktermPHP\Version;

const IAC = 255;
const DONT = 254;
const TELNET_DO = 253;
const WONT = 252;
const WILL = 251;
const SB = 250;
const SE = 240;
const OPT_ECHO = 1;
const OPT_SUPPRESS_GA = 3;
const OPT_NAWS = 31;

// Arrow key escape sequences
const KEY_UP = "\033[A";
const KEY_DOWN = "\033[B";
const KEY_RIGHT = "\033[C";
const KEY_LEFT = "\033[D";
const KEY_HOME = "\033[H";
const KEY_END = "\033[F";
const KEY_DELETE = "\033[3~";
const KEY_PGUP = "\033[5~";
const KEY_PGDOWN = "\033[6~";

const ANSI_RESET = "\033[0m";
const ANSI_BOLD = "\033[1m";
const ANSI_DIM = "\033[2m";
const ANSI_BLUE = "\033[34m";
const ANSI_CYAN = "\033[36m";
const ANSI_GREEN = "\033[32m";
const ANSI_YELLOW = "\033[33m";
const ANSI_MAGENTA = "\033[35m";
const ANSI_RED = "\033[31m";

// Global rate limiting tracking
$GLOBALS['failed_login_attempts'] = [];

function cleanupOldLoginAttempts(): void
{
    $now = time();
    $cutoff = $now - 60; // Remove attempts older than 1 minute

    foreach ($GLOBALS['failed_login_attempts'] as $ip => $attempts) {
        $GLOBALS['failed_login_attempts'][$ip] = array_filter($attempts, function($timestamp) use ($cutoff) {
            return $timestamp > $cutoff;
        });

        // Remove empty entries
        if (empty($GLOBALS['failed_login_attempts'][$ip])) {
            unset($GLOBALS['failed_login_attempts'][$ip]);
        }
    }
}

function recordFailedLogin(string $ip): void
{
    cleanupOldLoginAttempts();

    if (!isset($GLOBALS['failed_login_attempts'][$ip])) {
        $GLOBALS['failed_login_attempts'][$ip] = [];
    }

    $GLOBALS['failed_login_attempts'][$ip][] = time();
}

function getFailedLoginCount(string $ip): int
{
    cleanupOldLoginAttempts();
    return count($GLOBALS['failed_login_attempts'][$ip] ?? []);
}

function isRateLimited(string $ip): bool
{
    return getFailedLoginCount($ip) >= 5;
}

function clearFailedLogins(string $ip): void
{
    unset($GLOBALS['failed_login_attempts'][$ip]);
}

function parseArgs(array $argv): array
{
    $args = [];
    foreach ($argv as $arg) {
        if (strpos($arg, '--') === 0) {
            if (strpos($arg, '=') !== false) {
                [$key, $value] = explode('=', substr($arg, 2), 2);
                $args[$key] = $value;
            } else {
                $args[substr($arg, 2)] = true;
            }
        }
    }
    return $args;
}

function buildApiBase(array $args): string
{
    if (!empty($args['api-base'])) {
        return rtrim($args['api-base'], '/');
    }

    // Try to get site URL from config
    try {
        return Config::getSiteUrl();
    } catch (\Exception $e) {
        return 'http://127.0.0.1';
    }
}

function sendTelnetCommand($conn, int $cmd, int $opt): void
{
    safeWrite($conn, chr(IAC) . chr($cmd) . chr($opt));
}

function setEcho($conn, array &$state, bool $enable): void
{
    $state['input_echo'] = $enable;
    // Force server-side echo control (client echo off)
    sendTelnetCommand($conn, WILL, OPT_ECHO);
    sendTelnetCommand($conn, DONT, OPT_ECHO);
}

function negotiateTelnet($conn): void
{
    sendTelnetCommand($conn, TELNET_DO, OPT_NAWS);
    sendTelnetCommand($conn, WILL, OPT_SUPPRESS_GA);
}

function readTelnetLine($conn, array &$state): ?string
{
    // Check if connection is still valid
    if (!is_resource($conn) || feof($conn)) {
        return null;
    }

    // Check for timeout
    $metadata = stream_get_meta_data($conn);
    if ($metadata['timed_out']) {
        return null;
    }

    $line = '';
    while (true) {
        if (!empty($state['pushback'])) {
            $char = $state['pushback'][0];
            $state['pushback'] = substr($state['pushback'], 1);
        } else {
            $char = fread($conn, 1);
        }
        if ($char === false || $char === '') {
            // Check if connection died
            if (!is_resource($conn) || feof($conn)) {
                return null;
            }
            // Check for timeout
            $metadata = stream_get_meta_data($conn);
            if ($metadata['timed_out']) {
                return null;
            }
            // Empty read, continue
            continue;
        }
        $byte = ord($char);

        if (!empty($state['telnet_mode'])) {
            if ($state['telnet_mode'] === 'IAC') {
                if ($byte === IAC) {
                    $line .= chr(IAC);
                    $state['telnet_mode'] = null;
                } elseif (in_array($byte, [TELNET_DO, DONT, WILL, WONT], true)) {
                    $state['telnet_mode'] = 'IAC_CMD';
                    $state['telnet_cmd'] = $byte;
                } elseif ($byte === SB) {
                    $state['telnet_mode'] = 'SB';
                    $state['sb_opt'] = null;
                    $state['sb_data'] = '';
                } else {
                    $state['telnet_mode'] = null;
                }
                continue;
            }

            if ($state['telnet_mode'] === 'IAC_CMD') {
                $state['telnet_mode'] = null;
                $state['telnet_cmd'] = null;
                continue;
            }

            if ($state['telnet_mode'] === 'SB') {
                if ($state['sb_opt'] === null) {
                    $state['sb_opt'] = $byte;
                    continue;
                }
                if ($byte === IAC) {
                    $state['telnet_mode'] = 'SB_IAC';
                    continue;
                }
                $state['sb_data'] .= chr($byte);
                continue;
            }

            if ($state['telnet_mode'] === 'SB_IAC') {
                if ($byte === SE) {
                    if ($state['sb_opt'] === OPT_NAWS && strlen($state['sb_data']) >= 4) {
                        $w = (ord($state['sb_data'][0]) << 8) + ord($state['sb_data'][1]);
                        $h = (ord($state['sb_data'][2]) << 8) + ord($state['sb_data'][3]);
                        if ($w > 0) {
                            $state['cols'] = $w;
                        }
                        if ($h > 0) {
                            $state['rows'] = $h;
                        }
                        // Log screen size in debug mode
                        if (!empty($GLOBALS['telnet_debug'])) {
                            echo "[" . date('Y-m-d H:i:s') . "] NAWS: Screen size negotiated as {$w}x{$h}\n";
                        }
                    }
                    $state['telnet_mode'] = null;
                    $state['sb_opt'] = null;
                    $state['sb_data'] = '';
                    continue;
                }
                if ($byte === IAC) {
                    $state['sb_data'] .= chr(IAC);
                    $state['telnet_mode'] = 'SB';
                    continue;
                }
                $state['telnet_mode'] = 'SB';
                continue;
            }
        }

        if ($byte === IAC) {
            $state['telnet_mode'] = 'IAC';
            continue;
        }

        if ($byte === 10) {
            if (!empty($state['input_echo'])) {
                safeWrite($conn, "\r\n");
            }
            return $line;
        }

        if ($byte === 13) {
            $next = fread($conn, 1);
            if ($next !== false && $next !== '') {
                if (ord($next) !== 10) {
                    // push back one byte by prepending to a buffer
                    $state['pushback'] = ($state['pushback'] ?? '') . $next;
                }
            }
            if (!empty($state['input_echo'])) {
                safeWrite($conn, "\r\n");
            }
            return $line;
        }

        if ($byte === 8 || $byte === 127) {
            if ($line !== '') {
                $line = substr($line, 0, -1);
                if (!empty($state['input_echo'])) {
                    safeWrite($conn, "\x08 \x08");
                }
            }
            continue;
        }

        if ($byte === 0) {
            continue;
        }

        $line .= chr($byte);
        if (!empty($state['input_echo'])) {
            safeWrite($conn, chr($byte));
        }
    }
}

function writeLine($conn, string $text = ''): void
{
    safeWrite($conn, $text . "\r\n");
}

function colorize(string $text, string $color): string
{
    return $color . $text . ANSI_RESET;
}

function writeWrapped($conn, string $text, int $width): void
{
    $lines = preg_split("/\\r?\\n/", $text);
    foreach ($lines as $line) {
        if ($line === '') {
            writeLine($conn, '');
            continue;
        }
        $wrapped = wordwrap($line, max(20, $width), "\r\n", true);
        safeWrite($conn, $wrapped . "\r\n");
    }
}

function safeWrite($conn, string $data): void
{
    if (!is_resource($conn)) {
        return;
    }
    $prev = error_reporting();
    error_reporting($prev & ~E_NOTICE);
    @fwrite($conn, $data);
    error_reporting($prev);
}

function readRawChar($conn, array &$state): ?string
{
    // Check if connection is still valid
    if (!is_resource($conn) || feof($conn)) {
        return null;
    }

    if (!empty($state['pushback'])) {
        $char = $state['pushback'][0];
        $state['pushback'] = substr($state['pushback'], 1);
        return $char;
    }

    $char = fread($conn, 1);
    if ($char === false || $char === '') {
        return null;
    }

    $byte = ord($char);

    // Handle telnet IAC sequences
    if ($byte === IAC) {
        $cmd = fread($conn, 1);
        if ($cmd === false) {
            return null;
        }
        $cmdByte = ord($cmd);

        if ($cmdByte === IAC) {
            return chr(IAC);
        }

        if (in_array($cmdByte, [TELNET_DO, DONT, WILL, WONT], true)) {
            $opt = fread($conn, 1);
            return $char; // Return something to continue
        }

        if ($cmdByte === SB) {
            // Read subnegotiation
            while (true) {
                $byte = fread($conn, 1);
                if ($byte === false || ord($byte) === IAC) {
                    $next = fread($conn, 1);
                    if ($next !== false && ord($next) === SE) {
                        break;
                    }
                }
            }
            return $char;
        }
    }

    // Check for escape sequences (arrow keys, etc)
    if ($byte === 27) { // ESC
        // Look ahead for CSI sequences
        $next1 = fread($conn, 1);
        if ($next1 === false || $next1 === '') {
            return chr(27); // Just ESC
        }

        if ($next1 === '[') {
            // CSI sequence
            $next2 = fread($conn, 1);
            if ($next2 === false) {
                return chr(27);
            }

            // Check for sequences like ESC[3~
            if (ord($next2) >= ord('0') && ord($next2) <= ord('9')) {
                $tilde = fread($conn, 1);
                if ($tilde === '~') {
                    return chr(27) . '[' . $next2 . '~';
                }
            }

            return chr(27) . '[' . $next2;
        }

        // Not a CSI, push back
        $state['pushback'] = ($state['pushback'] ?? '') . $next1;
        return chr(27);
    }

    return chr($byte);
}

function fullScreenEditor($conn, array &$state, string $initialText = ''): string
{
    $rows = $state['rows'] ?? 24;
    $cols = $state['cols'] ?? 80;

    // Log screen dimensions in debug mode
    if (!empty($GLOBALS['telnet_debug'])) {
        echo "[" . date('Y-m-d H:i:s') . "] Editor: Screen size {$cols}x{$rows}\n";
    }

    // Clear screen and move to top
    safeWrite($conn, "\033[2J\033[H");

    $width = min($cols - 2, 70);
    $separator = str_repeat('=', $width);

    writeLine($conn, colorize($separator, ANSI_CYAN . ANSI_BOLD));
    writeLine($conn, colorize('MESSAGE EDITOR - FULL SCREEN MODE', ANSI_CYAN . ANSI_BOLD));
    writeLine($conn, colorize($separator, ANSI_CYAN . ANSI_BOLD));
    writeLine($conn, colorize('Commands:', ANSI_YELLOW . ANSI_BOLD));
    writeLine($conn, colorize('  Arrow Keys = Navigate cursor up/down/left/right', ANSI_YELLOW));
    writeLine($conn, colorize('  Backspace/Delete = Edit text', ANSI_YELLOW));
    writeLine($conn, colorize('  Ctrl+Y = Delete entire line', ANSI_YELLOW));
    writeLine($conn, colorize('  Ctrl+Z = Save message and send', ANSI_GREEN));
    writeLine($conn, colorize('  Ctrl+C = Cancel and discard message', ANSI_RED));
    writeLine($conn, colorize($separator, ANSI_CYAN . ANSI_BOLD));
    writeLine($conn, '');

    // Initialize lines with initial text (for quoting)
    if ($initialText !== '') {
        $lines = explode("\n", $initialText);
        if (empty($lines)) {
            $lines = [''];
        }
    } else {
        $lines = [''];
    }

    $cursorRow = count($lines) - 1;
    $cursorCol = strlen($lines[$cursorRow]);
    $startRow = 11; // Starting row on terminal (after header - 10 lines)
    $maxRows = max(10, $rows - $startRow - 2); // Leave room for footer

    setEcho($conn, $state, false);

    while (true) {
        // Display current text
        safeWrite($conn, "\033[" . $startRow . ";1H"); // Move to start row
        safeWrite($conn, "\033[J"); // Clear from cursor to end of screen

        $displayLines = array_slice($lines, 0, $maxRows);
        foreach ($displayLines as $idx => $line) {
            safeWrite($conn, "\033[" . ($startRow + $idx) . ";1H");
            safeWrite($conn, substr($line, 0, $cols - 1));
        }

        // Position cursor
        $displayRow = $startRow + $cursorRow;
        $displayCol = $cursorCol + 1;
        safeWrite($conn, "\033[{$displayRow};{$displayCol}H");

        // Read character
        $char = readRawChar($conn, $state);
        if ($char === null) {
            setEcho($conn, $state, true);
            return '';
        }

        $ord = ord($char[0]);

        // Check for Ctrl+Z (SUB) - Save and send
        if ($ord === 26) {
            break;
        }

        // Check for Ctrl+C (ETX) - Cancel
        if ($ord === 3) {
            setEcho($conn, $state, true);
            writeLine($conn, '');
            writeLine($conn, colorize('Message cancelled.', ANSI_RED));
            return '';
        }

        // Check for Ctrl+Y (EM) - Delete line
        if ($ord === 25) {
            if (count($lines) > 1) {
                // Remove current line
                array_splice($lines, $cursorRow, 1);
                // Adjust cursor position
                if ($cursorRow >= count($lines)) {
                    $cursorRow = count($lines) - 1;
                }
                $cursorCol = min($cursorCol, strlen($lines[$cursorRow]));
            } else {
                // Only one line, just clear it
                $lines[0] = '';
                $cursorCol = 0;
            }
            continue;
        }

        // Handle arrow keys
        if ($char === KEY_UP) {
            if ($cursorRow > 0) {
                $cursorRow--;
                $cursorCol = min($cursorCol, strlen($lines[$cursorRow]));
            }
            continue;
        }

        if ($char === KEY_DOWN) {
            if ($cursorRow < count($lines) - 1) {
                $cursorRow++;
                $cursorCol = min($cursorCol, strlen($lines[$cursorRow]));
            }
            continue;
        }

        if ($char === KEY_LEFT) {
            if ($cursorCol > 0) {
                $cursorCol--;
            } elseif ($cursorRow > 0) {
                $cursorRow--;
                $cursorCol = strlen($lines[$cursorRow]);
            }
            continue;
        }

        if ($char === KEY_RIGHT) {
            if ($cursorCol < strlen($lines[$cursorRow])) {
                $cursorCol++;
            } elseif ($cursorRow < count($lines) - 1) {
                $cursorRow++;
                $cursorCol = 0;
            }
            continue;
        }

        if ($char === KEY_HOME) {
            $cursorCol = 0;
            continue;
        }

        if ($char === KEY_END) {
            $cursorCol = strlen($lines[$cursorRow]);
            continue;
        }

        // Handle Enter (CR or LF)
        if ($ord === 13 || $ord === 10) {
            // If we got CR, check for and consume following LF
            if ($ord === 13) {
                $nextChar = readRawChar($conn, $state);
                if ($nextChar !== null && ord($nextChar[0]) !== 10) {
                    // Not LF, push it back
                    $state['pushback'] = ($state['pushback'] ?? '') . $nextChar;
                }
                // If it was LF, we just consumed it (don't push back)
            }

            $currentLine = $lines[$cursorRow];
            $beforeCursor = substr($currentLine, 0, $cursorCol);
            $afterCursor = substr($currentLine, $cursorCol);

            $lines[$cursorRow] = $beforeCursor;
            array_splice($lines, $cursorRow + 1, 0, [$afterCursor]);

            $cursorRow++;
            $cursorCol = 0;

            if (count($lines) > $maxRows) {
                // Limit lines
            }
            continue;
        }

        // Handle Backspace
        if ($ord === 8 || $ord === 127) {
            if ($cursorCol > 0) {
                $lines[$cursorRow] = substr($lines[$cursorRow], 0, $cursorCol - 1) .
                                      substr($lines[$cursorRow], $cursorCol);
                $cursorCol--;
            } elseif ($cursorRow > 0) {
                // Join with previous line
                $prevLine = $lines[$cursorRow - 1];
                $cursorCol = strlen($prevLine);
                $lines[$cursorRow - 1] = $prevLine . $lines[$cursorRow];
                array_splice($lines, $cursorRow, 1);
                $cursorRow--;
            }
            continue;
        }

        // Handle Delete
        if ($char === KEY_DELETE) {
            if ($cursorCol < strlen($lines[$cursorRow])) {
                $lines[$cursorRow] = substr($lines[$cursorRow], 0, $cursorCol) .
                                      substr($lines[$cursorRow], $cursorCol + 1);
            } elseif ($cursorRow < count($lines) - 1) {
                // Join with next line
                $lines[$cursorRow] .= $lines[$cursorRow + 1];
                array_splice($lines, $cursorRow + 1, 1);
            }
            continue;
        }

        // Regular character input
        if ($ord >= 32 && $ord < 127) {
            $lines[$cursorRow] = substr($lines[$cursorRow], 0, $cursorCol) .
                                 $char .
                                 substr($lines[$cursorRow], $cursorCol);
            $cursorCol++;
        }
    }

    setEcho($conn, $state, true);
    safeWrite($conn, "\033[" . ($startRow + $maxRows + 1) . ";1H");
    writeLine($conn, '');
    writeLine($conn, colorize('Message saved and ready to send.', ANSI_GREEN));
    writeLine($conn, '');

    // Remove trailing empty lines
    while (count($lines) > 0 && trim($lines[count($lines) - 1]) === '') {
        array_pop($lines);
    }

    return implode("\n", $lines);
}

function readMultiline($conn, array &$state, int $cols, string $initialText = ''): string
{
    // Use full-screen editor if terminal supports it
    if (($state['rows'] ?? 0) >= 15) {
        return fullScreenEditor($conn, $state, $initialText);
    }

    // Fallback to line-by-line editor
    if ($initialText !== '') {
        writeLine($conn, 'Starting with quoted text. Enter your reply below.');
        writeLine($conn, '');
        // Display the initial text
        $quotedLines = explode("\n", $initialText);
        foreach ($quotedLines as $line) {
            writeLine($conn, $line);
        }
        writeLine($conn, '');
    }

    writeLine($conn, 'Enter message text. End with a single "." line. Type "/abort" to cancel.');
    $lines = [];

    // Add initial text to lines if provided
    if ($initialText !== '') {
        $lines = explode("\n", $initialText);
    }

    while (true) {
        safeWrite($conn, '> ');
        $line = readTelnetLine($conn, $state);
        if ($line === null) {
            break;
        }
        if (trim($line) === '/abort') {
            return '';
        }
        if (trim($line) === '.') {
            break;
        }
        $lines[] = $line;
    }
    $text = implode("\n", $lines);
    if ($text === '') {
        return '';
    }
    return $text;
}

function sendMessage(string $apiBase, string $session, array $payload): array
{
    $result = apiRequest($apiBase, 'POST', '/api/messages/send', $payload, $session);
    $success = ($result['status'] ?? 0) === 200 && !empty($result['data']['success']);
    $error = null;

    if (!$success) {
        // Try to get error message from API response
        if (!empty($result['data']['error'])) {
            $error = $result['data']['error'];
        } elseif (!empty($result['data']['message'])) {
            $error = $result['data']['message'];
        } elseif (!empty($result['error'])) {
            $error = 'Network error: ' . $result['error'];
        } else {
            $error = 'HTTP ' . ($result['status'] ?? 'unknown');
        }
    }

    return ['success' => $success, 'error' => $error];
}

function normalizeSubject(string $subject): string
{
    return preg_replace('/^Re:\\s*/i', '', trim($subject));
}

function quoteMessage(string $body, string $author): string
{
    $lines = explode("\n", $body);
    $quoted = [];
    $quoted[] = '';
    $quoted[] = "On " . date('Y-m-d') . ", {$author} wrote:";
    $quoted[] = '';
    foreach ($lines as $line) {
        $quoted[] = '> ' . $line;
    }
    $quoted[] = '';
    $quoted[] = '';
    return implode("\n", $quoted);
}

function composeNetmail($conn, array &$state, string $apiBase, string $session, ?array $reply = null): void
{
    writeLine($conn, '');
    writeLine($conn, colorize('=== Compose Netmail ===', ANSI_CYAN . ANSI_BOLD));
    writeLine($conn, '');

    $toNameDefault = $reply['replyto_name'] ?? $reply['from_name'] ?? '';
    $toAddressDefault = $reply['replyto_address'] ?? $reply['from_address'] ?? '';
    $subjectDefault = $reply ? 'Re: ' . normalizeSubject((string)($reply['subject'] ?? '')) : '';

    $toNamePrompt = colorize('To Name: ', ANSI_CYAN);
    if ($toNameDefault) {
        $toNamePrompt .= colorize("[{$toNameDefault}] ", ANSI_YELLOW);
    }
    $toName = prompt($conn, $state, $toNamePrompt, true);
    if ($toName === null) {
        return;
    }
    if ($toName === '' && $toNameDefault !== '') {
        $toName = $toNameDefault;
    }

    $toAddressPrompt = colorize('To Address: ', ANSI_CYAN);
    if ($toAddressDefault) {
        $toAddressPrompt .= colorize("[{$toAddressDefault}] ", ANSI_YELLOW);
    }
    $toAddress = prompt($conn, $state, $toAddressPrompt, true);
    if ($toAddress === null) {
        return;
    }
    if ($toAddress === '' && $toAddressDefault !== '') {
        $toAddress = $toAddressDefault;
    }

    $subjectPrompt = colorize('Subject: ', ANSI_CYAN);
    if ($subjectDefault) {
        $subjectPrompt .= colorize("[{$subjectDefault}] ", ANSI_YELLOW);
    }
    $subject = prompt($conn, $state, $subjectPrompt, true);
    if ($subject === null) {
        return;
    }
    if ($subject === '' && $subjectDefault !== '') {
        $subject = $subjectDefault;
    }

    writeLine($conn, '');
    writeLine($conn, colorize('Enter your message below:', ANSI_GREEN));

    $cols = $state['cols'] ?? 80;

    // If replying, quote the original message
    $initialText = '';
    if ($reply) {
        $originalBody = $reply['message_text'] ?? '';
        $originalAuthor = $reply['from_name'] ?? 'Unknown';
        if ($originalBody !== '') {
            $initialText = quoteMessage($originalBody, $originalAuthor);
        }
    }

    $messageText = readMultiline($conn, $state, $cols, $initialText);
    if ($messageText === '') {
        writeLine($conn, '');
        writeLine($conn, colorize('Message cancelled (empty).', ANSI_YELLOW));
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

    writeLine($conn, '');
    writeLine($conn, colorize('Sending netmail...', ANSI_CYAN));
    $result = sendMessage($apiBase, $session, $payload);
    if ($result['success']) {
        writeLine($conn, colorize('✓ Netmail sent successfully!', ANSI_GREEN . ANSI_BOLD));
    } else {
        writeLine($conn, colorize('✗ Failed to send netmail: ' . ($result['error'] ?? 'Unknown error'), ANSI_RED));
    }
    writeLine($conn, '');
}

function composeEchomail($conn, array &$state, string $apiBase, string $session, string $area, ?array $reply = null): void
{
    writeLine($conn, '');
    writeLine($conn, colorize('=== Compose Echomail ===', ANSI_CYAN . ANSI_BOLD));
    writeLine($conn, colorize('Area: ' . $area, ANSI_MAGENTA));
    writeLine($conn, '');

    $toNameDefault = $reply['from_name'] ?? 'All';
    $subjectDefault = $reply ? 'Re: ' . normalizeSubject((string)($reply['subject'] ?? '')) : '';

    $toNamePrompt = colorize('To Name: ', ANSI_CYAN);
    if ($toNameDefault) {
        $toNamePrompt .= colorize("[{$toNameDefault}] ", ANSI_YELLOW);
    }
    $toName = prompt($conn, $state, $toNamePrompt, true);
    if ($toName === null) {
        return;
    }
    if ($toName === '' && $toNameDefault !== '') {
        $toName = $toNameDefault;
    }

    $subjectPrompt = colorize('Subject: ', ANSI_CYAN);
    if ($subjectDefault) {
        $subjectPrompt .= colorize("[{$subjectDefault}] ", ANSI_YELLOW);
    }
    $subject = prompt($conn, $state, $subjectPrompt, true);
    if ($subject === null) {
        return;
    }
    if ($subject === '' && $subjectDefault !== '') {
        $subject = $subjectDefault;
    }

    writeLine($conn, '');
    writeLine($conn, colorize('Enter your message below:', ANSI_GREEN));

    $cols = $state['cols'] ?? 80;

    // If replying, quote the original message
    $initialText = '';
    if ($reply) {
        $originalBody = $reply['message_text'] ?? '';
        $originalAuthor = $reply['from_name'] ?? 'Unknown';
        if ($originalBody !== '') {
            $initialText = quoteMessage($originalBody, $originalAuthor);
        }
    }

    $messageText = readMultiline($conn, $state, $cols, $initialText);
    if ($messageText === '') {
        writeLine($conn, '');
        writeLine($conn, colorize('Message cancelled (empty).', ANSI_YELLOW));
        return;
    }

    $payload = [
        'type' => 'echomail',
        'echoarea' => $area,
        'to_name' => $toName,
        'subject' => $subject,
        'message_text' => $messageText
    ];
    if (!empty($reply['id'])) {
        $payload['reply_to_id'] = $reply['id'];
    }

    writeLine($conn, '');
    writeLine($conn, colorize('Posting echomail...', ANSI_CYAN));
    $result = sendMessage($apiBase, $session, $payload);
    if ($result['success']) {
        writeLine($conn, colorize('✓ Echomail posted successfully!', ANSI_GREEN . ANSI_BOLD));
    } else {
        writeLine($conn, colorize('✗ Failed to post echomail: ' . ($result['error'] ?? 'Unknown error'), ANSI_RED));
    }
    writeLine($conn, '');
}

function apiRequest(string $base, string $method, string $path, ?array $payload, ?string $session, int $maxRetries = 3): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP curl extension is required for telnet API access.');
    }

    $url = rtrim($base, '/') . $path;
    $attempt = 0;

    while ($attempt <= $maxRetries) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HEADER, false);

        $headers = ['Accept: application/json'];
        if ($payload !== null) {
            $json = json_encode($payload);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
        }
        if ($session) {
            curl_setopt($ch, CURLOPT_COOKIE, 'binktermphp_session=' . $session);
        }

        $cookie = null;
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$cookie) {
            $prefix = 'Set-Cookie: binktermphp_session=';
            if (stripos($header, $prefix) === 0) {
                $value = trim(substr($header, strlen($prefix)));
                $cookie = strtok($value, ';');
            }
            return strlen($header);
        });

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if (!empty($GLOBALS['telnet_api_insecure'])) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        $data = null;
        if (is_string($response) && $response !== '') {
            $data = json_decode($response, true);
        }

        // Check if we should retry
        $shouldRetry = false;
        if ($curlErrno !== 0) {
            // Network errors - retry
            $shouldRetry = true;
        } elseif ($status >= 500 && $status < 600) {
            // Server errors - retry
            $shouldRetry = true;
        } elseif ($status === 0) {
            // No response - retry
            $shouldRetry = true;
        }

        // If successful or non-retryable error, return result
        if (!$shouldRetry || $attempt >= $maxRetries) {
            if ($attempt > 0 && !empty($GLOBALS['telnet_debug'])) {
                echo "[" . date('Y-m-d H:i:s') . "] API request to {$path} succeeded after " . ($attempt + 1) . " attempts\n";
            }
            return [
                'status' => $status,
                'data' => $data,
                'cookie' => $cookie,
                'error' => $curlError ?: null,
                'errno' => $curlErrno ?: null,
                'url' => $url,
                'attempts' => $attempt + 1
            ];
        }

        // Log retry attempt
        if (!empty($GLOBALS['telnet_debug'])) {
            $reason = $curlError ?: "HTTP {$status}";
            echo "[" . date('Y-m-d H:i:s') . "] API request to {$path} failed ({$reason}), retrying (attempt " . ($attempt + 2) . "/" . ($maxRetries + 1) . ")...\n";
        }

        // Exponential backoff: 0.5s, 1s, 2s
        $delay = (int)(0.5 * pow(2, $attempt) * 1000000); // microseconds
        usleep($delay);
        $attempt++;
    }

    // Should never reach here, but just in case
    return [
        'status' => 0,
        'data' => null,
        'cookie' => null,
        'error' => 'Max retries exceeded',
        'errno' => 0,
        'url' => $url,
        'attempts' => $maxRetries + 1
    ];
}

function prompt($conn, array &$state, string $label, bool $echo = true): ?string
{
    setEcho($conn, $state, $echo);
    safeWrite($conn, $label);

    if ($echo) {
        $value = readTelnetLine($conn, $state);
        return $value;
    }

    $value = readTelnetLine($conn, $state);
    setEcho($conn, $state, true);
    return $value;
}

function setTerminalTitle($conn, string $title): void
{
    // ANSI escape sequence to set terminal window title
    // \033]0; sets both icon and window title
    // \007 is the BEL terminator
    safeWrite($conn, "\033]0;{$title}\007");
}

function showLoginBanner($conn): void
{
    $loginAnsiPath = __DIR__ . '/screens/login.ans';
    if (is_file($loginAnsiPath)) {
        $content = @file_get_contents($loginAnsiPath);
        if ($content !== false && $content !== '') {
            $content = str_replace("\r\n", "\n", $content);
            $content = str_replace("\n", "\r\n", $content);
            safeWrite($conn, $content . "\r\n");
            return;
        }
    }

    $config = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
    $siteUrl = '';
    try {
        $siteUrl = Config::getSiteUrl();
    } catch (\Exception $e) {
        $siteUrl = '';
    }

    $rawLines = [
        ['text' => 'BinktermPHP ' . Version::getVersion() . ' Telnet', 'color' => ANSI_MAGENTA . ANSI_BOLD, 'center' => true],
        ['text' => '', 'color' => ANSI_DIM, 'center' => false],
        ['text' => 'System: ' . $config->getSystemName(), 'color' => ANSI_CYAN, 'center' => false],
        ['text' => 'Location: ' . $config->getSystemLocation(), 'color' => ANSI_DIM, 'center' => false],
        ['text' => 'Origin: ' . $config->getSystemOrigin(), 'color' => ANSI_DIM, 'center' => false],
    ];
    if ($siteUrl !== '') {
        $rawLines[] = ['text' => '', 'color' => ANSI_DIM, 'center' => false];
        $rawLines[] = ['text' => 'Web: ' . $siteUrl, 'color' => ANSI_YELLOW, 'center' => false];
    }

    $maxLen = 0;
    foreach ($rawLines as $entry) {
        $maxLen = max($maxLen, strlen($entry['text']));
    }
    $frameWidth = max(48, min(90, $maxLen + 6));
    $innerWidth = $frameWidth - 4;
    $border = '+' . str_repeat('-', $frameWidth - 2) . '+';

    writeLine($conn, '');
    writeLine($conn, colorize($border, ANSI_MAGENTA));

    foreach ($rawLines as $entry) {
        $text = $entry['text'];
        $wrapped = wordwrap($text, $innerWidth, "\n", true);
        foreach (explode("\n", $wrapped) as $part) {
            $padded = $entry['center']
                ? str_pad($part, $innerWidth, ' ', STR_PAD_BOTH)
                : str_pad($part, $innerWidth, ' ', STR_PAD_RIGHT);
            $content = '| ' . $padded . ' |';
            writeLine($conn, colorize($content, $entry['color']));
        }
    }

    writeLine($conn, colorize($border, ANSI_MAGENTA));
    writeLine($conn, '');

    if ($siteUrl !== '') {

        writeLine($conn, colorize('  For a good time visit us on the web @ ' . $siteUrl, ANSI_YELLOW));
        writeLine($conn, '');
    }
}

function attemptLogin($conn, array &$state, string $apiBase, bool $debug): ?array
{
    $username = prompt($conn, $state, 'Username: ', true);
    if ($username === null) {
        return null;
    }
    $password = prompt($conn, $state, 'Password: ', false);
    if ($password === null) {
        return null;
    }
    writeLine($conn, '');

    if ($debug) {
        writeLine($conn, "[DEBUG] username={$username}");
    }

    try {
        $result = apiRequest($apiBase, 'POST', '/api/auth/login', [
            'username' => $username,
            'password' => $password
        ], null);
    } catch (Throwable $e) {
        writeLine($conn, colorize('Login failed: ' . $e->getMessage(), ANSI_RED));
        return null;
    }

    if ($debug) {
        $status = $result['status'] ?? 0;
        $body = json_encode($result['data']);
        writeLine($conn, "[DEBUG] login status={$status} body={$body}");
        writeLine($conn, "[DEBUG] session=" . ($result['cookie'] ?: ''));
        if (!empty($result['error'])) {
            writeLine($conn, "[DEBUG] curl_error=" . $result['error']);
        }
        if (!empty($result['errno'])) {
            writeLine($conn, "[DEBUG] curl_errno=" . $result['errno']);
        }
        if (!empty($result['url'])) {
            writeLine($conn, "[DEBUG] url=" . $result['url']);
        }
    }

    if ($result['status'] !== 200 || empty($result['cookie'])) {
        return null;
    }

    return ['session' => $result['cookie'], 'username' => $username];
}

function getMessageCounts(string $apiBase, string $session): array
{
    $counts = ['netmail' => 0, 'echomail' => 0];

    // Try to get netmail count
    $netmailResponse = apiRequest($apiBase, 'GET', '/api/messages/netmail?page=1', null, $session);
    if (!empty($netmailResponse['data']['pagination']['total'])) {
        $counts['netmail'] = (int)$netmailResponse['data']['pagination']['total'];
    }

    // Try to get total echomail from all subscribed areas
    $areasResponse = apiRequest($apiBase, 'GET', '/api/echoareas?subscribed_only=true', null, $session);
    $areas = $areasResponse['data']['echoareas'] ?? [];
    $totalEcho = 0;
    foreach ($areas as $area) {
        $totalEcho += (int)($area['message_count'] ?? 0);
    }
    $counts['echomail'] = $totalEcho;

    return $counts;
}

function showShoutbox($conn, array &$state, string $apiBase, string $session, int $limit = 5): void
{
    if (!\BinktermPHP\BbsConfig::isFeatureEnabled('shoutbox')) {
        return;
    }
    $response = apiRequest($apiBase, 'GET', '/api/shoutbox?limit=' . $limit, null, $session);
    $messages = $response['data']['messages'] ?? [];
    $cols = (int)($state['cols'] ?? 80);
    $frameWidth = max(40, min($cols, 80));
    $innerWidth = $frameWidth - 4;
    $title = 'Recent Shoutbox';

    $lines = [];
    if (!$messages) {
        $lines[] = 'No shoutbox messages.';
    } else {
        foreach ($messages as $msg) {
            $user = $msg['username'] ?? 'Unknown';
            $text = $msg['message'] ?? '';
            $date = $msg['created_at'] ?? '';
            $lines[] = sprintf('[%s] %s: %s', $date, $user, $text);
        }
    }

    $borderTop = '+' . str_repeat('-', $frameWidth - 2) . '+';
    $borderMid = '|' . str_repeat(' ', $frameWidth - 2) . '|';
    $titleLine = '| ' . str_pad($title, $innerWidth, ' ', STR_PAD_BOTH) . ' |';

    writeLine($conn, '');
    writeLine($conn, colorize($borderTop, ANSI_MAGENTA));
    writeLine($conn, colorize($titleLine, ANSI_MAGENTA));
    writeLine($conn, colorize($borderMid, ANSI_MAGENTA));

    foreach ($lines as $line) {
        $wrapped = wordwrap($line, $innerWidth, "\n", true);
        foreach (explode("\n", $wrapped) as $part) {
            $contentLine = '| ' . str_pad($part, $innerWidth, ' ', STR_PAD_RIGHT) . ' |';
            writeLine($conn, colorize($contentLine, ANSI_MAGENTA));
        }
    }

    writeLine($conn, colorize($borderTop, ANSI_MAGENTA));
}

function showPolls($conn, array &$state, string $apiBase, string $session): void
{
    if (!\BinktermPHP\BbsConfig::isFeatureEnabled('voting_booth')) {
        writeLine($conn, 'Voting booth is disabled.');
        return;
    }
    $response = apiRequest($apiBase, 'GET', '/api/polls/active', null, $session);
    $polls = $response['data']['polls'] ?? [];
    if (!$polls) {
        writeLine($conn, 'No active polls.');
        return;
    }
    writeLine($conn, '');
    writeLine($conn, 'Active Polls');
    foreach ($polls as $poll) {
        $question = $poll['question'] ?? '';
        writeLine($conn, '');
        writeLine($conn, 'Q: ' . $question);
        $options = $poll['options'] ?? [];
        foreach ($options as $idx => $opt) {
            $num = $idx + 1;
            $text = $opt['option_text'] ?? '';
            writeLine($conn, "  {$num}) {$text}");
        }
        if (!empty($poll['has_voted']) && !empty($poll['results'])) {
            writeLine($conn, 'Results:');
            $total = (int)($poll['total_votes'] ?? 0);
            foreach ($poll['results'] as $result) {
                $text = $result['option_text'] ?? '';
                $votes = (int)($result['votes'] ?? 0);
                writeLine($conn, sprintf('  %s - %d', $text, $votes));
            }
            writeLine($conn, 'Total votes: ' . $total);
        }
    }
    writeLine($conn, '');
    writeLine($conn, 'Press Enter to return.');
    readTelnetLine($conn, $state);
}

function getMessagesPerPage(array &$state): int
{
    $rows = $state['rows'] ?? 24;
    // Be very conservative: header (1), messages (N), blank (1), prompt (1-2), input (1), safety (2) = N + 7
    // So N = rows - 7
    $perPage = max(5, $rows - 7);

    // Log in debug mode
    if (!empty($GLOBALS['telnet_debug'])) {
        echo "[" . date('Y-m-d H:i:s') . "] List view: Screen rows={$rows}, messages per page={$perPage}\n";
    }

    return $perPage;
}

function showNetmail($conn, array &$state, string $apiBase, string $session): void
{
    $page = 1;
    $perPage = getMessagesPerPage($state);

    while (true) {
        $response = apiRequest($apiBase, 'GET', '/api/messages/netmail?page=' . $page . '&per_page=' . $perPage, null, $session);
        $allMessages = $response['data']['messages'] ?? [];
        $pagination = $response['data']['pagination'] ?? [];
        $totalPages = $pagination['pages'] ?? 1;

        if (!$allMessages) {
            writeLine($conn, 'No netmail messages.');
            return;
        }

        // Force limit to perPage in case API returns more
        $messages = array_slice($allMessages, 0, $perPage);

        // Clear screen before displaying
        safeWrite($conn, "\033[2J\033[H");

        writeLine($conn, colorize("Netmail (page {$page}/{$totalPages}):", ANSI_CYAN . ANSI_BOLD));
        foreach ($messages as $idx => $msg) {
            $num = $idx + 1;
            $from = $msg['from_name'] ?? 'Unknown';
            $subject = $msg['subject'] ?? '(no subject)';
            $date = $msg['date_written'] ?? '';
            $dateShort = substr($date, 0, 10);
            writeLine($conn, sprintf(' %2d) %-20s %-35s %s', $num, substr($from, 0, 20), substr($subject, 0, 35), $dateShort));
        }
        writeLine($conn, '');
        writeLine($conn, 'Enter #, n/p (next/prev), c (compose), q (quit)');
        $input = trim((string)readTelnetLine($conn, $state));
        if ($input === 'q' || $input === '') {
            return;
        }
        if ($input === 'c') {
            composeNetmail($conn, $state, $apiBase, $session, null);
            continue;
        }
        if ($input === 'n') {
            if ($page < $totalPages) {
                $page++;
            }
            continue;
        }
        if ($input === 'p' && $page > 1) {
            $page--;
            continue;
        }
        $choice = (int)$input;
        if ($choice > 0 && $choice <= count($messages)) {
            $msg = $messages[$choice - 1];
            $id = $msg['id'] ?? null;
            if ($id) {
                $detail = apiRequest($apiBase, 'GET', '/api/messages/netmail/' . $id, null, $session);
                $body = $detail['data']['message_text'] ?? '';
                $cols = $state['cols'] ?? 80;
                writeLine($conn, '');
                writeLine($conn, colorize($msg['subject'] ?? 'Message', ANSI_BOLD));
                writeLine($conn, colorize('From: ' . ($msg['from_name'] ?? 'Unknown'), ANSI_DIM));
                writeLine($conn, str_repeat('-', min(78, $cols)));
                writeWrapped($conn, $body, $cols);
                writeLine($conn, '');
                writeLine($conn, 'Press Enter to return, r to reply.');
                $action = trim((string)readTelnetLine($conn, $state));
                if (strtolower($action) === 'r') {
                    $replyData = $detail['data'] ?? $msg;
                    composeNetmail($conn, $state, $apiBase, $session, $replyData);
                }
            }
        }
    }
}

function showEchoareas($conn, array &$state, string $apiBase, string $session): void
{
    $page = 1;
    $perPage = getMessagesPerPage($state);

    while (true) {
        $response = apiRequest($apiBase, 'GET', '/api/echoareas?subscribed_only=true', null, $session);
        $allAreas = $response['data']['echoareas'] ?? [];

        if (!$allAreas) {
            writeLine($conn, 'No echoareas available.');
            return;
        }

        $totalPages = (int)ceil(count($allAreas) / $perPage);
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        $areas = array_slice($allAreas, $offset, $perPage);

        // Clear screen before displaying
        safeWrite($conn, "\033[2J\033[H");

        writeLine($conn, colorize("Echoareas (page {$page}/{$totalPages}):", ANSI_CYAN . ANSI_BOLD));
        foreach ($areas as $idx => $area) {
            $num = $idx + 1;
            $tag = $area['tag'] ?? '';
            $domain = $area['domain'] ?? '';
            $desc = $area['description'] ?? '';
            writeLine($conn, sprintf(' %2d) %-20s %-10s %s', $num, substr($tag, 0, 20), substr($domain, 0, 10), substr($desc, 0, 40)));
        }
        writeLine($conn, '');
        writeLine($conn, 'Enter #, n/p (next/prev), q (quit)');
        $input = trim((string)readTelnetLine($conn, $state));

        if ($input === 'q' || $input === '') {
            return;
        }

        if ($input === 'n') {
            if ($page < $totalPages) {
                $page++;
            }
            continue;
        }

        if ($input === 'p' && $page > 1) {
            $page--;
            continue;
        }

        $choice = (int)$input;
        if ($choice > 0 && $choice <= count($areas)) {
            $area = $areas[$choice - 1];
            $tag = $area['tag'] ?? '';
            $domain = $area['domain'] ?? '';
            showEchomail($conn, $state, $apiBase, $session, $tag, $domain);
        }
    }
}

function showEchomail($conn, array &$state, string $apiBase, string $session, string $tag, string $domain): void
{
    $page = 1;
    $area = $tag . '@' . $domain;
    $perPage = getMessagesPerPage($state);

    while (true) {
        $response = apiRequest($apiBase, 'GET', '/api/messages/echomail/' . urlencode($area) . '?page=' . $page . '&per_page=' . $perPage, null, $session);
        $allMessages = $response['data']['messages'] ?? [];
        $pagination = $response['data']['pagination'] ?? [];
        $totalPages = $pagination['pages'] ?? 1;

        if (!$allMessages) {
            writeLine($conn, 'No echomail messages.');
            return;
        }

        // Force limit to perPage in case API returns more
        $messages = array_slice($allMessages, 0, $perPage);

        // Clear screen before displaying
        safeWrite($conn, "\033[2J\033[H");

        writeLine($conn, colorize("Echomail: {$area} (page {$page}/{$totalPages})", ANSI_CYAN . ANSI_BOLD));
        foreach ($messages as $idx => $msg) {
            $num = $idx + 1;
            $from = $msg['from_name'] ?? 'Unknown';
            $subject = $msg['subject'] ?? '(no subject)';
            $date = $msg['date_written'] ?? '';
            $dateShort = substr($date, 0, 10);
            writeLine($conn, sprintf(' %2d) %-20s %-35s %s', $num, substr($from, 0, 20), substr($subject, 0, 35), $dateShort));
        }
        writeLine($conn, '');
        writeLine($conn, 'Enter #, n/p (next/prev), c (compose), q (quit)');
        $input = trim((string)readTelnetLine($conn, $state));
        if ($input === 'q' || $input === '') {
            return;
        }
        if ($input === 'c') {
            composeEchomail($conn, $state, $apiBase, $session, $area, null);
            continue;
        }
        if ($input === 'n') {
            if ($page < $totalPages) {
                $page++;
            }
            continue;
        }
        if ($input === 'p' && $page > 1) {
            $page--;
            continue;
        }
        $choice = (int)$input;
        if ($choice > 0 && $choice <= count($messages)) {
            $msg = $messages[$choice - 1];
            $id = $msg['id'] ?? null;
            if ($id) {
                $detail = apiRequest($apiBase, 'GET', '/api/messages/echomail/' . urlencode($area) . '/' . $id, null, $session);
                $body = $detail['data']['message_text'] ?? '';
                $cols = $state['cols'] ?? 80;
                writeLine($conn, '');
                writeLine($conn, colorize($msg['subject'] ?? 'Message', ANSI_BOLD));
                writeLine($conn, colorize('From: ' . ($msg['from_name'] ?? 'Unknown') . ' to ' . ($msg['to_name'] ?? 'All'), ANSI_DIM));
                writeLine($conn, str_repeat('-', min(78, $cols)));
                writeWrapped($conn, $body, $cols);
                writeLine($conn, '');
                writeLine($conn, 'Press Enter to return, r to reply.');
                $action = trim((string)readTelnetLine($conn, $state));
                if (strtolower($action) === 'r') {
                    $replyData = $detail['data'] ?? $msg;
                    composeEchomail($conn, $state, $apiBase, $session, $area, $replyData);
                }
            }
        }
    }
}

$args = parseArgs($argv);

if (!empty($args['help'])) {
    echo "Usage: php scripts/telnet_daemon.php [options]\n";
    echo "  --host=ADDR       Bind address (default: 0.0.0.0)\n";
    echo "  --port=PORT       Bind port (default: 2323)\n";
    echo "  --api-base=URL    API base URL (default: SITE_URL or http://127.0.0.1)\n";
    echo "  --debug           Enable debug mode with verbose logging\n";
    echo "  --insecure        Disable SSL certificate verification\n";
    exit(0);
}

$host = $args['host'] ?? '0.0.0.0';
$port = (int)($args['port'] ?? 2323);
$apiBase = buildApiBase($args);
$debug = !empty($args['debug']);
$GLOBALS['telnet_debug'] = $debug;
$GLOBALS['telnet_api_insecure'] = !empty($args['insecure']);

// Set up signal handling for process cleanup
if (function_exists('pcntl_signal')) {
    // Handle SIGCHLD to reap zombie processes
    pcntl_signal(SIGCHLD, function($signo) {
        while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            // Reap all finished child processes
        }
    });

    // Handle SIGTERM and SIGINT for graceful shutdown
    $gracefulShutdown = function($signo) use (&$server) {
        echo "\nReceived shutdown signal, cleaning up...\n";
        if (is_resource($server)) {
            fclose($server);
        }
        exit(0);
    };
    pcntl_signal(SIGTERM, $gracefulShutdown);
    pcntl_signal(SIGINT, $gracefulShutdown);

    // Enable async signal handling
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
    }
}

$server = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "Failed to bind telnet server: {$errstr} ({$errno})\n");
    exit(1);
}

echo "Telnet daemon listening on {$host}:{$port}\n";
if($debug){
    echo " DEBUG MODE\n";
    echo " API Base URL: {$apiBase}\n";
}

$connectionCount = 0;

while (true) {
    // Dispatch signals if async signals not available
    if (function_exists('pcntl_signal_dispatch') && !function_exists('pcntl_async_signals')) {
        pcntl_signal_dispatch();
    }

    $conn = @stream_socket_accept($server, 60);
    if (!$conn) {
        // Timeout or error, reap zombies and continue
        if (function_exists('pcntl_waitpid')) {
            while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
                // Reap zombie processes
            }
        }
        continue;
    }

    $connectionCount++;
    if ($debug) {
        $peerName = @stream_socket_get_name($conn, true);
        echo "[" . date('Y-m-d H:i:s') . "] Connection #{$connectionCount} from {$peerName}\n";
    }

    $forked = false;
    if (function_exists('pcntl_fork')) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            // Fork failed
            $forked = false;
            if ($debug) {
                echo "[" . date('Y-m-d H:i:s') . "] WARNING: Fork failed, handling connection in main process\n";
            }
        } elseif ($pid === 0) {
            // Child process
            fclose($server);
            $forked = true;
        } else {
            // Parent process
            fclose($conn);
            // Reap any finished children (non-blocking)
            while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
                // Zombie reaped
            }
            continue;
        }
    }

    stream_set_timeout($conn, 300);
    $state = [
        'telnet_mode' => null,
        'input_echo' => true,
        'cols' => 80,
        'rows' => 24
    ];

    if ($debug) {
        echo "[" . date('Y-m-d H:i:s') . "] Connection initialized: Default screen size 80x24\n";
    }

    negotiateTelnet($conn);

    // Get peer IP for rate limiting
    $peerName = @stream_socket_get_name($conn, true);
    $peerIp = $peerName ? explode(':', $peerName)[0] : 'unknown';

    // Check if IP is rate limited
    if (isRateLimited($peerIp)) {
        writeLine($conn, '');
        writeLine($conn, colorize('Too many failed login attempts. Please try again later.', ANSI_RED));
        writeLine($conn, '');
        echo "[" . date('Y-m-d H:i:s') . "] Rate limited connection from {$peerName}\n";
        fclose($conn);
        if ($forked) {
            exit(0);
        }
        continue;
    }

    // Show login banner
    showLoginBanner($conn);

    // Allow up to 3 login attempts
    $loginResult = null;
    $maxAttempts = 3;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $loginResult = attemptLogin($conn, $state, $apiBase, $debug);

        if ($loginResult !== null) {
            // Successful login
            writeLine($conn, colorize('Login successful.', ANSI_GREEN));
            writeLine($conn, '');
            break;
        }

        // Failed login
        recordFailedLogin($peerIp);
        echo "[" . date('Y-m-d H:i:s') . "] Failed login attempt from {$peerName} (attempt {$attempt}/{$maxAttempts})\n";

        if ($attempt < $maxAttempts) {
            $remaining = $maxAttempts - $attempt;
            writeLine($conn, colorize("Login failed. {$remaining} attempt(s) remaining.", ANSI_RED));
            writeLine($conn, '');
        } else {
            writeLine($conn, colorize('Login failed. Maximum attempts exceeded.', ANSI_RED));
            writeLine($conn, '');
        }
    }

    // If all attempts failed, disconnect
    if ($loginResult === null) {
        echo "[" . date('Y-m-d H:i:s') . "] Login failed (max attempts) from {$peerName}\n";
        fclose($conn);
        if ($forked) {
            exit(0);
        }
        continue;
    }

    $session = $loginResult['session'];
    $username = $loginResult['username'];
    $loginTime = time();

    // Clear failed login attempts for this IP on successful login
    clearFailedLogins($peerIp);

    // Log successful login to console
    echo "[" . date('Y-m-d H:i:s') . "] Login: {$username} from {$peerName}\n";

    // Set terminal window title to BBS name
    $config = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
    setTerminalTitle($conn, $config->getSystemName());

    showShoutbox($conn, $state, $apiBase, $session, 5);

    // Get message counts once per session
    $messageCounts = getMessageCounts($apiBase, $session);

    while (true) {
        // Check if connection is still alive
        if (!is_resource($conn) || feof($conn)) {
            $duration = time() - $loginTime;
            $minutes = floor($duration / 60);
            $seconds = $duration % 60;
            echo "[" . date('Y-m-d H:i:s') . "] Connection lost: {$username} (session duration: {$minutes}m {$seconds}s)\n";
            break;
        }

        writeLine($conn, '');
        writeLine($conn, colorize($config->getSystemName(), ANSI_CYAN . ANSI_BOLD));
        writeLine($conn, colorize('Main Menu', ANSI_BLUE . ANSI_BOLD));
        writeLine($conn, colorize(' 1) Netmail (' . $messageCounts['netmail'] . ' messages)', ANSI_GREEN));
        writeLine($conn, colorize(' 2) Echomail (' . $messageCounts['echomail'] . ' messages)', ANSI_GREEN));
        $showShoutbox = \BinktermPHP\BbsConfig::isFeatureEnabled('shoutbox');
        $showPolls = \BinktermPHP\BbsConfig::isFeatureEnabled('voting_booth');

        $option = 3;
        if ($showShoutbox) {
            writeLine($conn, colorize(" {$option}) Shoutbox", ANSI_GREEN));
            $shoutboxOption = (string)$option;
            $option++;
        }
        if ($showPolls) {
            writeLine($conn, colorize(" {$option}) Polls", ANSI_GREEN));
            $pollsOption = (string)$option;
            $option++;
        }
        writeLine($conn, colorize(" {$option}) Quit", ANSI_YELLOW));
        $quitOption = (string)$option;
        writeLine($conn, colorize('Select option:', ANSI_DIM));
        $choice = trim((string)readTelnetLine($conn, $state));
        if ($choice === null) {
            // Connection lost
            $duration = time() - $loginTime;
            $minutes = floor($duration / 60);
            $seconds = $duration % 60;
            echo "[" . date('Y-m-d H:i:s') . "] Disconnected: {$username} (session duration: {$minutes}m {$seconds}s)\n";
            break;
        }
        if ($choice === '') {
            continue;
        }
        if ($choice === '1') {
            showNetmail($conn, $state, $apiBase, $session);
            // Refresh counts after viewing/composing messages
            $messageCounts = getMessageCounts($apiBase, $session);
        } elseif ($choice === '2') {
            showEchoareas($conn, $state, $apiBase, $session);
            // Refresh counts after viewing/composing messages
            $messageCounts = getMessageCounts($apiBase, $session);
        } elseif (!empty($shoutboxOption) && $choice === $shoutboxOption) {
            showShoutbox($conn, $state, $apiBase, $session, 20);
        } elseif (!empty($pollsOption) && $choice === $pollsOption) {
            showPolls($conn, $state, $apiBase, $session);
        } elseif ($choice === $quitOption || strtolower($choice) === 'q') {
            // Display goodbye message
            writeLine($conn, '');
            writeLine($conn, colorize('Thank you for visiting, have a great day!', ANSI_CYAN . ANSI_BOLD));
            writeLine($conn, '');
            try {
                $siteUrl = Config::getSiteUrl();
                writeLine($conn, colorize('Come back and visit us on the web at ' . $siteUrl, ANSI_YELLOW));
            } catch (\Exception $e) {
                // Silently skip if getSiteUrl fails
            }
            writeLine($conn, '');
            writeLine($conn, 'Press Enter to disconnect...');

            // Flush output and wait for acknowledgment
            if (is_resource($conn)) {
                fflush($conn);
            }

            // Wait for user to press enter or timeout after 5 seconds
            stream_set_timeout($conn, 5);
            readTelnetLine($conn, $state);

            // Graceful logout
            $duration = time() - $loginTime;
            $minutes = floor($duration / 60);
            $seconds = $duration % 60;
            echo "[" . date('Y-m-d H:i:s') . "] Logout: {$username} (session duration: {$minutes}m {$seconds}s)\n";
            break;
        }
    }

    fclose($conn);
    if ($forked) {
        exit(0);
    }
}
