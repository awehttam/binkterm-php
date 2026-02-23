<?php

/**
 * WebDoor SDK - PHP Helper Functions
 *
 * Common server-side utilities for WebDoor games.
 *
 * This file bootstraps the BinktermPHP environment for WebDoors.
 */
namespace WebDoorSDK;

// Define base directory if not already defined
if (!defined('BINKTERMPHP_BASEDIR')) {
    define('BINKTERMPHP_BASEDIR', dirname(__DIR__, 4));
}

// Load BinktermPHP autoloader
require_once BINKTERMPHP_BASEDIR . '/vendor/autoload.php';

// Initialize database connection
\BinktermPHP\Database::getInstance();

// Start session if not already started
if (!headers_sent() && session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/**
 * Get the current authenticated user from the session
 *
 * @return array|null User data or null if not authenticated
 *
 * @example
 * $user = \WebDoorSDK\getCurrentUser();
 * if ($user) {
 *     echo "Hello, " . $user['username'];
 * }
 */
function getCurrentUser(): ?array
{
    if (!isset($_SESSION['user'])) {
        return null;
    }

    return $_SESSION['user'];
}

/**
 * Require authentication and return the current user
 * Sends 401 response and exits if not authenticated
 *
 * @return array User data
 *
 * @example
 * $user = \WebDoorSDK\requireAuth();
 * // User is guaranteed to be authenticated here
 */
function requireAuth(): array
{
    $user = getCurrentUser();

    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }

    return $user;
}

/**
 * Send a JSON response and exit
 *
 * @param mixed $data Data to send as JSON
 * @param int $statusCode HTTP status code (default: 200)
 *
 * @example
 * \WebDoorSDK\jsonResponse(['success' => true, 'score' => 100]);
 */
function jsonResponse($data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Send a JSON error response and exit
 *
 * @param string $message Error message
 * @param int $statusCode HTTP status code (default: 400)
 *
 * @example
 * \WebDoorSDK\jsonError('Invalid input', 400);
 */
function jsonError(string $message, int $statusCode = 400): void
{
    jsonResponse(['error' => $message], $statusCode);
}

/**
 * Validate required fields in an array (typically $_POST or JSON input)
 *
 * @param array $data Input data to validate
 * @param array $requiredFields List of required field names
 * @return bool True if all required fields are present and non-empty
 *
 * @example
 * $input = json_decode(file_get_contents('php://input'), true);
 * if (!\WebDoorSDK\validateRequired($input, ['username', 'score'])) {
 *     \WebDoorSDK\jsonError('Missing required fields');
 * }
 */
function validateRequired(array $data, array $requiredFields): bool
{
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            return false;
        }
    }
    return true;
}

/**
 * Get the WebDoor's configuration from webdoors.json
 *
 * @param string $doorId The door identifier (e.g., 'netrealm')
 * @return array|null Door configuration or null if not found
 *
 * @example
 * $config = \WebDoorSDK\getDoorConfig('mygame');
 * $maxTurns = $config['max_turns'] ?? 100;
 */
function getDoorConfig(string $doorId): ?array
{
    $configPath = __DIR__ . '/../../../../config/webdoors.json';

    if (!file_exists($configPath)) {
        return null;
    }

    $config = json_decode(file_get_contents($configPath), true);

    if (!$config || !isset($config[$doorId])) {
        return null;
    }

    return $config[$doorId];
}

/**
 * Check if a WebDoor is enabled
 *
 * @param string $doorId The door identifier
 * @return bool True if door is enabled
 *
 * @example
 * if (!\WebDoorSDK\isDoorEnabled('mygame')) {
 *     \WebDoorSDK\jsonError('Game is disabled', 403);
 * }
 */
function isDoorEnabled(string $doorId): bool
{
    $config = getDoorConfig($doorId);
    return $config && ($config['enabled'] ?? false) === true;
}

/**
 * Get a database connection
 *
 * @return \PDO Database connection
 *
 * @example
 * $db = \WebDoorSDK\getDatabase();
 * $stmt = $db->prepare('SELECT * FROM game_scores WHERE user_id = ?');
 */
function getDatabase(): \PDO
{
    // Assuming BinktermPHP Database class is available
    return \BinktermPHP\Database::getInstance()->getPdo();
}

/**
 * Sanitize user input for display (prevent XSS)
 *
 * @param string $input User input to sanitize
 * @return string Sanitized output
 *
 * @example
 * $safeName = \WebDoorSDK\sanitize($_POST['name']);
 * echo "Hello, " . $safeName;
 */
function sanitize(string $input): string
{
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

/**
 * Log a message to the WebDoor log file
 *
 * @param string $doorId Door identifier
 * @param string $message Log message
 * @param string $level Log level (INFO, WARNING, ERROR)
 *
 * @example
 * \WebDoorSDK\log('mygame', 'User completed level 5', 'INFO');
 */
function log(string $doorId, string $message, string $level = 'INFO'): void
{
    // Sanitize doorId to prevent path traversal attacks
    // Only allow alphanumerics, dots, underscores, and dashes
    $safeDoorId = preg_replace('/[^a-zA-Z0-9._-]/', '', $doorId);

    // If sanitization removed all characters or doorId is empty, use fallback
    if ($safeDoorId === '' || $safeDoorId !== $doorId) {
        // Use hash of original doorId as fallback for invalid names
        $safeDoorId = 'invalid_' . substr(hash('sha256', $doorId), 0, 16);
    }

    $logDir = __DIR__ . '/../../../../data/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/webdoor_' . $safeDoorId . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$level}] {$message}\n";

    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}
