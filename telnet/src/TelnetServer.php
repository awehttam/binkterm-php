<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Config;
use BinktermPHP\Version;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\BbsConfig;


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
    private array $failedLoginAttempts = [];

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
            $gracefulShutdown = function($signo) use (&$server) {
                $this->log("Received shutdown signal, cleaning up...");
                $this->cleanupDaemon();
                if (isset($server) && is_resource($server)) {
                    fclose($server);
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

        // Create server socket
        $server = stream_socket_server("tcp://{$this->host}:{$this->port}", $errno, $errstr);
        if (!$server) {
            fwrite(STDERR, "Failed to bind telnet server: {$errstr} ({$errno})\n");
            exit(1);
        }

        $this->log("Telnet daemon ".Version::getVersion()." listening on {$this->host}:{$this->port}");
        if ($this->debug) {
            $this->log("DEBUG MODE");
            $this->log("API Base URL: {$this->apiBase}");
        }

        // Set terminal title in foreground mode
        if (!$daemonMode) {
            echo "\033]0;BinktermPHP Telnet Server\007";
        }

        $connectionCount = 0;

        // Main server loop
        while (true) {
            // Dispatch signals if async signals not available
            if (function_exists('pcntl_signal_dispatch') && !function_exists('pcntl_async_signals')) {
                pcntl_signal_dispatch();
            }

            $conn = @stream_socket_accept($server, 60);
            if (!$conn) {
                // Timeout or error, reap zombies and continue
                if (function_exists('pcntl_waitpid')) {
                    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
                        // Reap zombie processes
                    }
                }
                continue;
            }

            $connectionCount++;
            if ($this->debug) {
                $peerName = @stream_socket_get_name($conn, true);
                echo "[" . date('Y-m-d H:i:s') . "] Connection #{$connectionCount} from {$peerName}\n";
            }

            $forked = false;
            if (function_exists('pcntl_fork')) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    // Fork failed
                    $forked = false;
                    if ($this->debug) {
                        echo "[" . date('Y-m-d H:i:s') . "] WARNING: Fork failed, handling connection in main process\n";
                    }
                } elseif ($pid === 0) {
                    // Child process
                    fclose($server);
                    $forked = true;
                } else {
                    // Parent process
                    fclose($conn);
                    // Reap any finished children (non-blocking)
                    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
                        // Zombie reaped
                    }
                    continue;
                }
            }

            // Handle connection
            $this->handleConnection($conn, $forked);
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

        // Write PID file if configured
        if ($this->pidFile) {
            $pidDir = dirname($this->pidFile);
            if (!is_dir($pidDir)) {
                mkdir($pidDir, 0755, true);
            }
            file_put_contents($this->pidFile, getmypid());
        }
    }

    /**
     * Clean up daemon resources
     */
    private function cleanupDaemon(): void
    {
        if ($this->pidFile && file_exists($this->pidFile)) {
            @unlink($this->pidFile);
        }
    }

    /**
     * Handle an individual client connection
     *
     * @param resource $conn Socket connection resource
     * @param bool $forked Whether this is running in a forked child process
     */
    private function handleConnection($conn, bool $forked): void
    {
        stream_set_timeout($conn, 300);
        $state = [
            'telnet_mode' => null,
            'input_echo' => true,
            'cols' => 80,
            'rows' => 24,
            'last_activity' => time(),
            'idle_warned' => false,
            'idle_warning_timeout' => 300,  // 5 minutes
            'idle_disconnect_timeout' => 420,  // 7 minutes (5 + 2)
            'pushback' => ''
        ];

        if ($this->debug) {
            echo "[" . date('Y-m-d H:i:s') . "] Connection initialized: Default screen size 80x24\n";
        }

        $this->negotiateTelnet($conn);

        // Get peer IP for rate limiting
        $peerName = @stream_socket_get_name($conn, true);
        $peerIp = $peerName ? explode(':', $peerName)[0] : 'unknown';

        // Check if IP is rate limited
        if ($this->isRateLimited($peerIp)) {
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize('Too many failed login attempts. Please try again later.', self::ANSI_RED));
            $this->writeLine($conn, '');
            echo "[" . date('Y-m-d H:i:s') . "] Rate limited connection from {$peerName}\n";
            fclose($conn);
            if ($forked) {
                exit(0);
            }
            return;
        }

        // Show login banner
        $this->showLoginBanner($conn);

        // Login/Register loop
        $loginResult = null;
        while ($loginResult === null) {
            $this->writeLine($conn, 'Would you like to:');
            $this->writeLine($conn, '  (L) Login to existing account');
            $this->writeLine($conn, '  (R) Register new account');
            $this->writeLine($conn, '  (Q) Quit');
            $this->writeLine($conn, '');
            $loginOrRegister = $this->prompt($conn, $state, 'Your choice: ', true);

            if ($loginOrRegister === null || strtolower(trim($loginOrRegister)) === 'q') {
                $this->writeLine($conn, $this->colorize('Goodbye!', self::ANSI_CYAN));
                fclose($conn);
                if ($forked) {
                    exit(0);
                }
                return;
            }

            // Handle registration
            if (strtolower(trim($loginOrRegister)) === 'r') {
                $registered = $this->attemptRegistration($conn, $state);
                if ($registered) {
                    $this->writeLine($conn, 'Press Enter to disconnect.');
                    $this->readLineWithIdleCheck($conn, $state);
                    fclose($conn);
                    if ($forked) {
                        exit(0);
                    }
                    return;
                }
                // Registration was cancelled - loop back to menu
                $this->writeLine($conn, '');
                continue;
            }

            // Proceed with login
            $this->writeLine($conn, '');

            // Allow up to 3 login attempts
            $maxAttempts = 3;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $loginResult = $this->attemptLogin($conn, $state);

                if ($loginResult !== null) {
                    // Successful login
                    $this->writeLine($conn, $this->colorize('Login successful.', self::ANSI_GREEN));
                    $this->writeLine($conn, '');
                    break 2; // Break out of both for loop and while loop
                }

                // Failed login
                $this->recordFailedLogin($peerIp);
                echo "[" . date('Y-m-d H:i:s') . "] Failed login attempt from {$peerName} (attempt {$attempt}/{$maxAttempts})\n";

                if ($attempt < $maxAttempts) {
                    $remaining = $maxAttempts - $attempt;
                    $this->writeLine($conn, $this->colorize("Login failed. {$remaining} attempt(s) remaining.", self::ANSI_RED));
                    $this->writeLine($conn, '');
                } else {
                    $this->writeLine($conn, $this->colorize('Login failed. Maximum attempts exceeded.', self::ANSI_RED));
                    $this->writeLine($conn, '');
                }
            }

            // If all attempts failed, disconnect
            if ($loginResult === null) {
                echo "[" . date('Y-m-d H:i:s') . "] Login failed (max attempts) from {$peerName}\n";
                fclose($conn);
                if ($forked) {
                    exit(0);
                }
                return;
            }
        }

        $session = $loginResult['session'];
        $username = $loginResult['username'];
        $loginTime = time();

        // Fetch user data once at login and store in state
        $userData = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/user', null, $session);
        $state['user_timezone'] = $userData['data']['timezone'] ?? 'UTC';
        $state['username'] = $username;

        // Clear failed login attempts for this IP on successful login
        $this->clearFailedLogins($peerIp);

        // Log successful login to console
        echo "[" . date('Y-m-d H:i:s') . "] Login: {$username} from {$peerName}\n";

        // Set terminal window title to BBS name
        $config = BinkpConfig::getInstance();
        $this->setTerminalTitle($conn, $config->getSystemName());

        // Instantiate handlers
        $netmailHandler = new \BinktermPHP\TelnetServer\NetmailHandler($this, $this->apiBase);
        $echomailHandler = new \BinktermPHP\TelnetServer\EchomailHandler($this, $this->apiBase);
        $shoutboxHandler = new \BinktermPHP\TelnetServer\ShoutboxHandler($this, $this->apiBase);
        $pollsHandler = new \BinktermPHP\TelnetServer\PollsHandler($this, $this->apiBase);

        // Show shoutbox if enabled
        $shoutboxHandler->show($conn, $state, $session, 5);

        // Get message counts once per session
        $messageCounts = MailUtils::getMessageCounts($this->apiBase, $session);

        // Main menu loop
        while (true) {
            // Check if connection is still alive
            if (!is_resource($conn) || feof($conn)) {
                $duration = time() - $loginTime;
                $minutes = floor($duration / 60);
                $seconds = $duration % 60;
                echo "[" . date('Y-m-d H:i:s') . "] Connection lost: {$username} (session duration: {$minutes}m {$seconds}s)\n";
                break;
            }

            // Build the main menu
            $cols = $state['cols'] ?? 80;
            $menuWidth = min(60, $cols - 4);
            $innerWidth = $menuWidth - 4;
            $menuLeft = max(0, (int)floor(($cols - $menuWidth) / 2));
            $menuPad = str_repeat(' ', $menuLeft);

            $systemName = $config->getSystemName();
            $border = '+' . str_repeat('=', $menuWidth - 2) . '+';
            $divider = '+' . str_repeat('-', $menuWidth - 2) . '+';

            // Clear screen before rendering menu
            $this->safeWrite($conn, "\033[2J\033[H");
            // Status bar with system name and local time
            $timeStr = date('Y-m-d H:i');
            $statusLine = TelnetUtils::buildStatusBar([
                ['text' => $systemName . '  ', 'color' => self::ANSI_BLUE],
                ['text' => str_repeat(' ', max(1, $cols - strlen($systemName) - strlen($timeStr) - 2)), 'color' => self::ANSI_BLUE],
                ['text' => $timeStr, 'color' => self::ANSI_BLUE],
            ], $cols);
            $this->safeWrite($conn, "\033[1;1H");
            $this->safeWrite($conn, $statusLine . "\r");
            $this->safeWrite($conn, "\033[2;1H");
            $this->writeLine($conn, '');
            $this->writeLine($conn, $menuPad . $this->colorize($border, self::ANSI_CYAN . self::ANSI_BOLD));
            $titleLine = '| ' . str_pad('Main Menu', $innerWidth, ' ', STR_PAD_BOTH) . ' |';
            $this->writeLine($conn, $menuPad . $this->colorize($titleLine, self::ANSI_BLUE . self::ANSI_BOLD));
            $this->writeLine($conn, $menuPad . $this->colorize($divider, self::ANSI_CYAN));

            // Menu options
            $showShoutbox = BbsConfig::isFeatureEnabled('shoutbox');
            $showPolls = BbsConfig::isFeatureEnabled('voting_booth');

            $option1 = '| N) Netmail (' . $messageCounts['netmail'] . ' messages)';
            $this->writeLine($conn, $menuPad . $this->colorize(str_pad($option1, $menuWidth - 1, ' ', STR_PAD_RIGHT) . '|', self::ANSI_GREEN));

            $option2 = '| E) Echomail (' . $messageCounts['echomail'] . ' messages)';
            $this->writeLine($conn, $menuPad . $this->colorize(str_pad($option2, $menuWidth - 1, ' ', STR_PAD_RIGHT) . '|', self::ANSI_GREEN));

            $option = 1;
            $shoutboxOption = null;
            $pollsOption = null;
            $whosOnlineOption = 'w';

            $optionLine = "| W) Who's Online";
            $this->writeLine($conn, $menuPad . $this->colorize(str_pad($optionLine, $menuWidth - 1, ' ', STR_PAD_RIGHT) . '|', self::ANSI_GREEN));

            if ($showShoutbox) {
                $optionLine = "| S) Shoutbox";
                $this->writeLine($conn, $menuPad . $this->colorize(str_pad($optionLine, $menuWidth - 1, ' ', STR_PAD_RIGHT) . '|', self::ANSI_GREEN));
                $shoutboxOption = 's';
            }
            if ($showPolls) {
                $optionLine = "| P) Polls";
                $this->writeLine($conn, $menuPad . $this->colorize(str_pad($optionLine, $menuWidth - 1, ' ', STR_PAD_RIGHT) . '|', self::ANSI_GREEN));
                $pollsOption = 'p';
            }
            $quitLine = "| Q) Quit";
            $this->writeLine($conn, $menuPad . $this->colorize(str_pad($quitLine, $menuWidth - 1, ' ', STR_PAD_RIGHT) . '|', self::ANSI_YELLOW));
            $quitOption = 'q';

            $this->writeLine($conn, $menuPad . $this->colorize($border, self::ANSI_CYAN . self::ANSI_BOLD));
            $this->writeLine($conn, '');

            // Prompt loop - accept a single key immediately
            $choice = '';
            $promptShown = false;
            while ($choice === '') {
                if (!$promptShown) {
                    $this->writeLine($conn, $this->colorize('Select option:', self::ANSI_DIM));
                    $promptShown = true;
                }

                [$key, $timedOut, $shouldDisconnect] = $this->readTelnetKeyWithTimeout($conn, $state);

                if ($shouldDisconnect) {
                    // Idle timeout disconnect
                    $duration = time() - $loginTime;
                    $minutes = floor($duration / 60);
                    $seconds = $duration % 60;
                    echo "[" . date('Y-m-d H:i:s') . "] Idle timeout: {$username} (session duration: {$minutes}m {$seconds}s)\n";
                    break 2; // Break out of both loops
                }

                if ($key === null) {
                    // Connection lost
                    $duration = time() - $loginTime;
                    $minutes = floor($duration / 60);
                    $seconds = $duration % 60;
                    echo "[" . date('Y-m-d H:i:s') . "] Disconnected: {$username} (session duration: {$minutes}m {$seconds}s)\n";
                    break 2; // Break out of both loops
                }

                if ($timedOut) {
                    // Timeout occurred but not disconnect yet
                    continue;
                }

                if (str_starts_with($key, 'CHAR:')) {
                    $char = strtolower(substr($key, 5));
                    if ($char === 'n' || $char === 'e' || $char === 'q' || $char === 's' || $char === 'p' || $char === 'w') {
                        $choice = $char;
                    } elseif (ctype_digit($char)) {
                        $choice = $char;
                    }
                }
            }

            // Check if we broke out due to connection loss or timeout
            if ($choice === null || $choice === '') {
                break;
            }

            if ($choice === 'n') {
                $netmailHandler->show($conn, $state, $session);
                // Refresh counts after viewing/composing messages
                $messageCounts = MailUtils::getMessageCounts($this->apiBase, $session);
            } elseif ($choice === 'e') {
                $echomailHandler->showEchoareas($conn, $state, $session);
                // Refresh counts after viewing/composing messages
                $messageCounts = MailUtils::getMessageCounts($this->apiBase, $session);
            } elseif (!empty($shoutboxOption) && $choice === $shoutboxOption) {
                $shoutboxHandler->show($conn, $state, $session, 20);
            } elseif (!empty($pollsOption) && $choice === $pollsOption) {
                $pollsHandler->show($conn, $state, $session);
            } elseif (!empty($whosOnlineOption) && $choice === $whosOnlineOption) {
                $this->showWhosOnline($conn, $state, $session);
            } elseif ($choice === $quitOption || strtolower($choice) === 'q') {
                // Display goodbye message
                TelnetUtils::showScreenIfExists("bye.ans", $this, $conn);

                $this->writeLine($conn, '');
                $this->writeLine($conn, $this->colorize('Thank you for visiting, have a great day!', self::ANSI_CYAN . self::ANSI_BOLD));
                $this->writeLine($conn, '');
                try {
                    $siteUrl = Config::getSiteUrl();
                    $this->writeLine($conn, $this->colorize('Come back and visit us on the web at ' . $siteUrl, self::ANSI_YELLOW));
                } catch (\Exception $e) {
                    // Silently skip if getSiteUrl fails
                }
                $this->writeLine($conn, '');

                // Flush output and wait before disconnecting
                if (is_resource($conn)) {
                    fflush($conn);
                }
                sleep(2);

                // Graceful logout
                $duration = time() - $loginTime;
                $minutes = floor($duration / 60);
                $seconds = $duration % 60;
                echo "[" . date('Y-m-d H:i:s') . "] Logout: {$username} (session duration: {$minutes}m {$seconds}s)\n";
                $this->setTerminalTitle($conn, '');
                break;
            }
        }

        fclose($conn);
        if ($forked) {
            exit(0);
        }
    }

    /**
     * Display users currently online.
     */
    private function showWhosOnline($conn, array &$state, string $session): void
    {
        $response = $this->apiRequest('GET', '/api/whosonline', null, $session);
        $users = $response['data']['users'] ?? [];
        $minutes = $response['data']['online_minutes'] ?? 15;

        $cols = $state['cols'] ?? 80;
        $innerWidth = max(20, min($cols - 2, 78));

        $this->safeWrite($conn, "\033[2J\033[H");
        $this->writeLine($conn, $this->colorize("Who's Online (last {$minutes} minutes)", self::ANSI_CYAN . self::ANSI_BOLD));
        $this->writeLine($conn, '');

        if (!$users) {
            $this->writeLine($conn, $this->colorize('No users online.', self::ANSI_YELLOW));
        } else {
            $lineIndex = 0;
            foreach ($users as $user) {
                $name = $user['username'] ?? 'Unknown';
                $location = $user['location'] ?? '';
                $activity = $user['activity'] ?? '';
                $service = $user['service'] ?? '';
                $parts = [$name];
                if ($location !== '') {
                    $parts[] = $location;
                }
                if ($activity !== '') {
                    $parts[] = $activity;
                }
                if ($service !== '') {
                    $parts[] = $service;
                }
                $line = implode(' | ', $parts);
                $wrapped = wordwrap($line, $innerWidth, "\n", false);
                foreach (explode("\n", $wrapped) as $part) {
                    if (strlen($part) > $innerWidth) {
                        $part = substr($part, 0, $innerWidth - 3) . '...';
                    }
                    $color = ($lineIndex % 2 === 0) ? self::ANSI_GREEN : self::ANSI_CYAN;
                    $this->writeLine($conn, $this->colorize($part, $color));
                    $lineIndex++;
                }
            }
        }

        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->colorize('Press any key to return...', self::ANSI_YELLOW));
        $this->readKeyWithIdleCheck($conn, $state);
    }

    /**
     * Negotiate telnet options with client
     */
    private function negotiateTelnet($conn): void
    {
        $this->sendTelnetCommand($conn, self::TELNET_DO, self::OPT_NAWS);
        $this->sendTelnetCommand($conn, self::WILL, self::OPT_SUPPRESS_GA);
        $this->sendTelnetCommand($conn, self::TELNET_DO, self::OPT_LINEMODE);
    }

    // ===== I/O METHODS =====

    /**
     * Send telnet command to client
     */
    private function sendTelnetCommand($conn, int $cmd, int $opt): void
    {
        $this->safeWrite($conn, chr(self::IAC) . chr($cmd) . chr($opt));
    }

    /**
     * Safe write with error suppression
     */
    public function safeWrite($conn, string $data): void
    {
        if (!is_resource($conn)) {
            return;
        }
        $prev = error_reporting();
        error_reporting($prev & ~E_NOTICE);
        @fwrite($conn, $data);
        error_reporting($prev);
    }

    private function isRateLimited(string $ip): bool
    {
        $this->cleanupOldLoginAttempts();
        return count($this->failedLoginAttempts[$ip] ?? []) >= 5;
    }

    private function cleanupOldLoginAttempts(): void
    {
        $now = time();
        $cutoff = $now - 60;

        foreach ($this->failedLoginAttempts as $ip => $attempts) {
            $this->failedLoginAttempts[$ip] = array_filter($attempts, function($timestamp) use ($cutoff) {
                return $timestamp > $cutoff;
            });

            if (empty($this->failedLoginAttempts[$ip])) {
                unset($this->failedLoginAttempts[$ip]);
            }
        }
    }

    /**
     * Write a line of text to the connection
     */
    private function writeLine($conn, string $text = ''): void
    {
        $this->safeWrite($conn, $text . "\r\n");
    }

    /**
     * Colorize text with ANSI codes
     */
    private function colorize(string $text, string $color): string
    {
        return $color . $text . self::ANSI_RESET;
    }

    /**
     * Show login banner with system information
     */
    private function showLoginBanner($conn): void
    {
        // Print service name before showing login screen
        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->colorize('BinktermPHP Telnet Service', self::ANSI_MAGENTA . self::ANSI_BOLD));
        $this->writeLine($conn, '');

        if(TelnetUtils::showScreenIfExists("login.ans", $this, $conn)){
            return;
        }

        $config = BinkpConfig::getInstance();
        $siteUrl = '';
        try {
            $siteUrl = Config::getSiteUrl();
        } catch (\Exception $e) {
            $siteUrl = '';
        }

        $rawLines = [
            ['text' => '', 'color' => self::ANSI_DIM, 'center' => false],
            ['text' => 'System: ' . $config->getSystemName(), 'color' => self::ANSI_CYAN, 'center' => false],
            ['text' => 'Location: ' . $config->getSystemLocation(), 'color' => self::ANSI_DIM, 'center' => false],
            ['text' => 'Origin: ' . $config->getSystemOrigin(), 'color' => self::ANSI_DIM, 'center' => false],
        ];
        if ($siteUrl !== '') {
            $rawLines[] = ['text' => '', 'color' => self::ANSI_DIM, 'center' => false];
            $rawLines[] = ['text' => 'Web: ' . $siteUrl, 'color' => self::ANSI_YELLOW, 'center' => false];
        }

        $maxLen = 0;
        foreach ($rawLines as $entry) {
            $maxLen = max($maxLen, strlen($entry['text']));
        }
        $frameWidth = max(48, min(90, $maxLen + 6));
        $innerWidth = $frameWidth - 4;
        $border = '+' . str_repeat('-', $frameWidth - 2) . '+';

        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->colorize($border, self::ANSI_MAGENTA));

        foreach ($rawLines as $entry) {
            $text = $entry['text'];
            $wrapped = wordwrap($text, $innerWidth, "\n", true);
            foreach (explode("\n", $wrapped) as $part) {
                $padded = $entry['center']
                    ? str_pad($part, $innerWidth, ' ', STR_PAD_BOTH)
                    : str_pad($part, $innerWidth, ' ', STR_PAD_RIGHT);
                $content = '| ' . $padded . ' |';
                $this->writeLine($conn, $this->colorize($content, $entry['color']));
            }
        }

        $this->writeLine($conn, $this->colorize($border, self::ANSI_MAGENTA));
        $this->writeLine($conn, '');

        if ($siteUrl !== '') {
            $this->writeLine($conn, $this->colorize('  For a good time visit us on the web @ ' . $siteUrl, self::ANSI_YELLOW));
            $this->writeLine($conn, '');
        }
    }

    /**
     * Display a simple prompt and read input
     */
    public function prompt($conn, array &$state, string $label, bool $echo = true): ?string
    {
        $this->setEcho($conn, $state, $echo);
        $this->safeWrite($conn, $label);

        if ($echo) {
            $value = $this->readLineWithIdleCheck($conn, $state);
            return $value;
        }

        $value = $this->readLineWithIdleCheck($conn, $state);
        $this->setEcho($conn, $state, true);
        return $value;
    }

    /**
     * Set echo mode (server or client echo)
     */
    private function setEcho($conn, array &$state, bool $enable): void
    {
        $state['input_echo'] = $enable;
        // Force server-side echo control (client echo off)
        $this->sendTelnetCommand($conn, self::WILL, self::OPT_ECHO);
        $this->sendTelnetCommand($conn, self::DONT, self::OPT_ECHO);
    }

    /**
     * Simplified wrapper for readTelnetLineWithTimeout
     * Returns the line string or null on disconnect/idle timeout
     */
    public function readLineWithIdleCheck($conn, array &$state): ?string
    {
        while (true) {
            [$line, $timedOut, $shouldDisconnect] = $this->readTelnetLineWithTimeout($conn, $state);

            if ($shouldDisconnect) {
                return null;
            }

            if ($timedOut) {
                continue;
            }

            return $line;
        }
    }

    /**
     * Read a single key with idle timeout handling
     * Returns a normalized token: UP, DOWN, LEFT, RIGHT, ENTER, BACKSPACE, CHAR:<char>
     */
    public function readKeyWithIdleCheck($conn, array &$state): ?string
    {
        while (true) {
            [$key, $timedOut, $shouldDisconnect] = $this->readTelnetKeyWithTimeout($conn, $state);

            if ($shouldDisconnect) {
                return null;
            }

            if ($timedOut) {
                continue;
            }

            return $key;
        }
    }

    // ===== AUTHENTICATION METHODS =====

    /**
     * Read telnet line with idle timeout handling
     * Returns: [string|null line, bool timedOut, bool shouldDisconnect]
     */
    private function readTelnetLineWithTimeout($conn, array &$state): array
    {
        $now = time();
        $elapsed = $now - $state['last_activity'];
        $warningTimeout = $state['idle_warning_timeout'];
        $disconnectTimeout = $state['idle_disconnect_timeout'];

        // Check if we've exceeded disconnect timeout
        if ($elapsed >= $disconnectTimeout) {
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize('Idle timeout - disconnecting...', self::ANSI_YELLOW));
            $this->writeLine($conn, '');
            return [null, true, true];
        }

        // Check if we need to show warning
        if (!$state['idle_warned'] && $elapsed >= $warningTimeout) {
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize('Are you still there? (Press Enter to continue)', self::ANSI_YELLOW . self::ANSI_BOLD));
            $this->writeLine($conn, '');
            $state['idle_warned'] = true;
        }

        // Calculate timeout for this read
        $timeUntilDisconnect = $disconnectTimeout - $elapsed;
        $timeUntilWarning = $warningTimeout - $elapsed;

        if ($state['idle_warned']) {
            $timeout = min($timeUntilDisconnect, 30);
        } else {
            $timeout = min($timeUntilWarning, 30);
        }

        // Use stream_select to check for data with timeout
        $read = [$conn];
        $write = $except = null;
        $seconds = (int)$timeout;
        $microseconds = 0;

        $hasData = @stream_select($read, $write, $except, $seconds, $microseconds);

        if ($hasData === false) {
            // Error occurred
            return [null, false, true];
        }

        if ($hasData === 0) {
            // Timeout - no data available
            return ['', true, false];
        }

        // Data is available, read it
        $line = $this->readTelnetLine($conn, $state);

        if ($line !== null) {
            // Reset activity tracking on successful input
            $state['last_activity'] = time();
            $state['idle_warned'] = false;
        }

        return [$line, false, false];
    }

    /**
     * Read a single key with idle timeout handling
     * Returns: [string|null key, bool timedOut, bool shouldDisconnect]
     */
    private function readTelnetKeyWithTimeout($conn, array &$state): array
    {
        $now = time();
        $elapsed = $now - $state['last_activity'];
        $warningTimeout = $state['idle_warning_timeout'];
        $disconnectTimeout = $state['idle_disconnect_timeout'];

        if ($elapsed >= $disconnectTimeout) {
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize('Idle timeout - disconnecting...', self::ANSI_YELLOW));
            $this->writeLine($conn, '');
            return [null, true, true];
        }

        if (!$state['idle_warned'] && $elapsed >= $warningTimeout) {
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize('Are you still there? (Press any key to continue)', self::ANSI_YELLOW . self::ANSI_BOLD));
            $this->writeLine($conn, '');
            $state['idle_warned'] = true;
        }

        $timeUntilDisconnect = $disconnectTimeout - $elapsed;
        $timeUntilWarning = $warningTimeout - $elapsed;
        $timeout = $state['idle_warned'] ? min($timeUntilDisconnect, 30) : min($timeUntilWarning, 30);

        $read = [$conn];
        $write = $except = null;
        $seconds = (int)$timeout;
        $microseconds = 0;

        $hasData = @stream_select($read, $write, $except, $seconds, $microseconds);

        if ($hasData === false) {
            return [null, false, true];
        }

        if ($hasData === 0) {
            return ['', true, false];
        }

        $char = $this->readRawChar($conn, $state);
        if ($char === null) {
            return [null, false, true];
        }

        $state['last_activity'] = time();
        $state['idle_warned'] = false;

        if ($char === self::KEY_UP) return ['UP', false, false];
        if ($char === self::KEY_DOWN) return ['DOWN', false, false];
        if ($char === self::KEY_LEFT) return ['LEFT', false, false];
        if ($char === self::KEY_RIGHT) return ['RIGHT', false, false];
        if ($char === self::KEY_HOME) return ['HOME', false, false];
        if ($char === self::KEY_END) return ['END', false, false];

        $ord = ord($char[0]);
        if ($ord === 13 || $ord === 10) {
            return ['ENTER', false, false];
        }
        if ($ord === 8 || $ord === 127) {
            return ['BACKSPACE', false, false];
        }
        if ($ord >= 32 && $ord < 127) {
            return ['CHAR:' . $char, false, false];
        }

        return ['', false, false];
    }

    /**
     * Read a line of input from telnet connection with protocol handling
     */
    private function readTelnetLine($conn, array &$state): ?string
    {
        // Check if connection is still valid
        if (!is_resource($conn) || feof($conn)) {
            return null;
        }

        // Check for timeout
        $metadata = stream_get_meta_data($conn);
        if ($metadata['timed_out']) {
            return null;
        }

        $line = '';
        while (true) {
            if (!empty($state['pushback'])) {
                $char = $state['pushback'][0];
                $state['pushback'] = substr($state['pushback'], 1);
            } else {
                $char = fread($conn, 1);
            }
            if ($char === false || $char === '') {
                // Check if connection died
                if (!is_resource($conn) || feof($conn)) {
                    return null;
                }
                // Check for timeout
                $metadata = stream_get_meta_data($conn);
                if ($metadata['timed_out']) {
                    return null;
                }
                // Empty read, continue
                continue;
            }
            $byte = ord($char);

            if (!empty($state['telnet_mode'])) {
                if ($state['telnet_mode'] === 'IAC') {
                    if ($byte === self::IAC) {
                        $line .= chr(self::IAC);
                        $state['telnet_mode'] = null;
                    } elseif (in_array($byte, [self::TELNET_DO, self::DONT, self::WILL, self::WONT], true)) {
                        $state['telnet_mode'] = 'IAC_CMD';
                        $state['telnet_cmd'] = $byte;
                    } elseif ($byte === self::SB) {
                        $state['telnet_mode'] = 'SB';
                        $state['sb_opt'] = null;
                        $state['sb_data'] = '';
                    } else {
                        $state['telnet_mode'] = null;
                    }
                    continue;
                }

                if ($state['telnet_mode'] === 'IAC_CMD') {
                    $state['telnet_mode'] = null;
                    $state['telnet_cmd'] = null;
                    continue;
                }

                if ($state['telnet_mode'] === 'SB') {
                    if ($state['sb_opt'] === null) {
                        $state['sb_opt'] = $byte;
                        continue;
                    }
                    if ($byte === self::IAC) {
                        $state['telnet_mode'] = 'SB_IAC';
                        continue;
                    }
                    $state['sb_data'] .= chr($byte);
                    continue;
                }

                if ($state['telnet_mode'] === 'SB_IAC') {
                    if ($byte === self::SE) {
                        if ($state['sb_opt'] === self::OPT_NAWS && strlen($state['sb_data']) >= 4) {
                            $w = (ord($state['sb_data'][0]) << 8) + ord($state['sb_data'][1]);
                            $h = (ord($state['sb_data'][2]) << 8) + ord($state['sb_data'][3]);
                            if ($w > 0) {
                                $state['cols'] = $w;
                            }
                            if ($h > 0) {
                                $state['rows'] = $h;
                            }
                            // Log screen size in debug mode
                            if ($this->debug) {
                                echo "[" . date('Y-m-d H:i:s') . "] NAWS: Screen size negotiated as {$w}x{$h}\n";
                            }
                        }
                        $state['telnet_mode'] = null;
                        $state['sb_opt'] = null;
                        $state['sb_data'] = '';
                        continue;
                    }
                    if ($byte === self::IAC) {
                        $state['sb_data'] .= chr(self::IAC);
                        $state['telnet_mode'] = 'SB';
                        continue;
                    }
                    $state['telnet_mode'] = 'SB';
                    continue;
                }
            }

            if ($byte === self::IAC) {
                $state['telnet_mode'] = 'IAC';
                continue;
            }

            if ($byte === 10) {
                if (!empty($state['input_echo'])) {
                    $this->safeWrite($conn, "\r\n");
                }
                return $line;
            }

            if ($byte === 13) {
                // Check for following LF (CR+LF sequence) with non-blocking peek
                $read = [$conn];
                $write = $except = null;
                $hasData = stream_select($read, $write, $except, 0, 50000); // 50ms timeout

                if ($hasData > 0) {
                    $next = fread($conn, 1);
                    if ($next !== false && $next !== '' && ord($next) !== 10) {
                        // Not LF, push back for next read
                        $state['pushback'] = ($state['pushback'] ?? '') . $next;
                    }
                }

                if (!empty($state['input_echo'])) {
                    $this->safeWrite($conn, "\r\n");
                }
                return $line;
            }

            if ($byte === 8 || $byte === 127) {
                if ($line !== '') {
                    $line = substr($line, 0, -1);
                    if (!empty($state['input_echo'])) {
                        $this->safeWrite($conn, "\x08 \x08");
                    }
                }
                continue;
            }

            if ($byte === 0) {
                continue;
            }

            $line .= chr($byte);
            if (!empty($state['input_echo'])) {
                $this->safeWrite($conn, chr($byte));
            }
        }
    }

    // ===== RATE LIMITING METHODS =====

    /**
     * Attempt to register a new user
     *
     * @return bool True if registration successful, false otherwise
     */
    private function attemptRegistration($conn, array &$state): bool
    {
        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->colorize('=== New User Registration ===', self::ANSI_CYAN . self::ANSI_BOLD));
        $this->writeLine($conn, '');
        $this->writeLine($conn, 'Please provide the following information to create your account.');
        $this->writeLine($conn, 'All fields are required.');
        $this->writeLine($conn, $this->colorize('(Type "cancel" at any prompt to abort registration)', self::ANSI_DIM));
        $this->writeLine($conn, '');

        // Note: Full registration logic would go here
        // For now, this is a placeholder that needs to be implemented
        $this->writeLine($conn, 'Registration feature coming soon...');
        $this->writeLine($conn, 'Press Enter to continue.');
        $this->readLineWithIdleCheck($conn, $state);
        return false;
    }

    /**
     * Attempt to login a user
     *
     * @return array|null Returns ['session' => string, 'username' => string] on success, null on failure
     */
    private function attemptLogin($conn, array &$state): ?array
    {
        $username = $this->prompt($conn, $state, 'Username: ', true);
        if ($username === null) {
            return null;
        }
        $password = $this->prompt($conn, $state, 'Password: ', false);
        if ($password === null) {
            return null;
        }
        $this->writeLine($conn, '');

        if ($this->debug) {
            $this->writeLine($conn, "[DEBUG] username={$username}");
        }

        try {
            $result = $this->apiRequest('POST', '/api/auth/login', [
                'username' => $username,
                'password' => $password,
                'service' => 'telnet'
            ], null);
        } catch (\Throwable $e) {
            $this->writeLine($conn, $this->colorize('Login failed: ' . $e->getMessage(), self::ANSI_RED));
            return null;
        }

        if ($this->debug) {
            $status = $result['status'] ?? 0;
            $body = json_encode($result['data']);
            $this->writeLine($conn, "[DEBUG] login status={$status} body={$body}");
            $this->writeLine($conn, "[DEBUG] session=" . ($result['cookie'] ?: ''));
            if (!empty($result['error'])) {
                $this->writeLine($conn, "[DEBUG] curl_error=" . $result['error']);
            }
        }

        if ($result['status'] !== 200 || empty($result['cookie'])) {
            return null;
        }

        return ['session' => $result['cookie'], 'username' => $username];
    }

    /**
     * Make an API request with retry logic
     */
    private function apiRequest(string $method, string $path, ?array $payload, ?string $session, int $maxRetries = 3): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP curl extension is required for telnet API access.');
        }

        $url = $this->apiBase . $path;
        $attempt = 0;

        while ($attempt <= $maxRetries) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HEADER, false);

            $headers = ['Accept: application/json'];
            if ($payload !== null) {
                $json = json_encode($payload);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                $headers[] = 'Content-Type: application/json';
            }
            if ($session) {
                curl_setopt($ch, CURLOPT_COOKIE, 'binktermphp_session=' . $session);
            }

            $cookie = null;
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$cookie) {
                $prefix = 'Set-Cookie: binktermphp_session=';
                if (stripos($header, $prefix) === 0) {
                    $value = trim(substr($header, strlen($prefix)));
                    $cookie = strtok($value, ';');
                }
                return strlen($header);
            });

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            if ($this->insecure) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            }

            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            $data = null;
            if (is_string($response) && $response !== '') {
                $data = json_decode($response, true);
            }

            // Check if we should retry
            $shouldRetry = false;
            if ($curlErrno !== 0) {
                $shouldRetry = true;
            } elseif ($status >= 500 && $status < 600) {
                $shouldRetry = true;
            } elseif ($status === 0) {
                $shouldRetry = true;
            }

            // If successful or non-retryable error, return result
            if (!$shouldRetry || $attempt >= $maxRetries) {
                if ($attempt > 0 && $this->debug) {
                    echo "[" . date('Y-m-d H:i:s') . "] API request to {$path} succeeded after " . ($attempt + 1) . " attempts\n";
                }
                return [
                    'status' => $status,
                    'data' => $data,
                    'cookie' => $cookie,
                    'error' => $curlError ?: null,
                    'errno' => $curlErrno ?: null,
                    'url' => $url,
                    'attempts' => $attempt + 1
                ];
            }

            // Log retry
            if ($this->debug) {
                $reason = $curlError ?: "HTTP {$status}";
                echo "[" . date('Y-m-d H:i:s') . "] API request to {$path} failed ({$reason}), retrying (attempt " . ($attempt + 2) . "/" . ($maxRetries + 1) . ")...\n";
            }

            // Exponential backoff
            $delay = (int)(0.5 * pow(2, $attempt) * 1000000);
            usleep($delay);
            $attempt++;
        }

        return [
            'status' => 0,
            'data' => null,
            'cookie' => null,
            'error' => 'Max retries exceeded',
            'errno' => 0,
            'url' => $url,
            'attempts' => $maxRetries + 1
        ];
    }

    private function recordFailedLogin(string $ip): void
    {
        $this->cleanupOldLoginAttempts();

        if (!isset($this->failedLoginAttempts[$ip])) {
            $this->failedLoginAttempts[$ip] = [];
        }

        $this->failedLoginAttempts[$ip][] = time();
    }

    // ===== BANNER / UI METHODS =====

    private function clearFailedLogins(string $ip): void
    {
        unset($this->failedLoginAttempts[$ip]);
    }

    // ===== FEATURE METHODS =====

    /**
     * Set terminal window title
     */
    private function setTerminalTitle($conn, string $title): void
    {
        // ANSI escape sequence to set terminal window title
        $this->safeWrite($conn, "\033]0;{$title}\007");
    }

    // ===== API REQUEST METHOD =====

    // ===== DAEMON METHODS =====

    /**
     * Read multiline input (uses full-screen editor if terminal supports it)
     */
    public function readMultiline($conn, array &$state, int $cols, string $initialText = ''): string
    {
        // Use full-screen editor if terminal supports it
        if (($state['rows'] ?? 0) >= 15) {
            return $this->fullScreenEditor($conn, $state, $initialText);
        }

        // Fallback to line-by-line editor
        if ($initialText !== '') {
            $this->writeLine($conn, 'Starting with quoted text. Enter your reply below.');
            $this->writeLine($conn, '');
            $quotedLines = explode("\n", $initialText);
            foreach ($quotedLines as $line) {
                $this->writeLine($conn, $line);
            }
            $this->writeLine($conn, '');
        }

        $this->writeLine($conn, 'Enter message text. End with a single "." line. Type "/abort" to cancel.');
        $lines = [];

        if ($initialText !== '') {
            $lines = explode("\n", $initialText);
        }

        while (true) {
            $this->safeWrite($conn, '> ');
            $line = $this->readLineWithIdleCheck($conn, $state);
            if ($line === null) {
                break;
            }
            if (trim($line) === '/abort') {
                return '';
            }
            if (trim($line) === '.') {
                break;
            }
            $lines[] = $line;
        }
        $text = implode("\n", $lines);
        if ($text === '') {
            return '';
        }
        return $text;
    }

    /**
     * Full-screen message editor
     */
    private function fullScreenEditor($conn, array &$state, string $initialText = ''): string
    {
        $rows = $state['rows'] ?? 24;
        $cols = $state['cols'] ?? 80;

        if ($this->debug) {
            echo "[" . date('Y-m-d H:i:s') . "] Editor: Screen size {$cols}x{$rows}\n";
        }

        // Clear screen and move to top
        $this->safeWrite($conn, "\033[2J\033[H");
        $this->safeWrite($conn, "\033[?25h");

        $width = min($cols - 2, 70);
        $separator = str_repeat('=', $width);

        $headerLines = 0;
        $this->writeLine($conn, $this->colorize($separator, self::ANSI_CYAN . self::ANSI_BOLD)); $headerLines++;
        $this->writeLine($conn, $this->colorize('MESSAGE EDITOR - FULL SCREEN MODE', self::ANSI_CYAN . self::ANSI_BOLD)); $headerLines++;
        $this->writeLine($conn, $this->colorize($separator, self::ANSI_CYAN . self::ANSI_BOLD)); $headerLines++;
        $this->writeLine($conn, $this->colorize('Ctrl+K=Help  Ctrl+Z=Send  Ctrl+C=Cancel', self::ANSI_YELLOW)); $headerLines++;
        $this->writeLine($conn, $this->colorize($separator, self::ANSI_CYAN . self::ANSI_BOLD)); $headerLines++;

        // Initialize lines with initial text
        if ($initialText !== '') {
            $lines = explode("\n", $initialText);
            if (empty($lines)) {
                $lines = [''];
            }
        } else {
            $lines = [''];
        }

        $cursorRow = 0;
        $cursorCol = 0;
        $viewTop = 0;
        $startRow = $headerLines + 1;
        $maxRows = max(10, $rows - $startRow - 2);

        $this->setEcho($conn, $state, false);
        // Ensure cursor is visible for the editor
        $this->safeWrite($conn, "\033[?25h");

        while (true) {
            // Display current text
            $this->safeWrite($conn, "\033[" . $startRow . ";1H");
            $this->safeWrite($conn, "\033[J");

            $maxTop = max(0, count($lines) - $maxRows);
            if ($viewTop > $maxTop) {
                $viewTop = $maxTop;
            }
            if ($cursorRow < $viewTop) {
                $viewTop = $cursorRow;
            } elseif ($cursorRow >= $viewTop + $maxRows) {
                $viewTop = $cursorRow - $maxRows + 1;
            }

            $displayLines = array_slice($lines, $viewTop, $maxRows);
            foreach ($displayLines as $idx => $line) {
                $this->safeWrite($conn, "\033[" . ($startRow + $idx) . ";1H");
                $this->safeWrite($conn, substr($line, 0, $cols - 1));
            }

            // Position cursor
            $displayRow = $startRow + ($cursorRow - $viewTop);
            $displayCol = $cursorCol + 1;
            $this->safeWrite($conn, "\033[{$displayRow};{$displayCol}H");

            // Read character
            $char = $this->readRawChar($conn, $state);
            if ($char === null) {
                $this->setEcho($conn, $state, true);
                return '';
            }

            $ord = ord($char[0]);

            // Ctrl+Z - Save and send
            if ($ord === 26) {
                break;
            }

            // Ctrl+C - Cancel
            if ($ord === 3) {
                $this->setEcho($conn, $state, true);
                $this->writeLine($conn, '');
                $this->writeLine($conn, $this->colorize('Message cancelled.', self::ANSI_RED));
                return '';
            }

            // Ctrl+Y - Delete line
            if ($ord === 25) {
                if (count($lines) > 1) {
                    array_splice($lines, $cursorRow, 1);
                    if ($cursorRow >= count($lines)) {
                        $cursorRow = count($lines) - 1;
                    }
                    $cursorCol = min($cursorCol, strlen($lines[$cursorRow]));
                } else {
                    $lines[0] = '';
                    $cursorCol = 0;
                }
                continue;
            }

            // Ctrl+K - Help
            if ($ord === 11) {
                $this->showEditorHelp($conn, $state);
                continue;
            }

            // Ctrl+A - Start of line
            if ($ord === 1) {
                $cursorCol = 0;
                continue;
            }

            // Ctrl+E - End of line
            if ($ord === 5) {
                $cursorCol = strlen($lines[$cursorRow]);
                continue;
            }

            // Handle arrow keys
            if ($char === self::KEY_UP) {
                if ($cursorRow > 0) {
                    $cursorRow--;
                    $cursorCol = min($cursorCol, strlen($lines[$cursorRow]));
                }
                continue;
            }

            if ($char === self::KEY_DOWN) {
                if ($cursorRow < count($lines) - 1) {
                    $cursorRow++;
                    $cursorCol = min($cursorCol, strlen($lines[$cursorRow]));
                }
                continue;
            }

            if ($char === self::KEY_LEFT) {
                if ($cursorCol > 0) {
                    $cursorCol--;
                } elseif ($cursorRow > 0) {
                    $cursorRow--;
                    $cursorCol = strlen($lines[$cursorRow]);
                }
                continue;
            }

            if ($char === self::KEY_RIGHT) {
                if ($cursorCol < strlen($lines[$cursorRow])) {
                    $cursorCol++;
                } elseif ($cursorRow < count($lines) - 1) {
                    $cursorRow++;
                    $cursorCol = 0;
                }
                continue;
            }

            if ($char === self::KEY_HOME) {
                $cursorCol = 0;
                continue;
            }

            if ($char === self::KEY_END) {
                $cursorCol = strlen($lines[$cursorRow]);
                continue;
            }

            // Handle Enter
            if ($ord === 13 || $ord === 10) {
                if ($ord === 13) {
                    $nextChar = $this->readRawChar($conn, $state);
                    if ($nextChar !== null && ord($nextChar[0]) !== 10) {
                        $state['pushback'] = ($state['pushback'] ?? '') . $nextChar;
                    }
                }

                $currentLine = $lines[$cursorRow];
                $beforeCursor = substr($currentLine, 0, $cursorCol);
                $afterCursor = substr($currentLine, $cursorCol);

                $lines[$cursorRow] = $beforeCursor;
                array_splice($lines, $cursorRow + 1, 0, [$afterCursor]);

                $cursorRow++;
                $cursorCol = 0;
                continue;
            }

            // Handle Backspace
            if ($ord === 8 || $ord === 127) {
                if ($cursorCol > 0) {
                    $lines[$cursorRow] = substr($lines[$cursorRow], 0, $cursorCol - 1) .
                                          substr($lines[$cursorRow], $cursorCol);
                    $cursorCol--;
                } elseif ($cursorRow > 0) {
                    $prevLine = $lines[$cursorRow - 1];
                    $cursorCol = strlen($prevLine);
                    $lines[$cursorRow - 1] = $prevLine . $lines[$cursorRow];
                    array_splice($lines, $cursorRow, 1);
                    $cursorRow--;
                }
                continue;
            }

            // Handle Delete
            if ($char === self::KEY_DELETE) {
                if ($cursorCol < strlen($lines[$cursorRow])) {
                    $lines[$cursorRow] = substr($lines[$cursorRow], 0, $cursorCol) .
                                          substr($lines[$cursorRow], $cursorCol + 1);
                } elseif ($cursorRow < count($lines) - 1) {
                    $lines[$cursorRow] .= $lines[$cursorRow + 1];
                    array_splice($lines, $cursorRow + 1, 1);
                }
                continue;
            }

            // Regular character input
            if ($ord >= 32 && $ord < 127) {
                $lines[$cursorRow] = substr($lines[$cursorRow], 0, $cursorCol) .
                                     $char .
                                     substr($lines[$cursorRow], $cursorCol);
                $cursorCol++;
            }
        }

        $this->setEcho($conn, $state, true);
        $this->safeWrite($conn, "\033[" . ($startRow + $maxRows + 1) . ";1H");
        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->colorize('Message saved and ready to send.', self::ANSI_GREEN));
        $this->writeLine($conn, '');

        // Remove trailing empty lines
        while (count($lines) > 0 && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    /**
     * Read a single raw character
     */
    private function readRawChar($conn, array &$state): ?string
    {
        if (!is_resource($conn) || feof($conn)) {
            return null;
        }

        if (!empty($state['pushback'])) {
            $char = $state['pushback'][0];
            $state['pushback'] = substr($state['pushback'], 1);
            return $char;
        }

        $char = fread($conn, 1);
        if ($char === false || $char === '') {
            return null;
        }

        $byte = ord($char);

        // Handle telnet IAC sequences
        if ($byte === self::IAC) {
            $cmd = fread($conn, 1);
            if ($cmd === false) {
                return null;
            }
            $cmdByte = ord($cmd);

            if ($cmdByte === self::IAC) {
                return chr(self::IAC);
            }

            if (in_array($cmdByte, [self::TELNET_DO, self::DONT, self::WILL, self::WONT], true)) {
                $opt = fread($conn, 1);
                return $char;
            }

            if ($cmdByte === self::SB) {
                while (true) {
                    $byte = fread($conn, 1);
                    if ($byte === false || ord($byte) === self::IAC) {
                        $next = fread($conn, 1);
                        if ($next !== false && ord($next) === self::SE) {
                            break;
                        }
                    }
                }
                return $char;
            }
        }

        // Check for escape sequences (arrow keys, etc)
        if ($byte === 27) {
            $next1 = fread($conn, 1);
            if ($next1 === false || $next1 === '') {
                return chr(27);
            }

            if ($next1 === '[') {
                $next2 = fread($conn, 1);
                if ($next2 === false) {
                    return chr(27);
                }

                // Check for sequences like ESC[3~
                if (ord($next2) >= ord('0') && ord($next2) <= ord('9')) {
                    $tilde = fread($conn, 1);
                    if ($tilde === '~') {
                        return chr(27) . '[' . $next2 . '~';
                    }
                }

                return chr(27) . '[' . $next2;
            }

            $state['pushback'] = ($state['pushback'] ?? '') . $next1;
            return chr(27);
        }

        return chr($byte);
    }

    private function showEditorHelp($conn, array &$state): void
    {
        $this->safeWrite($conn, "\033[2J\033[H");
        $this->writeLine($conn, $this->colorize('MESSAGE EDITOR HELP', self::ANSI_CYAN . self::ANSI_BOLD));
        $this->writeLine($conn, $this->colorize('-------------------', self::ANSI_CYAN));
        $this->writeLine($conn, $this->colorize('Arrow Keys = Navigate cursor', self::ANSI_YELLOW));
        $this->writeLine($conn, $this->colorize('Backspace/Delete = Edit text', self::ANSI_YELLOW));
        $this->writeLine($conn, $this->colorize('Ctrl+K = Help', self::ANSI_YELLOW));
        $this->writeLine($conn, $this->colorize('Ctrl+A = Start of line', self::ANSI_YELLOW));
        $this->writeLine($conn, $this->colorize('Ctrl+E = End of line', self::ANSI_YELLOW));
        $this->writeLine($conn, $this->colorize('Ctrl+Y = Delete entire line', self::ANSI_YELLOW));
        $this->writeLine($conn, $this->colorize('Ctrl+Z = Save message and send', self::ANSI_GREEN));
        $this->writeLine($conn, $this->colorize('Ctrl+C = Cancel and discard message', self::ANSI_RED));
        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->colorize('Press any key to return...', self::ANSI_YELLOW));
        $this->readRawChar($conn, $state);
        $this->safeWrite($conn, "\033[?25h");
    }
}
