<?php

namespace BinktermPHP\Admin;

use BinktermPHP\Binkp\Logger;
use BinktermPHP\BbsConfig;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Config;

class AdminDaemonServer
{
    private string $socketTarget;
    private string $secret;
    private Logger $logger;
    private ?string $pidFile;
    private ?string $socketPerms;
    private $serverSocket;

    public function __construct(?string $socketTarget = null, ?string $secret = null, ?Logger $logger = null, ?string $pidFile = null, ?string $socketPerms = null)
    {
        $this->socketTarget = $socketTarget
            ?? (Config::env('ADMIN_DAEMON_SOCKET') ?: $this->getDefaultSocketTarget());
        $this->secret = $secret ?? (string)Config::env('ADMIN_DAEMON_SECRET', '');
        $this->logger = $logger ?? new Logger(Config::getLogPath('admin_daemon.log'), 'INFO', true);
        $this->pidFile = $pidFile ?? Config::env('ADMIN_DAEMON_PID_FILE');
        $this->socketPerms = $socketPerms ?? Config::env('ADMIN_DAEMON_SOCKET_PERMS');
    }

    public function run(): void
    {
        if ($this->secret === '') {
            throw new \RuntimeException('ADMIN_DAEMON_SECRET must be set');
        }

        $this->serverSocket = $this->createServerSocket($this->socketTarget);
        $this->writePidFile();

        $this->logger->info('Admin daemon started', ['socket' => $this->socketTarget]);

        while (true) {
            $client = @stream_socket_accept($this->serverSocket, 1);
            if ($client === false) {
                continue;
            }

            $this->handleClient($client);
        }
    }

    private function createServerSocket(string $socketTarget)
    {
        if ($this->isUnixSocket($socketTarget) && PHP_OS_FAMILY === 'Windows') {
            throw new \RuntimeException('Unix sockets are not supported on Windows. Use a tcp://127.0.0.1:PORT socket.');
        }

        if ($this->isUnixSocket($socketTarget)) {
            $path = substr($socketTarget, strlen('unix://'));
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        $server = @stream_socket_server($socketTarget, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (!$server) {
            throw new \RuntimeException("Failed to bind admin daemon socket: {$errstr} ({$errno})");
        }

        if ($this->isUnixSocket($socketTarget)) {
            $this->applySocketPerms(substr($socketTarget, strlen('unix://')));
        }

        return $server;
    }

    private function applySocketPerms(string $path): void
    {
        if (!$this->socketPerms) {
            return;
        }

        $perms = intval($this->socketPerms, 8);
        @chmod($path, $perms);
    }

    private function handleClient($client): void
    {
        stream_set_timeout($client, 10);
        $authed = false;

        while (!feof($client)) {
            $line = fgets($client);
            if ($line === false) {
                break;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $payload = json_decode($line, true);
            if (!is_array($payload)) {
                $this->writeResponse($client, ['ok' => false, 'error' => 'invalid_json']);
                continue;
            }

            if (!$authed) {
                $authed = $this->handleAuth($client, $payload);
                if (!$authed) {
                    break;
                }
                continue;
            }

            $this->handleCommand($client, $payload);
        }

        fclose($client);
    }

    private function handleAuth($client, array $payload): bool
    {
        $auth = $payload['auth'] ?? null;
        if (!$auth || !hash_equals($this->secret, (string)$auth)) {
            $this->logger->warning('Admin daemon auth failed');
            $this->writeResponse($client, ['ok' => false, 'error' => 'unauthorized']);
            return false;
        }

        $this->writeResponse($client, ['ok' => true]);
        return true;
    }

    private function handleCommand($client, array $payload): void
    {
        $cmd = $payload['cmd'] ?? '';
        $data = $payload['data'] ?? [];

        try {
            $this->logger->debug('Admin daemon command received', [
                'cmd' => $cmd,
                'data' => $this->sanitizeLogData(is_array($data) ? $data : [])
            ]);

            switch ($cmd) {
                case 'process_packets':
                    $result = $this->runCommand([PHP_BINARY, 'scripts/process_packets.php']);
                    $this->logCommandResult($cmd, $result);
                    $this->writeResponse($client, ['ok' => true, 'result' => $result]);
                    break;
                case 'get_logs':
                    $lines = (int)($data['lines'] ?? 25);
                    if ($lines <= 0) {
                        $lines = 25;
                    }
                    $logFiles = [
                        'binkp_poll.log' => \BinktermPHP\Config::getLogPath('binkp_poll.log'),
                        'binkp_server.log' => \BinktermPHP\Config::getLogPath('binkp_server.log'),
                        'binkp_scheduler.log' => \BinktermPHP\Config::getLogPath('binkp_scheduler.log'),
                        'admin_daemon.log' => \BinktermPHP\Config::getLogPath('admin_daemon.log')
                    ];
                    $logs = $this->logger->getRecentLogs($lines, $logFiles);
                    $this->writeResponse($client, ['ok' => true, 'result' => $logs]);
                    break;
                case 'crashmail_poll':
                    $result = $this->runCommand([PHP_BINARY, 'scripts/crashmail_poll.php']);
                    $this->logCommandResult($cmd, $result);
                    $this->writeResponse($client, ['ok' => true, 'result' => $result]);
                    break;
                case 'binkp_poll':
                    $upstream = $data['upstream'] ?? null;
                    if (!$upstream) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'missing_upstream']);
                        break;
                    }
                    if ($upstream === 'all') {
                        $result = $this->runCommand([PHP_BINARY, 'scripts/binkp_poll.php', '--all']);
                    } else {
                        $result = $this->runCommand([PHP_BINARY, 'scripts/binkp_poll.php', $upstream]);
                    }
                    $this->logCommandResult($cmd, $result);
                    $this->writeResponse($client, ['ok' => true, 'result' => $result]);
                    break;
                case 'get_bbs_config':
                    BbsConfig::reload();
                    $this->writeResponse($client, ['ok' => true, 'result' => BbsConfig::getConfig()]);
                    break;
                case 'set_bbs_config':
                    $config = is_array($data['config'] ?? null) ? $data['config'] : [];
                    if (!BbsConfig::saveConfig($config)) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'save_failed']);
                        break;
                    }
                    BbsConfig::reload();
                    $this->writeResponse($client, ['ok' => true, 'result' => BbsConfig::getConfig()]);
                    break;
                case 'get_system_config':
                    $binkpConfig = BinkpConfig::getInstance();
                    $this->writeResponse($client, ['ok' => true, 'result' => $binkpConfig->getSystemConfig()]);
                    break;
                case 'set_system_config':
                    $payload = is_array($data['config'] ?? null) ? $data['config'] : [];
                    $binkpConfig = BinkpConfig::getInstance();
                    $binkpConfig->setSystemConfig(
                        $payload['name'] ?? null,
                        $payload['address'] ?? null,
                        $payload['sysop'] ?? null,
                        $payload['location'] ?? null,
                        $payload['hostname'] ?? null,
                        $payload['origin'] ?? null,
                        $payload['timezone'] ?? null
                    );
                    $this->writeResponse($client, ['ok' => true, 'result' => $binkpConfig->getSystemConfig()]);
                    break;
                case 'get_binkp_config':
                    $binkpConfig = BinkpConfig::getInstance();
                    $this->writeResponse($client, ['ok' => true, 'result' => $binkpConfig->getBinkpConfig()]);
                    break;
                case 'set_binkp_config':
                    $payload = is_array($data['config'] ?? null) ? $data['config'] : [];
                    $binkpConfig = BinkpConfig::getInstance();
                    $binkpConfig->setBinkpConfig(
                        $payload['port'] ?? null,
                        $payload['timeout'] ?? null,
                        $payload['max_connections'] ?? null,
                        $payload['bind_address'] ?? null,
                        $payload['preserve_processed_packets'] ?? null
                    );
                    $this->writeResponse($client, ['ok' => true, 'result' => $binkpConfig->getBinkpConfig()]);
                    break;
                case 'get_full_binkp_config':
                    $binkpConfig = BinkpConfig::getInstance();
                    $this->writeResponse($client, ['ok' => true, 'result' => $binkpConfig->getFullConfig()]);
                    break;
                case 'set_full_binkp_config':
                    $payload = is_array($data['config'] ?? null) ? $data['config'] : [];
                    if (!is_array($payload)) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'invalid_config']);
                        break;
                    }
                    $payload['system'] = is_array($payload['system'] ?? null) ? $payload['system'] : [];
                    $payload['binkp'] = is_array($payload['binkp'] ?? null) ? $payload['binkp'] : [];
                    $payload['uplinks'] = is_array($payload['uplinks'] ?? null) ? $payload['uplinks'] : [];
                    $payload['security'] = is_array($payload['security'] ?? null) ? $payload['security'] : [];
                    $payload['crashmail'] = is_array($payload['crashmail'] ?? null) ? $payload['crashmail'] : [];

                    $binkpConfig = BinkpConfig::getInstance();
                    $existing = $binkpConfig->getFullConfig();
                    $merged = is_array($existing) ? $existing : [];
                    $merged['system'] = array_merge($merged['system'] ?? [], $payload['system']);
                    $merged['binkp'] = array_merge($merged['binkp'] ?? [], $payload['binkp']);
                    $merged['security'] = array_merge($merged['security'] ?? [], $payload['security']);
                    $merged['crashmail'] = array_merge($merged['crashmail'] ?? [], $payload['crashmail']);
                    $merged['uplinks'] = $this->mergeUplinks($merged['uplinks'] ?? [], $payload['uplinks']);
                    $binkpConfig->setFullConfig($merged);
                    $this->writeResponse($client, ['ok' => true, 'result' => $binkpConfig->getFullConfig()]);
                    break;
                case 'get_webdoors_config':
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getWebdoorsConfig()]);
                    break;
                case 'save_webdoors_config':
                    $json = $data['json'] ?? null;
                    if (!is_string($json) || trim($json) === '') {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'missing_json']);
                        break;
                    }
                    $decoded = json_decode($json, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'invalid_json']);
                        break;
                    }
                    $this->writeWebdoorsConfig($decoded);
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getWebdoorsConfig()]);
                    break;
                case 'activate_webdoors_config':
                    $this->activateWebdoorsConfig();
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getWebdoorsConfig()]);
                    break;
                case 'list_ads':
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->listAds()]);
                    break;
                case 'upload_ad':
                    $name = $data['name'] ?? '';
                    $originalName = $data['original_name'] ?? '';
                    $contentBase64 = $data['content_base64'] ?? '';
                    $result = $this->uploadAd((string)$contentBase64, (string)$name, (string)$originalName);
                    $this->writeResponse($client, ['ok' => true, 'result' => $result]);
                    break;
                case 'delete_ad':
                    $name = $data['name'] ?? '';
                    $this->deleteAd((string)$name);
                    $this->writeResponse($client, ['ok' => true, 'result' => ['success' => true]]);
                    break;
                default:
                    $this->writeResponse($client, ['ok' => false, 'error' => 'unknown_command']);
                    break;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Admin daemon command error', ['error' => $e->getMessage(), 'cmd' => $cmd]);
            $this->writeResponse($client, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private function runCommand(array $command): array
    {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, __DIR__ . '/../../');
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start command');
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr
        ];
    }

    private function writeResponse($client, array $payload): void
    {
        fwrite($client, json_encode($payload) . "\n");
    }

    private function logCommandResult(string $cmd, array $result): void
    {
        $this->logger->info('Admin daemon command completed', [
            'cmd' => $cmd,
            'exit_code' => $result['exit_code'] ?? null
        ]);
    }

    private function sanitizeLogData(array $data): array
    {
        if (array_key_exists('secret', $data)) {
            $data['secret'] = '[redacted]';
        }

        return $data;
    }

    private function writePidFile(): void
    {
        if (!$this->pidFile) {
            return;
        }

        if (@file_put_contents($this->pidFile, (string)getmypid()) !== false) {
            @chmod($this->pidFile, 0644);
        }
    }

    private function isUnixSocket(string $socketTarget): bool
    {
        return strncmp($socketTarget, 'unix://', 7) === 0;
    }

    private function getDefaultSocketTarget(): string
    {
        return 'tcp://127.0.0.1:9065';
    }

    private function getWebdoorsConfig(): array
    {
        $configPath = $this->getWebdoorsConfigPath();
        $examplePath = $this->getWebdoorsExamplePath();

        $active = file_exists($configPath);
        $configJson = $active ? file_get_contents($configPath) : null;
        $exampleJson = file_exists($examplePath) ? file_get_contents($examplePath) : null;

        return [
            'active' => $active,
            'config_json' => $configJson,
            'example_json' => $exampleJson
        ];
    }

    private function writeWebdoorsConfig(array $config): void
    {
        $configPath = $this->getWebdoorsConfigPath();
        $configDir = dirname($configPath);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode webdoors config');
        }

        file_put_contents($configPath, $json . PHP_EOL);
    }

    private function activateWebdoorsConfig(): void
    {
        $configPath = $this->getWebdoorsConfigPath();
        if (file_exists($configPath)) {
            return;
        }

        $examplePath = $this->getWebdoorsExamplePath();
        if (!file_exists($examplePath)) {
            throw new \RuntimeException('webdoors.json.example not found');
        }

        $configDir = dirname($configPath);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        copy($examplePath, $configPath);
    }

    private function getWebdoorsConfigPath(): string
    {
        return __DIR__ . '/../../config/webdoors.json';
    }

    private function getWebdoorsExamplePath(): string
    {
        return __DIR__ . '/../../config/webdoors.json.example';
    }

    private function mergeUplinks(array $existing, array $incoming): array
    {
        $indexed = [];
        foreach ($existing as $uplink) {
            if (!is_array($uplink)) {
                continue;
            }
            $key = $uplink['address'] ?? ($uplink['hostname'] ?? null);
            if ($key === null) {
                continue;
            }
            $indexed[strtolower((string)$key)] = $uplink;
        }

        $merged = [];
        foreach ($incoming as $uplink) {
            if (!is_array($uplink)) {
                continue;
            }
            $key = $uplink['address'] ?? ($uplink['hostname'] ?? null);
            $lookup = $key !== null ? strtolower((string)$key) : null;
            $base = $lookup !== null && isset($indexed[$lookup]) ? $indexed[$lookup] : [];
            $merged[] = array_merge($base, $uplink);
        }

        return $merged;
    }

    private function listAds(): array
    {
        $adsDir = $this->getAdsDir();
        if (!is_dir($adsDir)) {
            return [];
        }

        $ads = [];
        $files = glob($adsDir . DIRECTORY_SEPARATOR . '*.ans') ?: [];
        foreach ($files as $file) {
            $ads[] = [
                'name' => basename($file),
                'size' => filesize($file) ?: 0,
                'updated_at' => date('c', filemtime($file) ?: time())
            ];
        }

        usort($ads, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return $ads;
    }

    private function uploadAd(string $contentBase64, string $name, string $originalName): array
    {
        if ($contentBase64 === '') {
            throw new \RuntimeException('Missing content');
        }

        $content = base64_decode($contentBase64, true);
        if ($content === false) {
            throw new \RuntimeException('Invalid content encoding');
        }

        $maxSize = 1024 * 1024;
        if (strlen($content) > $maxSize) {
            throw new \RuntimeException('File is too large (max 1MB)');
        }

        $safeName = $this->sanitizeAdFilename($name !== '' ? $name : $originalName);
        if ($safeName === '') {
            throw new \RuntimeException('Invalid file name');
        }

        $adsDir = $this->getAdsDir();
        if (!is_dir($adsDir) && !@mkdir($adsDir, 0775, true)) {
            throw new \RuntimeException('Failed to create ads directory');
        }

        $path = $adsDir . DIRECTORY_SEPARATOR . $safeName;
        if (@file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Failed to save advertisement');
        }

        return [
            'name' => $safeName,
            'size' => filesize($path) ?: 0,
            'updated_at' => date('c', filemtime($path) ?: time())
        ];
    }

    private function deleteAd(string $name): void
    {
        $safeName = $this->sanitizeAdFilename($name);
        if ($safeName === '') {
            throw new \RuntimeException('Invalid file name');
        }

        $adsDir = $this->getAdsDir();
        $path = $adsDir . DIRECTORY_SEPARATOR . $safeName;
        if (!is_file($path)) {
            throw new \RuntimeException('Advertisement not found');
        }

        if (!@unlink($path)) {
            throw new \RuntimeException('Failed to delete advertisement');
        }
    }

    private function sanitizeAdFilename(string $name): string
    {
        $safe = basename($name);
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $safe);
        $safe = trim($safe, '._');
        if ($safe === '') {
            return '';
        }
        if (substr($safe, -4) !== '.ans') {
            $safe .= '.ans';
        }
        return $safe;
    }

    private function getAdsDir(): string
    {
        return __DIR__ . '/../../bbs_ads';
    }

}
