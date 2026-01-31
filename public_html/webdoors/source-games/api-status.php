<?php
/**
 * Source Games API - Live Server Status
 * Returns current map, players, and online status for all configured servers
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use BinktermPHP\GameConfig;

class SourceServerQuery
{
    private const A2S_INFO = "\xFF\xFF\xFF\xFFTSource Engine Query\x00";
    private const TIMEOUT = 2;

    public static function query(string $host, int $port): ?array
    {
        $socket = @fsockopen("udp://{$host}", $port, $errno, $errstr, self::TIMEOUT);

        if (!$socket) {
            return null;
        }

        stream_set_timeout($socket, self::TIMEOUT);
        fwrite($socket, self::A2S_INFO);
        $response = fread($socket, 1400);

        if (!$response || strlen($response) < 5) {
            fclose($socket);
            return null;
        }

        // Handle challenge response
        if (ord($response[4]) === 0x41) {
            $challenge = substr($response, 5, 4);
            $challengeRequest = "\xFF\xFF\xFF\xFFTSource Engine Query\x00" . $challenge;
            fwrite($socket, $challengeRequest);
            $response = fread($socket, 1400);
        }

        fclose($socket);

        if (!$response || strlen($response) < 5) {
            return null;
        }

        return self::parseResponse($response);
    }

    private static function parseResponse(string $data): ?array
    {
        $pos = 4;
        $header = ord($data[$pos++]);

        if ($header !== 0x49) {
            return null;
        }

        try {
            $protocol = ord($data[$pos++]);
            $serverName = self::readString($data, $pos);
            $map = self::readString($data, $pos);
            $folder = self::readString($data, $pos);
            $game = self::readString($data, $pos);

            $appId = unpack('v', substr($data, $pos, 2))[1];
            $pos += 2;

            $players = ord($data[$pos++]);
            $maxPlayers = ord($data[$pos++]);

            return [
                'map' => $map,
                'players' => $players,
                'maxPlayers' => $maxPlayers,
                'online' => true
            ];
        } catch (Exception $e) {
            return null;
        }
    }

    private static function readString(string $data, int &$pos): string
    {
        $str = '';
        $len = strlen($data);

        while ($pos < $len) {
            $char = $data[$pos++];
            if ($char === "\x00") break;
            $str .= $char;
        }

        return $str;
    }
}

// Main execution
header('Content-Type: application/json');

$gameConfig = GameConfig::getGameConfig('source-games') ?? [];
$servers = $gameConfig['servers'] ?? [];

$results = [];

foreach ($servers as $server) {
    $parts = explode(':', $server['address']);
    $host = $parts[0];
    $port = isset($parts[1]) ? (int)$parts[1] : 27015;

    $info = SourceServerQuery::query($host, $port);

    if ($info) {
        $results[] = [
            'name' => $server['name'],
            'game' => $server['game'],
            'address' => $server['address'],
            'description' => $server['description'] ?? '',
            'steamAppId' => $server['steamAppId'] ?? 0,
            'online' => true,
            'map' => $info['map'],
            'players' => $info['players'],
            'maxPlayers' => $info['maxPlayers']
        ];
    } else {
        $results[] = [
            'name' => $server['name'],
            'game' => $server['game'],
            'address' => $server['address'],
            'description' => $server['description'] ?? '',
            'steamAppId' => $server['steamAppId'] ?? 0,
            'online' => false
        ];
    }
}

echo json_encode(['servers' => $results]);
