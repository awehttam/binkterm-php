<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
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

use BinktermPHP\PacketBbs\PacketBbsGateway;
use BinktermPHP\Binkp\Config\BinkpConfig;
use Pecee\SimpleRouter\SimpleRouter;

/**
 * Authenticate an inbound packet-BBS bridge request.
 *
 * Validates the Bearer token against the per-node API key stored in the database.
 * Keys are generated from the admin panel; only the SHA-256 hash is stored server-side.
 *
 * @param string $nodeId  The node_id from the request body/query.
 */
function requirePacketBbsAuth(string $nodeId): bool
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        http_response_code(401);
        header('Content-Type: text/plain');
        echo 'Unauthorized';
        return false;
    }
    $token = $m[1];

    if ($nodeId !== '') {
        $db   = \BinktermPHP\Database::getInstance()->getPdo();
        $stmt = $db->prepare('SELECT api_key_hash FROM packet_bbs_nodes WHERE node_id = ?');
        $stmt->execute([$nodeId]);
        $row  = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row && $row['api_key_hash'] !== null
            && hash_equals($row['api_key_hash'], hash('sha256', $token))) {
            return true;
        }
    }

    http_response_code(401);
    header('Content-Type: text/plain');
    echo 'Unauthorized';
    return false;
}

/**
 * POST /api/packetbbs/command
 *
 * Submit a text command from a radio node. Returns a plain-text response
 * the bridge should relay back to the radio operator.
 *
 * Request body (JSON):
 *   { "node_id": "!a1b2c3d4", "interface": "meshcore", "command": "HELP" }
 *
 * Successful response: text/plain, HTTP 200
 * Infrastructure errors: HTTP 4xx/5xx (bridge should not relay these to radio)
 */
SimpleRouter::post('/api/packetbbs/command', function () {
    $body         = json_decode(file_get_contents('php://input'), true);
    $nodeId       = (string)($body['node_id'] ?? '');
    $bridgeNodeId = (string)($body['bridge_node_id'] ?? $nodeId);

    if (!requirePacketBbsAuth($bridgeNodeId)) {
        return;
    }

    if (!is_array($body) || $nodeId === '' || !isset($body['command'])) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Bad request: node_id and command are required.';
        return;
    }

    $interface = (string)($body['interface'] ?? 'meshcore');
    $command   = (string)$body['command'];

    // Reject absurdly long input before it reaches the gateway
    if (strlen($nodeId) > 64 || strlen($command) > 2000) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo 'Input too long.';
        return;
    }

    $gateway  = new PacketBbsGateway();
    $response = $gateway->handleCommand($nodeId, $interface, $command, $bridgeNodeId);

    header('Content-Type: text/plain; charset=utf-8');
    echo $response;
});

/**
 * POST /api/meshcore/advert
 *
 * Receive a MeshCore node advertisement from the bridge and upsert it into the
 * CWN WebDoor network table.
 *
 * Request body (JSON):
 *   {
 *     "bridge_node_id": "aabbccddeeff",
 *     "pub_key_hex": "64 lowercase hex chars",
 *     "name": "NodeName",
 *     "adv_type": "repeater",
 *     "latitude": 37.123456,
 *     "longitude": -122.123456,
 *     "hop_count": 0,
 *     "timestamp_iso": "2026-05-01T12:34:56Z"
 *   }
 */
SimpleRouter::post('/api/meshcore/advert', function () {
    $body         = json_decode(file_get_contents('php://input'), true);
    $bridgeNodeId = is_array($body) ? (string)($body['bridge_node_id'] ?? '') : '';

    if (!requirePacketBbsAuth($bridgeNodeId)) {
        return;
    }

    if (!is_array($body)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
        return;
    }

    $pubKeyHex = (string)($body['pub_key_hex'] ?? '');
    if (!preg_match('/^[0-9a-f]{64}$/', $pubKeyHex)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Invalid pub_key_hex.']);
        return;
    }

    if (!isset($body['latitude'], $body['longitude']) || !is_numeric($body['latitude']) || !is_numeric($body['longitude'])) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Latitude and longitude are required.']);
        return;
    }

    $latitude  = (float)$body['latitude'];
    $longitude = (float)$body['longitude'];
    if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Invalid coordinates.']);
        return;
    }

    $name = trim((string)($body['name'] ?? ''));
    if ($name === '') {
        $name = 'MeshCore ' . substr($pubKeyHex, 0, 12);
    }
    if (strlen($name) > 100) {
        $name = substr($name, 0, 100);
    }

    $advType = trim((string)($body['adv_type'] ?? ''));
    if ($advType === '') {
        $advType = 'meshcore';
    }
    if (strlen($advType) > 50) {
        $advType = substr($advType, 0, 50);
    }

    $hopCount = isset($body['hop_count']) && is_numeric($body['hop_count'])
        ? max(0, min(32767, (int)$body['hop_count']))
        : null;

    $db      = \BinktermPHP\Database::getInstance()->getPdo();
    $bbsName = BinkpConfig::getInstance()->getSystemName();

    $stmt = $db->prepare("
        INSERT INTO cwn_networks
            (public_key, ssid, latitude, longitude, source_type, hop_count, last_seen_at,
             network_type, bbs_name)
        VALUES
            (:public_key, :ssid, :latitude, :longitude, 'meshcore', :hop_count, NOW(),
             :network_type, :bbs_name)
        ON CONFLICT (public_key) WHERE public_key IS NOT NULL DO UPDATE SET
            ssid = EXCLUDED.ssid,
            latitude = EXCLUDED.latitude,
            longitude = EXCLUDED.longitude,
            hop_count = EXCLUDED.hop_count,
            network_type = EXCLUDED.network_type,
            last_seen_at = NOW(),
            date_updated = NOW()
        RETURNING id, (xmax = 0) AS was_inserted
    ");
    $stmt->execute([
        ':public_key'   => $pubKeyHex,
        ':ssid'         => $name,
        ':latitude'     => $latitude,
        ':longitude'    => $longitude,
        ':hop_count'    => $hopCount,
        ':network_type' => $advType,
        ':bbs_name'     => $bbsName,
    ]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'    => true,
        'action'     => !empty($row['was_inserted']) && $row['was_inserted'] !== 'f' ? 'created' : 'updated',
        'network_id' => (int)($row['id'] ?? 0),
    ]);
});

/**
 * GET /api/packetbbs/pending?node_id=!a1b2c3d4
 *
 * Poll for queued outbound messages for a node (e.g. new mail notifications).
 * Marks returned messages as delivered. Returns empty array when nothing is queued.
 *
 * Response (application/json):
 *   { "messages": [ { "id": 42, "payload": "** New netmail from Bob: Hello" } ] }
 */
SimpleRouter::get('/api/packetbbs/pending', function () {
    $nodeId       = trim($_GET['node_id'] ?? '');
    $bridgeNodeId = trim($_GET['bridge_node_id'] ?? $nodeId);

    if (!requirePacketBbsAuth($bridgeNodeId)) {
        return;
    }

    if ($nodeId === '' || strlen($nodeId) > 64) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'node_id required']);
        return;
    }

    $gateway  = new PacketBbsGateway();
    $messages = $gateway->getPendingMessages($nodeId);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['messages' => $messages]);
});
