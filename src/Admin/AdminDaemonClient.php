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

    public function crashmailPoll(): array
    {
        return $this->sendCommand('crashmail_poll');
    }

    public function getLogs(int $lines = 25): array
    {
        return $this->sendCommand('get_logs', ['lines' => $lines]);
    }

    public function binkPoll(string $upstream): array
    {
        return $this->sendCommand('binkp_poll', ['upstream' => $upstream]);
    }

    public function getBbsConfig(): array
    {
        return $this->sendCommand('get_bbs_config');
    }

    public function setBbsConfig(array $config): array
    {
        return $this->sendCommand('set_bbs_config', ['config' => $config]);
    }

    public function getSystemConfig(): array
    {
        return $this->sendCommand('get_system_config');
    }

    public function setSystemConfig(array $config): array
    {
        return $this->sendCommand('set_system_config', ['config' => $config]);
    }

    public function getBinkpConfig(): array
    {
        return $this->sendCommand('get_binkp_config');
    }

    public function setBinkpConfig(array $config): array
    {
        return $this->sendCommand('set_binkp_config', ['config' => $config]);
    }

    public function getFullBinkpConfig(): array
    {
        return $this->sendCommand('get_full_binkp_config');
    }

    public function setFullBinkpConfig(array $config): array
    {
        return $this->sendCommand('set_full_binkp_config', ['config' => $config]);
    }

    public function getWebdoorsConfig(): array
    {
        return $this->sendCommand('get_webdoors_config');
    }

    public function saveWebdoorsConfig(string $json): array
    {
        return $this->sendCommand('save_webdoors_config', ['json' => $json]);
    }

    public function activateWebdoorsConfig(): array
    {
        return $this->sendCommand('activate_webdoors_config');
    }

    public function getFileAreaRulesConfig(): array
    {
        return $this->sendCommand('get_filearea_rules');
    }

    public function saveFileAreaRulesConfig(string $json): array
    {
        return $this->sendCommand('save_filearea_rules', ['json' => $json]);
    }

    public function listAds(): array
    {
        return $this->sendCommand('list_ads');
    }

    public function uploadAd(string $contentBase64, string $name = '', string $originalName = ''): array
    {
        return $this->sendCommand('upload_ad', [
            'content_base64' => $contentBase64,
            'name' => $name,
            'original_name' => $originalName
        ]);
    }

    public function deleteAd(string $name): array
    {
        return $this->sendCommand('delete_ad', ['name' => $name]);
    }

    public function listCustomTemplates(): array
    {
        return $this->sendCommand('list_custom_templates');
    }

    public function getCustomTemplate(string $path): array
    {
        return $this->sendCommand('get_custom_template', ['path' => $path]);
    }

    public function saveCustomTemplate(string $path, string $content): array
    {
        return $this->sendCommand('save_custom_template', [
            'path' => $path,
            'content' => $content
        ]);
    }

    public function deleteCustomTemplate(string $path): array
    {
        return $this->sendCommand('delete_custom_template', ['path' => $path]);
    }

    public function installCustomTemplate(string $source, bool $overwrite = false): array
    {
        return $this->sendCommand('install_custom_template', [
            'source' => $source,
            'overwrite' => $overwrite
        ]);
    }

    public function stopServices(): array
    {
        return $this->sendCommand('stop_services');
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
        return 'tcp://127.0.0.1:9065';
    }
}
