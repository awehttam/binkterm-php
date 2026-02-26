<?php
/**
 * Door Drop File Generator
 *
 * Generates drop files (DOOR.SYS, DORINFO1.DEF, etc.) for BBS door games.
 * Drop files contain user and session information that door games read.
 *
 * @package BinktermPHP
 */

namespace BinktermPHP;

class DoorDropFile
{
    private $basePath;
    private $bbsName;
    private $sysopName;

    /**
     * Constructor
     *
     * @param string $basePath Base path for drop files (typically data/run/door_sessions)
     */
    public function __construct($basePath = null)
    {
        $base = $basePath ?? (defined('BINKTERMPHP_BASEDIR')
            ? BINKTERMPHP_BASEDIR
            : realpath(__DIR__ . '/..'));

        $this->basePath = $base . '/data/run/door_sessions';

        $this->bbsName = getenv('BBS_NAME') ?: 'BinktermPHP BBS';
        $this->sysopName = getenv('SYSOP_NAME') ?: 'Sysop';
    }

    /**
     * Generate DOOR.SYS drop file
     *
     * DOOR.SYS is the most widely supported drop file format, compatible with
     * GAP, Wildcat, and most door games.
     *
     * @param array $userData User information
     * @param array $sessionData Session information
     * @param string $sessionId Unique session identifier
     * @return string Path to generated drop file
     */
    public function generateDoorSys(array $userData, array $sessionData, string $sessionId): string
    {
        $sessionPath = $this->basePath . '/' . $sessionId;

        // Create session directory if it doesn't exist
        if (!is_dir($sessionPath)) {
            mkdir($sessionPath, 0755, true);
        }

        // Extract user data with defaults
        $realName = $userData['real_name'] ?? 'Unknown User';
        $location = $userData['location'] ?? 'Unknown';
        $securityLevel = $userData['security_level'] ?? 30;
        $timesOn = $userData['total_logins'] ?? 0;
        $lastDate = isset($userData['last_login'])
            ? date('m/d/Y', strtotime($userData['last_login']))
            : date('m/d/Y');

        // Extract session data with defaults
        $comPort = $sessionData['com_port'] ?? 'COM1:';
        $nodeNumber = $sessionData['node'] ?? 1;
        $baudRate = $sessionData['baud_rate'] ?? 115200;
        $timeRemaining = $sessionData['time_remaining'] ?? 7200; // seconds (2 hours default)
        $graphics = ($userData['ansi_enabled'] ?? true) ? 'GR' : 'NG';

        // Build DOOR.SYS content (52 lines)
        $lines = [
            // Lines 1-5: Communication settings
            $comPort,                           // 1: COM port
            (string)$baudRate,                  // 2: Baud rate
            '8',                                // 3: Parity (8 bits, no parity)
            (string)$nodeNumber,                // 4: Node number
            (string)$baudRate,                  // 5: DTE rate (locked)

            // Lines 6-9: Display/printer settings
            'Y',                                // 6: Screen display
            'Y',                                // 7: Printer toggle
            'Y',                                // 8: Page bell
            'Y',                                // 9: Caller alarm

            // Lines 10-14: User information
            $realName,                          // 10: User's real name
            $location,                          // 11: Location (city, state)
            '000-000-0000',                     // 12: Home phone
            '000-000-0000',                     // 13: Work/data phone
            'PASSWORD',                         // 14: Password (masked)

            // Lines 15-19: User stats and time
            (string)$securityLevel,             // 15: Security level
            (string)$timesOn,                   // 16: Times on system
            $lastDate,                          // 17: Last date called
            (string)$timeRemaining,             // 18: Seconds remaining
            (string)(int)($timeRemaining / 60), // 19: Minutes remaining

            // Lines 20-24: Display settings
            $graphics,                          // 20: Graphics mode (GR=ANSI, NG=ASCII)
            '25',                               // 21: Page length
            'N',                                // 22: User mode (expert)
            '1,2,3,4,5,6,7',                    // 23: Conferences registered
            '7',                                // 24: Conference area

            // Lines 25-26: Account info
            date('m/d/Y', strtotime('+1 year')), // 25: Expiration date
            (string)($userData['id'] ?? 1),     // 26: User record number

            // Lines 27-31: Transfer settings
            'Y',                                // 27: Default protocol (Ymodem)
            '0',                                // 28: Total uploads
            '0',                                // 29: Total downloads
            '0',                                // 30: Daily download limit (KB)
            '0',                                // 31: Daily downloads so far

            // Lines 32-36: User details
            isset($userData['birthdate'])
                ? date('mdY', strtotime($userData['birthdate']))
                : '01011990',                   // 32: Birthdate (MMDDYYYY)
            $this->basePath . '/',              // 33: Path to main menu
            $this->basePath . '/',              // 34: Path to gen directory
            $this->sysopName,                   // 35: Sysop name
            $this->bbsName,                     // 36: BBS name

            // Lines 37-39: Event and connection info
            '00:00',                            // 37: Event time
            'N',                                // 38: Error correcting connection
            'Y',                                // 39: ANSI supported

            // Lines 40-42: Display settings
            'Y',                                // 40: Record locking
            '14',                               // 41: Default color
            (string)(int)($timeRemaining / 60), // 42: Time limit (minutes)

            // Lines 43-45: Last usage info
            $lastDate,                          // 43: Last new files scan
            date('H:i'),                        // 44: Time of this call
            date('H:i'),                        // 45: Last time on

            // Lines 46-52: File transfer limits
            '9999',                             // 46: Max daily files
            '0',                                // 47: Files downloaded today
            '0',                                // 48: Upload/download ratio
            '0',                                // 49: Uploads today
            '0',                                // 50: Downloads today
            '0',                                // 51: Upload ratio KB
            '32768',                            // 52: Download ratio KB
        ];

        // Write DOOR.SYS file
        $dropFilePath = $sessionPath . '/DOOR.SYS';
        file_put_contents($dropFilePath, implode("\r\n", $lines) . "\r\n");

        return $dropFilePath;
    }

    /**
     * Get session directory path
     *
     * @param string $sessionId Session identifier
     * @return string Full path to session directory
     */
    public function getSessionPath(string $sessionId): string
    {
        return $this->basePath . '/' . $sessionId;
    }

    /**
     * Clean up session directory
     *
     * @param string $sessionId Session identifier
     * @return bool Success
     */
    public function cleanupSession(string $sessionId): bool
    {
        $sessionPath = $this->getSessionPath($sessionId);

        if (!is_dir($sessionPath)) {
            return true; // Already clean
        }

        // Remove all files in session directory
        $files = glob($sessionPath . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        // Remove directory
        return rmdir($sessionPath);
    }

    /**
     * Generate a unique session ID
     *
     * @param int $userId User ID
     * @param int $nodeNumber Node number (optional)
     * @return string Session ID
     */
    public static function generateSessionId(int $userId, int $nodeNumber = null): string
    {
        $node = $nodeNumber ?? rand(1, 999);
        $timestamp = time();
        return sprintf('door_%d_node%d_%d', $userId, $node, $timestamp);
    }
}
