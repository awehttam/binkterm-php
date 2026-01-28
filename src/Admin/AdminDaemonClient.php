<?php

namespace BinktermPHP\Admin;

use BinktermPHP\Config;

class AdminDaemonClient
{
    private string $socketTarget;
    private string $secret;
    private $socket;

    public function __construct(?string $socketTarget = null, ?string $secret = null)
    {
        $this->socketTarget = $socketTarget
            ?? (Config::env('ADMIN_DAEMON_SOCKET') ?: $this->getDefaultSocketTarget());
        $this->secret = $secret ?? (string)Config::env('ADMIN_DAEMON_SECRET', '');
    }

    public function processPackets(): array
    {
        return $this->sendCommand('process_packets');
    }

    public function binkPoll(string $upstream): array
    {
        return $this->sendCommand('binkp_poll', ['upstream' => $upstream]);
    }

    public function close(): void
    {
        if ($this->socket && is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    private function connect(): void
    {
        if ($this->secret === '') {
            throw new \RuntimeException('ADMIN_DAEMON_SECRET must be set');
        }

        if ($this->socket && is_resource($this->socket)) {
            return;
        }

        $this->socket = @stream_socket_client($this->socketTarget, $errno, $errstr, 5);
        if (!$this->socket) {
            throw new \RuntimeException("Failed to connect to admin daemon: {$errstr} ({$errno})");
        }

        $this->writeLine(['auth' => $this->secret]);
        $response = $this->readResponse();
        if (!($response['ok'] ?? false)) {
            throw new \RuntimeException('Admin daemon auth failed');
        }
    }

    private function sendCommand(string $cmd, array $data = []): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->connect();

                $this->writeLine([
                    'cmd' => $cmd,
                    'data' => $data
                ]);

                $response = $this->readResponse();
                if (!($response['ok'] ?? false)) {
                    $error = $response['error'] ?? 'unknown_error';
                    throw new \RuntimeException("Admin daemon error: {$error}");
                }

                return $response['result'] ?? [];
            } catch (\RuntimeException $e) {
                $this->close();
                if ($attempt === 1) {
                    throw $e;
                }
            }
        }

        return [];
    }

    private function writeLine(array $payload): void
    {
        $written = @fwrite($this->socket, json_encode($payload) . "\n");
        if ($written === false) {
            $this->close();
            throw new \RuntimeException('Admin daemon connection closed while sending request');
        }
    }

    private function readResponse(): array
    {
        $line = @fgets($this->socket);
        if ($line === false) {
            $this->close();
            throw new \RuntimeException('Admin daemon closed connection');
        }

        $data = json_decode(trim($line), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid response from admin daemon');
        }

        return $data;
    }

    private function getDefaultSocketTarget(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 'tcp://127.0.0.1:9065';
        }

        return 'unix:///tmp/binkterm_admin.sock';
    }
}
