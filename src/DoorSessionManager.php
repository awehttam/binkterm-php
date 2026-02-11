<?php
/**
 * Door Session Manager
 *
 * Manages DOSBox door game sessions - spawning bridge servers, DOSBox instances,
 * tracking active sessions, and cleanup.
 *
 * @package BinktermPHP
 */

namespace BinktermPHP;

use Exception;
use PDO;

class DoorSessionManager
{
    private $basePath;
    private $bridgePath;
    private $dosboxPath;
    private $configPath;
    private $db;
    private $processHandles = []; // Store process resources (not serialized)
    private $headlessMode = true; // Use production headless config by default

    // Port ranges for multi-user support
    private const TCP_PORT_BASE = 5000;
    private const WS_PORT_BASE = 6000;
    private const MAX_SESSIONS = 100;

    /**
     * Constructor
     *
     * @param string|null $basePath Base path for BinktermPHP
     * @param bool $headless Use headless mode (true) or visible window for testing (false)
     */
    public function __construct($basePath = null, bool $headless = true)
    {
        $this->basePath = $basePath ?? (defined('BINKTERMPHP_BASEDIR')
            ? BINKTERMPHP_BASEDIR
            : __DIR__ . '/..');

        $this->bridgePath = $this->basePath . '/scripts/dosbox-bridge/server.js';
        $this->dosboxPath = $this->basePath . '/dosbox-bridge';
        $this->headlessMode = $headless;

        // Choose config file
        // 1. Check environment variable (allows custom config files)
        // 2. Fall back to headless mode parameter
        $configFile = Config::env('DOSDOOR_CONFIG');
        if (!$configFile) {
            $configFile = $headless
                ? 'dosbox-bridge-production.conf'
                : 'dosbox-bridge-test.conf';
        }
        $this->configPath = $this->basePath . '/dosbox-bridge/' . $configFile;

        // Initialize database connection
        $this->db = Database::getInstance()->getPdo();
    }

    /**
     * Start a door game session
     *
     * @param int $userId User ID
     * @param string $doorName Door game name (e.g., 'lord')
     * @param array $userData User data for drop file
     * @return array Session information
     * @throws Exception If session cannot be started
     */
    public function startSession(int $userId, string $doorName, array $userData): array
    {
        error_log("DOSDOOR: [StartSession] BEGIN - User: $userId, Door: $doorName");

        // Get door information from manifest to get the display name
        $doorManager = new DoorManager();
        $doorInfo = $doorManager->getDoor($doorName);

        if (!$doorInfo) {
            error_log("DOSDOOR: [StartSession] ERROR - Door not found: $doorName");
            throw new Exception("Door not found: $doorName");
        }

        $doorDisplayName = $doorInfo['name'] ?? $doorName;
        error_log("DOSDOOR: [StartSession] Door display name: $doorDisplayName");

        // Find available node number and ports
        $node = $this->findAvailableNode();
        if ($node === null) {
            error_log("DOSDOOR: [StartSession] ERROR - No available nodes");
            throw new Exception('No available door nodes (max ' . self::MAX_SESSIONS . ' sessions)');
        }

        $tcpPort = self::TCP_PORT_BASE + $node;
        $wsPort = self::WS_PORT_BASE + $node;
        error_log("DOSDOOR: [StartSession] Ports assigned - TCP: $tcpPort, WS: $wsPort");

        // Generate session ID
        $sessionId = DoorDropFile::generateSessionId($userId, $node);
        error_log("DOSDOOR: [StartSession] Session ID: $sessionId");

        // Generate drop file
        error_log("DOSDOOR: [StartSession] Generating drop file...");
        $dropFile = new DoorDropFile();
        $sessionData = [
            'com_port' => 'COM1:',
            'node' => $node,
            'baud_rate' => 115200,
            'time_remaining' => 7200, // 2 hours
        ];

        $dropFilePath = $dropFile->generateDoorSys($userData, $sessionData, $sessionId);
        $sessionPath = $dropFile->getSessionPath($sessionId);
        error_log("DOSDOOR: [StartSession] Drop file created at: $dropFilePath");

        // Start bridge server
        error_log("DOSDOOR: [StartSession] Starting bridge server...");
        $bridgePid = $this->startBridge($tcpPort, $wsPort, $sessionId);
        error_log("DOSDOOR: [StartSession] Bridge started with PID: $bridgePid");

        // Start DOSBox
        error_log("DOSDOOR: [StartSession] Starting DOSBox...");
        $dosboxPid = $this->startDosBox($sessionId, $sessionPath, $doorName, $node);
        error_log("DOSDOOR: [StartSession] DOSBox started with PID: $dosboxPid");

        // Calculate expiration time (default 2 hours)
        $expiresAt = date('Y-m-d H:i:s', time() + 7200);

        // Save session to database
        $stmt = $this->db->prepare("
            INSERT INTO door_sessions (
                session_id, user_id, door_id, node_number,
                tcp_port, ws_port, dosbox_pid, bridge_pid,
                session_path, expires_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $sessionId,
            $userId,
            $doorName,
            $node,
            $tcpPort,
            $wsPort,
            $dosboxPid,
            $bridgePid,
            $sessionPath,
            $expiresAt
        ]);

        // Log session launch
        $this->logSessionEvent($sessionId, 'launched', [
            'door' => $doorName,
            'node' => $node,
            'tcp_port' => $tcpPort,
            'ws_port' => $wsPort
        ]);

        // Return session info
        $session = [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'door_id' => $doorName,  // Keep door ID for internal use
            'door_name' => $doorDisplayName,  // Human-readable name for display
            'node' => $node,
            'tcp_port' => $tcpPort,
            'ws_port' => $wsPort,
            'bridge_pid' => $bridgePid,
            'dosbox_pid' => $dosboxPid,
            'session_path' => $sessionPath,
            'drop_file' => $dropFilePath,
            'started_at' => time(),
            'expires_at' => $expiresAt,
        ];

        return $session;
    }

    /**
     * End a door game session
     *
     * @param string $sessionId Session identifier
     * @return bool Success
     */
    public function endSession(string $sessionId): bool
    {
        error_log("DOSDOOR: [EndSession] BEGIN - Session: $sessionId");

        // Get session from database
        $session = $this->getSession($sessionId);
        if (!$session) {
            error_log("DOSDOOR: [EndSession] Session not found: $sessionId");
            return false;
        }

        $dosboxPid = $session['dosbox_pid'] ?? null;
        $bridgePid = $session['bridge_pid'] ?? null;

        // Kill DOSBox process using PID (taskkill works, proc_terminate doesn't)
        if ($dosboxPid) {
            error_log("DOSDOOR: [EndSession] Killing DOSBox PID: $dosboxPid");
            $killed = $this->killProcess($dosboxPid);
            if ($killed) {
                error_log("DOSDOOR: [EndSession] DOSBox killed successfully");
            } else {
                error_log("DOSDOOR: [EndSession] WARNING - Failed to kill DOSBox PID: $dosboxPid (may already be dead)");
            }
        }

        // Clean up process handle if it exists
        if (isset($this->processHandles[$sessionId])) {
            error_log("DOSDOOR: [EndSession] Closing process handle");
            proc_close($this->processHandles[$sessionId]);
            unset($this->processHandles[$sessionId]);
        }

        // Kill bridge process
        if ($bridgePid) {
            error_log("DOSDOOR: [EndSession] Killing bridge PID: $bridgePid");
            $killed = $this->killProcess($bridgePid);
            if ($killed) {
                error_log("DOSDOOR: [EndSession] Bridge killed successfully");
            } else {
                error_log("DOSDOOR: [EndSession] WARNING - Failed to kill bridge PID: $bridgePid (may already be dead)");
            }
        }

        // Cleanup drop files
        error_log("DOSDOOR: [EndSession] Cleaning up drop files");
        $dropFile = new DoorDropFile();
        $dropFile->cleanupSession($sessionId);

        // Update database - mark session as ended
        error_log("DOSDOOR: [EndSession] Updating database");
        $stmt = $this->db->prepare("
            UPDATE door_sessions
            SET ended_at = NOW(), exit_status = ?
            WHERE session_id = ?
        ");
        $stmt->execute(['normal', $sessionId]);

        // Log session termination
        $this->logSessionEvent($sessionId, 'terminated', ['exit_status' => 'normal']);

        error_log("DOSDOOR: [EndSession] COMPLETE - Session: $sessionId");
        return true;
    }

    /**
     * Get door display name from manifest
     *
     * @param string $doorId Door ID
     * @return string Display name or door ID if not found
     */
    private function getDoorDisplayName(string $doorId): string
    {
        static $doorManager = null;
        if ($doorManager === null) {
            $doorManager = new DoorManager();
        }

        $doorInfo = $doorManager->getDoor($doorId);
        return $doorInfo['name'] ?? $doorId;
    }

    /**
     * Get active session by ID
     *
     * @param string $sessionId Session identifier
     * @return array|null Session data or null if not found
     */
    public function getSession(string $sessionId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM door_sessions
            WHERE session_id = ? AND ended_at IS NULL
        ");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            return null;
        }

        // Convert database format to expected format
        return [
            'session_id' => $session['session_id'],
            'user_id' => $session['user_id'],
            'door_id' => $session['door_id'],
            'door_name' => $this->getDoorDisplayName($session['door_id']),
            'node' => $session['node_number'],
            'tcp_port' => $session['tcp_port'],
            'ws_port' => $session['ws_port'],
            'bridge_pid' => $session['bridge_pid'],
            'dosbox_pid' => $session['dosbox_pid'],
            'session_path' => $session['session_path'],
            'started_at' => strtotime($session['started_at']),
            'expires_at' => $session['expires_at'],
        ];
    }

    /**
     * Get all active sessions
     *
     * @return array Active sessions
     */
    public function getActiveSessions(): array
    {
        $stmt = $this->db->query("
            SELECT * FROM door_sessions
            WHERE ended_at IS NULL
            ORDER BY started_at DESC
        ");

        $sessions = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sessions[] = [
                'session_id' => $row['session_id'],
                'user_id' => $row['user_id'],
                'door_id' => $row['door_id'],
                'door_name' => $this->getDoorDisplayName($row['door_id']),
                'node' => $row['node_number'],
                'tcp_port' => $row['tcp_port'],
                'ws_port' => $row['ws_port'],
                'bridge_pid' => $row['bridge_pid'],
                'dosbox_pid' => $row['dosbox_pid'],
                'session_path' => $row['session_path'],
                'started_at' => strtotime($row['started_at']),
                'expires_at' => $row['expires_at'],
            ];
        }

        return $sessions;
    }

    /**
     * Get active session for a user
     *
     * @param int $userId User ID
     * @return array|null Session data or null if not found
     */
    public function getUserSession(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM door_sessions
            WHERE user_id = ? AND ended_at IS NULL
            ORDER BY started_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            return null;
        }

        return [
            'session_id' => $session['session_id'],
            'user_id' => $session['user_id'],
            'door_id' => $session['door_id'],
            'door_name' => $this->getDoorDisplayName($session['door_id']),
            'node' => $session['node_number'],
            'tcp_port' => $session['tcp_port'],
            'ws_port' => $session['ws_port'],
            'bridge_pid' => $session['bridge_pid'],
            'dosbox_pid' => $session['dosbox_pid'],
            'session_path' => $session['session_path'],
            'started_at' => strtotime($session['started_at']),
            'expires_at' => $session['expires_at'],
        ];
    }

    /**
     * Start bridge server process
     *
     * @param int $tcpPort TCP port for DOSBox connection
     * @param int $wsPort WebSocket port for browser
     * @param string $sessionId Session identifier
     * @return int Process ID
     * @throws Exception If bridge cannot be started
     */
    private function startBridge(int $tcpPort, int $wsPort, string $sessionId): int
    {
        $nodeExe = $this->findNodeExecutable();
        if (!$nodeExe) {
            throw new Exception('Node.js executable not found');
        }

        if (!file_exists($this->bridgePath)) {
            throw new Exception('Bridge server not found at: ' . $this->bridgePath);
        }

        // Get disconnect timeout from environment (0 = immediate, default)
        $disconnectTimeout = (int)Config::env('DOSDOOR_DISCONNECT_TIMEOUT', '0');

        // Build command
        $cmd = sprintf(
            '%s "%s" %d %d %s %d',
            $nodeExe,
            $this->bridgePath,
            $tcpPort,
            $wsPort,
            escapeshellarg($sessionId),
            $disconnectTimeout
        );

        // Start bridge in background
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: use start /B to run in background
            $cmd = 'start /B ' . $cmd . ' > nul 2>&1';
            pclose(popen($cmd, 'r'));

            // On Windows, we can't easily get PID from popen, so we'll find it
            // Retry for up to 5 seconds
            $pid = null;
            for ($i = 0; $i < 10; $i++) {
                usleep(500000); // Wait 0.5 seconds
                $pid = $this->findProcessByPort($wsPort);
                if ($pid) {
                    break;
                }
            }
        } else {
            // Linux/Mac: use & to background and get PID
            $cmd .= ' > /dev/null 2>&1 & echo $!';
            $pid = (int)shell_exec($cmd);
        }

        if (!$pid) {
            throw new Exception('Failed to start bridge server - process not detected on port ' . $wsPort);
        }

        return $pid;
    }

    /**
     * Start DOSBox process
     *
     * @param string $sessionId Session identifier
     * @param string $sessionPath Session directory path
     * @param string $doorName Door game name
     * @param int $node Node number
     * @return int Process ID
     * @throws Exception If DOSBox cannot be started
     */
    private function startDosBox(string $sessionId, string $sessionPath, string $doorName, int $node): int
    {
        $dosboxExe = $this->findDosBoxExecutable();
        if (!$dosboxExe) {
            throw new Exception('DOSBox-X executable not found');
        }

        if (!file_exists($this->configPath)) {
            throw new Exception('DOSBox config not found at: ' . $this->configPath);
        }

        // Copy drop file to door's directory as DOOR<node>.SYS
        $dropFileSrc = $sessionPath . '/DOOR.SYS';
        $doorDir = $this->basePath . '/dosbox-bridge/dos/doors/' . strtolower($doorName);

        if (!is_dir($doorDir)) {
            throw new Exception("Door directory not found: $doorDir");
        }

        // LORD expects DOOR<node>.SYS (e.g., DOOR1.SYS for node 1)
        $dropFileDest = $doorDir . '/DOOR' . $node . '.SYS';
        if (!copy($dropFileSrc, $dropFileDest)) {
            throw new Exception("Failed to copy drop file to door directory");
        }

        // Load door manifest to get executable and path info
        $manifestScanner = new \BinktermPHP\DosBoxDoorManifest($this->basePath);
        $doorManifest = $manifestScanner->getDoorManifest($doorName);

        if (!$doorManifest) {
            throw new Exception("Door manifest not found for: $doorName");
        }

        // Extract the DOS path from the directory (remove "dosbox-bridge/dos" prefix)
        // e.g., "dosbox-bridge/dos/doors/lord" becomes "\doors\lord"
        $fullDir = $doorManifest['directory'];
        $dosPath = str_replace('dosbox-bridge/dos', '', $fullDir);
        $dosPath = str_replace('/', '\\', $dosPath); // Convert to DOS path separators

        // Get the launch command from manifest, or build a default one
        if (!empty($doorManifest['launch_command'])) {
            $launchCmd = $doorManifest['launch_command'];
        } else {
            // Fallback: auto-generate based on executable
            $executable = $doorManifest['executable'];
            if (strtoupper(pathinfo($executable, PATHINFO_EXTENSION)) === 'BAT') {
                $launchCmd = "call " . strtolower($executable) . " {node}";
            } else {
                $launchCmd = strtolower($executable) . " {node}";
            }
        }

        // Replace macros in launch command
        $dropFileName = "DOOR" . $node . ".SYS";
        $launchCmd = str_replace('{node}', $node, $launchCmd);
        $launchCmd = str_replace('{dropfile}', $dropFileName, $launchCmd);

        // Build the door-specific autoexec commands
        $doorCommands = "cd $dosPath\n";
        $doorCommands .= $launchCmd;

        // Generate session-specific config
        $baseConfig = file_get_contents($this->configPath);

        // Replace the placeholder comment with door-specific commands
        $sessionConfig = str_replace(
            '# Door-specific commands will be appended here',
            $doorCommands,
            $baseConfig
        );

        // Update TCP port in serial configuration (must match bridge server port)
        $tcpPort = self::TCP_PORT_BASE + $node;
        $sessionConfig = preg_replace(
            '/port:\d+/',
            'port:' . $tcpPort,
            $sessionConfig
        );

        $sessionConfigPath = $sessionPath . '/dosbox.conf';
        file_put_contents($sessionConfigPath, $sessionConfig);

        // Build command
        if ($this->headlessMode) {
            // Headless mode: -nogui hides window, -exit closes after game exits
            $cmd = sprintf(
                '"%s" -nogui -conf "%s" -exit',
                $dosboxExe,
                $sessionConfigPath
            );
        } else {
            // Visible mode for testing: -exit closes after game exits
            $cmd = sprintf(
                '"%s" -conf "%s" -exit',
                $dosboxExe,
                $sessionConfigPath
            );
        }

        // Start DOSBox in background
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Get list of dosbox PIDs BEFORE starting (to find the new one)
            $pidsBefore = $this->getDosBoxPids();
            error_log("DOSDOOR: [Launch] PIDs before: " . implode(', ', $pidsBefore));
            error_log("DOSDOOR: [Launch] Command: $cmd");

            // Windows: use proc_open
            $descriptors = [
                0 => ['pipe', 'r'],  // stdin
                1 => ['pipe', 'w'],  // stdout
                2 => ['pipe', 'w'],  // stderr
            ];

            // HEADLESS MODE - WINDOWS
            // On Windows: Use windowposition=-2000,-2000 in config (window offscreen)
            // SDL_VIDEODRIVER=dummy breaks serial ports on Windows, so don't use it
            $env = null;

            // Linux testing: Uncomment below to test SDL_VIDEODRIVER=dummy on Linux
            // if ($this->headlessMode) {
            //     $env = array_merge($_ENV, ['SDL_VIDEODRIVER' => 'dummy']);
            // }

            // Set working directory to project root so relative paths in config work
            $options = ['bypass_shell' => false];

            $process = proc_open($cmd, $descriptors, $pipes, $this->basePath, $env, $options);
            if (!is_resource($process)) {
                error_log("DOSDOOR: [Launch] proc_open failed - not a resource");
                throw new Exception('Failed to start DOSBox');
            }

            error_log("DOSDOOR: [Launch] proc_open succeeded");

            // Close pipes immediately (don't read stderr as it can block on Windows)
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            error_log("DOSDOOR: [Launch] Pipes closed");

            // Wait for DOSBox to actually start
            error_log("DOSDOOR: [Launch] Sleeping 2 seconds...");
            sleep(2);
            error_log("DOSDOOR: [Launch] Sleep complete, checking PIDs...");

            // Find the NEW dosbox PID that wasn't there before
            $pidsAfter = $this->getDosBoxPids();
            $newPids = array_diff($pidsAfter, $pidsBefore);
            error_log("DOSDOOR: [Launch] PID check complete");

            error_log("DOSDOOR: [Launch] PIDs after: " . implode(', ', $pidsAfter));
            error_log("DOSDOOR: [Launch] New PIDs: " . implode(', ', $newPids));

            if (empty($newPids)) {
                error_log("DOSDOOR: [Launch] FAILED - No new DOSBox process detected");
                throw new Exception('Failed to detect DOSBox-X process');
            }

            // Use the first new PID (should only be one)
            $pid = reset($newPids);
            error_log("DOSDOOR: [Launch] SUCCESS - PID: $pid");

            // Store process handle for cleanup (can't be serialized, kept in memory)
            $this->processHandles[$sessionId] = $process;
        } else {
            // HEADLESS MODE - LINUX/MAC
            // On Linux: SDL_VIDEODRIVER=dummy MAY work (needs testing)
            // Config uses windowposition=-2000,-2000 as fallback

            // Uncomment below to test SDL_VIDEODRIVER=dummy on Linux:
            // if ($this->headlessMode) {
            //     $cmd = 'SDL_VIDEODRIVER=dummy ' . $cmd;
            // }

            $cmd .= ' & echo $!';
            $pid = (int)shell_exec($cmd);
        }

        if (!$pid) {
            throw new Exception('Failed to start DOSBox');
        }

        return $pid;
    }

    /**
     * Find available node number
     *
     * @return int|null Node number or null if none available
     */
    private function findAvailableNode(): ?int
    {
        // Get all active node numbers from database
        $stmt = $this->db->query("
            SELECT node_number FROM door_sessions
            WHERE ended_at IS NULL
            ORDER BY node_number
        ");
        $usedNodes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        error_log("DOSDOOR: [NodeAlloc] Used nodes: " . implode(', ', $usedNodes));

        // Find first available node
        for ($i = 1; $i <= self::MAX_SESSIONS; $i++) {
            if (!in_array($i, $usedNodes)) {
                error_log("DOSDOOR: [NodeAlloc] Assigned node: $i");
                return $i;
            }
        }

        error_log("DOSDOOR: [NodeAlloc] No available nodes (max " . self::MAX_SESSIONS . ")");
        return null;
    }

    /**
     * Find Node.js executable
     *
     * @return string|null Path to node executable
     */
    private function findNodeExecutable(): ?string
    {
        $paths = ['node', 'nodejs', '/usr/bin/node', '/usr/local/bin/node'];

        foreach ($paths as $path) {
            $result = shell_exec($path . ' --version 2>&1');
            if ($result && strpos($result, 'v') === 0) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Find DOSBox executable
     *
     * @return string|null Full path to dosbox executable
     */
    private function findDosBoxExecutable(): ?string
    {
        // Check for env variable first
        $envPath = Config::env('DOSBOX_EXECUTABLE');
        if ($envPath && file_exists($envPath)) {
            return $envPath;
        }

        // Fallback to auto-detection
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        if ($isWindows) {
            // Windows: try default location first
            $defaultPath = 'c:\\dosbox-x\\dosbox-x.exe';
            if (file_exists($defaultPath)) {
                return $defaultPath;
            }

            // Windows: use 'where' command to find full path
            $result = shell_exec('where dosbox-x 2>&1');
            if ($result && stripos($result, 'Could not find') === false) {
                // 'where' might return multiple paths, use the first one
                $lines = explode("\n", trim($result));
                return trim($lines[0]);
            }

            $result = shell_exec('where dosbox 2>&1');
            if ($result && stripos($result, 'Could not find') === false) {
                $lines = explode("\n", trim($result));
                return trim($lines[0]);
            }
        } else {
            // Linux/Mac: try default location first
            $defaultPath = '/usr/bin/dosbox-x';
            if (file_exists($defaultPath)) {
                return $defaultPath;
            }

            // Linux/Mac: use 'which' command
            $result = shell_exec('which dosbox-x 2>&1');
            if ($result && strpos($result, '/') === 0) {
                return trim($result);
            }

            $result = shell_exec('which dosbox 2>&1');
            if ($result && strpos($result, '/') === 0) {
                return trim($result);
            }
        }

        return null;
    }

    /**
     * Check if a process is running
     *
     * @param int $pid Process ID
     * @return bool True if running
     */
    public function isProcessRunning(int $pid): bool
    {
        if (!$pid) {
            return false;
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows: use tasklist
            exec("tasklist /FI \"PID eq $pid\" 2>nul", $output);
            foreach ($output as $line) {
                if (strpos($line, (string)$pid) !== false) {
                    return true;
                }
            }
            return false;
        } else {
            // Linux/Mac: send signal 0 (doesn't kill, just checks)
            exec("kill -0 $pid 2>&1", $output, $returnCode);
            return $returnCode === 0;
        }
    }

    /**
     * Kill a process by PID
     *
     * @param int $pid Process ID
     * @return bool Success
     */
    private function killProcess(int $pid): bool
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows
            exec("taskkill /F /PID $pid 2>&1", $output, $returnCode);
            if ($returnCode !== 0) {
                error_log("DOSDOOR: [KillProcess] taskkill failed for PID $pid - Return code: $returnCode, Output: " . implode(' ', $output));
            }
        } else {
            // Linux/Mac
            exec("kill -9 $pid 2>&1", $output, $returnCode);
            if ($returnCode !== 0) {
                error_log("DOSDOOR: [KillProcess] kill failed for PID $pid - Return code: $returnCode, Output: " . implode(' ', $output));
            }
        }

        return $returnCode === 0;
    }

    /**
     * Find process ID by port (Windows)
     *
     * @param int $port Port number
     * @return int|null Process ID
     */
    private function findProcessByPort(int $port): ?int
    {
        $output = shell_exec("netstat -ano | findstr :$port");
        if ($output && preg_match('/\s+(\d+)\s*$/', $output, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    /**
     * Find process ID by name (Windows)
     *
     * @param string $name Process name
     * @return int|null Process ID
     */
    private function findProcessByName(string $name): ?int
    {
        $output = shell_exec("tasklist | findstr /I $name");
        if ($output && preg_match('/\s+(\d+)\s/', $output, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    /**
     * Get all DOSBox process PIDs (Windows)
     *
     * @return array List of PIDs
     */
    private function getDosBoxPids(): array
    {
        $pids = [];
        $output = shell_exec("tasklist | findstr /I dosbox");

        if ($output) {
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                if (preg_match('/\s+(\d+)\s/', $line, $matches)) {
                    $pids[] = (int)$matches[1];
                }
            }
        }

        return $pids;
    }

    /**
     * Log a session event
     *
     * @param string $sessionId Session identifier
     * @param string $eventType Event type (launched, connected, disconnected, error, terminated, etc.)
     * @param array $eventData Additional event data
     * @return void
     */
    private function logSessionEvent(string $sessionId, string $eventType, array $eventData = []): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO door_session_logs (session_id, event_type, event_data)
            VALUES (?, ?, ?)
        ");

        $stmt->execute([
            $sessionId,
            $eventType,
            json_encode($eventData)
        ]);
    }
}
