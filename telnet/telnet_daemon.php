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

const ANSI_RESET = "\033[0m";
const ANSI_BOLD = "\033[1m";
const ANSI_DIM = "\033[2m";
const ANSI_BLUE = "\033[34m";
const ANSI_CYAN = "\033[36m";
const ANSI_GREEN = "\033[32m";
const ANSI_YELLOW = "\033[33m";
const ANSI_MAGENTA = "\033[35m";

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
    $siteUrl = Config::env('SITE_URL');
    if ($siteUrl) {
        return rtrim($siteUrl, '/');
    }
    return 'http://127.0.0.1';
}

function sendTelnetCommand($conn, int $cmd, int $opt): void
{
    safeWrite($conn, chr(IAC) . chr($cmd) . chr($opt));
}

function setEcho($conn, bool $enable): void
{
    if ($enable) {
        sendTelnetCommand($conn, WONT, OPT_ECHO);
        sendTelnetCommand($conn, DONT, OPT_ECHO);
    } else {
        sendTelnetCommand($conn, WILL, OPT_ECHO);
        sendTelnetCommand($conn, TELNET_DO, OPT_ECHO);
    }
}

function negotiateTelnet($conn): void
{
    sendTelnetCommand($conn, TELNET_DO, OPT_NAWS);
    sendTelnetCommand($conn, WILL, OPT_SUPPRESS_GA);
}

function readTelnetLine($conn, array &$state): ?string
{
    $line = '';
    while (true) {
        if (!empty($state['pushback'])) {
            $char = $state['pushback'][0];
            $state['pushback'] = substr($state['pushback'], 1);
        } else {
            $char = fread($conn, 1);
        }
        if ($char === false || $char === '') {
            return null;
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
            return $line;
        }

        if ($byte === 8 || $byte === 127) {
            if ($line !== '') {
                $line = substr($line, 0, -1);
            }
            continue;
        }

        if ($byte === 0) {
            continue;
        }

        $line .= chr($byte);
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

function readMultiline($conn, array &$state, int $cols): string
{
    writeLine($conn, 'Enter message text. End with a single "." line. Type "/abort" to cancel.');
    $lines = [];
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

function sendMessage(string $apiBase, string $session, array $payload): bool
{
    $result = apiRequest($apiBase, 'POST', '/api/messages/send', $payload, $session);
    return ($result['status'] ?? 0) === 200 && !empty($result['data']['success']);
}

function normalizeSubject(string $subject): string
{
    return preg_replace('/^Re:\\s*/i', '', trim($subject));
}

function composeNetmail($conn, array &$state, string $apiBase, string $session, ?array $reply = null): void
{
    $toNameDefault = $reply['replyto_name'] ?? $reply['from_name'] ?? '';
    $toAddressDefault = $reply['replyto_address'] ?? $reply['from_address'] ?? '';
    $subjectDefault = $reply ? 'Re: ' . normalizeSubject((string)($reply['subject'] ?? '')) : '';

    $toName = prompt($conn, $state, 'To Name' . ($toNameDefault ? " [{$toNameDefault}]" : '') . ': ', true);
    if ($toName === null) {
        return;
    }
    if ($toName === '' && $toNameDefault !== '') {
        $toName = $toNameDefault;
    }

    $toAddress = prompt($conn, $state, 'To Address' . ($toAddressDefault ? " [{$toAddressDefault}]" : '') . ': ', true);
    if ($toAddress === null) {
        return;
    }
    if ($toAddress === '' && $toAddressDefault !== '') {
        $toAddress = $toAddressDefault;
    }

    $subject = prompt($conn, $state, 'Subject' . ($subjectDefault ? " [{$subjectDefault}]" : '') . ': ', true);
    if ($subject === null) {
        return;
    }
    if ($subject === '' && $subjectDefault !== '') {
        $subject = $subjectDefault;
    }

    $cols = $state['cols'] ?? 80;
    $messageText = readMultiline($conn, $state, $cols);
    if ($messageText === '') {
        writeLine($conn, 'Message cancelled (empty).');
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

    if (sendMessage($apiBase, $session, $payload)) {
        writeLine($conn, 'Netmail sent.');
    } else {
        writeLine($conn, 'Failed to send netmail.');
    }
}

function composeEchomail($conn, array &$state, string $apiBase, string $session, string $area, ?array $reply = null): void
{
    $toNameDefault = $reply['from_name'] ?? 'All';
    $subjectDefault = $reply ? 'Re: ' . normalizeSubject((string)($reply['subject'] ?? '')) : '';

    $toName = prompt($conn, $state, 'To Name' . ($toNameDefault ? " [{$toNameDefault}]" : '') . ': ', true);
    if ($toName === null) {
        return;
    }
    if ($toName === '' && $toNameDefault !== '') {
        $toName = $toNameDefault;
    }

    $subject = prompt($conn, $state, 'Subject' . ($subjectDefault ? " [{$subjectDefault}]" : '') . ': ', true);
    if ($subject === null) {
        return;
    }
    if ($subject === '' && $subjectDefault !== '') {
        $subject = $subjectDefault;
    }

    $cols = $state['cols'] ?? 80;
    $messageText = readMultiline($conn, $state, $cols);
    if ($messageText === '') {
        writeLine($conn, 'Message cancelled (empty).');
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

    if (sendMessage($apiBase, $session, $payload)) {
        writeLine($conn, 'Echomail posted.');
    } else {
        writeLine($conn, 'Failed to post echomail.');
    }
}

function apiRequest(string $base, string $method, string $path, ?array $payload, ?string $session): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP curl extension is required for telnet API access.');
    }

    $ch = curl_init();
    $url = rtrim($base, '/') . $path;
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

    return [
        'status' => $status,
        'data' => $data,
        'cookie' => $cookie,
        'error' => $curlError ?: null,
        'errno' => $curlErrno ?: null,
        'url' => $url
    ];
}

function prompt($conn, array &$state, string $label, bool $echo = true): ?string
{
    setEcho($conn, $echo);
    safeWrite($conn, $label);

    if ($echo) {
        $value = readTelnetLine($conn, $state);
        writeLine($conn, '');
        return $value;
    }

    $value = readTelnetLine($conn, $state);
    setEcho($conn, true);
    writeLine($conn, '');
    return $value;
}

function login($conn, array &$state, string $apiBase, bool $debug): ?string
{
    $config = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();


    writeLine($conn, colorize('Welcome to BinktermPHP ' . Version::getVersion() . ' Telnet.', ANSI_CYAN . ANSI_BOLD));
    writeLine($conn, '');
    writeLine($conn, colorize('    ' . $config->getSystemName(), ANSI_MAGENTA . ANSI_BOLD));
    writeLine($conn, colorize('    ' . $config->getSystemLocation(), ANSI_DIM));
    writeLine($conn, '');
    writeLine($conn, colorize('    ' . $config->getSystemOrigin(), ANSI_DIM));
    writeLine($conn, colorize(str_repeat('=', 40), ANSI_DIM));
    writeLine($conn, '');

    $username = prompt($conn, $state, 'Username: ', true);
    if ($username === null) {
        return null;
    }
    writeLine($conn, "\r\n");
    $password = prompt($conn, $state, 'Password: ', false);
    if ($password === null) {
        return null;
    }
    writeLine($conn, "\r\n");
    if ($debug) {
        writeLine($conn, "[DEBUG] username={$username} password={$password}");
    }

    try {
        $result = apiRequest($apiBase, 'POST', '/api/auth/login', [
            'username' => $username,
            'password' => $password
        ], null);
    } catch (Throwable $e) {
        writeLine($conn, 'Login failed: ' . $e->getMessage());
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
        writeLine($conn, 'Login failed.');
        return null;
    }

    writeLine($conn, 'Login successful.');
    return $result['cookie'];
}

function showShoutbox($conn, array &$state, string $apiBase, string $session, int $limit = 5): void
{
    if (!\BinktermPHP\BbsConfig::isFeatureEnabled('shoutbox')) {
        return;
    }
    $response = apiRequest($apiBase, 'GET', '/api/shoutbox?limit=' . $limit, null, $session);
    $messages = $response['data']['messages'] ?? [];
    if (!$messages) {
        writeLine($conn, 'No shoutbox messages.');
        return;
    }
    writeLine($conn, '');
    writeLine($conn, 'Recent Shoutbox');
    foreach ($messages as $msg) {
        $user = $msg['username'] ?? 'Unknown';
        $text = $msg['message'] ?? '';
        $date = $msg['created_at'] ?? '';
        writeLine($conn, sprintf('[%s] %s: %s', $date, $user, $text));
    }
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

function showNetmail($conn, array &$state, string $apiBase, string $session): void
{
    $page = 1;
    while (true) {
        $response = apiRequest($apiBase, 'GET', '/api/messages/netmail?page=' . $page, null, $session);
        $messages = $response['data']['messages'] ?? [];
        if (!$messages) {
            writeLine($conn, 'No netmail messages.');
            return;
        }
        writeLine($conn, "Netmail page {$page}:");
        foreach ($messages as $idx => $msg) {
            $num = $idx + 1;
            $from = $msg['from_name'] ?? 'Unknown';
            $subject = $msg['subject'] ?? '(no subject)';
            $date = $msg['date_written'] ?? '';
            writeLine($conn, sprintf(' %2d) %-20s %-40s %s', $num, $from, $subject, $date));
        }
        writeLine($conn, 'Enter message number, n/p for next/prev, c to compose, q to return.');
        $input = trim((string)readTelnetLine($conn, $state));
        if ($input === 'q') {
            return;
        }
        if ($input === 'c') {
            composeNetmail($conn, $state, $apiBase, $session, null);
            continue;
        }
        if ($input === 'n') {
            $page++;
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
                writeLine($conn, $msg['subject'] ?? 'Message');
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
    $response = apiRequest($apiBase, 'GET', '/api/echoareas?subscribed_only=true', null, $session);
    $areas = $response['data']['echoareas'] ?? [];
    if (!$areas) {
        writeLine($conn, 'No echoareas available.');
        return;
    }
    writeLine($conn, 'Echoareas:');
    foreach ($areas as $idx => $area) {
        $num = $idx + 1;
        $tag = $area['tag'] ?? '';
        $domain = $area['domain'] ?? '';
        $desc = $area['description'] ?? '';
        writeLine($conn, sprintf(' %2d) %-20s %-10s %s', $num, $tag, $domain, $desc));
    }
    writeLine($conn, 'Select echoarea number or q to return.');
    $input = trim((string)readTelnetLine($conn, $state));
    if ($input === 'q') {
        return;
    }
    $choice = (int)$input;
    if ($choice > 0 && $choice <= count($areas)) {
        $area = $areas[$choice - 1];
        $tag = $area['tag'] ?? '';
        $domain = $area['domain'] ?? '';
        showEchomail($conn, $state, $apiBase, $session, $tag, $domain);
    }
}

function showEchomail($conn, array &$state, string $apiBase, string $session, string $tag, string $domain): void
{
    $page = 1;
    $area = $tag . '@' . $domain;
    while (true) {
        $response = apiRequest($apiBase, 'GET', '/api/messages/echomail/' . urlencode($area) . '?page=' . $page, null, $session);
        $messages = $response['data']['messages'] ?? [];
        if (!$messages) {
            writeLine($conn, 'No echomail messages.');
            return;
        }
        writeLine($conn, "Echomail {$area} page {$page}:");
        foreach ($messages as $idx => $msg) {
            $num = $idx + 1;
            $from = $msg['from_name'] ?? 'Unknown';
            $subject = $msg['subject'] ?? '(no subject)';
            $date = $msg['date_written'] ?? '';
            writeLine($conn, sprintf(' %2d) %-20s %-40s %s', $num, $from, $subject, $date));
        }
        writeLine($conn, 'Enter message number, n/p for next/prev, c to compose, q to return.');
        $input = trim((string)readTelnetLine($conn, $state));
        if ($input === 'q') {
            return;
        }
        if ($input === 'c') {
            composeEchomail($conn, $state, $apiBase, $session, $area, null);
            continue;
        }
        if ($input === 'n') {
            $page++;
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
                writeLine($conn, $msg['subject'] ?? 'Message');
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
    exit(0);
}

$host = $args['host'] ?? '0.0.0.0';
$port = (int)($args['port'] ?? 2323);
$apiBase = buildApiBase($args);
$debug = !empty($args['debug']);
$GLOBALS['telnet_api_insecure'] = !empty($args['insecure']);

$server = stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "Failed to bind telnet server: {$errstr} ({$errno})\n");
    exit(1);
}

echo "Telnet daemon listening on {$host}:{$port}\n";

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }

    $forked = false;
    if (function_exists('pcntl_fork')) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $forked = false;
        } elseif ($pid === 0) {
            fclose($server);
            $forked = true;
        } else {
            fclose($conn);
            if (function_exists('pcntl_waitpid')) {
                pcntl_waitpid(-1, $status, WNOHANG);
            }
            continue;
        }
    }

    stream_set_timeout($conn, 300);
    $state = [
        'telnet_mode' => null,
        'cols' => 80,
        'rows' => 24
    ];
    negotiateTelnet($conn);
    $session = login($conn, $state, $apiBase, $debug);
    if (!$session) {
        fclose($conn);
        if ($forked) {
            exit(0);
        }
        continue;
    }
    showShoutbox($conn, $state, $apiBase, $session, 5);
    while (true) {
        writeLine($conn, '');
        writeLine($conn, colorize('Main Menu', ANSI_BLUE . ANSI_BOLD));
        writeLine($conn, colorize(' 1) Netmail', ANSI_GREEN));
        writeLine($conn, colorize(' 2) Echomail', ANSI_GREEN));
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
            break;
        }
        if ($choice === '') {
            continue;
        }
        if ($choice === '1') {
            showNetmail($conn, $state, $apiBase, $session);
        } elseif ($choice === '2') {
            showEchoareas($conn, $state, $apiBase, $session);
        } elseif (!empty($shoutboxOption) && $choice === $shoutboxOption) {
            showShoutbox($conn, $state, $apiBase, $session, 20);
        } elseif (!empty($pollsOption) && $choice === $pollsOption) {
            showPolls($conn, $state, $apiBase, $session);
        } elseif ($choice === $quitOption || strtolower($choice) === 'q') {
            break;
        }
    }
    fclose($conn);
    if ($forked) {
        exit(0);
    }
}
