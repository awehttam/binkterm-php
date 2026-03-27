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

    /** @var resource|null Raw pg_* connection used for sse_events cleanup */
    private $pgConn = null;

    /** Loop iteration counter used to schedule periodic sse_events cleanup. */
    private int $loopIteration = 0;

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

        $this->initPgConnection();

        $canFork = function_exists('pcntl_fork')
            && function_exists('posix_getppid')
            && function_exists('posix_kill');

        $parentPid = getmypid();

        if ($canFork && function_exists('pcntl_signal')) {
            // Reap zombie child processes
            pcntl_signal(SIGCHLD, function () {
                while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
                    // reaped
                }
            });
            // Allow a child handling stop_services to signal us to shut down
            pcntl_signal(SIGTERM, function () {
                $this->shutdownRequested = true;
            });
        }

        while (true) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            if ($this->shutdownRequested) {
                break;
            }


            // Prune stale SSE events roughly once per minute (600 iterations × 0.1 s timeout).
            if (++$this->loopIteration % 600 === 0) {
                $this->pruneSSEEvents();
            }

            $client = @stream_socket_accept($this->serverSocket, 0.1);
            if ($client === false) {
                continue;
            }

            if ($canFork) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    // Fork failed — fall back to synchronous handling
                    $this->handleClient($client);
                    if ($this->shutdownRequested) {
                        break;
                    }
                } elseif ($pid === 0) {
                    // Child: close the server socket and handle the client
                    fclose($this->serverSocket);
                    $this->handleClient($client);
                    if ($this->shutdownRequested) {
                        // stop_services was issued — tell the parent to shut down
                        posix_kill($parentPid, SIGTERM);
                    }
                    exit(0);
                } else {
                    // Parent: close our copy of the client socket and keep accepting
                    fclose($client);
                }
            } else {
                // pcntl not available — handle synchronously
                $this->handleClient($client);
                if ($this->shutdownRequested) {
                    break;
                }
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
                        'admin_daemon.log' => \BinktermPHP\Config::getLogPath('admin_daemon.log'),
                        'mrc_daemon.log' => \BinktermPHP\Config::getLogPath('mrc_daemon.log')
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
                    // Spawn poll in background and return immediately so the HTTP
                    // request that triggered this does not block waiting for the
                    // network connection to the uplink to complete.
                    if ($upstream === 'all') {
                        $this->spawnCommand([PHP_BINARY, 'scripts/binkp_poll.php', '--all', '--no-console']);
                    } else {
                        $this->spawnCommand([PHP_BINARY, 'scripts/binkp_poll.php', '--no-console', $upstream]);
                    }
                    $this->logger->info("Spawned background binkp_poll for {$upstream}");
                    $this->writeResponse($client, ['ok' => true, 'result' => ['exit_code' => 0, 'stdout' => '', 'stderr' => '']]);
                    break;
                case 'binkp_auth_test':
                    $domain = $data['domain'] ?? null;
                    $address = $data['address'] ?? null;
                    if (!$domain && !$address) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'missing_domain']);
                        break;
                    }
                    try {
                        $binkpConfig = BinkpConfig::getInstance();
                        $uplink = $address
                            ? $binkpConfig->getUplinkByAddress((string)$address)
                            : $binkpConfig->getUplinkByDomain((string)$domain);
                        if (!$uplink) {
                            $lookup = $address !== null ? (string)$address : (string)$domain;
                            $kind = $address !== null ? 'address' : 'domain';
                            $this->writeResponse($client, ['ok' => false, 'error' => "No uplink configured for {$kind}: {$lookup}"]);
                            break;
                        }
                        $uplinkAddress = $uplink['address'] ?? null;
                        if (!$uplinkAddress) {
                            $this->writeResponse($client, ['ok' => false, 'error' => 'Uplink has no address configured']);
                            break;
                        }
                        $binkpClient = new \BinktermPHP\Binkp\Protocol\BinkpClient($binkpConfig, $this->logger);
                        $result = $binkpClient->authTest($uplinkAddress);
                        $this->writeResponse($client, [
                            'ok'     => true,
                            'result' => [
                                'auth_method'      => $result['auth_method'] ?? null,
                                'remote_address'   => $result['remote_address'] ?? null,
                            ],
                        ]);
                    } catch (\Exception $e) {
                        $this->writeResponse($client, ['ok' => false, 'error' => $e->getMessage()]);
                    }
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
                        $payload['preserve_processed_packets'] ?? null,
                        $payload['preserve_sent_packets'] ?? null
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
                case 'get_native_doors_config':
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getNativeDoorsConfig()]);
                    break;
                case 'save_native_doors_config':
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
                    $this->writeNativeDoorsConfig($decoded);
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getNativeDoorsConfig()]);
                    break;
                case 'save_dosdoors_config':
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
                case 'list_terminal_screens':
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->listTerminalScreens()]);
                    break;
                case 'get_terminal_screen':
                    $key = (string)($data['key'] ?? '');
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getTerminalScreen($key)]);
                    break;
                case 'save_terminal_screen':
                    $key = (string)($data['key'] ?? '');
                    $content = (string)($data['content'] ?? '');
                    $this->saveTerminalScreen($key, $content);
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getTerminalScreen($key)]);
                    break;
                case 'upload_terminal_screen':
                    $key = (string)($data['key'] ?? '');
                    $contentBase64 = (string)($data['content_base64'] ?? '');
                    $originalName = (string)($data['original_name'] ?? '');
                    $this->uploadTerminalScreen($key, $contentBase64, $originalName);
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getTerminalScreen($key)]);
                    break;
                case 'delete_terminal_screen':
                    $key = (string)($data['key'] ?? '');
                    $this->deleteTerminalScreen($key);
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
                    $mrcConfig->reloadConfig();
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
                case 'set_login_splash':
                    $text = $data['text'] ?? '';
                    if (!is_string($text)) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'invalid_text']);
                        break;
                    }
                    $this->writeLoginSplash($text);
                    $this->writeResponse($client, ['ok' => true, 'result' => ['success' => true]]);
                    break;
                case 'set_register_splash':
                    $text = $data['text'] ?? '';
                    if (!is_string($text)) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'invalid_text']);
                        break;
                    }
                    $this->writeRegisterSplash($text);
                    $this->writeResponse($client, ['ok' => true, 'result' => ['success' => true]]);
                    break;
                case 'get_i18n_overlay':
                    $locale = (string)($data['locale'] ?? '');
                    $ns     = (string)($data['ns'] ?? '');
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getI18nOverlay($locale, $ns)]);
                    break;
                case 'save_lovlynet_config':
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
                    $this->saveLovlyNetConfig($decoded);
                    $this->writeResponse($client, ['ok' => true, 'result' => ['success' => true]]);
                    break;
                case 'save_i18n_overlay':
                    $locale    = (string)($data['locale'] ?? '');
                    $ns        = (string)($data['ns'] ?? '');
                    $overrides = is_array($data['overrides'] ?? null) ? $data['overrides'] : [];
                    $this->saveI18nOverlay($locale, $ns, $overrides);
                    $this->writeResponse($client, ['ok' => true, 'result' => ['success' => true]]);
                    break;
                case 'stop_services':
                    $results = $this->stopServices();
                    $this->writeResponse($client, ['ok' => true, 'result' => $results]);
                    $this->shutdownRequested = true;
                    break;
                case 'server_log':
                    $level   = strtoupper((string)($data['level']   ?? 'INFO'));
                    $message = (string)($data['message'] ?? '');
                    $context = is_array($data['context'] ?? null) ? $data['context'] : [];
                    $this->appendServerLog($level, $message, $context);
                    $this->writeResponse($client, ['ok' => true, 'result' => []]);
                    break;
                case 'scan_file':
                    $fileId = (int)($data['file_id'] ?? 0);
                    if ($fileId <= 0) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'invalid file_id']);
                        break;
                    }
                    $db = \BinktermPHP\Database::getInstance()->getPdo();
                    $stmt = $db->prepare('SELECT storage_path FROM files WHERE id = ?');
                    $stmt->execute([$fileId]);
                    $file = $stmt->fetch();
                    if (!$file || !file_exists($file['storage_path'])) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'file not found']);
                        break;
                    }
                    $scanner = new \BinktermPHP\VirusScanner();
                    if (!$scanner->isEnabled()) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'virus scanner not available']);
                        break;
                    }
                    $scanResult = $scanner->scanFile($file['storage_path']);
                    $update = $db->prepare("
                        UPDATE files
                        SET virus_scanned = ?,
                            virus_scan_result = ?,
                            virus_signature = ?,
                            virus_scanned_at = NOW()
                        WHERE id = ?
                    ");
                    $update->execute([
                        $scanResult['scanned'] ? 'true' : 'false',
                        $scanResult['result'],
                        $scanResult['signature'] ?? null,
                        $fileId
                    ]);
                    $this->logger->info('Manual virus scan', [
                        'file_id' => $fileId,
                        'result'  => $scanResult['result'],
                        'sig'     => $scanResult['signature'] ?? null,
                    ]);
                    $this->writeResponse($client, ['ok' => true, 'result' => $scanResult]);
                    break;
                case 'run_echomail_robot':
                    $robotId = (int)($data['robot_id'] ?? 0);
                    if ($robotId <= 0) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'missing_robot_id']);
                        break;
                    }
                    $result = $this->runCommand([PHP_BINARY, 'scripts/echomail_robots.php', "--robot-id={$robotId}", '--debug']);
                    $this->logCommandResult('run_echomail_robot', $result);
                    $this->writeResponse($client, ['ok' => true, 'result' => $result]);
                    break;
                case 'rehatch_file':
                    $fileId = (int)($data['file_id'] ?? 0);
                    if ($fileId <= 0) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'invalid file_id']);
                        break;
                    }
                    $result = $this->runCommand([PHP_BINARY, 'scripts/file_hatch.php', "--file-id={$fileId}"]);
                    $this->logCommandResult('rehatch_file', $result);
                    $this->writeResponse($client, ['ok' => $result['exit_code'] === 0, 'result' => $result]);
                    break;
                case 'reindex_iso':
                    $areaId = (int)($data['area_id'] ?? 0);
                    if ($areaId <= 0) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'missing_area_id']);
                        break;
                    }
                    $this->spawnCommand([PHP_BINARY, 'scripts/import_iso.php', "--area={$areaId}", '--update']);
                    $this->writeResponse($client, ['ok' => true, 'result' => ['spawned' => true]]);
                    break;
                case 'set_license':
                    $licenseData = $data['license'] ?? null;
                    if (!is_array($licenseData) || !isset($licenseData['payload'], $licenseData['signature'])) {
                        $this->writeResponse($client, ['ok' => false, 'error' => 'invalid_license_format']);
                        break;
                    }
                    $this->writeLicenseFile($licenseData);
                    $this->writeResponse($client, ['ok' => true, 'result' => ['success' => true]]);
                    break;
                case 'delete_license':
                    $this->deleteLicenseFile();
                    $this->writeResponse($client, ['ok' => true, 'result' => ['success' => true]]);
                    break;
                case 'get_weather_config':
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getWeatherConfig()]);
                    break;
                case 'save_weather_config':
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
                    $this->saveWeatherConfig($decoded);
                    $this->writeResponse($client, ['ok' => true, 'result' => $this->getWeatherConfig()]);
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

    /**
     * Open a raw pg_* connection and start listening on the 'binkstream' channel.
     * Called once at daemon startup. Failures are logged but not fatal — the daemon
     * continues running and PHP endpoints fall back to direct DB catch-up queries.
     */
    private function initPgConnection(): void
    {
        if (!function_exists('pg_connect')) {
            $this->logger->warning('pg_connect not available — sse_events cleanup disabled');
            return;
        }

        try {
            $cfg = \BinktermPHP\Config::getDatabaseConfig();
            $connStr = sprintf(
                "host=%s port=%s dbname=%s user=%s password=%s",
                $cfg['host'], $cfg['port'], $cfg['database'],
                $cfg['username'], $cfg['password']
            );
            $this->logger->debug('Admin daemon: attempting pg_connect', [
                'host' => $cfg['host'],
                'port' => $cfg['port'],
                'dbname' => $cfg['database'],
                'user' => $cfg['username'],
            ]);
            $this->pgConn = pg_connect($connStr);
            if (!$this->pgConn) {
                $this->logger->warning('Admin daemon: pg_connect failed — sse_events cleanup disabled');
                $this->pgConn = null;
                return;
            }

            $this->logger->info('Admin daemon: pg connection active for sse_events cleanup');
        } catch (\Throwable $e) {
            $this->logger->warning('Admin daemon: pg connection init failed', ['error' => $e->getMessage()]);
            $this->pgConn = null;
        }
    }

    /**
     * Delete sse_events rows older than one hour. The table is UNLOGGED so
     * autovacuum handles dead tuples; this just keeps the row count bounded.
     * Called from the main loop roughly once per minute.
     */
    private function pruneSSEEvents(): void
    {
        if (!$this->pgConn) {
            return;
        }

        @pg_query($this->pgConn, "DELETE FROM sse_events WHERE created_at < NOW() - INTERVAL '1 hour'");
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

        // Read stdout and stderr concurrently to avoid pipe buffer deadlock.
        // Sequential reads would block if the child fills the stderr buffer
        // while the parent is waiting for stdout EOF (or vice versa).
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 5) > 0) {
                foreach ($read as $pipe) {
                    if ($pipe === $pipes[1]) {
                        $stdout .= fread($pipe, 8192);
                    } elseif ($pipe === $pipes[2]) {
                        $stderr .= fread($pipe, 8192);
                    }
                }
            }
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr
        ];
    }

    /**
     * Spawn a command in the background without waiting for it to complete.
     * Used for long-running operations (e.g. binkp_poll) that should not block
     * the admin daemon socket response.
     *
     * Uses a double-fork so the spawned process is fully adopted by init/systemd
     * and is not affected by the calling child's exit or the parent's SIGCHLD handler.
     *
     * On Windows, process spawning is unreliable from a daemon context, so we
     * skip the immediate poll.  The outbound packet is already spooled to disk
     * and the scheduler will deliver it on its next scheduled interval.
     */
    private function spawnCommand(array $command): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Let the scheduler pick up the spooled outbound packet.
            return;
        }

        if (function_exists('pcntl_fork') && function_exists('posix_setsid')) {
            // Double-fork: intermediate child creates a new session then forks
            // the real worker, then exits immediately.  The worker is re-parented
            // to init so it outlives both the intermediate child and this process.
            $intermediatePid = pcntl_fork();
            if ($intermediatePid === -1) {
                $this->logger->warning('spawnCommand: first fork failed, falling back to nohup');
            } elseif ($intermediatePid === 0) {
                // Intermediate child — detach from the daemon's session.
                posix_setsid();

                $workerPid = pcntl_fork();
                if ($workerPid === -1) {
                    exit(1);
                } elseif ($workerPid === 0) {
                    // Worker grandchild — run the command via proc_open so that
                    // stdout/stderr (including pre-logger PHP fatal errors) are
                    // captured in binkp_poll.log rather than silently discarded.
                    $logFile = \BinktermPHP\Config::getLogPath('binkp_poll.log');
                    $descriptorSpec = [
                        0 => ['file', '/dev/null', 'r'],
                        1 => ['file', $logFile, 'a'],
                        2 => ['file', $logFile, 'a'],
                    ];
                    $escaped = implode(' ', array_map('escapeshellarg', $command));
                    $cwd = dirname(dirname(__DIR__)); // project root (src/Admin -> src -> root)
                    $process = proc_open($escaped, $descriptorSpec, $pipes, $cwd);
                    if (is_resource($process)) {
                        proc_close($process);
                    }
                    exit(0);
                }
                // Intermediate child exits, orphaning the worker to init.
                exit(0);
            } else {
                // Parent (or caller's forked child) — reap the intermediate child.
                pcntl_waitpid($intermediatePid, $status);
                return;
            }
        }

        // Fallback for environments without pcntl.
        $escaped = implode(' ', array_map('escapeshellarg', $command));
        exec("nohup {$escaped} > /dev/null 2>&1 &");
    }

    private function writeResponse($client, array $payload): void
    {
        // JSON_INVALID_UTF8_SUBSTITUTE prevents json_encode() from returning false when
        // payload strings contain non-UTF-8 bytes (e.g. CP437/ISO-8859 error messages
        // from remote BinkP servers).  Without this flag, json_encode returns false,
        // fwrite sends only "\n", and the client throws "Invalid response from admin daemon".
        fwrite($client, json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    }

    private function logCommandResult(string $cmd, array $result): void
    {
        $this->logger->debug('Admin daemon command completed', [
            'cmd' => $cmd,
            'exit_code' => $result['exit_code'] ?? null
        ]);
    }

    /**
     * Append a structured entry to data/logs/server.log.
     *
     * Each line is written in the format:
     *   [YYYY-MM-DD HH:MM:SS] LEVEL message  key=value key=value ...
     *
     * @param string               $level   Log level (INFO, WARNING, ERROR, …)
     * @param string               $message Human-readable message
     * @param array<string,scalar> $context Optional key/value context pairs
     */
    private function appendServerLog(string $level, string $message, array $context = []): void
    {
        $logPath = Config::getLogPath('server.log');
        $timestamp = date('Y-m-d H:i:s');

        $line = "[{$timestamp}] {$level} {$message}";
        foreach ($context as $k => $v) {
            $line .= '  ' . $k . '=' . (is_string($v) ? $v : json_encode($v));
        }
        $line .= "\n";

        @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
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

    private function saveLovlyNetConfig(array $config): void
    {
        $configPath = __DIR__ . '/../../config/lovlynet.json';
        $backupPath = dirname($configPath) . '/lovlynet_' . date('Ymd_His') . '.json';

        if (file_exists($configPath)) {
            @copy($configPath, $backupPath);
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode LovlyNet config');
        }

        file_put_contents($configPath, $json . PHP_EOL);
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
        if (!is_dir($configDir) && mkdir($configDir, 0755, true) === false && !is_dir($configDir)) {
            throw new \RuntimeException("Failed to create config directory: $configDir");
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode dosdoors config');
        }

        if (file_put_contents($configPath, $json . PHP_EOL) === false) {
            throw new \RuntimeException("Failed to write dosdoors config: $configPath");
        }
    }

    private function getDosdoorsConfigPath(): string
    {
        return __DIR__ . '/../../config/dosdoors.json';
    }

    private function getNativeDoorsConfig(): array
    {
        $configPath = $this->getNativeDoorsConfigPath();

        $active = file_exists($configPath);
        $configJson = $active ? file_get_contents($configPath) : null;

        return [
            'active' => $active,
            'config_json' => $configJson
        ];
    }

    private function writeNativeDoorsConfig(array $config): void
    {
        $configPath = $this->getNativeDoorsConfigPath();
        $configDir = dirname($configPath);
        if (!is_dir($configDir) && mkdir($configDir, 0755, true) === false && !is_dir($configDir)) {
            throw new \RuntimeException("Failed to create config directory: $configDir");
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode native doors config');
        }

        if (file_put_contents($configPath, $json . PHP_EOL) === false) {
            throw new \RuntimeException("Failed to write native doors config: $configPath");
        }
    }

    private function getNativeDoorsConfigPath(): string
    {
        return __DIR__ . '/../../config/nativedoors.json';
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

    /**
     * @return array<string,array{filename:string,label:string,description:string}>
     */
    private function getSupportedTerminalScreens(): array
    {
        return [
            'welcome' => [
                'filename' => 'login.ans',
                'label' => 'Welcome',
                'description' => 'Shown when a user first connects to the terminal server.',
            ],
            'main_menu' => [
                'filename' => 'mainmenu.ans',
                'label' => 'Main Menu',
                'description' => 'Shown behind the terminal main menu after login.',
            ],
            'goodbye' => [
                'filename' => 'bye.ans',
                'label' => 'Goodbye',
                'description' => 'Shown when a user disconnects from the terminal server.',
            ],
        ];
    }

    private function getTerminalScreensDir(): string
    {
        return __DIR__ . '/../../telnet/screens';
    }

    /**
     * @return array{filename:string,label:string,description:string}|null
     */
    private function resolveTerminalScreen(string $key): ?array
    {
        $supported = $this->getSupportedTerminalScreens();
        return $supported[$key] ?? null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function listTerminalScreens(): array
    {
        $dir = $this->getTerminalScreensDir();
        $result = [];

        foreach ($this->getSupportedTerminalScreens() as $key => $meta) {
            $path = $dir . DIRECTORY_SEPARATOR . $meta['filename'];
            $exists = is_file($path);
            $result[] = [
                'key' => $key,
                'filename' => $meta['filename'],
                'label' => $meta['label'],
                'description' => $meta['description'],
                'exists' => $exists,
                'size' => $exists ? (filesize($path) ?: 0) : 0,
                'updated_at' => $exists ? date('c', filemtime($path) ?: time()) : null,
            ];
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function getTerminalScreen(string $key): array
    {
        $meta = $this->resolveTerminalScreen($key);
        if ($meta === null) {
            throw new \RuntimeException('Unsupported terminal screen');
        }

        $path = $this->getTerminalScreensDir() . DIRECTORY_SEPARATOR . $meta['filename'];
        $exists = is_file($path);
        $content = $exists ? (@file_get_contents($path) ?: '') : '';

        return [
            'key' => $key,
            'filename' => $meta['filename'],
            'label' => $meta['label'],
            'description' => $meta['description'],
            'exists' => $exists,
            'content' => $content,
            'size' => $exists ? (filesize($path) ?: 0) : 0,
            'updated_at' => $exists ? date('c', filemtime($path) ?: time()) : null,
        ];
    }

    private function saveTerminalScreen(string $key, string $content): void
    {
        $meta = $this->resolveTerminalScreen($key);
        if ($meta === null) {
            throw new \RuntimeException('Unsupported terminal screen');
        }

        $dir = $this->getTerminalScreensDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new \RuntimeException('Failed to create terminal screens directory');
        }

        $path = $dir . DIRECTORY_SEPARATOR . $meta['filename'];
        if (@file_put_contents($path, $content) === false) {
            throw new \RuntimeException('Failed to save terminal screen');
        }
    }

    private function uploadTerminalScreen(string $key, string $contentBase64, string $originalName): void
    {
        if ($contentBase64 === '') {
            throw new \RuntimeException('Missing content');
        }

        $content = base64_decode($contentBase64, true);
        if ($content === false) {
            throw new \RuntimeException('Invalid content encoding');
        }

        if (strlen($content) > 1024 * 1024) {
            throw new \RuntimeException('File is too large (max 1MB)');
        }

        if ($originalName !== '') {
            $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['ans', 'asc', 'txt'], true)) {
                throw new \RuntimeException('Invalid file extension');
            }
        }

        $this->saveTerminalScreen($key, $content);
    }

    private function deleteTerminalScreen(string $key): void
    {
        $meta = $this->resolveTerminalScreen($key);
        if ($meta === null) {
            throw new \RuntimeException('Unsupported terminal screen');
        }

        $path = $this->getTerminalScreensDir() . DIRECTORY_SEPARATOR . $meta['filename'];
        if (!is_file($path)) {
            return;
        }

        if (!@unlink($path)) {
            throw new \RuntimeException('Failed to delete terminal screen');
        }
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

    private function getLoginSplashPath(): string
    {
        return __DIR__ . '/../../data/login_splash.md';
    }

    private function getRegisterSplashPath(): string
    {
        return __DIR__ . '/../../data/register_splash.md';
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
        $loginSplashPath = $this->getLoginSplashPath();
        $registerSplashPath = $this->getRegisterSplashPath();

        return [
            'config' => $config ?? [],
            'system_news' => file_exists($systemNewsPath) ? (file_get_contents($systemNewsPath) ?: '') : null,
            'house_rules' => file_exists($houseRulesPath) ? (file_get_contents($houseRulesPath) ?: '') : null,
            'login_splash' => file_exists($loginSplashPath) ? (file_get_contents($loginSplashPath) ?: '') : null,
            'register_splash' => file_exists($registerSplashPath) ? (file_get_contents($registerSplashPath) ?: '') : null,
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

    private function writeLoginSplash(string $text): void
    {
        $path = $this->getLoginSplashPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (@file_put_contents($path, $text, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write login splash');
        }
    }

    private function writeRegisterSplash(string $text): void
    {
        $path = $this->getRegisterSplashPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (@file_put_contents($path, $text, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write register splash');
        }
    }

    // =========================================================================
    // Language overlay editor
    // =========================================================================

    private function getI18nBasePath(): string
    {
        return rtrim(__DIR__ . '/../../config/i18n', '/\\');
    }

    /**
     * Validates and returns the absolute overlay file path for a locale/namespace.
     * Returns null if the locale or namespace is invalid.
     */
    private function resolveOverlayPath(string $locale, string $namespace): ?string
    {
        // Locale: 2-letter code with optional region, e.g. en, es, en-US
        if (!preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale)) {
            return null;
        }
        // Namespace: lowercase alphanumeric + underscore only
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $namespace)) {
            return null;
        }

        $basePath = $this->getI18nBasePath();
        return $basePath . '/overrides/' . $locale . '/' . $namespace . '.json';
    }

    /**
     * Returns the base PHP catalog keys + current overlay overrides for a locale/namespace.
     *
     * @return array{base: array<string,string>, overrides: array<string,string>}
     */
    private function getI18nOverlay(string $locale, string $namespace): array
    {
        if ($this->resolveOverlayPath($locale, $namespace) === null) {
            throw new \RuntimeException('Invalid locale or namespace');
        }

        $basePath = $this->getI18nBasePath();
        $phpPath  = $basePath . '/' . $locale . '/' . $namespace . '.php';

        $base = [];
        if (is_file($phpPath)) {
            $data = include $phpPath;
            if (is_array($data)) {
                foreach ($data as $k => $v) {
                    if (is_string($k) && is_string($v)) {
                        $base[$k] = $v;
                    }
                }
            }
        }

        // Always load the English base so the editor can show en → locale comparison.
        $enBase  = [];
        $enPath  = $basePath . '/en/' . $namespace . '.php';
        if (is_file($enPath)) {
            $data = include $enPath;
            if (is_array($data)) {
                foreach ($data as $k => $v) {
                    if (is_string($k) && is_string($v)) {
                        $enBase[$k] = $v;
                    }
                }
            }
        }

        $overlayPath = $this->resolveOverlayPath($locale, $namespace);
        $overrides   = [];
        if ($overlayPath !== null && is_file($overlayPath)) {
            $raw  = file_get_contents($overlayPath);
            $data = ($raw !== false) ? json_decode($raw, true) : null;
            if (is_array($data)) {
                foreach ($data as $k => $v) {
                    if (is_string($k) && is_string($v)) {
                        $overrides[$k] = $v;
                    }
                }
            }
        }

        return ['base' => $base, 'en_base' => $enBase, 'overrides' => $overrides];
    }

    /**
     * Saves an overlay JSON file for the given locale/namespace.
     * Passing an empty overrides array removes the overlay file.
     *
     * @param array<string,string> $overrides
     */
    private function saveI18nOverlay(string $locale, string $namespace, array $overrides): void
    {
        $overlayPath = $this->resolveOverlayPath($locale, $namespace);
        if ($overlayPath === null) {
            throw new \RuntimeException('Invalid locale or namespace');
        }

        // Sanitize: string keys and values only; skip empty overrides
        $clean = [];
        foreach ($overrides as $k => $v) {
            if (is_string($k) && $k !== '' && is_string($v) && $v !== '') {
                $clean[$k] = $v;
            }
        }

        if (empty($clean)) {
            // No overrides — remove file if it exists
            if (is_file($overlayPath)) {
                if (!@unlink($overlayPath)) {
                    throw new \RuntimeException('Failed to remove overlay file');
                }
            }
            return;
        }

        $dir = dirname($overlayPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException('Failed to create overlay directory');
        }

        $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($overlayPath, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write overlay file');
        }
    }

    /**
     * Write a validated license payload to data/license.json.
     *
     * @param array<string,mixed> $licenseData
     */
    private function writeLicenseFile(array $licenseData): void
    {
        $path = __DIR__ . '/../../data/license.json';
        $dir  = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException('Failed to create data directory');
        }

        $json = json_encode($licenseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($path, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write license file');
        }
    }

    private function getWeatherConfig(): array
    {
        $configPath  = __DIR__ . '/../../config/weather.json';
        $examplePath = __DIR__ . '/../../config/weather.json.example';

        $active     = file_exists($configPath);
        $configJson = $active ? file_get_contents($configPath) : null;
        $exampleJson = file_exists($examplePath) ? file_get_contents($examplePath) : null;

        return [
            'active'      => $active,
            'config_json' => $configJson,
            'example_json' => $exampleJson,
        ];
    }

    private function saveWeatherConfig(array $config): void
    {
        $configPath = __DIR__ . '/../../config/weather.json';

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode weather config');
        }

        if (file_put_contents($configPath, $json . PHP_EOL) === false) {
            throw new \RuntimeException('Failed to write weather config');
        }
    }

    /**
     * Remove the license file, reverting the installation to Community Edition.
     */
    private function deleteLicenseFile(): void
    {
        $path = __DIR__ . '/../../data/license.json';
        if (file_exists($path) && !@unlink($path)) {
            throw new \RuntimeException('Failed to remove license file');
        }
    }

}

