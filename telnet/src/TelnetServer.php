<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Config;
use BinktermPHP\Version;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\BbsConfig;
use BinktermPHP\I18n\Translator;


/**
 * BinktermPHP Telnet Server
 *
 * A Fidonet-style BBS telnet server that provides text-based access
 * to netmail, echomail, shoutbox, and voting booth features.
 */
class TelnetServer
{
    // Telnet protocol constants
    private const IAC = 255;
    private const DONT = 254;
    private const TELNET_DO = 253;
    private const WONT = 252;
    private const WILL = 251;
    private const SB = 250;
    private const SE = 240;
    private const OPT_ECHO = 1;
    private const OPT_SUPPRESS_GA = 3;
    private const OPT_NAWS = 31;
    private const OPT_LINEMODE = 34;

    // Arrow key escape sequences
    private const KEY_UP = "\033[A";
    private const KEY_DOWN = "\033[B";
    private const KEY_RIGHT = "\033[C";
    private const KEY_LEFT = "\033[D";
    private const KEY_HOME = "\033[H";
    private const KEY_END = "\033[F";
    private const KEY_DELETE = "\033[3~";
    private const KEY_PGUP = "\033[5~";
    private const KEY_PGDOWN = "\033[6~";

    // ANSI color constants
    private const ANSI_RESET = "\033[0m";
    private const ANSI_BOLD = "\033[1m";
    private const ANSI_DIM = "\033[2m";
    private const ANSI_BLUE = "\033[34m";
    private const ANSI_CYAN = "\033[36m";
    private const ANSI_GREEN = "\033[32m";
    private const ANSI_YELLOW = "\033[33m";
    private const ANSI_MAGENTA = "\033[35m";
    private const ANSI_RED = "\033[31m";

    private string $host;
    private int $port;
    private string $apiBase;
    private bool $debug;
    private bool $insecure;
    private ?string $logFile = null;
    private bool $daemonMode = false;
    private ?string $pidFile = null;
    private ?int $masterPid = null;
    private array $failedLoginAttempts = [];
    private Translator $translator;
    private string $systemLocale;
    private bool $tlsEnabled = true;
    private int $tlsPort = 8023;
    private ?string $tlsCert = null;
    private ?string $tlsKey = null;

    /**
     * Create a new Telnet Server instance
     *
     * @param string $host Host address to bind to
     * @param int $port Port number to listen on
     * @param string $apiBase Base URL for API requests
     * @param bool $debug Enable debug logging
     */
    public function __construct(string $host, int $port, string $apiBase, bool $debug = false, bool $insecure = false)
    {
        $this->host = $host;
        $this->port = $port;
        $this->apiBase = rtrim($apiBase, '/');
        $this->debug = $debug;
        $this->insecure = $insecure;
        $this->translator = new Translator();
        $this->systemLocale = (string)Config::env('I18N_DEFAULT_LOCALE', 'en');
    }

    /**
     * Translate a telnet UI string.
     *
     * @param string $key Translation key (looked up in the 'telnet' namespace)
     * @param string $fallback English fallback used when the key is missing from all catalogs
     * @param array $params Placeholder substitutions ({key} => value)
     * @param string $locale User locale; defaults to system locale
     * @return string Translated string with params interpolated
     */
    public function t(string $key, string $fallback, array $params = [], string $locale = ''): string
    {
        $result = $this->translator->translate($key, $params, $locale !== '' ? $locale : null, ['telnet']);
        if ($result === $key) {
            // Key not found in any catalog — use fallback with param interpolation
            foreach ($params as $k => $v) {
                $fallback = str_replace('{' . $k . '}', (string)$v, $fallback);
            }
            return $fallback;
        }
        return $result;
    }

    /**
     * Set PID file path for daemon mode
     *
     * @param string $pidFile Path to PID file
     */
    public function setPidFile(string $pidFile): void
    {
        $this->pidFile = $pidFile;
    }

    /**
     * Configure TLS. TLS is enabled by default on port 8023.
     * If cert/key are omitted a self-signed certificate is auto-generated.
     *
     * @param int         $port Port to listen on for TLS connections
     * @param string|null $cert Path to PEM certificate file (null = auto-generate)
     * @param string|null $key  Path to PEM private key file (null = auto-generate)
     */
    public function setTls(int $port, ?string $cert = null, ?string $key = null): void
    {
        $this->tlsEnabled = true;
        $this->tlsPort    = $port;
        $this->tlsCert    = $cert;
        $this->tlsKey     = $key;
    }

    /**
     * Disable TLS entirely.
     */
    public function disableTls(): void
    {
        $this->tlsEnabled = false;
    }

    /**
     * Start the telnet server
     *
     * @param bool $daemonMode Run as background daemon
     * @throws \RuntimeException if server fails to start
     */
    public function start(bool $daemonMode = false): void
    {
        $this->daemonMode = $daemonMode;

        // Set up logging
        $logDir = __DIR__ . '/../../data/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->logFile = $logDir . '/telnetd.log';

        // Daemonize if requested
        if ($daemonMode) {
            if (!function_exists('pcntl_fork') || !function_exists('posix_setsid')) {
                fwrite(STDERR, "Daemon mode requires pcntl and posix extensions\n");
                exit(1);
            }

            $this->log("Starting telnet daemon in background mode");
            $this->daemonize();

            // Register cleanup on shutdown
            register_shutdown_function(fn() => $this->cleanupDaemon());
        }

        // Set up signal handling
        if (function_exists('pcntl_signal')) {
            // Handle SIGCHLD to reap zombie processes
            pcntl_signal(SIGCHLD, function($signo) {
                while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
                    // Reap all finished child processes
                }
            });

            // Handle SIGTERM and SIGINT for graceful shutdown
            $gracefulShutdown = function($signo) use (&$server, &$tlsServer) {
                $this->log("Received shutdown signal, cleaning up...");
                $this->cleanupDaemon();
                if (isset($server) && is_resource($server)) {
                    fclose($server);
                }
                if (isset($tlsServer) && is_resource($tlsServer)) {
                    fclose($tlsServer);
                }
                exit(0);
            };
            pcntl_signal(SIGTERM, $gracefulShutdown);
            pcntl_signal(SIGINT, $gracefulShutdown);

            // Enable async signal handling
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals(true);
            }
        }

        // Create plain-text server socket
        $server = stream_socket_server("tcp://{$this->host}:{$this->port}", $errno, $errstr);
        if (!$server) {
            fwrite(STDERR, "Failed to bind telnet server: {$errstr} ({$errno})\n");
            exit(1);
        }
        $this->log("Telnet daemon " . Version::getVersion() . " listening on {$this->host}:{$this->port}");

        // Create TLS server socket if enabled
        $tlsServer = null;
        if ($this->tlsEnabled) {
            // Resolve default cert paths and auto-generate if needed
            $dataDir = dirname(dirname(__DIR__)) . '/data/telnet';
            if ($this->tlsCert === null) {
                $this->tlsCert = $dataDir . '/telnetd.crt';
            }
            if ($this->tlsKey === null) {
                $this->tlsKey = $dataDir . '/telnetd.key';
            }

            try {
                $this->ensureTlsCert($dataDir);
            } catch (\Exception $e) {
                $this->log("WARNING: Could not generate TLS certificate: " . $e->getMessage());
                $this->log("WARNING: TLS disabled — fix certificate configuration or provide valid cert/key files");
                $this->tlsEnabled = false;
            }

            if ($this->tlsEnabled) {
                // Use tcp:// and perform the TLS handshake explicitly via
                // stream_socket_enable_crypto() after accept.  This gives us proper
                // OpenSSL error visibility on failure; ssl:// swallows errors on Windows.
                // SSL context options are set here at server-creation time so accepted
                // child sockets inherit them.
                $tlsContext = stream_context_create([
                    'ssl' => [
                        'local_cert'          => $this->tlsCert,
                        'local_pk'            => $this->tlsKey,
                        'allow_self_signed'   => true,
                        'verify_peer'         => false,
                        'crypto_method'       => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER
                                              | STREAM_CRYPTO_METHOD_TLSv1_1_SERVER
                                              | STREAM_CRYPTO_METHOD_TLSv1_0_SERVER,
                        'ciphers'             => 'DEFAULT:@SECLEVEL=0',
                        'disable_compression' => true,
                    ],
                ]);
                $tlsServer = @stream_socket_server(
                    "tcp://{$this->host}:{$this->tlsPort}",
                    $errno, $errstr,
                    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                    $tlsContext
                );
                if (!$tlsServer) {
                    $this->log("WARNING: Failed to bind TLS server on port {$this->tlsPort}: {$errstr} ({$errno})");
                } else {
                    $this->log("TLS listening on {$this->host}:{$this->tlsPort}");
                }
            }
        }

        if ($this->debug) {
            $this->log("DEBUG MODE");
            $this->log("API Base URL: {$this->apiBase}");
        }

        // Set terminal title in foreground mode
        if (!$daemonMode) {
            echo "\033]0;BinktermPHP Telnet Server\007";
        }

        $connectionCount = 0;

        // Main server loop — use stream_select to watch plain and TLS sockets together
        while (true) {
            // Dispatch signals if async signals not available
            if (function_exists('pcntl_signal_dispatch') && !function_exists('pcntl_async_signals')) {
                pcntl_signal_dispatch();
            }

            $readSockets = array_filter([$server, $tlsServer]);
            $write = null;
            $except = null;
            $changed = @stream_select($readSockets, $write, $except, 60);

            if ($changed === false || $changed === 0) {
                // Timeout or interrupted — reap zombies and continue
                if (function_exists('pcntl_waitpid')) {
                    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {}
                }
                continue;
            }

            foreach ($readSockets as $readySocket) {
                $isTls = ($tlsServer && $readySocket === $tlsServer);
                $conn = @stream_socket_accept($readySocket, 0);
                if (!$conn) {
                    continue;
                }

                $connectionCount++;
                if ($this->debug) {
                    $peerName = @stream_socket_get_name($conn, true);
                    $this->log("Connection #{$connectionCount} from {$peerName}" . ($isTls ? ' (TLS)' : ''));
                }

                $forked = false;
                if (function_exists('pcntl_fork')) {
                    $pid = pcntl_fork();
                    if ($pid === -1) {
                        // Fork failed — handle TLS handshake and connection in main process
                        $forked = false;
                        if ($this->debug) {
                            $this->log("WARNING: Fork failed, handling connection in main process");
                        }
                        if ($isTls && !$this->doTlsHandshake($conn)) {
                            fclose($conn);
                            continue;
                        }
                    } elseif ($pid === 0) {
                        // Child process — TLS handshake must happen here, after fork.
                        // If we did it in the parent, the parent's fclose() would send
                        // SSL close_notify and destroy the session before the child uses it.
                        fclose($server);
                        if ($tlsServer) {
                            fclose($tlsServer);
                        }
                        $forked = true;
                        if ($isTls && !$this->doTlsHandshake($conn)) {
                            fclose($conn);
                            exit(0);
                        }
                    } else {
                        // Parent process — conn is still a plain TCP socket (no TLS yet),
                        // so fclose() just decrements the OS fd refcount; no SSL close_notify.
                        fclose($conn);
                        while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {}
                        continue;
                    }
                } else {
                    // No fork (Windows or pcntl not compiled in) — handshake in main process
                    if ($isTls && !$this->doTlsHandshake($conn)) {
                        fclose($conn);
                        continue;
                    }
                }

                $this->handleConnection($conn, $forked, $isTls);
            }
        }
    }

    /**
     * Log a message
     */
    private function log(string $message): void
    {
        $timestamp = '[' . date('Y-m-d H:i:s') . '] ';
        $logMessage = $timestamp . $message . "\n";

        if ($this->logFile) {
            file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        }

        if (!$this->daemonMode) {
            echo $logMessage;
        }
    }

    // ===== TELNET PROTOCOL METHODS =====

    /**
     * Daemonize the process
     */
    private function daemonize(): void
    {
        // Fork the process
        $pid = pcntl_fork();
        if ($pid === -1) {
            fwrite(STDERR, "Failed to fork daemon process\n");
            exit(1);
        } elseif ($pid > 0) {
            exit(0);
        }

        // Become session leader
        if (posix_setsid() === -1) {
            fwrite(STDERR, "Failed to become session leader\n");
            exit(1);
        }

        // Fork again to prevent acquiring a controlling terminal
        $pid = pcntl_fork();
        if ($pid === -1) {
            fwrite(STDERR, "Failed to fork second time\n");
            exit(1);
        } elseif ($pid > 0) {
            exit(0);
        }

        // Close standard file descriptors
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);

        // Reopen them to /dev/null
        $devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $GLOBALS['STDIN'] = fopen($devNull, 'r');
        $GLOBALS['STDOUT'] = fopen($devNull, 'w');
        $GLOBALS['STDERR'] = fopen($devNull, 'w');

        // Write PID file if configured and store master PID
        if ($this->pidFile) {
            $pidDir = dirname($this->pidFile);
            if (!is_dir($pidDir)) {
                mkdir($pidDir, 0755, true);
            }
            $this->masterPid = getmypid();
            file_put_contents($this->pidFile, $this->masterPid);
        }
    }

    /**
     * Clean up daemon resources
     */
    private function cleanupDaemon(): void
    {
        // Only delete PID file from master process, not forked children
        if ($this->pidFile && file_exists($this->pidFile) && getmypid() === $this->masterPid) {
            @unlink($this->pidFile);
        }
    }

    /**
     * Perform TLS handshake on an accepted TCP socket.
     *
     * Must be called in the process that will use the connection (child after fork,
     * or main process when no fork is available).  Calling it in the parent before
     * fork would cause the parent's fclose() to send SSL close_notify and tear down
     * the session before the child can use it.
     *
     * @param resource $conn Accepted TCP socket
     * @return bool True on successful handshake, false on failure (caller must fclose)
     */
    private function doTlsHandshake($conn): bool
    {
        $peerName = @stream_socket_get_name($conn, true);
        $peerIp   = $peerName ? explode(':', $peerName)[0] : 'unknown';

        stream_set_blocking($conn, true);
        stream_set_timeout($conn, 10);

        // Set SSL options explicitly on the accepted socket — context inheritance
        // from the server socket is not guaranteed on all PHP/OS combinations.
        stream_context_set_option($conn, 'ssl', 'local_cert',          $this->tlsCert);
        stream_context_set_option($conn, 'ssl', 'local_pk',            $this->tlsKey);
        stream_context_set_option($conn, 'ssl', 'allow_self_signed',   true);
        stream_context_set_option($conn, 'ssl', 'verify_peer',         false);
        stream_context_set_option($conn, 'ssl', 'ciphers',             'DEFAULT:@SECLEVEL=0');
        stream_context_set_option($conn, 'ssl', 'disable_compression', true);

        $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER
                      | STREAM_CRYPTO_METHOD_TLSv1_1_SERVER
                      | STREAM_CRYPTO_METHOD_TLSv1_0_SERVER;

        error_clear_last();
        $handshake = false;
        $attempts  = 0;
        while ($attempts++ < 50) {
            $result = @stream_socket_enable_crypto($conn, true, $cryptoMethod);
            if ($result === true)  { $handshake = true; break; }
            if ($result === false) { break; }
            usleep(50000);
        }

        if (!$handshake) {
            $opensslErrors = [];
            while ($err = openssl_error_string()) {
                $opensslErrors[] = $err;
            }
            $phpError = error_get_last();
            $detail   = $opensslErrors ? ': ' . implode(' | ', $opensslErrors) : '';
            if ($phpError) {
                $detail .= ' [PHP: ' . $phpError['message'] . ']';
            }
            $this->log("TLS handshake failed from {$peerIp}{$detail}");
            return false;
        }

        $meta     = stream_get_meta_data($conn);
        $crypto   = $meta['crypto'] ?? [];
        $protocol = $crypto['protocol']    ?? 'unknown';
        $cipher   = $crypto['cipher_name'] ?? 'unknown';
        $bits     = $crypto['cipher_bits'] ?? '?';
        $this->log("TLS connection from {$peerIp} [{$protocol} {$cipher} {$bits}-bit]");
        return true;
    }

    /**
     * Ensure a TLS certificate and key exist, generating a self-signed pair if not.
     * Mirrors the approach used by the Gemini capsule server: openssl CLI first,
     * PHP openssl_* functions as fallback.
     *
     * @param string $dataDir Directory to create/store generated cert files
     * @throws \RuntimeException if certificate generation fails
     */
    private function ensureTlsCert(string $dataDir): void
    {
        if (file_exists($this->tlsCert) && file_exists($this->tlsKey)) {
            return;
        }

        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0750, true);
        }

        $this->log('Generating self-signed TLS certificate...');

        $cn = 'localhost';
        try {
            $siteUrl = Config::getSiteUrl();
            $parsed  = parse_url($siteUrl);
            if (!empty($parsed['host'])) {
                $cn = $parsed['host'];
            }
        } catch (\Exception $e) {
            // fall back to localhost
        }

        $opensslCnf = realpath(dirname(dirname(__DIR__)) . '/config/gemini_openssl.cnf');

        if ($opensslCnf !== false && $this->generateTlsCertViaCli($cn, $opensslCnf)) {
            return;
        }

        // Fallback: PHP openssl_* functions
        $opensslCfg = $opensslCnf ? ['config' => $opensslCnf] : [];

        $pkey = openssl_pkey_new(array_merge([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ], $opensslCfg));

        if ($pkey === false) {
            throw new \RuntimeException('openssl_pkey_new() failed — check that the openssl PHP extension is enabled');
        }

        $csr = openssl_csr_new(
            ['commonName' => $cn],
            $pkey,
            array_merge(['digest_alg' => 'sha256'], $opensslCfg)
        );
        if ($csr === false) {
            throw new \RuntimeException('openssl_csr_new() failed');
        }

        $cert = openssl_csr_sign($csr, null, $pkey, 3650, array_merge(['digest_alg' => 'sha256'], $opensslCfg));
        if ($cert === false) {
            throw new \RuntimeException('openssl_csr_sign() failed');
        }

        $certPem = '';
        $keyPem  = '';
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($pkey, $keyPem, null, $opensslCfg);

        file_put_contents($this->tlsCert, $certPem . $keyPem);
        file_put_contents($this->tlsKey, $keyPem);
        chmod($this->tlsCert, 0600);
        chmod($this->tlsKey, 0600);

        $this->log("TLS certificate generated for CN={$cn} (PHP), stored in {$dataDir}/");
    }

    /**
     * Attempt to generate a self-signed cert using the openssl CLI.
     *
     * @return bool True on success, false if CLI is unavailable or fails
     */
    private function generateTlsCertViaCli(string $cn, string $opensslCnf): bool
    {
        // escapeshellarg() wraps in quotes, so a single leading slash is correct
        // on all platforms.  The historical '//CN=' Windows workaround is not
        // needed here and causes OpenSSL 3.x to produce an empty subject DN.
        $subj = '/CN=' . $cn;

        $sanParts = filter_var($cn, FILTER_VALIDATE_IP) ? ['IP:' . $cn] : ['DNS:' . $cn];
        if ($cn !== 'localhost') {
            $sanParts[] = 'DNS:localhost';
            $sanParts[] = 'IP:127.0.0.1';
        } else {
            $sanParts[] = 'IP:127.0.0.1';
        }
        $san = implode(',', $sanParts);

        $cmd = implode(' ', [
            'openssl', 'req',
            '-x509',
            '-newkey', 'rsa:2048',
            '-keyout', escapeshellarg($this->tlsKey),
            '-out',    escapeshellarg($this->tlsCert),
            '-days',   '3650',
            '-nodes',
            '-config', escapeshellarg($opensslCnf),
            '-subj',   escapeshellarg($subj),
            '-addext', escapeshellarg("subjectAltName={$san}"),
            '2>&1',
        ]);

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($this->tlsCert) || !file_exists($this->tlsKey)) {
            $this->log('DEBUG: openssl CLI unavailable or failed: ' . implode(' | ', $output));
            return false;
        }

        // Append key to cert file so PHP ssl:// local_cert can load both from one file
        $certPem = file_get_contents($this->tlsCert);
        $keyPem  = file_get_contents($this->tlsKey);
        file_put_contents($this->tlsCert, $certPem . $keyPem);
        chmod($this->tlsCert, 0600);
        chmod($this->tlsKey, 0600);

        $this->log("TLS certificate generated for CN={$cn} (openssl CLI), stored in " . dirname($this->tlsCert) . '/');
        return true;
    }

    /**
     * Handle an individual client connection
     *
     * @param resource $conn Socket connection resource
     * @param bool $forked Whether this is running in a forked child process
     * @param bool $isTls Whether the connection is TLS-encrypted
     */
    private function handleConnection($conn, bool $forked, bool $isTls = false): void
    {
        $session = new BbsSession(
            $conn,
            $this->apiBase,
            $this->debug,
            $this->insecure,
            $isTls,
            false,
            $this->tlsEnabled,
            $this->tlsPort,
            $this->logFile,
            null
        );
        $session->run($forked);
    }

}
