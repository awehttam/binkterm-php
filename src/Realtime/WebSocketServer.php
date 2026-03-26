<?php

namespace BinktermPHP\Realtime;

use BinktermPHP\Auth;
use BinktermPHP\Binkp\Logger;
use BinktermPHP\Database;

class WebSocketServer
{
    private string $bindHost;
    private int $port;
    private Logger $logger;
    /** @var resource|null */
    private $server = null;
    /** @var array<int, array<string, mixed>> */
    private array $clients = [];
    private StreamService $streamService;
    private CommandDispatcher $commandDispatcher;
    private int $lastActiveConnectionsLogAt = 0;

    public function __construct(string $bindHost, int $port, Logger $logger)
    {
        $this->bindHost = $bindHost;
        $this->port = $port;
        $this->logger = $logger;
        $db = Database::getInstance()->getPdo();
        $this->streamService = new StreamService($db);
        $this->commandDispatcher = new CommandDispatcher($db);
    }

    public function run(): void
    {
        $errno = 0;
        $errstr = '';
        $this->server = @stream_socket_server(
            "tcp://{$this->bindHost}:{$this->port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if (!$this->server) {
            throw new \RuntimeException("Failed to bind realtime websocket server: {$errstr} ({$errno})");
        }

        stream_set_blocking($this->server, false);
        $this->logger->info('Realtime websocket server started', [
            'bind_host' => $this->bindHost,
            'port' => $this->port,
        ]);

        while (true) {
            $read = [$this->server];
            foreach ($this->clients as $client) {
                $read[] = $client['socket'];
            }

            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 0, 200000);
            if ($changed === false) {
                usleep(200000);
                continue;
            }

            foreach ($read as $socket) {
                if ($socket === $this->server) {
                    $this->acceptClient();
                    continue;
                }
                $this->readClient($socket);
            }

            $this->pollAndDispatchEvents();
            $this->logActiveConnectionsIfDue();
        }
    }

    private function acceptClient(): void
    {
        $socket = @stream_socket_accept($this->server, 0);
        if (!$socket) {
            return;
        }

        stream_set_blocking($socket, false);
        $id = (int)$socket;
        $this->clients[$id] = [
            'socket' => $socket,
            'buffer' => '',
            'handshake_complete' => false,
            'user' => null,
            'remote_ip' => $this->extractRemoteIp($socket),
            'cursor' => 0,
            'subscriptions' => [],
        ];
    }

    /**
     * @param resource $socket
     */
    private function readClient($socket): void
    {
        $id = (int)$socket;
        if (!isset($this->clients[$id])) {
            return;
        }

        $chunk = @fread($socket, 8192);
        if ($chunk === '' || $chunk === false) {
            if (feof($socket)) {
                $this->closeClient($id);
            }
            return;
        }

        $this->clients[$id]['buffer'] .= $chunk;

        if (!$this->clients[$id]['handshake_complete']) {
            $this->tryHandshake($id);
            return;
        }

        $this->processFrames($id);
    }

    private function tryHandshake(int $clientId): void
    {
        $buffer = $this->clients[$clientId]['buffer'];
        $headerEnd = strpos($buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            return;
        }

        $request = substr($buffer, 0, $headerEnd + 4);
        $this->clients[$clientId]['buffer'] = substr($buffer, $headerEnd + 4);

        [$requestLine, $headers] = $this->parseHttpRequest($request);
        if (!$requestLine || !isset($headers['sec-websocket-key'])) {
            $this->sendHttpErrorAndClose($clientId, 400, 'Bad Request');
            return;
        }

        $sessionId = $this->extractSessionId($headers['cookie'] ?? '');
        if ($sessionId === null) {
            $this->sendHttpErrorAndClose($clientId, 401, 'Unauthorized');
            return;
        }

        $auth = new Auth();
        $user = $auth->validateSession($sessionId);
        if (!$user) {
            $this->sendHttpErrorAndClose($clientId, 401, 'Unauthorized');
            return;
        }

        $target = $requestLine['target'] ?? '/';
        $query = parse_url($target, PHP_URL_QUERY) ?: '';
        parse_str($query, $queryParams);
        $lastEventId = (int)($queryParams['cursor'] ?? 0);
        $anchorId = $this->streamService->getAnchorCursor($lastEventId);

        $accept = base64_encode(sha1(trim($headers['sec-websocket-key']) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $response = implode("\r\n", [
            'HTTP/1.1 101 Switching Protocols',
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Accept: ' . $accept,
            '',
            '',
        ]);
        fwrite($this->clients[$clientId]['socket'], $response);

        $this->clients[$clientId]['handshake_complete'] = true;
        $this->clients[$clientId]['user'] = $user;
        $this->clients[$clientId]['cursor'] = $anchorId;

        $this->sendJson($clientId, [
            'type' => 'connected',
            'id' => $anchorId,
            'data' => $this->streamService->getConnectedPayload($user, $anchorId),
        ]);

        $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
        $username = (string)($user['username'] ?? '');
        $remoteIp = (string)($this->clients[$clientId]['remote_ip'] ?? '');
        $this->logger->info(sprintf(
            'Realtime websocket client connected: ip=%s username=%s user_id=%d client_id=%d',
            $remoteIp !== '' ? $remoteIp : '-',
            $username !== '' ? $username : '-',
            $userId,
            $clientId
        ));
    }

    private function processFrames(int $clientId): void
    {
        while (true) {
            $frame = $this->decodeFrame($this->clients[$clientId]['buffer']);
            if ($frame === null) {
                return;
            }

            $this->clients[$clientId]['buffer'] = $frame['remaining'];
            if ($frame['opcode'] === 0x8) {
                $this->closeClient($clientId);
                return;
            }
            if ($frame['opcode'] === 0x9) {
                $this->sendControlFrame($clientId, 0xA, $frame['payload']);
                continue;
            }
            if ($frame['opcode'] !== 0x1) {
                continue;
            }

            $payload = json_decode($frame['payload'], true);
            if (!is_array($payload)) {
                continue;
            }
            $this->handleClientMessage($clientId, $payload);
        }
    }

    private function handleClientMessage(int $clientId, array $payload): void
    {
        $action = strtolower(trim((string)($payload['action'] ?? '')));
        switch ($action) {
            case 'subscribe':
                $type = strtolower(trim((string)($payload['type'] ?? '')));
                if ($type !== '') {
                    $this->clients[$clientId]['subscriptions'][$type] = true;
                }
                return;

            case 'unsubscribe':
                $type = strtolower(trim((string)($payload['type'] ?? '')));
                if ($type !== '') {
                    unset($this->clients[$clientId]['subscriptions'][$type]);
                }
                return;

            case 'command':
                $requestId = (string)($payload['requestId'] ?? '');
                $command = strtolower(trim((string)($payload['command'] ?? '')));
                $commandPayload = $payload['payload'] ?? [];
                if ($requestId === '' || $command === '' || !is_array($commandPayload)) {
                    $this->sendJson($clientId, [
                        'type' => 'command_result',
                        'requestId' => $requestId,
                        'success' => false,
                        'errorCode' => 'errors.realtime.invalid_payload',
                        'error' => 'Invalid realtime command payload',
                    ]);
                    return;
                }
                try {
                    $result = $this->commandDispatcher->dispatch($this->clients[$clientId]['user'], $command, $commandPayload);
                    $result['type'] = 'command_result';
                    $result['requestId'] = $requestId;
                    $this->sendJson($clientId, $result);
                } catch (\RuntimeException $e) {
                    $this->sendJson($clientId, [
                        'type' => 'command_result',
                        'requestId' => $requestId,
                        'success' => false,
                        'errorCode' => 'errors.realtime.unknown_command',
                        'error' => 'Unknown realtime command',
                    ]);
                }
                return;
        }
    }

    private function pollAndDispatchEvents(): void
    {
        if (!$this->clients) {
            return;
        }

        $maxId = $this->streamService->getMaxSseId();
        foreach (array_keys($this->clients) as $clientId) {
            if (!isset($this->clients[$clientId]) || !$this->clients[$clientId]['handshake_complete']) {
                continue;
            }
            $cursor = (int)$this->clients[$clientId]['cursor'];
            if ($maxId <= $cursor) {
                continue;
            }

            $highestSeen = $cursor;
            foreach ($this->streamService->fetchEventsSince($this->clients[$clientId]['user'], $cursor) as $event) {
                $highestSeen = max($highestSeen, (int)$event['id']);
                if (!isset($this->clients[$clientId]['subscriptions'][$event['event']])) {
                    continue;
                }

                $this->sendJson($clientId, [
                    'type' => $event['event'],
                    'id' => (int)$event['id'],
                    'data' => $this->tryParseJson($event['data']),
                ]);
            }
            $this->clients[$clientId]['cursor'] = $highestSeen;
        }
    }

    private function sendHttpErrorAndClose(int $clientId, int $status, string $message): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }
        $body = $message . "\n";
        $response = implode("\r\n", [
            "HTTP/1.1 {$status} {$message}",
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Length: ' . strlen($body),
            'Connection: close',
            '',
            $body,
        ]);
        @fwrite($this->clients[$clientId]['socket'], $response);
        $this->closeClient($clientId);
    }

    private function closeClient(int $clientId): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }
        $client = $this->clients[$clientId];
        if (!empty($client['handshake_complete']) && is_array($client['user'])) {
            $user = $client['user'];
            $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
            $username = (string)($user['username'] ?? '');
            $remoteIp = (string)($client['remote_ip'] ?? '');
            $this->logger->info(sprintf(
                'Realtime websocket client disconnected: ip=%s username=%s user_id=%d client_id=%d',
                $remoteIp !== '' ? $remoteIp : '-',
                $username !== '' ? $username : '-',
                $userId,
                $clientId
            ));
        }
        $socket = $this->clients[$clientId]['socket'];
        @fclose($socket);
        unset($this->clients[$clientId]);
    }

    private function logActiveConnectionsIfDue(): void
    {
        $now = time();
        if ($this->lastActiveConnectionsLogAt !== 0 && ($now - $this->lastActiveConnectionsLogAt) < 60) {
            return;
        }

        $activeConnections = [];
        foreach ($this->clients as $clientId => $client) {
            if (empty($client['handshake_complete']) || !is_array($client['user'])) {
                continue;
            }
            $user = $client['user'];
            $activeConnections[] = [
                'client_id' => (int)$clientId,
                'user_id' => (int)($user['user_id'] ?? $user['id'] ?? 0),
                'username' => (string)($user['username'] ?? ''),
                'remote_ip' => (string)($client['remote_ip'] ?? ''),
            ];
        }

        if (!$activeConnections) {
            return;
        }

        $this->lastActiveConnectionsLogAt = $now;
        $parts = [];
        foreach ($activeConnections as $connection) {
            $parts[] = sprintf(
                'ip=%s username=%s user_id=%d client_id=%d',
                $connection['remote_ip'] !== '' ? $connection['remote_ip'] : '-',
                $connection['username'] !== '' ? $connection['username'] : '-',
                $connection['user_id'],
                $connection['client_id']
            );
        }
        $this->logger->debug(sprintf(
            'Realtime websocket active connections (%d): %s',
            count($activeConnections),
            implode('; ', $parts)
        ));
    }

    private function sendJson(int $clientId, array $payload): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }
        $json = json_encode($payload);
        if ($json === false) {
            return;
        }
        @fwrite($this->clients[$clientId]['socket'], $this->encodeFrame($json));
    }

    private function sendControlFrame(int $clientId, int $opcode, string $payload = ''): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }
        @fwrite($this->clients[$clientId]['socket'], $this->encodeFrame($payload, $opcode));
    }

    /**
     * @return array{0: array<string,string>|null, 1: array<string,string>}
     */
    private function parseHttpRequest(string $request): array
    {
        $lines = preg_split("/\r\n/", trim($request));
        $requestLineRaw = array_shift($lines);
        if (!$requestLineRaw) {
            return [null, []];
        }
        $parts = explode(' ', $requestLineRaw, 3);
        if (count($parts) < 3) {
            return [null, []];
        }
        $requestLine = [
            'method' => $parts[0],
            'target' => $parts[1],
            'version' => $parts[2],
        ];
        $headers = [];
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return [$requestLine, $headers];
    }

    private function extractSessionId(string $cookieHeader): ?string
    {
        foreach (explode(';', $cookieHeader) as $pair) {
            $pair = trim($pair);
            if (!str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            if (trim($name) === 'binktermphp_session') {
                return trim($value);
            }
        }
        return null;
    }

    /**
     * @return array{opcode:int,payload:string,remaining:string}|null
     */
    private function decodeFrame(string $buffer): ?array
    {
        $length = strlen($buffer);
        if ($length < 2) {
            return null;
        }

        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $opcode = $first & 0x0F;
        $masked = ($second & 0x80) !== 0;
        $payloadLength = $second & 0x7F;
        $offset = 2;

        if ($payloadLength === 126) {
            if ($length < 4) {
                return null;
            }
            $payloadLength = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLength === 127) {
            if ($length < 10) {
                return null;
            }
            $lengthParts = unpack('N2', substr($buffer, 2, 8));
            $payloadLength = ($lengthParts[1] << 32) | $lengthParts[2];
            $offset = 10;
        }

        $maskKey = '';
        if ($masked) {
            if ($length < $offset + 4) {
                return null;
            }
            $maskKey = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if ($length < $offset + $payloadLength) {
            return null;
        }

        $payload = substr($buffer, $offset, $payloadLength);
        if ($masked) {
            $payload = $this->applyMask($payload, $maskKey);
        }

        return [
            'opcode' => $opcode,
            'payload' => $payload,
            'remaining' => substr($buffer, $offset + $payloadLength),
        ];
    }

    private function encodeFrame(string $payload, int $opcode = 0x1): string
    {
        $length = strlen($payload);
        $head = chr(0x80 | ($opcode & 0x0F));
        if ($length <= 125) {
            return $head . chr($length) . $payload;
        }
        if ($length <= 65535) {
            return $head . chr(126) . pack('n', $length) . $payload;
        }
        return $head . chr(127) . pack('NN', 0, $length) . $payload;
    }

    private function applyMask(string $payload, string $mask): string
    {
        $out = '';
        $maskLength = strlen($mask);
        $payloadLength = strlen($payload);
        for ($i = 0; $i < $payloadLength; $i++) {
            $out .= $payload[$i] ^ $mask[$i % $maskLength];
        }
        return $out;
    }

    private function tryParseJson(string $str): mixed
    {
        $decoded = json_decode($str, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $str;
    }

    /**
     * @param resource $socket
     */
    private function extractRemoteIp($socket): string
    {
        $peer = @stream_socket_get_name($socket, true);
        if (!is_string($peer) || $peer === '') {
            return '';
        }

        if ($peer[0] === '[') {
            $end = strpos($peer, ']');
            return $end === false ? $peer : substr($peer, 1, $end - 1);
        }

        $pos = strrpos($peer, ':');
        if ($pos === false) {
            return $peer;
        }

        if (substr_count($peer, ':') > 1) {
            return $peer;
        }

        return substr($peer, 0, $pos);
    }
}
