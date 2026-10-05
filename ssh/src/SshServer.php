<?php

namespace BinktermPHP\SshServer;

use BinktermPHP\Binkp\Logger;
use BinktermPHP\Config;
use BinktermPHP\Terminal\ConnectionRateLimiter;
use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\SshServer\SshSession;
use BinktermPHP\Version;

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
    private ?Logger $logger     = null;
    private bool   $daemonMode  = false;
    private ?string $pidFile    = null;
    private ?int   $masterPid   = null;
    private string $hostKeyFile;
    private ConnectionRateLimiter $rateLimiter;

    /** Global ceiling on simultaneously live SSH child processes. */
    private int $maxChildren;
    /** True when SSH_MAX_CHILDREN was non-positive and the safe default was used. */
    private bool $maxChildrenClamped = false;
    /** @var array<int,true> live direct-child PIDs, one per accepted connection */
    private array $childPids = [];
    private bool $capacityLogged = false;
    private int  $capacitySuppressed = 0;

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

        // Per-source-IP connection rate limit, applied before any fork/KEX/auth
        // work — the same fixed-window policy the Telnet daemon uses, with
        // SSH-namespaced env keys and the identical defaults (5 / 60s; set max
        // to 0 to disable).
        $this->rateLimiter = new ConnectionRateLimiter(
            (int)Config::env('SSH_RATE_LIMIT_MAX', '5'),
            (int)Config::env('SSH_RATE_LIMIT_WINDOW', '60'),
            fn(string $message) => $this->log($message)
        );

        // Global live-child ceiling. A distributed / many-source-IP pre-auth
        // flood gets past the per-IP limiter, so cap the total number of
        // fork+KEX workers that can exist at once. Non-positive config is NOT
        // treated as "disabled" — an unbounded fork path is the whole thing
        // this guards — it falls back to the safe default instead.
        $rawMaxChildren          = (int)Config::env('SSH_MAX_CHILDREN', '32');
        $this->maxChildren       = $rawMaxChildren > 0 ? $rawMaxChildren : 32;
        $this->maxChildrenClamped = $rawMaxChildren <= 0;
    }

    public function setPidFile(string $path): void { $this->pidFile = $path; }

    // =========================================================================
    // START
    // =========================================================================

    public function start(bool $daemonMode = false): void
    {
        $this->daemonMode = $daemonMode;

        $this->logger = new Logger(
            Config::getLogPath('sshd.log'),
            Config::env('SSHD_LOG_LEVEL', 'INFO'),
            !$daemonMode
        );

        if ($this->maxChildrenClamped) {
            $this->log("SSH_MAX_CHILDREN was non-positive; using the safe default of {$this->maxChildren}");
        }

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
                $this->reapChildren();
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
                $this->reapChildren();
                // Prune expired rate-limit entries (mirrors the Telnet daemon's
                // cleanRateTable() on each select() timeout).
                $this->rateLimiter->clean();
                continue;
            }

            $conn = @stream_socket_accept($server, 0);
            if (!$conn) { continue; }

            $connectionCount++;

            // Per-IP connection rate limit — BEFORE pcntl_fork(), the SSH
            // handshake, or any auth work, so a single flooding source cannot
            // spend the box's processes/CPU pre-authentication.
            $peer   = @stream_socket_get_name($conn, true);
            $peerIp = $this->extractIp($peer);
            if ($peerIp !== null) {
                $rl = $this->rateLimiter->check($peerIp);
                if ($rl > 0) {
                    if ($rl === 1) {
                        $this->log("SSH rate limit exceeded for {$peerIp} - connection rejected");
                    }
                    @fwrite($conn, "Too many connections from your IP. Please try again later.\r\n");
                    fclose($conn);
                    continue;
                }
            }

            // Global live-child ceiling. Reap first so the count reflects
            // children that have already exited, then admit or reject WITHOUT
            // forking or doing any SSH/KEX/auth work — capacity rejection must
            // pay no child-process cost.
            $this->reapChildren();
            if ($this->atCapacity()) {
                if (!$this->capacityLogged) {
                    $this->capacityLogged = true;
                    $this->log("SSH at capacity ({$this->maxChildren} concurrent sessions) - connection rejected");
                } else {
                    $this->capacitySuppressed++;
                }
                @fwrite($conn, "Server is at capacity. Please try again later.\r\n");
                fclose($conn);
                continue;
            }

            if ($this->debug) {
                $this->log("Connection #{$connectionCount} from {$peer}");
            }

            if (function_exists('pcntl_fork')) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    // Fork failed — handle in main process (no child, no slot).
                    $this->handleConnection($conn, false);
                } elseif ($pid === 0) {
                    // Child
                    fclose($server);
                    $this->handleConnection($conn, true);
                } else {
                    // Parent — track the child against the global ceiling.
                    $this->childPids[$pid] = true;
                    if ($this->capacityLogged) {
                        if ($this->capacitySuppressed > 0) {
                            $this->log("SSH capacity: {$this->capacitySuppressed} further connection(s) were rejected while full");
                        }
                        $this->capacityLogged = false;
                        $this->capacitySuppressed = 0;
                    }
                    fclose($conn);
                    $this->reapChildren();
                }
            } else {
                $this->handleConnection($conn, false);
            }
        }
    }

    /**
     * Whether the global live-child ceiling has been reached. Admission control
     * only — keyed on nothing per-IP, so every source shares the one ceiling.
     */
    private function atCapacity(): bool
    {
        return count($this->childPids) >= $this->maxChildren;
    }

    /**
     * One non-blocking child reap. Isolated so tests can drive the reap
     * bookkeeping without real subprocesses.
     *
     * @return int reaped pid (>0), 0 (none ready), or -1 (no children / no pcntl)
     */
    protected function reapOne(): int
    {
        if (!function_exists('pcntl_waitpid')) {
            return -1;
        }
        $status = 0;
        return pcntl_waitpid(-1, $status, WNOHANG);
    }

    /**
     * Reap every child that has exited and release its capacity slot. Called
     * from the SIGCHLD handler, on each accept-loop select() timeout, right
     * before the capacity check, and just after a fork — so a slot cannot leak
     * past a child's normal exit.
     */
    private function reapChildren(): void
    {
        while (($pid = $this->reapOne()) > 0) {
            unset($this->childPids[$pid]);
        }
    }

    /**
     * Pull the bare IP out of a `stream_socket_get_name()` peer string
     * (`1.2.3.4:5678` or `[2001:db8::1]:5678`). Returns null when it cannot be
     * resolved — the caller then skips rate limiting for that connection rather
     * than keying the whole table under a bogus value.
     */
    private function extractIp(?string $peer): ?string
    {
        if ($peer === null || $peer === '') {
            return null;
        }

        if ($peer[0] === '[') {
            $end = strpos($peer, ']');
            $ip  = $end !== false ? substr($peer, 1, $end - 1) : $peer;
        } else {
            $ip = strrpos($peer, ':') !== false ? substr($peer, 0, strrpos($peer, ':')) : $peer;
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
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
                'term_type'  => $authResult['term_type'] ?? null,
                'sixel_supported' => !empty($authResult['sixel_supported']),
            ]
            : [
                'cols' => $authResult['cols'] ?? 80,
                'rows' => $authResult['rows'] ?? 24,
                'term_type' => $authResult['term_type'] ?? null,
                'sixel_supported' => !empty($authResult['sixel_supported']),
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
                $this->logger,
                $preAuth,
                $peer ?: 'unknown',
                $peerIp
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
            // Suppress SIGPIPE so that a broken plain socket returns false from
            // fwrite rather than killing the bridge process immediately.
            if (function_exists('pcntl_signal')) { pcntl_signal(SIGPIPE, SIG_IGN); }
            $this->runBridge($conn, $sshSession, $sshSide);
            exit(0);
        }

        // Session child — BbsSession runs on plainSide
        fclose($sshSide);
        // Suppress SIGPIPE so that if the bridge exits during a file transfer,
        // writes to $plainSide return false rather than killing the session.
        if (function_exists('pcntl_signal')) { pcntl_signal(SIGPIPE, SIG_IGN); }

        $bbsSession = new BbsSession(
            $plainSide,
            $this->apiBase,
            $this->debug,
            $this->insecure,
            false,   // isTls
            true,    // isSsh
            false,   // tlsEnabled (no hint needed for SSH)
            0,       // tlsPort
            $this->logger,
            $preAuth,
            $peer ?: 'unknown',
            $peerIp
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
            if ($ready === false) {
                // stream_select can return false when interrupted by a signal
                // (EINTR).  Only break if the sockets are actually dead.
                if (feof($sshConn) || feof($plainSocket)) { break; }
                continue;
            }

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

            // Inject a NAWS subneg if a window-change channel request arrived.
            // BbsSession's existing NAWS handler picks this up transparently.
            $resize = $sshSession->consumePendingResize();
            if ($resize !== null) {
                $toPlain .= SshSession::nawsBytes($resize['cols'], $resize['rows']);
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
        $this->logger?->info($message);
    }
}
