<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
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
    private bool $shutdownRequested = false;

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

            if ($this->shutdownRequested) {
                break;
            }
        }

        if (is_resource($this->serverSocket)) {
            fclose($this->serverSocket);
        }

        $this->cleanupPidFile();
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
                case 'reload_binkp_config':
                    $defaultPidFile = __DIR__ . '/../../data/run/binkp_server.pid';
                    $pidFile = \BinktermPHP\Config::env('BINKP_SERVER_PID_FILE') ?: $defaultPidFile;

                    if (!file_exists($pidFile)) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'binkp_server_not_running']);
                        break;
                    }

                    $pid = (int)trim(file_get_contents($pidFile));
                    if ($pid <= 0) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'invalid_pid']);
                        break;
                    }

                    // Check if process exists
                    if (!posix_kill($pid, 0)) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'process_not_found']);
                        break;
                    }

                    // Send SIGHUP to reload config
                    if (posix_kill($pid, SIGHUP)) {
                        $this->logger->info("Sent SIGHUP to binkp_server (PID: $pid)");
                        $this->writeResponse($client, ['ok' => true, 'result' => ['message' => 'Configuration reload signal sent']]);
                    } else {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'failed_to_send_signal']);
                    }
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
                    $decoded = $this->applyWebdoorManifestConfig($decoded);
                    $this->writeWebdoorsConfig($decoded);
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getWebdoorsConfig()]);
                    break;
                case 'activate_webdoors_config':
                    $this->activateWebdoorsConfig();
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getWebdoorsConfig()]);
                    break;
                case 'get_dosdoors_config':
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getDosdoorsConfig()]);
                    break;
                case 'save_dosdoors_config':
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
                    $this->writeDosdoorsConfig($decoded);
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getDosdoorsConfig()]);
                    break;
                case 'get_filearea_rules':
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getFileAreaRulesConfig()]);
                    break;
                case 'save_filearea_rules':
                    $json = $data['json'] ?? null;
                    if (!is_string($json) || trim($json) === '') {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'missing_json']);
                        break;
                    }
                    $decoded = json_decode($json, true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'invalid_json']);
                        break;
                    }
                    $this->writeFileAreaRulesConfig($decoded);
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getFileAreaRulesConfig()]);
                    break;
                case 'get_taglines':
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getTaglinesConfig()]);
                    break;
                case 'save_taglines':
                    $text = $data['text'] ?? '';
                    if (!is_string($text)) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'invalid_text']);
                        break;
                    }
                    $this->writeTaglinesConfig($text);
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getTaglinesConfig()]);
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
                case 'list_shell_art':
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->listShellArt()]);
                    break;
                case 'upload_shell_art':
                    $name = $data['name'] ?? '';
                    $originalName = $data['original_name'] ?? '';
                    $contentBase64 = $data['content_base64'] ?? '';
                    $result = $this->uploadShellArt((string)$contentBase64, (string)$name, (string)$originalName);
                    $this->writeResponse($client, ['ok' => true, 'result' => $result]);
                    break;
                case 'delete_shell_art':
                    $name = $data['name'] ?? '';
                    $this->deleteShellArt((string)$name);
                    $this->writeResponse($client, ['ok' => true, 'result' => ['success' => true]]);
                    break;
                case 'list_custom_templates':
                    $templates = $this->listCustomTemplates();
                    $this->writeResponse($client, ['ok' => true, 'result' => $templates]);
                    break;
                case 'get_custom_template':
                    $path = (string)($data['path'] ?? '');
                    $template = $this->getCustomTemplate($path);
                    $this->writeResponse($client, ['ok' => true, 'result' => $template]);
                    break;
                case 'save_custom_template':
                    $path = (string)($data['path'] ?? '');
                    $content = (string)($data['content'] ?? '');
                    $result = $this->saveCustomTemplate($path, $content);
                    $this->writeResponse($client, ['ok' => true, 'result' => $result]);
                    break;
                case 'delete_custom_template':
                    $path = (string)($data['path'] ?? '');
                    $result = $this->deleteCustomTemplate($path);
                    $this->writeResponse($client, ['ok' => true, 'result' => $result]);
                    break;
                case 'install_custom_template':
                    $source = (string)($data['source'] ?? '');
                    $overwrite = !empty($data['overwrite']);
                    $result = $this->installCustomTemplate($source, $overwrite);
                    $this->writeResponse($client, ['ok' => true, 'result' => $result]);
                    break;
                case 'get_mrc_config':
                    $mrcConfig = \BinktermPHP\Mrc\MrcConfig::getInstance();
                    $this->writeResponse($client, ['ok' => true, 'result' => $mrcConfig->getFullConfig()]);
                    break;
                case 'set_mrc_config':
                    $payload = is_array($data['config'] ?? null) ? $data['config'] : [];
                    if (!is_array($payload)) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'invalid_config']);
                        break;
                    }
                    $mrcConfig = \BinktermPHP\Mrc\MrcConfig::getInstance();
                    $mrcConfig->setFullConfig($payload);
                    $this->writeResponse($client, ['ok' => true, 'result' => $mrcConfig->getFullConfig()]);
                    break;
                case 'restart_mrc_daemon':
                    $defaultPidFile = __DIR__ . '/../../data/run/mrc_daemon.pid';
                    $pidFile = \BinktermPHP\Config::env('MRC_DAEMON_PID_FILE') ?: $defaultPidFile;

                    // Stop daemon if running
                    if (file_exists($pidFile)) {
                        $pid = (int)trim(file_get_contents($pidFile));
                        if ($pid > 0 && posix_kill($pid, 0)) {
                            posix_kill($pid, SIGTERM);
                            // Wait for shutdown
                            for ($i = 0; $i < 10; $i++) {
                                usleep(100000); // 100ms
                                if (!posix_kill($pid, 0)) {
                                    break;
                                }
                            }
                        }
                    }

                    // Start daemon
                    $result = $this->runCommand([PHP_BINARY, 'scripts/mrc_daemon.php', '--daemon', "--pid-file=$pidFile"]);
                    $this->logCommandResult('restart_mrc_daemon', $result);
                    $this->writeResponse($client, ['ok' => true, 'result' => $result]);
                case 'get_appearance_config':
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getAppearanceConfig()]);
                    break;
                case 'set_appearance_config':
                    $config = is_array($data['config'] ?? null) ? $data['config'] : [];
                    $this->writeAppearanceConfig($config);
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getAppearanceConfig()]);
                    break;
                case 'set_system_news':
                    $text = $data['text'] ?? '';
                    if (!is_string($text)) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'invalid_text']);
                        break;
                    }
                    $this->writeSystemNews($text);
                    $this->writeResponse($client, ['ok' => true, 'result' => ['success' => true]]);
                    break;
                case 'set_house_rules':
                    $text = $data['text'] ?? '';
                    if (!is_string($text)) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'invalid_text']);
                        break;
                    }
                    $this->writeHouseRules($text);
                    $this->writeResponse($client, ['ok' => true, 'result' => ['success' => true]]);
                    break;
                case 'stop_services':
                    $results = $this->stopServices();
                    $this->writeResponse($client, ['ok' => true, 'result' => $results]);
                    $this->shutdownRequested = true;
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
        $this->logger->debug('Admin daemon command completed', [
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

    private function cleanupPidFile(): void
    {
        if ($this->pidFile && file_exists($this->pidFile)) {
            @unlink($this->pidFile);
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

    private function stopServices(): array
    {
        $runDir = __DIR__ . '/../../data/run';
        $schedulerPid = Config::env('BINKP_SCHEDULER_PID_FILE', $runDir . '/binkp_scheduler.pid');
        $serverPid = Config::env('BINKP_SERVER_PID_FILE', $runDir . '/binkp_server.pid');

        return [
            'binkp_scheduler' => $this->stopProcess($schedulerPid, 'binkp_scheduler'),
            'binkp_server' => $this->stopProcess($serverPid, 'binkp_server'),
            'admin_daemon' => ['status' => 'stopping']
        ];
    }

    private function stopProcess(string $pidFile, string $name): array
    {
        if (!file_exists($pidFile)) {
            return ['status' => 'missing_pid_file'];
        }

        $pid = trim((string)@file_get_contents($pidFile));
        if ($pid === '' || !ctype_digit($pid)) {
            return ['status' => 'invalid_pid'];
        }

        $pidInt = (int)$pid;
        if (!$this->isProcessRunning($pidInt)) {
            return ['status' => 'not_running'];
        }

        $terminated = $this->sendSignal($pidInt, 15);
        if (!$terminated) {
            return ['status' => 'signal_failed'];
        }

        usleep(500000);
        if ($this->isProcessRunning($pidInt)) {
            $this->sendSignal($pidInt, 9);
            return ['status' => 'killed'];
        }

        return ['status' => 'stopped'];
    }

    private function isProcessRunning(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            @exec('tasklist /FI "PID eq ' . $pid . '"', $output);
            foreach ($output as $line) {
                if (preg_match('/\b' . preg_quote((string)$pid, '/') . '\b/', $line)) {
                    return true;
                }
            }
            return false;
        }

        $output = [];
        @exec('ps -p ' . $pid, $output);
        return count($output) > 1;
    }

    private function sendSignal(int $pid, int $signal): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, $signal);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $flag = $signal === 9 ? ' /F' : '';
            @exec('taskkill /PID ' . $pid . $flag);
            return true;
        }

        $cmd = $signal === 9 ? 'kill -9 ' : 'kill ';
        @exec($cmd . $pid);
        return true;
    }

    private function getFileAreaRulesConfig(): array
    {
        $configPath = $this->getFileAreaRulesConfigPath();
        $examplePath = $this->getFileAreaRulesExamplePath();

        $active = file_exists($configPath);
        $configJson = $active ? file_get_contents($configPath) : null;
        $exampleJson = file_exists($examplePath) ? file_get_contents($examplePath) : null;

        return [
            'active' => $active,
            'config_json' => $configJson,
            'example_json' => $exampleJson
        ];
    }

    private function writeFileAreaRulesConfig(array $config): void
    {
        $configPath = $this->getFileAreaRulesConfigPath();
        $configDir = dirname($configPath);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode file area rules config');
        }

        file_put_contents($configPath, $json . PHP_EOL);
    }

    private function getFileAreaRulesConfigPath(): string
    {
        return __DIR__ . '/../../config/filearea_rules.json';
    }

    private function getFileAreaRulesExamplePath(): string
    {
        return __DIR__ . '/../../config/filearea_rules.json.example';
    }

    private function getTaglinesConfig(): array
    {
        $path = $this->getTaglinesPath();
        $text = file_exists($path) ? file_get_contents($path) : '';

        return [
            'path' => $path,
            'text' => $text === false ? '' : $text
        ];
    }

    private function writeTaglinesConfig(string $text): void
    {
        $path = $this->getTaglinesPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $normalized = rtrim($normalized, "\n");
        $content = $normalized === '' ? '' : $normalized . "\n";

        file_put_contents($path, $content);
    }

    private function getTaglinesPath(): string
    {
        return __DIR__ . '/../../config/taglines.txt';
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

        $json = file_get_contents($examplePath);
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new \RuntimeException('webdoors.json.example is invalid');
        }
        $decoded = $this->applyWebdoorManifestConfig($decoded);
        $this->writeWebdoorsConfig($decoded);
    }

    private function getWebdoorsConfigPath(): string
    {
        return __DIR__ . '/../../config/webdoors.json';
    }

    private function getWebdoorsExamplePath(): string
    {
        return __DIR__ . '/../../config/webdoors.json.example';
    }

    private function applyWebdoorManifestConfig(array $config): array
    {
        $manifests = \BinktermPHP\WebDoorManifest::listManifests();
        if (empty($manifests)) {
            return $config;
        }

        foreach ($manifests as $entry) {
            $manifest = $entry['manifest'];
            $gameId = $entry['id'];
            $manifestConfig = $manifest['config'] ?? null;
            if (!is_array($manifestConfig) || $manifestConfig === []) {
                continue;
            }

            if (!isset($config[$gameId]) || !is_array($config[$gameId])) {
                continue;
            }
            if (empty($config[$gameId]['enabled'])) {
                continue;
            }

            foreach ($manifestConfig as $key => $value) {
                if (!array_key_exists($key, $config[$gameId])) {
                    $config[$gameId][$key] = $value;
                }
            }
        }

        return $config;
    }

    private function getDosdoorsConfig(): array
    {
        $configPath = $this->getDosdoorsConfigPath();

        $active = file_exists($configPath);
        $configJson = $active ? file_get_contents($configPath) : null;

        return [
            'active' => $active,
            'config_json' => $configJson
        ];
    }

    private function writeDosdoorsConfig(array $config): void
    {
        $configPath = $this->getDosdoorsConfigPath();
        $configDir = dirname($configPath);
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode dosdoors config');
        }

        file_put_contents($configPath, $json . PHP_EOL);
    }

    private function getDosdoorsConfigPath(): string
    {
        return __DIR__ . '/../../config/dosdoors.json';
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

    private function getCustomTemplatesBasePath(): string
    {
        return rtrim(Config::TEMPLATE_PATH, '/\\') . '/custom';
    }

    private function resolveCustomTemplatePath(string $relativePath, bool $allowExample = false): ?string
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '' || strpos($relativePath, "\0") !== false) {
            return null;
        }

        if (preg_match('#(^|/)\.{1,2}(/|$)#', $relativePath)) {
            return null;
        }

        if (!preg_match('#^[A-Za-z0-9._/-]+$#', $relativePath)) {
            return null;
        }

        $pattern = $allowExample ? '#\.twig(\.example)?$#' : '#\.twig$#';
        if (!preg_match($pattern, $relativePath)) {
            return null;
        }

        $basePath = $this->getCustomTemplatesBasePath();
        $fullPath = $basePath . '/' . $relativePath;
        $baseReal = realpath($basePath);
        $dirReal = realpath(dirname($fullPath));

        if ($baseReal === false || $dirReal === false || strpos($dirReal, $baseReal) !== 0) {
            return null;
        }

        return $fullPath;
    }

    private function listCustomTemplates(): array
    {
        $basePath = $this->getCustomTemplatesBasePath();
        if (!is_dir($basePath)) {
            return [];
        }

        $templates = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $filename = $fileInfo->getFilename();
            if (!preg_match('/\.twig(\.example)?$/', $filename)) {
                continue;
            }

            $fullPath = $fileInfo->getPathname();
            $relativePath = str_replace('\\', '/', substr($fullPath, strlen($basePath) + 1));

            $templates[] = [
                'path' => $relativePath,
                'size' => $fileInfo->getSize(),
                'modified_at' => date('c', $fileInfo->getMTime())
            ];
        }

        usort($templates, function($a, $b) {
            return strcasecmp($a['path'], $b['path']);
        });

        return $templates;
    }

    private function getCustomTemplate(string $path): array
    {
        $fullPath = $this->resolveCustomTemplatePath($path, true);
        if ($fullPath === null || !is_file($fullPath)) {
            throw new \RuntimeException('Template not found');
        }

        $content = @file_get_contents($fullPath);
        if ($content === false) {
            throw new \RuntimeException('Failed to read template');
        }

        return [
            'path' => $path,
            'content' => $content
        ];
    }

    private function saveCustomTemplate(string $path, string $content): array
    {
        if (!preg_match('#\.twig$#', $path)) {
            throw new \RuntimeException('Only .twig files can be saved');
        }

        $fullPath = $this->resolveCustomTemplatePath($path, false);
        if ($fullPath === null) {
            throw new \RuntimeException('Invalid template path');
        }

        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            throw new \RuntimeException('Directory does not exist');
        }

        $maxBytes = 512 * 1024;
        if (strlen($content) > $maxBytes) {
            throw new \RuntimeException('Template is too large (max 512KB)');
        }

        if (@file_put_contents($fullPath, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to save template');
        }

        return ['success' => true, 'path' => $path];
    }

    private function deleteCustomTemplate(string $path): array
    {
        if (!preg_match('#\.twig$#', $path)) {
            throw new \RuntimeException('Only .twig files can be deleted');
        }

        $fullPath = $this->resolveCustomTemplatePath($path, false);
        if ($fullPath === null || !is_file($fullPath)) {
            throw new \RuntimeException('Template not found');
        }

        if (!@unlink($fullPath)) {
            throw new \RuntimeException('Failed to delete template');
        }

        return ['success' => true];
    }

    private function installCustomTemplate(string $source, bool $overwrite): array
    {
        if (!preg_match('#\.twig\.example$#', $source)) {
            throw new \RuntimeException('Source must be a .twig.example file');
        }

        $sourcePath = $this->resolveCustomTemplatePath($source, true);
        if ($sourcePath === null || !is_file($sourcePath)) {
            throw new \RuntimeException('Example template not found');
        }

        $target = preg_replace('#\.example$#', '', $source);
        $targetPath = $this->resolveCustomTemplatePath($target, false);
        if ($targetPath === null) {
            throw new \RuntimeException('Invalid target path');
        }

        if (file_exists($targetPath) && !$overwrite) {
            throw new \RuntimeException('Target template already exists');
        }

        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            throw new \RuntimeException('Directory does not exist');
        }

        if (!@copy($sourcePath, $targetPath)) {
            throw new \RuntimeException('Failed to install template');
        }

        return ['success' => true, 'path' => $target];
    }

    private function getShellArtDir(): string
    {
        return __DIR__ . '/../../data/shell_art';
    }

    private function sanitizeShellArtFilename(string $name): string
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

    private function listShellArt(): array
    {
        $dir = $this->getShellArtDir();
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.ans') ?: [];
        $result = [];
        foreach ($files as $file) {
            $result[] = [
                'name' => basename($file),
                'size' => filesize($file) ?: 0,
                'updated_at' => date('c', filemtime($file) ?: time())
            ];
        }

        usort($result, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $result;
    }

    private function uploadShellArt(string $contentBase64, string $name, string $originalName): array
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

        $safeName = $this->sanitizeShellArtFilename($name !== '' ? $name : $originalName);
        if ($safeName === '') {
            throw new \RuntimeException('Invalid file name');
        }

        $dir = $this->getShellArtDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new \RuntimeException('Failed to create shell_art directory');
        }

        $path = $dir . DIRECTORY_SEPARATOR . $safeName;
        if (@file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Failed to save shell art file');
        }

        return [
            'name' => $safeName,
            'size' => filesize($path) ?: 0,
            'updated_at' => date('c', filemtime($path) ?: time())
        ];
    }

    private function deleteShellArt(string $name): void
    {
        $safeName = $this->sanitizeShellArtFilename($name);
        if ($safeName === '') {
            throw new \RuntimeException('Invalid file name');
        }

        $path = $this->getShellArtDir() . DIRECTORY_SEPARATOR . $safeName;
        if (!is_file($path)) {
            throw new \RuntimeException('Shell art file not found');
        }

        if (!@unlink($path)) {
            throw new \RuntimeException('Failed to delete shell art file');
        }
    }

    private function getAppearanceConfigPath(): string
    {
        return __DIR__ . '/../../data/appearance.json';
    }

    private function getSystemNewsPath(): string
    {
        return __DIR__ . '/../../data/systemnews.md';
    }

    private function getHouseRulesPath(): string
    {
        return __DIR__ . '/../../data/houserules.md';
    }

    private function getAppearanceConfig(): array
    {
        $path = $this->getAppearanceConfigPath();
        $config = null;
        if (file_exists($path)) {
            $json = file_get_contents($path);
            $decoded = json_decode($json, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $config = $decoded;
            }
        }

        $systemNewsPath = $this->getSystemNewsPath();
        $houseRulesPath = $this->getHouseRulesPath();

        return [
            'config' => $config ?? [],
            'system_news' => file_exists($systemNewsPath) ? (file_get_contents($systemNewsPath) ?: '') : null,
            'house_rules' => file_exists($houseRulesPath) ? (file_get_contents($houseRulesPath) ?: '') : null,
        ];
    }

    private function writeAppearanceConfig(array $config): void
    {
        $path = $this->getAppearanceConfigPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode appearance config');
        }

        if (@file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write appearance config');
        }
    }

    private function writeSystemNews(string $text): void
    {
        $path = $this->getSystemNewsPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (@file_put_contents($path, $text, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write system news');
        }
    }

    private function writeHouseRules(string $text): void
    {
        $path = $this->getHouseRulesPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (@file_put_contents($path, $text, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write house rules');
        }
    }

}

