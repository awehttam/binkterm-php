<?php
/**
 * Source Server Query Script
 * Queries Source engine servers using the A2S_INFO protocol
 * https://developer.valvesoftware.com/wiki/Server_queries
 */

class SourceServerQuery
{
    private const A2S_INFO = "\xFF\xFF\xFF\xFFTSource Engine Query\x00";
    private const TIMEOUT = 2; // seconds

    /**
     * Query a Source server for info
     * @param string $host Server IP/hostname
     * @param int $port Server port
     * @return array|null Server info or null on failure
     */
    public static function query(string $host, int $port): ?array
    {
        $socket = @fsockopen("udp://{$host}", $port, $errno, $errstr, self::TIMEOUT);

        if (!$socket) {
            error_log("Failed to connect to {$host}:{$port} - {$errstr}");
            return null;
        }

        stream_set_timeout($socket, self::TIMEOUT);

        // Send A2S_INFO request
        fwrite($socket, self::A2S_INFO);

        // Read response
        $response = fread($socket, 1400);

        if (!$response || strlen($response) < 5) {
            fclose($socket);
            error_log("No response from {$host}:{$port}");
            return null;
        }

        // Check if we got a challenge response (0x41)
        if (ord($response[4]) === 0x41) {
            // Extract challenge number (4 bytes after header)
            $challenge = substr($response, 5, 4);

            // Send A2S_INFO with challenge
            $challengeRequest = "\xFF\xFF\xFF\xFFTSource Engine Query\x00" . $challenge;
            fwrite($socket, $challengeRequest);

            // Read the actual response
            $response = fread($socket, 1400);
        }

        fclose($socket);

        if (!$response || strlen($response) < 5) {
            error_log("No response from {$host}:{$port} after challenge");
            return null;
        }

        return self::parseResponse($response);
    }

    /**
     * Parse A2S_INFO response
     */
    private static function parseResponse(string $data): ?array
    {
        // Skip header (4 bytes of 0xFF)
        $pos = 4;

        // Response type should be 'I' (0x49)
        $header = ord($data[$pos++]);
        if ($header !== 0x49) {
            error_log("Invalid response header: " . dechex($header));
            return null;
        }

        try {
            // Protocol version (1 byte)
            $protocol = ord($data[$pos++]);

            // Server name (null-terminated string)
            $serverName = self::readString($data, $pos);

            // Map name (null-terminated string)
            $map = self::readString($data, $pos);

            // Folder (null-terminated string)
            $folder = self::readString($data, $pos);

            // Game (null-terminated string)
            $game = self::readString($data, $pos);

            // App ID (2 bytes, little-endian)
            $appId = unpack('v', substr($data, $pos, 2))[1];
            $pos += 2;

            // Number of players (1 byte)
            $players = ord($data[$pos++]);

            // Max players (1 byte)
            $maxPlayers = ord($data[$pos++]);

            // Number of bots (1 byte)
            $bots = ord($data[$pos++]);

            // Server type (1 byte)
            $serverType = chr(ord($data[$pos++]));

            // Environment (1 byte)
            $environment = chr(ord($data[$pos++]));

            // Visibility (1 byte)
            $visibility = ord($data[$pos++]);

            // VAC (1 byte)
            $vac = ord($data[$pos++]);

            return [
                'serverName' => $serverName,
                'map' => $map,
                'game' => $game,
                'appId' => $appId,
                'players' => $players,
                'maxPlayers' => $maxPlayers,
                'bots' => $bots,
                'online' => true
            ];
        } catch (Exception $e) {
            error_log("Failed to parse response: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Read null-terminated string from data
     */
    private static function readString(string $data, int &$pos): string
    {
        $str = '';
        $len = strlen($data);

        while ($pos < $len) {
            $char = $data[$pos++];
            if ($char === "\x00") {
                break;
            }
            $str .= $char;
        }

        return $str;
    }
}

// Main execution
header('Content-Type: application/json');

// Get server list from config
// Include WebDoor SDK (handles autoload, database, and session initialization)
require_once __DIR__ . '/../_doorsdk/php/helpers.php';

use BinktermPHP\GameConfig;

$gameConfig = GameConfig::getGameConfig('source-games') ?? [];
$servers = $gameConfig['servers'] ?? [];

$results = [];

foreach ($servers as $server) {
    // Parse address
    $parts = explode(':', $server['address']);
    $host = $parts[0];
    $port = isset($parts[1]) ? (int)$parts[1] : 27015;

    echo "Querying {$server['name']} ({$host}:{$port})...\n";

    $info = SourceServerQuery::query($host, $port);

    if ($info) {
        $results[] = [
            'name' => $server['name'],
            'address' => $server['address'],
            'online' => true,
            'map' => $info['map'],
            'maxPlayers' => $info['maxPlayers'],
            'currentPlayers' => $info['players'],
            'game' => $info['game']
        ];
        echo "  ✓ Online - Map: {$info['map']}, Players: {$info['players']}/{$info['maxPlayers']}\n";
    } else {
        $results[] = [
            'name' => $server['name'],
            'address' => $server['address'],
            'online' => false,
            'error' => 'Server did not respond'
        ];
        echo "  ✗ Offline or not responding\n";
    }
}

echo "\n" . json_encode($results, JSON_PRETTY_PRINT) . "\n";
