<?php

/*
 * Copyright Matthew Asham and BinktermPHP Contributors
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
 * following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 *
 */

namespace BinktermPHP\Mrc;

/**
 * MRC Protocol Client
 *
 * Implements MRC Protocol v1.3 for real-time multi-user chat.
 * Handles connection, packet sending/receiving, and protocol compliance.
 *
 * Protocol Details:
 * - 7-field message format separated by tilde (~) with trailing ~ and \n
 * - Format: F1~F2~F3~F4~F5~F6~F7~\n
 * - Handshake: {BBSName}~{Version}\n (must be sent within 1 second)
 * - Keepalive: PING every 60s, respond with IMALIVE
 * - Reserved characters: Tilde (~) Chr(126) must be blacklisted from input
 * - Valid characters: Chr(32) through Chr(125)
 * - Spaces in usernames replaced with underscore (_)
 */
class MrcClient
{
    private $socket = null;
    private $config;
    private $lastPing = 0;
    private $receiveBuffer = '';
    private $connected = false;

    public function __construct(MrcConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Connect to MRC server and send handshake
     *
     * @return bool True on success, false on failure
     */
    public function connect(): bool
    {
        try {
            $host = $this->config->getServerHost();
            $port = $this->config->getServerPort();
            $useSSL = $this->config->useSSL();

            // Build connection string
            $connectionString = $useSSL
                ? "ssl://{$host}:{$port}"
                : "tcp://{$host}:{$port}";

            // Create context for SSL
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ]);

            // Connect with timeout
            $errno = 0;
            $errstr = '';
            $this->socket = @stream_socket_client(
                $connectionString,
                $errno,
                $errstr,
                10,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (!$this->socket) {
                error_log("MRC: Connection failed to {$connectionString}: {$errstr} ({$errno})");
                return false;
            }

            // Set non-blocking mode for async I/O
            stream_set_blocking($this->socket, false);

            // Send handshake within 1 second
            $handshake = $this->config->getHandshakeString() . "\n";
            $written = fwrite($this->socket, $handshake);

            if ($written === false) {
                error_log("MRC: Failed to send handshake");
                $this->disconnect();
                return false;
            }

            $this->connected = true;
            $this->lastPing = time();
            error_log("MRC: Connected to {$host}:{$port}" . ($useSSL ? " (SSL)" : ""));

            return true;

        } catch (\Exception $e) {
            error_log("MRC: Connection exception: " . $e->getMessage());
            $this->disconnect();
            return false;
        }
    }

    /**
     * Disconnect from MRC server
     * Sends SHUTDOWN command before closing
     */
    public function disconnect(): void
    {
        if ($this->isConnected()) {
            // Send SHUTDOWN command
            $this->sendCommand('', 'SHUTDOWN');

            // Give it a moment to send
            usleep(100000); // 100ms

            fclose($this->socket);
        }

        $this->socket = null;
        $this->connected = false;
        $this->receiveBuffer = '';
        error_log("MRC: Disconnected");
    }

    /**
     * Check if connected to server
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        if (!$this->connected || !$this->socket) {
            return false;
        }

        // Check if socket is still open
        $meta = stream_get_meta_data($this->socket);
        if ($meta['eof'] || !is_resource($this->socket)) {
            $this->connected = false;
            return false;
        }

        return true;
    }

    /**
     * Send a 7-field MRC packet
     *
     * @param string $f1 Field 1 (FromUser or CLIENT/SERVER)
     * @param string $f2 Field 2 (FromSite or command)
     * @param string $f3 Field 3 (FromRoom)
     * @param string $f4 Field 4 (ToUser)
     * @param string $f5 Field 5 (MsgExt)
     * @param string $f6 Field 6 (ToRoom)
     * @param string $f7 Field 7 (MessageBody)
     * @return bool True on success, false on failure
     */
    public function sendPacket(string $f1, string $f2, string $f3, string $f4, string $f5, string $f6, string $f7): bool
    {
        if (!$this->isConnected()) {
            error_log("MRC: Cannot send packet - not connected");
            return false;
        }

        // Sanitize all fields - remove tildes and ensure valid character range
        $fields = [
            $this->sanitizeField($f1),
            $this->sanitizeField($f2),
            $this->sanitizeField($f3),
            $this->sanitizeField($f4),
            $this->sanitizeField($f5),
            $this->sanitizeField($f6),
            $this->sanitizeField($f7)
        ];

        // Build packet: F1~F2~F3~F4~F5~F6~F7~\n
        $packet = implode('~', $fields) . "~\n";

        // Send packet
        $written = @fwrite($this->socket, $packet);

        if ($written === false) {
            error_log("MRC: Failed to write packet to socket");
            $this->connected = false;
            return false;
        }

        return true;
    }

    /**
     * Read incoming packets from server
     * Returns array of parsed packets (each packet is an associative array)
     *
     * @return array Array of packets, each with keys: f1-f7
     */
    public function readPackets(): array
    {
        $packets = [];

        if (!$this->isConnected()) {
            return $packets;
        }

        // Read available data
        $data = @fread($this->socket, 8192);

        if ($data === false) {
            // Read error
            error_log("MRC: Socket read error");
            $this->connected = false;
            return $packets;
        }

        if ($data === '') {
            // No data available (non-blocking read)
            return $packets;
        }

        // Append to receive buffer
        $this->receiveBuffer .= $data;

        // Split on newlines to get complete packets
        $lines = explode("\n", $this->receiveBuffer);

        // Keep the last incomplete line in buffer
        $this->receiveBuffer = array_pop($lines);

        // Parse complete packets
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $packet = $this->parsePacket($line);
            if ($packet !== null) {
                $packets[] = $packet;
            }
        }

        return $packets;
    }

    /**
     * Parse an MRC packet line into associative array
     *
     * @param string $line Raw packet line
     * @return array|null Parsed packet or null if invalid
     */
    private function parsePacket(string $line): ?array
    {
        // Remove trailing newline if present
        $line = rtrim($line, "\r\n");

        // Split on tilde
        $parts = explode('~', $line);

        // Valid packet has exactly 8 parts (7 fields + trailing ~)
        if (count($parts) !== 8) {
            error_log("MRC: Invalid packet format (expected 8 parts, got " . count($parts) . "): " . substr($line, 0, 100));
            return null;
        }

        return [
            'f1' => $parts[0], // FromUser or SERVER/CLIENT
            'f2' => $parts[1], // FromSite or command
            'f3' => $parts[2], // FromRoom
            'f4' => $parts[3], // ToUser
            'f5' => $parts[4], // MsgExt
            'f6' => $parts[5], // ToRoom
            'f7' => $parts[6]  // MessageBody
        ];
    }

    /**
     * Sanitize a field for MRC protocol
     * - Removes tilde (~) characters
     * - Ensures only Chr(32) through Chr(125) are used
     * - Replaces spaces with underscores in usernames/rooms (caller's responsibility)
     *
     * @param string $field Field value
     * @return string Sanitized field
     */
    private function sanitizeField(string $field): string
    {
        // Remove tildes
        $field = str_replace('~', '', $field);

        // Filter to valid character range (Chr 32-125)
        $filtered = '';
        for ($i = 0; $i < strlen($field); $i++) {
            $char = $field[$i];
            $ord = ord($char);
            if ($ord >= 32 && $ord <= 125) {
                $filtered .= $char;
            }
        }

        return $filtered;
    }

    /**
     * Sanitize username or room name
     * - Removes tildes
     * - Replaces spaces with underscores
     * - Ensures valid character range
     *
     * @param string $name Username or room name
     * @return string Sanitized name
     */
    public static function sanitizeName(string $name): string
    {
        // Remove tildes
        $name = str_replace('~', '', $name);

        // Replace spaces with underscores
        $name = str_replace(' ', '_', $name);

        // Filter to valid character range (Chr 32-125)
        $filtered = '';
        for ($i = 0; $i < strlen($name); $i++) {
            $char = $name[$i];
            $ord = ord($char);
            if ($ord >= 32 && $ord <= 125) {
                $filtered .= $char;
            }
        }

        return $filtered;
    }

    /**
     * Send keepalive response (IMALIVE)
     * Response to server PING
     *
     * @return bool
     */
    public function sendKeepalive(): bool
    {
        $this->lastPing = time();
        return $this->sendCommand('', 'IMALIVE');
    }

    /**
     * Send a command packet
     * Format: CLIENT~{command}~~~~~
     *
     * @param string $username Username for context (empty for system commands)
     * @param string $command Command to send
     * @return bool
     */
    public function sendCommand(string $username, string $command): bool
    {
        $from = $username ? self::sanitizeName($username) : 'CLIENT';
        return $this->sendPacket($from, $command, '', '', '', '', '');
    }

    /**
     * Join a room
     * Sends NEWROOM command (REQUIRED on initial connect)
     *
     * @param string $room Room name
     * @param string $username Username
     * @return bool
     */
    public function joinRoom(string $room, string $username): bool
    {
        $room = self::sanitizeName($room);
        $username = self::sanitizeName($username);

        // NEWROOM command format: username~NEWROOM~~~~room~
        return $this->sendPacket($username, 'NEWROOM', '', '', '', $room, '');
    }

    /**
     * Send a chat message to a room
     *
     * @param string $username Sender username
     * @param string $room Target room
     * @param string $message Message body (max 140 chars)
     * @return bool
     */
    public function sendMessage(string $username, string $room, string $message): bool
    {
        $username = self::sanitizeName($username);
        $room = self::sanitizeName($room);
        $bbsName = $this->config->getBbsName();

        // Truncate message to max length
        $maxLength = $this->config->getMaxMessageLength();
        $message = substr($message, 0, $maxLength);

        // Message format: username~bbsname~room~~~room~message~
        return $this->sendPacket($username, $bbsName, $room, '', '', $room, $message);
    }

    /**
     * Send a private message to a user
     *
     * @param string $fromUser Sender username
     * @param string $toUser Recipient username
     * @param string $message Message body
     * @return bool
     */
    public function sendPrivateMessage(string $fromUser, string $toUser, string $message): bool
    {
        $fromUser = self::sanitizeName($fromUser);
        $toUser = self::sanitizeName($toUser);
        $bbsName = $this->config->getBbsName();

        // Truncate message to max length
        $maxLength = $this->config->getMaxMessageLength();
        $message = substr($message, 0, $maxLength);

        // PM format: username~bbsname~~touser~~room~message~
        return $this->sendPacket($fromUser, $bbsName, '', $toUser, '', '', $message);
    }

    /**
     * Get timestamp of last ping received
     *
     * @return int Unix timestamp
     */
    public function getLastPing(): int
    {
        return $this->lastPing;
    }

    /**
     * Update last ping timestamp (called when PING received)
     */
    public function updateLastPing(): void
    {
        $this->lastPing = time();
    }

    /**
     * Check if keepalive timeout has been exceeded
     *
     * @return bool True if timeout exceeded
     */
    public function isKeepaliveExpired(): bool
    {
        $timeout = $this->config->getKeepaliveTimeout();
        return (time() - $this->lastPing) > $timeout;
    }
}
