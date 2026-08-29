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

namespace BinktermPHP\Nntp;

use BinktermPHP\Binkp\Logger;
use BinktermPHP\Config;
use BinktermPHP\Database;

/**
 * NNTP daemon: dual listeners (plaintext + STARTTLS on one port, implicit TLS on
 * another), a fork-per-connection accept loop with a Windows single-connection
 * fallback, a per-source-IP concurrent connection cap, and self-signed certificate
 * bootstrapping. Per-connection protocol handling lives in {@see NntpSession}.
 *
 * Modelled on scripts/gemini_daemon.php. TLS is negotiated in the child (after
 * fork) so the parent's socket close never emits a stray close_notify.
 */
class NntpServer
{
    private string $bindHost;
    private int $plainPort;
    private int $tlsPort;
    private string $certPath;
    private string $keyPath;
    private bool $externalCert;
    private Logger $logger;
    private NntpConfig $config;

    /** @var array<int,string>  child pid => remote IP */
    private array $children = [];
    /** @var array<string,int>  remote IP => live connection count */
    private array $perIp = [];

    private $plainSocket;
    private $tlsSocket;
    private bool $running = true;

    public function __construct(
        string $bindHost,
        int $plainPort,
        int $tlsPort,
        string $certPath,
        string $keyPath,
        bool $externalCert,
        Logger $logger,
        ?NntpConfig $config = null
    ) {
        $this->bindHost = $bindHost;
        $this->plainPort = $plainPort;
        $this->tlsPort = $tlsPort;
        $this->certPath = $certPath;
        $this->keyPath = $keyPath;
        $this->externalCert = $externalCert;
        $this->logger = $logger;
        $this->config = $config ?? NntpConfig::getInstance();
    }

    public function run(): void
    {
        if (!$this->config->isEnabled()) {
            $this->logger->warning('[nntp] NNTP server is disabled in config/nntp.json — set "enabled": true to serve.');
        }

        $this->prepareCertificate();

        $ctx = $this->buildSslContext();
        $this->openListeners($ctx);
        $this->logStartupDiagnostics();
        $this->installSignalHandlers();

        $canFork = function_exists('pcntl_fork');
        if (!$canFork) {
            $this->logger->warning('[nntp] pcntl not available — single-connection blocking mode (dev/testing only)');
        }

        $listeners = array_filter([$this->plainSocket, $this->tlsSocket]);

        while ($this->running) {
            $this->reapChildren();

            $read = $listeners;
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 1) === false) {
                continue;
            }

            foreach ($read as $listener) {
                $isTls = ($listener === $this->tlsSocket);
                $client = @stream_socket_accept($listener, 0, $peer);
                if ($client === false) {
                    continue;
                }

                $ip = $this->peerIp($peer);

                if (($this->perIp[$ip] ?? 0) >= $this->config->getMaxConnectionsPerIp()) {
                    $this->logger->warning('[nntp] connection refused (per-IP limit)', ['ip' => $ip]);
                    @fwrite($client, "400 Too many connections from your address\r\n");
                    @fclose($client);
                    continue;
                }

                if (!$canFork) {
                    $this->handleClient($client, $ip, $isTls, true);
                    @fclose($client);
                    continue;
                }

                $pid = pcntl_fork();
                if ($pid < 0) {
                    $this->logger->error('[nntp] pcntl_fork failed');
                    @fclose($client);
                    continue;
                }

                if ($pid === 0) {
                    // Child.
                    foreach ($listeners as $l) {
                        @fclose($l);
                    }
                    $this->children = [];
                    $this->handleClient($client, $ip, $isTls, false);
                    @fclose($client);
                    exit(0);
                }

                // Parent.
                $this->children[$pid] = $ip;
                $this->perIp[$ip] = ($this->perIp[$ip] ?? 0) + 1;
                @fclose($client);
            }
        }

        foreach ($listeners as $l) {
            @fclose($l);
        }
        $this->logger->info('[nntp] shut down');
    }

    // ── Connection handling ────────────────────────────────────────────────

    private function handleClient($client, string $ip, bool $isTls, bool $inlineMode): void
    {
        stream_set_timeout($client, 300);

        if ($isTls) {
            $ok = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            if ($ok !== true) {
                $this->drainSslErrors('implicit TLS handshake failed', $ip);
                return;
            }
        }

        // Fresh PDO handle after fork (the parent's must not be shared).
        if (!$inlineMode) {
            try {
                Database::getInstance()->reconnect();
            } catch (\Throwable $e) {
                // getInstance() below still yields a usable handle in most setups
            }
        }
        $db = Database::getInstance()->getPdo();

        $session = new NntpSession([
            'db' => $db,
            'config' => $this->config,
            'logger' => $this->logger,
            'remote_ip' => $ip,
            'tls_active' => $isTls,
            'plaintext_port' => !$isTls,
            'read_line' => function () use ($client): ?string {
                $line = @fgets($client, 8192);
                if ($line === false) {
                    return null;
                }
                $meta = stream_get_meta_data($client);
                if (!empty($meta['timed_out'])) {
                    return null;
                }
                return $line;
            },
            'write_raw' => function (string $data) use ($client): void {
                $len = strlen($data);
                $written = 0;
                while ($written < $len) {
                    $n = @fwrite($client, substr($data, $written));
                    if ($n === false || $n === 0) {
                        return;
                    }
                    $written += $n;
                }
            },
            'start_tls' => function () use ($client, $ip): bool {
                $ok = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
                if ($ok !== true) {
                    $this->drainSslErrors('STARTTLS handshake failed', $ip);
                    return false;
                }
                return true;
            },
        ]);

        $this->logger->info('[nntp] connection', ['ip' => $ip, 'tls' => $isTls ? 'implicit' : 'plain']);
        $session->run();
    }

    private function drainSslErrors(string $context, string $ip): void
    {
        $logged = false;
        while (($err = openssl_error_string()) !== false) {
            $this->logger->warning("[nntp] {$context}: {$err}", ['ip' => $ip]);
            $logged = true;
        }
        if (!$logged) {
            $this->logger->warning("[nntp] {$context}", ['ip' => $ip]);
        }
    }

    // ── Listeners / TLS ────────────────────────────────────────────────────

    /**
     * @return resource
     */
    private function buildSslContext()
    {
        $ssl = [
            'local_cert' => $this->certPath,
            'allow_self_signed' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ];
        if ($this->externalCert) {
            $ssl['local_pk'] = $this->keyPath;
        }

        return stream_context_create(['ssl' => $ssl]);
    }

    /**
     * @param resource $ctx
     */
    private function openListeners($ctx): void
    {
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;

        // The SSL context rides on the plaintext listener too so that STARTTLS can
        // enable crypto on an accepted connection (mirrors scripts/gemini_daemon.php).
        $this->plainSocket = @stream_socket_server("tcp://{$this->bindHost}:{$this->plainPort}", $errno, $errstr, $flags, $ctx);
        if ($this->plainSocket === false) {
            $this->fatal("failed to bind tcp://{$this->bindHost}:{$this->plainPort} — {$errstr} ({$errno})");
        }
        $this->logger->info("[nntp] listening (plaintext + STARTTLS) on tcp://{$this->bindHost}:{$this->plainPort}");

        if ($this->tlsPort > 0) {
            // Bind a plain TCP socket; TLS is negotiated per connection in the child.
            $this->tlsSocket = @stream_socket_server("tcp://{$this->bindHost}:{$this->tlsPort}", $errno, $errstr, $flags, $ctx);
            if ($this->tlsSocket === false) {
                $this->fatal("failed to bind tcp://{$this->bindHost}:{$this->tlsPort} — {$errstr} ({$errno})");
            }
            $this->logger->info("[nntp] listening (implicit TLS) on tcp://{$this->bindHost}:{$this->tlsPort}");
        }
    }

    private function logStartupDiagnostics(): void
    {
        try {
            $groups = new NntpNewsgroups(Database::getInstance()->getPdo(), $this->config);
            foreach ($groups->detectCollisions() as $name => $sources) {
                $this->logger->warning('[nntp] newsgroup name collision: ' . $name . ' <= ' . implode(', ', $sources));
            }
            foreach ($groups->skippedAreas() as $skipped) {
                $this->logger->warning('[nntp] echoarea skipped (tag not a legal newsgroup component): ' . $skipped);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[nntp] startup diagnostics failed: ' . $e->getMessage());
        }
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        $stop = function (): void {
            $this->running = false;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGCHLD, function (): void {
            $this->reapChildren();
        });
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }
    }

    private function reapChildren(): void
    {
        if (!function_exists('pcntl_waitpid')) {
            return;
        }
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            $ip = $this->children[$pid] ?? null;
            unset($this->children[$pid]);
            if ($ip !== null && isset($this->perIp[$ip])) {
                $this->perIp[$ip] = max(0, $this->perIp[$ip] - 1);
                if ($this->perIp[$ip] === 0) {
                    unset($this->perIp[$ip]);
                }
            }
        }
    }

    private function peerIp(?string $peer): string
    {
        if ($peer === null || $peer === '') {
            return 'unknown';
        }
        // Strip trailing :port (IPv4 "1.2.3.4:5" / IPv6 "[::1]:5").
        if ($peer[0] === '[') {
            return substr($peer, 1, strpos($peer, ']') - 1);
        }
        $pos = strrpos($peer, ':');

        return $pos === false ? $peer : substr($peer, 0, $pos);
    }

    private function fatal(string $message): void
    {
        $this->logger->critical('[nntp] ' . $message);
        exit(1);
    }

    // ── Certificate bootstrap (mirrors scripts/gemini_daemon.php) ──────────

    private function prepareCertificate(): void
    {
        // A cert is needed even when the implicit-TLS port is disabled, because
        // STARTTLS is always offered on the plaintext port.
        if ($this->externalCert) {
            if (!file_exists($this->certPath)) {
                $this->fatal("NNTP_TLS_CERT_PATH does not exist: {$this->certPath}");
            }
            if (!file_exists($this->keyPath)) {
                $this->fatal("NNTP_TLS_KEY_PATH does not exist: {$this->keyPath}");
            }
            $this->logger->info("[nntp] using external TLS certificate: {$this->certPath}");
        } elseif (!file_exists($this->certPath) || !file_exists($this->keyPath)) {
            $this->generateSelfSignedCertificate();
        }

        $this->certPath = realpath($this->certPath) ?: $this->certPath;
        $this->keyPath = realpath($this->keyPath) ?: $this->keyPath;
    }

    private function generateSelfSignedCertificate(): void
    {
        $certDir = dirname($this->certPath);
        if (!is_dir($certDir)) {
            mkdir($certDir, 0750, true);
        }

        $cn = 'localhost';
        try {
            $host = parse_url(Config::getSiteUrl(), PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $cn = $host;
            }
        } catch (\Throwable $e) {
            // localhost
        }

        $opensslCnf = realpath(__DIR__ . '/../../config/nntp_openssl.cnf');
        $this->logger->info("[nntp] generating self-signed TLS certificate for CN={$cn}");

        if ($opensslCnf !== false && $this->generateCertViaCli($cn, $opensslCnf)) {
            return;
        }

        $cfg = $opensslCnf ? ['config' => $opensslCnf] : [];
        $pkey = openssl_pkey_new(array_merge(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA], $cfg));
        if ($pkey === false) {
            $this->fatal('openssl_pkey_new() failed — is the openssl extension enabled?');
        }
        $csr = openssl_csr_new(['commonName' => $cn], $pkey, array_merge(['digest_alg' => 'sha256'], $cfg));
        $cert = openssl_csr_sign($csr, null, $pkey, 3650, array_merge(['digest_alg' => 'sha256'], $cfg));

        $certPem = '';
        $keyPem = '';
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($pkey, $keyPem, null, $cfg ?: null);

        file_put_contents($this->certPath, $certPem . $keyPem);
        file_put_contents($this->keyPath, $keyPem);
        @chmod($this->certPath, 0600);
        @chmod($this->keyPath, 0600);
        $this->logger->info("[nntp] certificate generated (PHP) at {$this->certPath}");
    }

    private function generateCertViaCli(string $cn, string $opensslCnf): bool
    {
        $subj = (PHP_OS_FAMILY === 'Windows') ? '//CN=' . $cn : '/CN=' . $cn;
        $san = filter_var($cn, FILTER_VALIDATE_IP) ? 'IP:' . $cn : 'DNS:' . $cn;
        if ($cn !== 'localhost') {
            $san .= ',DNS:localhost,IP:127.0.0.1';
        } else {
            $san .= ',IP:127.0.0.1';
        }

        $cmd = implode(' ', [
            'openssl', 'req', '-x509', '-newkey', 'rsa:2048',
            '-keyout', escapeshellarg($this->keyPath),
            '-out', escapeshellarg($this->certPath),
            '-days', '3650', '-nodes',
            '-config', escapeshellarg($opensslCnf),
            '-subj', escapeshellarg($subj),
            '-addext', escapeshellarg("subjectAltName={$san}"),
            '2>&1',
        ]);

        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0 || !file_exists($this->certPath) || !file_exists($this->keyPath)) {
            $this->logger->debug('[nntp] openssl CLI unavailable: ' . implode(' | ', $output));
            return false;
        }

        file_put_contents($this->certPath, file_get_contents($this->certPath) . file_get_contents($this->keyPath));
        @chmod($this->certPath, 0600);
        @chmod($this->keyPath, 0600);
        $this->logger->info("[nntp] certificate generated (openssl CLI) at {$this->certPath}");

        return true;
    }
}
