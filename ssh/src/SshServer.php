<?php

namespace BinktermPHP\SshServer;

use BinktermPHP\Config;
use BinktermPHP\Version;
use BinktermPHP\TelnetServer\BbsSession;

/**
 * SshServer — pure-PHP SSH-2 BBS server daemon.
 *
 * Accepts TCP connections on a configurable port, performs the SSH handshake
 * (via SshSession), then hands the authenticated session to BbsSession for
 * the BBS UI — exactly like the Telnet daemon does.
 *
 * Architecture:
 *   - Main process: accept loop, pcntl_fork per connection
 *   - Parent: closes conn fd and waits for next connection
 *   - Child:  runs SshSession::handshake(), then creates a socket pair,
 *             forks again into a "bridge" process that shuttles encrypted SSH
 *             data between the real socket and the plain side of the pair, and
 *             a "session" process that runs BbsSession on the plain side.
 *
 * The bridge/session double-fork keeps BbsSession completely unaware of SSH:
 * it just reads/writes a regular stream socket the same way it does for Telnet.
 */
class SshServer
{
    private string $host;
    private int    $port;
    private string $apiBase;
    private bool   $debug;
    private bool   $insecure;
    private ?string $logFile    = null;
    private bool   $daemonMode  = false;
    private ?string $pidFile    = null;
    private ?int   $masterPid   = null;
    private string $hostKeyFile;

    public function __construct(
        string $host,
        int    $port,
        string $apiBase,
        bool   $debug    = false,
        bool   $insecure = false
    ) {
        $this->host     = $host;
        $this->port     = $port;
        $this->apiBase  = rtrim($apiBase, '/');
        $this->debug    = $debug;
        $this->insecure = $insecure;

        $dataDir            = dirname(__DIR__, 2) . '/data/ssh';
        $this->hostKeyFile  = $dataDir . '/ssh_host_rsa_key';
    }

    public function setPidFile(string $path): void { $this->pidFile = $path; }

    // =========================================================================
    // START
    // =========================================================================

    public function start(bool $daemonMode = false): void
    {
        $this->daemonMode = $daemonMode;

        $logDir = dirname(__DIR__, 2) . '/data/logs';
        if (!is_dir($logDir)) { mkdir($logDir, 0755, true); }
        $this->logFile = $logDir . '/sshd.log';

        if ($daemonMode) {
            if (!function_exists('pcntl_fork') || !function_exists('posix_setsid')) {
                fwrite(STDERR, "Daemon mode requires pcntl and posix extensions\n");
                exit(1);
            }
            $this->log("Starting SSH daemon in background mode");
            $this->daemonize();
            register_shutdown_function(fn() => $this->cleanupDaemon());
        }

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGCHLD, function() {
                while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {}
            });
            $shutdown = function() use (&$server) {
                $this->log("Received shutdown signal");
                $this->cleanupDaemon();
                if (isset($server) && is_resource($server)) { fclose($server); }
                exit(0);
            };
            pcntl_signal(SIGTERM, $shutdown);
            pcntl_signal(SIGINT,  $shutdown);
            if (function_exists('pcntl_async_signals')) { pcntl_async_signals(true); }
        }

        // Ensure host key exists before binding (so errors surface early)
        $this->ensureHostKey();

        $server = stream_socket_server("tcp://{$this->host}:{$this->port}", $errno, $errstr);
        if (!$server) {
            fwrite(STDERR, "Failed to bind SSH server on {$this->host}:{$this->port}: {$errstr} ({$errno})\n");
            exit(1);
        }
        $this->log("SSH daemon " . Version::getVersion() . " listening on {$this->host}:{$this->port}");

        if ($this->pidFile) {
            @mkdir(dirname($this->pidFile), 0755, true);
            file_put_contents($this->pidFile, getmypid());
        }

        $connectionCount = 0;

        while (true) {
            if (function_exists('pcntl_signal_dispatch') && !function_exists('pcntl_async_signals')) {
                pcntl_signal_dispatch();
            }

            $read    = [$server];
            $write   = $except = null;
            $changed = @stream_select($read, $write, $except, 60);
            if ($changed === false || $changed === 0) {
                if (function_exists('pcntl_waitpid')) {
                    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {}
                }
                continue;
            }

            $conn = @stream_socket_accept($server, 0);
            if (!$conn) { continue; }

            $connectionCount++;
            if ($this->debug) {
                $peer = @stream_socket_get_name($conn, true);
                $this->log("Connection #{$connectionCount} from {$peer}");
            }

            if (function_exists('pcntl_fork')) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    // Fork failed — handle in main process
                    $this->handleConnection($conn, false);
                } elseif ($pid === 0) {
                    // Child
                    fclose($server);
                    $this->handleConnection($conn, true);
                } else {
                    // Parent
                    fclose($conn);
                    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {}
                }
            } else {
                $this->handleConnection($conn, false);
            }
        }
    }

    // =========================================================================
    // PER-CONNECTION HANDLER
    // =========================================================================

    private function handleConnection($conn, bool $forked): void
    {
        $peer   = @stream_socket_get_name($conn, true);
        $peerIp = $peer ? explode(':', $peer)[0] : 'unknown';

        $sshSession = new SshSession(
            $conn,
            $this->apiBase,
            $this->debug,
            $this->insecure,
            $this->hostKeyFile,
            $this->hostKeyFile . '.pub'
        );

        $authResult = $sshSession->handshake();
        if ($authResult === null) {
            $this->log("SSH handshake/auth failed from {$peerIp}");
            fclose($conn);
            if ($forked) { exit(0); }
            return;
        }

        $authenticated = $authResult['authenticated'] ?? true;
        if ($authenticated) {
            $this->log("SSH session started (authenticated): {$authResult['username']} from {$peerIp}");
        } else {
            $this->log("SSH session started (unauthenticated) from {$peerIp}");
        }

        // Create a socket pair so BbsSession can use a normal read/write stream
        // while the bridge process handles SSH framing transparently.
        // stream_socket_pair(STREAM_PF_UNIX) fails on Windows; fall back to a
        // loopback TCP pair which works on all platforms.
        $pair = $this->createSocketPair();
        if ($pair === false) {
            $this->log("Failed to create socket pair");
            fclose($conn);
            if ($forked) { exit(0); }
            return;
        }

        [$plainSide, $sshSide] = $pair;

        // Fork the bridge process
        $bridgePid = function_exists('pcntl_fork') ? pcntl_fork() : -1;

        // Authenticated: pass full session so BbsSession skips its login UI.
        // Unauthenticated: pass only terminal size so BbsSession shows login.
        $preAuth = $authenticated
            ? [
                'session'    => $authResult['session'],
                'username'   => $authResult['username'],
                'csrf_token' => $authResult['csrf_token'] ?? null,
                'cols'       => $authResult['cols'] ?? 80,
                'rows'       => $authResult['rows'] ?? 24,
            ]
            : [
                'cols' => $authResult['cols'] ?? 80,
                'rows' => $authResult['rows'] ?? 24,
            ];

        if ($bridgePid === -1) {
            // No fork available (Windows).  Use a stream wrapper so BbsSession
            // reads/writes the SSH channel directly as a plain stream — no bridge
            // process needed.
            fclose($plainSide);
            fclose($sshSide);

            $sshStream = SshStreamWrapper::open($sshSession);
            if ($sshStream === false) {
                $this->log("Failed to create SSH stream wrapper");
                fclose($conn);
                if ($forked) { exit(0); }
                return;
            }

            $bbsSession = new BbsSession(
                $sshStream,
                $this->apiBase,
                $this->debug,
                $this->insecure,
                false, true, false, 0,
                $this->logFile,
                $preAuth
            );
            $bbsSession->run($forked);

            if (is_resource($sshStream)) { fclose($sshStream); }
            if (is_resource($conn))      { fclose($conn); }
            if ($forked) { exit(0); }
            return;
        }

        if ($bridgePid === 0) {
            // Bridge child — shuttles data between SSH socket and sshSide of pair
            fclose($plainSide);
            $this->runBridge($conn, $sshSession, $sshSide);
            exit(0);
        }

        // Session child — BbsSession runs on plainSide
        fclose($sshSide);

        $bbsSession = new BbsSession(
            $plainSide,
            $this->apiBase,
            $this->debug,
            $this->insecure,
            false,   // isTls
            true,    // isSsh
            false,   // tlsEnabled (no hint needed for SSH)
            0,       // tlsPort
            $this->logFile,
            $preAuth
        );

        $bbsSession->run(true);

        // Tell the bridge to stop
        fclose($plainSide);
        if (function_exists('posix_kill')) { @posix_kill($bridgePid, SIGTERM); }
        pcntl_waitpid($bridgePid, $status);

        fclose($conn);
        if ($forked) { exit(0); }
    }

    // =========================================================================
    // SOCKET PAIR
    // =========================================================================

    /**
     * Create a bidirectional socket pair.
     *
     * Uses Unix domain sockets on Linux/macOS and a loopback TCP pair on
     * Windows, where STREAM_PF_UNIX is not reliably available.
     *
     * @return array{0:resource,1:resource}|false  [plainSide, sshSide] or false
     */
    private function createSocketPair(): array|false
    {
        if (PHP_OS_FAMILY !== 'Windows' && defined('STREAM_PF_UNIX')) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair !== false) { return $pair; }
        }

        // Windows fallback: loopback TCP pair
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$server) { return false; }

        $addr   = stream_socket_get_name($server, false);
        $client = stream_socket_client("tcp://{$addr}", $errno, $errstr, 5);
        if (!$client) { fclose($server); return false; }

        $peer = stream_socket_accept($server, 5);
        fclose($server);
        if (!$peer) { fclose($client); return false; }

        return [$client, $peer];
    }

    // =========================================================================
    // SSH ↔ PLAIN BRIDGE
    // =========================================================================

    /**
     * Bridge process: shuttles data between the SSH socket and the plain socket pair.
     *
     * Both sockets are kept in non-blocking mode.  Raw bytes from the SSH socket
     * are fed into SshSession's reassembly buffer via feedRawBytes(); complete SSH
     * packets are extracted with tryReadChannelData() without ever blocking.
     * trySendChannelData() honours the SSH flow-control window and returns
     * immediately if the window is exhausted, avoiding the blocking loop that
     * waitForPeerWindowAdjust() would otherwise introduce.
     *
     * This removes the head-of-line blocking that previously stalled Z-modem
     * transfers when the bridge was waiting inside readChannelData() while the
     * BBS session needed the plain socket serviced in the other direction.
     */
    private function runBridge($sshConn, SshSession $sshSession, $plainSocket): void
    {
        stream_set_blocking($sshConn,    false);
        stream_set_blocking($plainSocket, false);

        $toPlain = '';  // SSH channel data → plain socket
        $toSsh   = '';  // plain socket data → SSH channel

        while (true) {
            $read   = [$sshConn, $plainSocket];
            $write  = null;
            $except = null;
            // When plain→SSH data is queued but the SSH window is full, use a
            // short timeout so we wake up quickly once a WINDOW_ADJUST arrives.
            $windowFull = $toSsh !== '' && $sshSession->getPeerWindowSize() <= 0;
            $sec  = $windowFull ? 0 : 1;
            $usec = $windowFull ? 50000 : 0;

            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false) { break; }

            // Feed any newly arrived raw SSH bytes into the session buffer.
            if (in_array($sshConn, $read, true)) {
                $raw = @fread($sshConn, 65536);
                if ($raw === false || ($raw === '' && feof($sshConn))) { break; }
                if ($raw !== '') { $sshSession->feedRawBytes($raw); }
            }

            // Drain all complete SSH packets from the buffer.  This runs every
            // loop iteration so bytes carried over from a prior read are consumed.
            while (true) {
                $data = $sshSession->tryReadChannelData();
                if ($data === null) { goto bridgeDone; }
                if ($data === false) { break; }
                if ($data !== '') { $toPlain .= $data; }
            }

            // Read BBS-session output from the plain socket.
            if (in_array($plainSocket, $read, true)) {
                $chunk = @fread($plainSocket, 8192);
                if ($chunk === false || ($chunk === '' && feof($plainSocket))) { break; }
                if ($chunk !== '') { $toSsh .= $chunk; }
            }

            // Forward plain→SSH within the current window.  Unsent remainder
            // stays in $toSsh and is retried once the window reopens.
            if ($toSsh !== '') {
                try {
                    $sshSession->trySendChannelData($toSsh);
                } catch (\Throwable $e) { break; }
            }

            // Forward SSH→plain.  Non-blocking fwrite returns immediately; a
            // partial write leaves the remainder in $toPlain for the next pass.
            if ($toPlain !== '') {
                $n = @fwrite($plainSocket, $toPlain);
                if ($n === false) { break; }
                if ($n > 0) { $toPlain = (string)substr($toPlain, $n); }
            }
        }

        bridgeDone:
        $sshSession->sendChannelClose();
    }
    private function ensureHostKey(): void
    {
        if (file_exists($this->hostKeyFile)) { return; }

        $dir = dirname($this->hostKeyFile);
        if (!is_dir($dir)) { mkdir($dir, 0700, true); }

        $this->log("Generating SSH host key at {$this->hostKeyFile}...");

        // Use a bundled openssl.cnf so key generation works on Windows without
        // requiring a system-wide OpenSSL install or OPENSSL_CONF to be set.
        $cnfPath = realpath(dirname(__DIR__, 2) . '/config/ssh_openssl.cnf');
        $cfg = $cnfPath ? ['config' => $cnfPath] : [];

        $key = openssl_pkey_new(array_merge([
            'private_key_bits' => 3072,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ], $cfg));
        if ($key === false) {
            throw new \RuntimeException('Failed to generate SSH host key: ' . openssl_error_string());
        }
        openssl_pkey_export($key, $pem, null, $cfg ?: null);
        file_put_contents($this->hostKeyFile, $pem);
        chmod($this->hostKeyFile, 0600);
        $this->log("SSH host key generated");
    }

    // =========================================================================
    // DAEMON SUPPORT
    // =========================================================================

    private function daemonize(): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) { fwrite(STDERR, "Fork failed\n"); exit(1); }
        if ($pid > 0)    { exit(0); }

        posix_setsid();

        $pid = pcntl_fork();
        if ($pid === -1) { fwrite(STDERR, "Second fork failed\n"); exit(1); }
        if ($pid > 0)    { exit(0); }

        $this->masterPid = getmypid();
        chdir('/');
        umask(0);

        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
    }

    private function cleanupDaemon(): void
    {
        if ($this->pidFile && file_exists($this->pidFile) && (int)file_get_contents($this->pidFile) === getmypid()) {
            unlink($this->pidFile);
        }
    }

    private function log(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        if ($this->logFile) {
            file_put_contents($this->logFile, $line, FILE_APPEND);
        }
        if (!$this->daemonMode) {
            echo $line;
        }
    }
}
