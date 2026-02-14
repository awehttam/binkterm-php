<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Config;
use BinktermPHP\DoorManager;

/**
 * DoorHandler - DOS door game access via telnet
 *
 * Connects telnet clients to DOS door games by acting as a WebSocket client
 * to the dosbox-bridge multiplexing server. Data is relayed bidirectionally
 * with CP437/UTF-8 encoding conversion and ANSI→Doorway protocol key mapping.
 *
 * The WebSocket URL is always built from DOSDOOR_WS_BIND_HOST and
 * DOSDOOR_WS_PORT so the daemon connects to the bridge directly over the
 * loopback interface, bypassing any public-facing SSL proxy.
 */
class DoorHandler
{
    private TelnetServer $server;
    private string $apiBase;

    /**
     * @param TelnetServer $server Telnet server instance for I/O operations
     * @param string $apiBase Base URL for API requests
     */
    public function __construct(TelnetServer $server, string $apiBase)
    {
        $this->server = $server;
        $this->apiBase = $apiBase;
    }

    /**
     * Show door selection menu and launch the selected door
     *
     * @param resource $conn Socket connection to client
     * @param array $state Terminal state array
     * @param string $session Session token for authentication
     */
    public function show($conn, array &$state, string $session): void
    {
        $doorManager = new DoorManager();
        $allDoors = $doorManager->getEnabledDoors();

        // Hide admin-only doors from non-admin users
        if (empty($state['is_admin'])) {
            $allDoors = array_filter($allDoors, fn($door) => empty($door['admin_only']));
        }

        if (empty($allDoors)) {
            TelnetUtils::safeWrite($conn, "\033[2J\033[H");
            TelnetUtils::writeLine($conn, TelnetUtils::colorize('No doors are currently available.', TelnetUtils::ANSI_YELLOW));
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize('Press any key to return...', TelnetUtils::ANSI_YELLOW));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        // Convert associative array to indexed list, preserving door IDs
        $doorList = [];
        foreach ($allDoors as $doorId => $door) {
            $doorList[] = ['id' => $doorId, 'data' => $door];
        }

        while (true) {
            $this->displayDoorList($conn, $state, $doorList);

            $choice = $this->server->readLineWithIdleCheck($conn, $state);
            if ($choice === null || strtolower(trim($choice)) === 'q') {
                return;
            }

            $idx = (int)trim($choice) - 1;
            if ($idx >= 0 && $idx < count($doorList)) {
                $entry = $doorList[$idx];
                $doorName = $entry['data']['name'] ?? $entry['id'];
                $this->launchDoor($conn, $state, $session, $entry['id'], $doorName);
                return;
            }

            TelnetUtils::writeLine($conn, TelnetUtils::colorize('Invalid selection.', TelnetUtils::ANSI_RED));
            sleep(1);
        }
    }

    /**
     * Render the door selection list
     *
     * @param resource $conn
     * @param array $state
     * @param array $doorList Indexed array of ['id' => string, 'data' => array]
     */
    private function displayDoorList($conn, array &$state, array $doorList): void
    {
        $cols = $state['cols'] ?? 80;

        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        TelnetUtils::writeLine($conn, TelnetUtils::colorize('=== DOS Door Games ===', TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD));
        TelnetUtils::writeLine($conn, '');

        foreach ($doorList as $i => $entry) {
            $num = $i + 1;
            $door = $entry['data'];
            $name = $door['name'] ?? $entry['id'];
            $desc = $door['description'] ?? '';
            $creditCost = (int)($door['config']['credit_cost'] ?? 0);
            $timeLimit = (int)($door['time_per_day'] ?? $door['config']['max_time_minutes'] ?? 0);

            $meta = [];
            if ($timeLimit > 0) {
                $meta[] = "{$timeLimit} min/day";
            }
            if ($creditCost > 0) {
                $meta[] = "{$creditCost} credits";
            }
            $metaStr = $meta ? ' [' . implode(', ', $meta) . ']' : '';

            TelnetUtils::writeLine($conn,
                TelnetUtils::colorize(str_pad("{$num})", 4), TelnetUtils::ANSI_YELLOW) .
                TelnetUtils::colorize($name, TelnetUtils::ANSI_GREEN . TelnetUtils::ANSI_BOLD) .
                TelnetUtils::colorize($metaStr, TelnetUtils::ANSI_DIM)
            );

            if ($desc !== '') {
                $maxWidth = max(20, $cols - 7);
                $wrapped = wordwrap($desc, $maxWidth, "\n", true);
                foreach (explode("\n", $wrapped) as $line) {
                    TelnetUtils::writeLine($conn, TelnetUtils::colorize('     ' . $line, TelnetUtils::ANSI_DIM));
                }
            }
        }

        TelnetUtils::writeLine($conn, TelnetUtils::colorize('Enter number to play, or Q to return: ', TelnetUtils::ANSI_DIM));
    }

    /**
     * Launch a door game: call the API to create a session, then relay data
     * bidirectionally between the telnet client and the dosbox-bridge WebSocket server.
     *
     * @param resource $conn
     * @param array $state
     * @param string $session Auth session cookie value
     * @param string $doorId Door identifier (e.g. "lord")
     * @param string $doorName Human-readable door name for display
     */
    private function launchDoor($conn, array &$state, string $session, string $doorId, string $doorName): void
    {
        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        TelnetUtils::writeLine($conn, TelnetUtils::colorize("Launching {$doorName}...", TelnetUtils::ANSI_CYAN));
        TelnetUtils::writeLine($conn, '');

        $apiResult = $this->callDoorLaunchApi($session, $doorId);

        if (empty($apiResult['success'])) {
            $msg = $apiResult['message'] ?? $apiResult['error'] ?? 'Failed to start door session';
            TelnetUtils::writeLine($conn, TelnetUtils::colorize('Error: ' . $msg, TelnetUtils::ANSI_RED));
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize('Press any key to return...', TelnetUtils::ANSI_YELLOW));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        $doorSession = $apiResult['session'];
        $wsToken = $doorSession['ws_token'];
        $sessionId = $doorSession['session_id'];

        // Connect directly to the bridge over loopback using .env settings.
        // This bypasses any public-facing SSL proxy (DOSDOOR_WS_URL is for browsers).
        $wsHost = Config::env('DOSDOOR_WS_BIND_HOST', '127.0.0.1');
        $wsPort = (int) Config::env('DOSDOOR_WS_PORT', '6001');

        TelnetUtils::writeLine($conn, TelnetUtils::colorize('Connecting to game server...', TelnetUtils::ANSI_DIM));

        $wsSock = $this->wsConnect($wsHost, $wsPort, $wsToken);
        if ($wsSock === null) {
            TelnetUtils::writeLine($conn, TelnetUtils::colorize(
                'Could not connect to game bridge. Is the DOS door bridge running?',
                TelnetUtils::ANSI_RED
            ));
            TelnetUtils::writeLine($conn, '');
            TelnetUtils::writeLine($conn, TelnetUtils::colorize('Press any key to return...', TelnetUtils::ANSI_YELLOW));
            $this->server->readKeyWithIdleCheck($conn, $state);
            return;
        }

        TelnetUtils::writeLine($conn, TelnetUtils::colorize('Connected! Starting game...', TelnetUtils::ANSI_GREEN));
        sleep(1);

        // Suppress daemon-layer echo — the door game drives all display output
        $this->server->safeWrite($conn, chr(255) . chr(251) . chr(1)); // IAC WILL ECHO
        $this->server->safeWrite($conn, chr(255) . chr(254) . chr(1)); // IAC DONT ECHO

        $this->relayLoop($conn, $state, $wsSock);

        // Send WebSocket close frame and release the socket
        $this->wsSendClose($wsSock);
        @fclose($wsSock);

        // Notify the API the session ended (best-effort; bridge also cleans up on disconnect)
        $this->callDoorEndApi($session, $sessionId);

        // Restore echo state
        $this->server->safeWrite($conn, chr(255) . chr(251) . chr(1)); // IAC WILL ECHO
        $this->server->safeWrite($conn, chr(255) . chr(254) . chr(1)); // IAC DONT ECHO

        TelnetUtils::safeWrite($conn, "\033[2J\033[H");
        TelnetUtils::writeLine($conn, '');
        TelnetUtils::writeLine($conn, TelnetUtils::colorize("Returned from {$doorName}.", TelnetUtils::ANSI_CYAN));
        TelnetUtils::writeLine($conn, TelnetUtils::colorize('Press any key to continue...', TelnetUtils::ANSI_YELLOW));
        $this->server->readKeyWithIdleCheck($conn, $state);
    }

    /**
     * Bidirectional relay loop between the telnet client and the dosbox-bridge WebSocket.
     *
     * Uses stream_select on both streams simultaneously so neither side blocks the other.
     * Telnet client data: IAC-stripped → ANSI→Doorway key conversion → CP437→UTF-8 → WebSocket
     * Bridge data:        WebSocket frame → UTF-8→CP437 → telnet client
     *
     * @param resource $conn Telnet client socket
     * @param array $state Terminal state (updated by NAWS sequences)
     * @param resource $wsSock WebSocket TCP socket
     */
    private function relayLoop($conn, array &$state, $wsSock): void
    {
        stream_set_blocking($conn, false);
        stream_set_blocking($wsSock, false);

        $wsBuf = '';

        while (true) {
            if (!is_resource($conn) || feof($conn)) {
                break;
            }
            if (!is_resource($wsSock) || feof($wsSock)) {
                break;
            }

            $read = [$conn, $wsSock];
            $w = $e = null;

            if (@stream_select($read, $w, $e, 0, 50000) === false) {
                break;
            }

            foreach ($read as $ready) {
                if ($ready === $conn) {
                    // --- Telnet client → bridge ---
                    $raw = @fread($conn, 4096);
                    if ($raw === false || ($raw === '' && feof($conn))) {
                        return;
                    }
                    if ($raw === '') {
                        continue;
                    }

                    // Strip IAC commands (capture NAWS resizes) and convert ANSI escape
                    // sequences to Doorway protocol scan codes understood by DOS door games
                    $processed = $this->processTelnetInput($raw, $state);
                    if ($processed === '') {
                        continue;
                    }

                    // Convert CP437 → UTF-8 before sending as WebSocket text frame
                    $utf8 = function_exists('iconv') ? iconv('CP437', 'UTF-8//IGNORE', $processed) : $processed;
                    if ($utf8 !== '' && $utf8 !== false) {
                        $this->wsSend($wsSock, $utf8);
                    }
                } else {
                    // --- Bridge → telnet client ---
                    $chunk = @fread($wsSock, 4096);
                    if ($chunk === false || ($chunk === '' && feof($wsSock))) {
                        return;
                    }
                    if ($chunk === '') {
                        continue;
                    }

                    $wsBuf .= $chunk;

                    // Consume all complete WebSocket frames from the buffer
                    while (true) {
                        $result = $this->wsParseFrame($wsBuf);
                        if ($result['type'] === 'incomplete') {
                            break;
                        }
                        $wsBuf = $result['remaining'];

                        if ($result['type'] === 'close') {
                            return;
                        }
                        if ($result['type'] === 'ping') {
                            $this->wsSendPong($wsSock, $result['payload']);
                            continue;
                        }
                        if ($result['type'] === 'pong' || $result['payload'] === '') {
                            continue;
                        }

                        // Convert UTF-8 → CP437 for the telnet client
                        $cp437 = function_exists('iconv')
                            ? iconv('UTF-8', 'CP437//IGNORE', $result['payload'])
                            : $result['payload'];

                        if ($cp437 !== '' && $cp437 !== false) {
                            $this->server->safeWrite($conn, $cp437);
                        }
                    }
                }
            }
        }
    }

    /**
     * Strip telnet IAC command sequences from raw input and convert ANSI terminal
     * escape sequences (ESC[...) to Doorway protocol scan codes (0x00 + scan_code).
     *
     * Doorway protocol is the standard used by DOS BBS door games for extended keys.
     * NAWS subnegotiations are parsed to keep the terminal size in sync.
     *
     * @param string $data Raw bytes from the telnet client
     * @param array $state Terminal state (cols/rows updated if NAWS seen)
     * @return string Processed bytes ready to send to the bridge
     */
    private function processTelnetInput(string $data, array &$state): string
    {
        $out = '';
        $len = strlen($data);
        $i = 0;

        while ($i < $len) {
            $byte = ord($data[$i]);

            // Telnet IAC (0xFF) command sequence
            if ($byte === 255) {
                $i++;
                if ($i >= $len) {
                    break;
                }
                $cmd = ord($data[$i++]);

                // Escaped IAC — literal 0xFF in data stream
                if ($cmd === 255) {
                    $out .= chr(255);
                    continue;
                }

                // WILL/WONT/DO/DONT (251-254) — consume one option byte
                if ($cmd >= 251 && $cmd <= 254) {
                    $i++;
                    continue;
                }

                // SB (250) — subnegotiation, consume until IAC SE (255 240)
                if ($cmd === 250) {
                    $opt = ($i < $len) ? ord($data[$i++]) : null;
                    $sbData = '';
                    while ($i < $len) {
                        $b = ord($data[$i++]);
                        if ($b === 255 && $i < $len && ord($data[$i]) === 240) {
                            $i++; // consume SE
                            break;
                        }
                        $sbData .= chr($b);
                    }
                    // NAWS (option 31) — update terminal dimensions
                    if ($opt === 31 && strlen($sbData) >= 4) {
                        $w = (ord($sbData[0]) << 8) + ord($sbData[1]);
                        $h = (ord($sbData[2]) << 8) + ord($sbData[3]);
                        if ($w > 0) {
                            $state['cols'] = $w;
                        }
                        if ($h > 0) {
                            $state['rows'] = $h;
                        }
                    }
                    continue;
                }

                continue; // Unrecognised IAC command — skip
            }

            // ANSI escape sequence — convert to Doorway protocol if possible
            if ($byte === 27 && ($i + 1) < $len && $data[$i + 1] === '[') {
                $i += 2; // skip ESC[
                $params = '';
                while ($i < $len && !ctype_alpha($data[$i])) {
                    $params .= $data[$i++];
                }
                $final = ($i < $len) ? $data[$i++] : '';

                $scanCode = $this->ansiToScanCode($params, $final);
                if ($scanCode !== null) {
                    $out .= "\x00" . chr($scanCode); // Doorway protocol extended key
                } else {
                    $out .= chr(27) . '[' . $params . $final; // pass through unknown sequence
                }
                continue;
            }

            // CR — strip the trailing LF or NUL that telnet clients append.
            // Telnet sends \r\n or \r\0 for Enter; door games expect bare \r.
            if ($byte === 13) {
                $i++; // consume the CR itself
                $out .= chr(13);
                if ($i < $len && (ord($data[$i]) === 10 || ord($data[$i]) === 0)) {
                    $i++; // consume the trailing LF or NUL
                }
                continue;
            }

            // NUL — strip bare null bytes (telnet CR+NUL padding, not our generated Doorway codes)
            if ($byte === 0) {
                $i++; // consume the NUL
                continue;
            }

            // DEL (0x7F) — modern terminals send DEL for Backspace; DOS doors expect Ctrl-H (0x08)
            if ($byte === 127) {
                $i++;
                $out .= chr(8);
                continue;
            }

            $out .= $data[$i++];
        }

        return $out;
    }

    /**
     * Map an ANSI CSI escape sequence to an IBM PC keyboard scan code.
     *
     * @param string $params Parameter string between ESC[ and the final byte
     * @param string $final  Final byte of the escape sequence (letter or ~)
     * @return int|null PC scan code, or null if the sequence is not recognised
     */
    private function ansiToScanCode(string $params, string $final): ?int
    {
        // Cursor keys: ESC[A / ESC[B / ESC[C / ESC[D and ESC[H / ESC[F
        if ($params === '') {
            return match($final) {
                'A' => 0x48, // Up
                'B' => 0x50, // Down
                'C' => 0x4D, // Right
                'D' => 0x4B, // Left
                'H' => 0x47, // Home
                'F' => 0x4F, // End
                default => null,
            };
        }

        // Extended keys via ESC[{n}~ (xterm / VT220 format)
        if ($final === '~') {
            return match($params) {
                '1', '7' => 0x47, // Home
                '2'      => 0x52, // Insert
                '3'      => 0x53, // Delete
                '4', '8' => 0x4F, // End
                '5'      => 0x49, // Page Up
                '6'      => 0x51, // Page Down
                '11'     => 0x3B, // F1
                '12'     => 0x3C, // F2
                '13'     => 0x3D, // F3
                '14'     => 0x3E, // F4
                '15'     => 0x3F, // F5
                '17'     => 0x40, // F6
                '18'     => 0x41, // F7
                '19'     => 0x42, // F8
                '20'     => 0x43, // F9
                '21'     => 0x44, // F10
                '23'     => 0x85, // F11
                '24'     => 0x86, // F12
                default  => null,
            };
        }

        return null;
    }

    // ===== WebSocket CLIENT =====

    /**
     * Open a TCP connection and perform the WebSocket HTTP upgrade handshake.
     *
     * @param string $host Bridge bind host (from DOSDOOR_WS_BIND_HOST)
     * @param int    $port Bridge port (from DOSDOOR_WS_PORT)
     * @param string $token Session auth token to pass as a query parameter
     * @return resource|null Connected socket, or null on failure
     */
    private function wsConnect(string $host, int $port, string $token): mixed
    {
        $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5);
        if (!$sock) {
            return null;
        }

        stream_set_blocking($sock, true);
        stream_set_timeout($sock, 5);

        $key = base64_encode(random_bytes(16));

        $handshake = "GET /?token=" . urlencode($token) . " HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";

        fwrite($sock, $handshake);

        // Read until the end of HTTP headers
        $response = '';
        $deadline = time() + 5;
        while (!str_contains($response, "\r\n\r\n")) {
            if (time() > $deadline || feof($sock)) {
                fclose($sock);
                return null;
            }
            $chunk = fread($sock, 1024);
            if ($chunk === false || $chunk === '') {
                fclose($sock);
                return null;
            }
            $response .= $chunk;
        }

        // Must receive 101 Switching Protocols
        if (!str_contains($response, '101')) {
            fclose($sock);
            return null;
        }

        return $sock;
    }

    /**
     * Send a WebSocket text frame to the server (client frames must be masked).
     *
     * @param resource $sock
     * @param string   $payload UTF-8 text payload
     */
    private function wsSend($sock, string $payload): void
    {
        if (!is_resource($sock)) {
            return;
        }

        $len = strlen($payload);
        $mask = random_bytes(4);

        if ($len < 126) {
            $header = chr(0x81) . chr(0x80 | $len) . $mask;
        } elseif ($len < 65536) {
            $header = chr(0x81) . chr(0x80 | 126) . pack('n', $len) . $mask;
        } else {
            $header = chr(0x81) . chr(0x80 | 127) . pack('J', $len) . $mask;
        }

        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        @fwrite($sock, $header . $masked);
    }

    /**
     * Send a WebSocket pong frame in response to a ping.
     *
     * @param resource $sock
     * @param string   $payload Echo the ping payload back
     */
    private function wsSendPong($sock, string $payload = ''): void
    {
        if (!is_resource($sock)) {
            return;
        }

        $len = min(strlen($payload), 125); // pong payload must be ≤ 125 bytes
        $mask = random_bytes(4);
        $header = chr(0x8A) . chr(0x80 | $len) . $mask;

        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        @fwrite($sock, $header . $masked);
    }

    /**
     * Send a WebSocket connection close frame.
     *
     * @param resource $sock
     */
    private function wsSendClose($sock): void
    {
        if (!is_resource($sock)) {
            return;
        }
        $mask = random_bytes(4);
        @fwrite($sock, chr(0x88) . chr(0x80) . $mask); // close, masked, no payload
    }

    /**
     * Parse one complete WebSocket frame from a raw byte buffer.
     *
     * Returns an associative array:
     *   type      => 'data' | 'ping' | 'pong' | 'close' | 'incomplete'
     *   payload   => string (frame payload, empty for close/pong/incomplete)
     *   remaining => string (buffer bytes after this frame)
     *
     * @param string $buf Raw bytes accumulated from fread
     * @return array{type: string, payload: string, remaining: string}
     */
    private function wsParseFrame(string $buf): array
    {
        $incomplete = ['type' => 'incomplete', 'payload' => '', 'remaining' => $buf];

        if (strlen($buf) < 2) {
            return $incomplete;
        }

        $byte1  = ord($buf[0]);
        $byte2  = ord($buf[1]);
        $opcode = $byte1 & 0x0F;
        $masked = ($byte2 & 0x80) !== 0;
        $len    = $byte2 & 0x7F;
        $pos    = 2;

        if ($len === 126) {
            if (strlen($buf) < 4) {
                return $incomplete;
            }
            $len = (ord($buf[2]) << 8) + ord($buf[3]);
            $pos = 4;
        } elseif ($len === 127) {
            if (strlen($buf) < 10) {
                return $incomplete;
            }
            $len = unpack('J', substr($buf, 2, 8))[1];
            $pos = 10;
        }

        $maskLen  = $masked ? 4 : 0;
        $totalLen = $pos + $maskLen + $len;

        if (strlen($buf) < $totalLen) {
            return $incomplete;
        }

        $remaining = substr($buf, $totalLen);
        $payload   = substr($buf, $pos + $maskLen, $len);

        if ($masked) {
            $maskBytes = substr($buf, $pos, 4);
            $unmasked  = '';
            for ($i = 0; $i < strlen($payload); $i++) {
                $unmasked .= $payload[$i] ^ $maskBytes[$i % 4];
            }
            $payload = $unmasked;
        }

        $type = match($opcode) {
            0x0, 0x1, 0x2 => 'data',  // continuation, text, binary
            0x8            => 'close',
            0x9            => 'ping',
            0xA            => 'pong',
            default        => 'data',
        };

        return ['type' => $type, 'payload' => $payload, 'remaining' => $remaining];
    }

    // ===== API HELPERS =====

    /**
     * Call POST /api/door/launch with form-encoded body.
     *
     * The door launch API reads $_POST (not JSON), so we send
     * application/x-www-form-urlencoded rather than using TelnetUtils::apiRequest.
     *
     * @param string $session Auth session cookie value
     * @param string $doorId  Door identifier
     * @return array Decoded JSON response
     */
    private function callDoorLaunchApi(string $session, string $doorId): array
    {
        $ch = curl_init(rtrim($this->apiBase, '/') . '/api/door/launch');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['door' => $doorId]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_COOKIE         => 'binktermphp_session=' . $session,
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status === 0) {
            return ['success' => false, 'error' => 'API request failed'];
        }

        return json_decode($response, true) ?? ['success' => false, 'error' => 'Invalid API response'];
    }

    /**
     * Call POST /api/door/end (best-effort — bridge also cleans up on WebSocket close).
     *
     * @param string $session   Auth session cookie value
     * @param string $sessionId Door session UUID
     */
    private function callDoorEndApi(string $session, string $sessionId): void
    {
        $ch = curl_init(rtrim($this->apiBase, '/') . '/api/door/end');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['session_id' => $sessionId]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_COOKIE         => 'binktermphp_session=' . $session,
            CURLOPT_TIMEOUT        => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
