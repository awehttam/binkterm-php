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
    if (!in_array($interface, ['meshcore', 'meshtastic', 'tnc'], true)) {
        $interface = 'meshcore';
    }
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

    // Two-step upsert: the table has two unique constraints — (public_key) partial and
    // (ssid, latitude, longitude) — and PostgreSQL only allows one ON CONFLICT clause.
    // Step 1: update by public_key if a matching row exists.
    // Step 2: insert with ON CONFLICT on (ssid, latitude, longitude) for new nodes or
    //         location collisions with a pre-existing manually-added entry.
    $db->beginTransaction();

    // Remove any row that occupies the target (ssid, latitude, longitude) with a different
    // public_key before the UPDATE runs. This must be a separate statement: PostgreSQL CTE
    // sub-statements run concurrently with the same snapshot, so a DELETE inside a WITH
    // clause does not satisfy the unique-constraint check fired by the UPDATE in the same
    // statement.
    $cleanupParams = [
        ':ssid'       => $name,
        ':latitude'   => $latitude,
        ':longitude'  => $longitude,
        ':public_key' => $pubKeyHex,
    ];
    $cleanupStmt = $db->prepare("
        DELETE FROM cwn_networks
        WHERE ssid      = :ssid
          AND latitude  = :latitude
          AND longitude = :longitude
          AND (public_key IS DISTINCT FROM :public_key)
    ");
    $cleanupStmt->execute($cleanupParams);

    $updateParams = [
        ':public_key'   => $pubKeyHex,
        ':ssid'         => $name,
        ':latitude'     => $latitude,
        ':longitude'    => $longitude,
        ':hop_count'    => $hopCount,
        ':network_type' => $advType,
    ];
    $updateStmt = $db->prepare("
        UPDATE cwn_networks
        SET ssid         = :ssid,
            latitude     = :latitude,
            longitude    = :longitude,
            hop_count    = :hop_count,
            network_type = :network_type,
            source_type  = 'meshcore',
            last_seen_at = NOW(),
            date_updated = NOW()
        WHERE public_key = :public_key
        RETURNING id
    ");

    // Use a savepoint so that if a concurrent transaction committed a row at the target
    // position between our cleanup DELETE and this UPDATE, we can roll back only the
    // UPDATE attempt, redo the cleanup (which will now see the race-committed row), and
    // retry — without unwinding the entire transaction.
    $db->exec("SAVEPOINT meshcore_advert_update");
    try {
        $updateStmt->execute($updateParams);
        $db->exec("RELEASE SAVEPOINT meshcore_advert_update");
    } catch (\PDOException $e) {
        if ($e->getCode() === '23505') {
            $db->exec("ROLLBACK TO SAVEPOINT meshcore_advert_update");
            $cleanupStmt->execute($cleanupParams);
            $updateStmt->execute($updateParams);
            $db->exec("RELEASE SAVEPOINT meshcore_advert_update");
        } else {
            $db->rollBack();
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Database error.']);
            return;
        }
    }
    $row = $updateStmt->fetch(\PDO::FETCH_ASSOC);

    if ($row) {
        $db->commit();
        $action    = 'updated';
        $networkId = (int)$row['id'];
    } else {
        $insertStmt = $db->prepare("
            INSERT INTO cwn_networks
                (public_key, ssid, latitude, longitude, source_type, hop_count, last_seen_at,
                 network_type, bbs_name)
            VALUES
                (:public_key, :ssid, :latitude, :longitude, 'meshcore', :hop_count, NOW(),
                 :network_type, :bbs_name)
            ON CONFLICT (ssid, latitude, longitude) DO UPDATE SET
                public_key   = COALESCE(EXCLUDED.public_key, cwn_networks.public_key),
                hop_count    = EXCLUDED.hop_count,
                network_type = EXCLUDED.network_type,
                source_type  = EXCLUDED.source_type,
                last_seen_at = NOW(),
                date_updated = NOW()
            RETURNING id, (xmax = 0) AS was_inserted
        ");
        $insertStmt->execute([
            ':public_key'   => $pubKeyHex,
            ':ssid'         => $name,
            ':latitude'     => $latitude,
            ':longitude'    => $longitude,
            ':hop_count'    => $hopCount,
            ':network_type' => $advType,
            ':bbs_name'     => $bbsName,
        ]);
        $row = $insertStmt->fetch(\PDO::FETCH_ASSOC);
        $db->commit();
        $action    = (!empty($row['was_inserted']) && $row['was_inserted'] !== 'f') ? 'created' : 'updated';
        $networkId = (int)($row['id'] ?? 0);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'    => true,
        'action'     => $action,
        'network_id' => $networkId,
    ]);
});

/**
 * POST /api/meshcore/contact
 *
 * Upsert a companion contact reported by the bridge.  The bridge calls this
 * once per contact entry it receives during the contacts_start/contact/contacts_end
 * sequence.  Matches on the full 64-char public key; if a user has already
 * pre-registered the same node by 12-char prefix (pub_key_full IS NULL), this
 * call fills in the full key and bridge association on that record.
 *
 * Request body (JSON):
 *   {
 *     "bridge_node_id": "aabbccddeeff",
 *     "pub_key_hex":    "64 lowercase hex chars",
 *     "name":           "NodeName",
 *     "adv_type":       "chat",
 *     "lat":            37.123456,
 *     "lon":            -122.123456
 *   }
 */
SimpleRouter::post('/api/meshcore/contact', function () {
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

    $pubKeyHex = strtolower(trim((string)($body['pub_key_hex'] ?? '')));
    if (!preg_match('/^[0-9a-f]{64}$/', $pubKeyHex)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'pub_key_hex must be 64 lowercase hex chars.']);
        return;
    }

    $pubKeyPrefix = substr($pubKeyHex, 0, 12);
    $name    = trim((string)($body['name'] ?? ''));
    $advType = trim((string)($body['adv_type'] ?? ''));
    $lat     = isset($body['lat']) && is_numeric($body['lat']) ? (float)$body['lat'] : null;
    $lon     = isset($body['lon']) && is_numeric($body['lon']) ? (float)$body['lon'] : null;

    $db = \BinktermPHP\Database::getInstance()->getPdo();

    // Resolve bridge node PK from node_id string
    $nodeStmt = $db->prepare('SELECT id FROM packet_bbs_nodes WHERE node_id = ?');
    $nodeStmt->execute([$bridgeNodeId]);
    $nodeRow      = $nodeStmt->fetch(\PDO::FETCH_ASSOC);
    $bridgeNodePk = $nodeRow ? (int)$nodeRow['id'] : null;

    // Try to claim a user-registered prefix-only row first (pub_key_full IS NULL, same prefix).
    // This links the user's pre-registration to the real full key once the bridge sees it.
    $claimStmt = $db->prepare(
        'UPDATE meshcore_contacts
         SET pub_key_full = ?, bridge_node_id = ?, pub_key_prefix = ?,
             adv_type = ?, lat = COALESCE(?, lat), lon = COALESCE(?, lon),
             last_seen_at = NOW(), updated_at = NOW()
         WHERE pub_key_full IS NULL AND pub_key_prefix = ?
         RETURNING id'
    );
    $claimStmt->execute([
        $pubKeyHex, $bridgeNodePk, $pubKeyPrefix,
        $advType !== '' ? $advType : null,
        $lat, $lon,
        $pubKeyPrefix,
    ]);
    $claimed = $claimStmt->fetch(\PDO::FETCH_ASSOC);

    if ($claimed) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'action' => 'claimed', 'contact_id' => (int)$claimed['id']]);
        return;
    }

    // Upsert on the full key
    $stmt = $db->prepare("
        INSERT INTO meshcore_contacts
            (pub_key_full, pub_key_prefix, bridge_node_id, name, adv_type, lat, lon, last_seen_at, updated_at)
        VALUES
            (:full, :prefix, :bridge_node_id, :name, :adv_type, :lat, :lon, NOW(), NOW())
        ON CONFLICT (pub_key_full) WHERE pub_key_full IS NOT NULL DO UPDATE SET
            bridge_node_id = EXCLUDED.bridge_node_id,
            pub_key_prefix = EXCLUDED.pub_key_prefix,
            name           = COALESCE(meshcore_contacts.name, EXCLUDED.name),
            adv_type       = EXCLUDED.adv_type,
            lat            = COALESCE(EXCLUDED.lat, meshcore_contacts.lat),
            lon            = COALESCE(EXCLUDED.lon, meshcore_contacts.lon),
            last_seen_at   = NOW(),
            updated_at     = NOW()
        RETURNING id, (xmax = 0) AS was_inserted
    ");
    $stmt->execute([
        ':full'           => $pubKeyHex,
        ':prefix'         => $pubKeyPrefix,
        ':bridge_node_id' => $bridgeNodePk,
        ':name'           => $name !== '' ? $name : null,
        ':adv_type'       => $advType !== '' ? $advType : null,
        ':lat'            => $lat,
        ':lon'            => $lon,
    ]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'    => true,
        'action'     => (!empty($row['was_inserted']) && $row['was_inserted'] !== 'f') ? 'created' : 'updated',
        'contact_id' => (int)($row['id'] ?? 0),
    ]);
});

/**
 * GET /api/meshcore/pending-commands?bridge_node_id=<64-char-hex>
 *
 * Return unexecuted device commands queued for this bridge node (e.g. remove_contact).
 * The bridge polls this endpoint and executes each command against the radio, then ACKs.
 *
 * Response (application/json):
 *   { "commands": [ { "id": 1, "command_type": "remove_contact", "payload": {...} } ] }
 */
SimpleRouter::get('/api/meshcore/pending-commands', function () {
    $bridgeNodeId = trim($_GET['bridge_node_id'] ?? '');

    if (!requirePacketBbsAuth($bridgeNodeId)) {
        return;
    }

    header('Content-Type: application/json');
    $db = \BinktermPHP\Database::getInstance()->getPdo();

    $nodeStmt = $db->prepare('SELECT id FROM packet_bbs_nodes WHERE node_id = ?');
    $nodeStmt->execute([$bridgeNodeId]);
    $nodeRow  = $nodeStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$nodeRow) {
        echo json_encode(['commands' => []]);
        return;
    }
    $nodeDbId = (int)$nodeRow['id'];

    $stmt = $db->prepare(
        'SELECT id, command_type, payload
           FROM meshcore_device_commands
          WHERE bridge_node_id = ? AND executed_at IS NULL
          ORDER BY created_at ASC
          LIMIT 50'
    );
    $stmt->execute([$nodeDbId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $commands = [];
    foreach ($rows as $row) {
        $commands[] = [
            'id'           => (int)$row['id'],
            'command_type' => $row['command_type'],
            'payload'      => json_decode($row['payload'], true) ?? [],
        ];
    }

    echo json_encode(['commands' => $commands]);
});

/**
 * POST /api/meshcore/commands/{id}/ack
 *
 * Mark a device command as executed. Called by the bridge after sending the command
 * to the radio (whether or not the radio acknowledged it successfully).
 *
 * Request body (JSON): { "bridge_node_id": "<64-char-hex>" }
 */
SimpleRouter::post('/api/meshcore/commands/{id}/ack', function ($id) {
    $body         = json_decode(file_get_contents('php://input'), true);
    $bridgeNodeId = is_array($body) ? (string)($body['bridge_node_id'] ?? '') : '';

    if (!requirePacketBbsAuth($bridgeNodeId)) {
        return;
    }

    header('Content-Type: application/json');
    $db = \BinktermPHP\Database::getInstance()->getPdo();

    $nodeStmt = $db->prepare('SELECT id FROM packet_bbs_nodes WHERE node_id = ?');
    $nodeStmt->execute([$bridgeNodeId]);
    $nodeRow  = $nodeStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$nodeRow) {
        http_response_code(404);
        echo json_encode(['success' => false]);
        return;
    }
    $nodeDbId = (int)$nodeRow['id'];

    $stmt = $db->prepare(
        'UPDATE meshcore_device_commands
            SET executed_at = NOW()
          WHERE id = ? AND bridge_node_id = ? AND executed_at IS NULL'
    );
    $stmt->execute([(int)$id, $nodeDbId]);
    echo json_encode(['success' => $stmt->rowCount() > 0]);
});

/**
 * POST /api/meshcore/autoadd-config
 *
 * Called by the bridge after it reads CMD_GET_AUTOADD_CONFIG from the radio.
 * Persists the device's current autoadd bitmask on the bridge node record so
 * the admin panel can display it without querying the device on every page load.
 *
 * Request body (JSON):
 *   { "bridge_node_id": "<64-char-hex>", "config_byte": <0-255>, "max_hops": <0-64> }
 */
SimpleRouter::post('/api/meshcore/autoadd-config', function () {
    $body         = json_decode(file_get_contents('php://input'), true);
    $bridgeNodeId = is_array($body) ? (string)($body['bridge_node_id'] ?? '') : '';

    if (!requirePacketBbsAuth($bridgeNodeId)) {
        return;
    }

    $configByte = isset($body['config_byte']) ? (int)$body['config_byte'] : null;
    if ($configByte === null || $configByte < 0 || $configByte > 255) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'config_byte required (0-255)']);
        return;
    }

    header('Content-Type: application/json');
    $db   = \BinktermPHP\Database::getInstance()->getPdo();
    $stmt = $db->prepare(
        'UPDATE packet_bbs_nodes SET autoadd_config = ? WHERE node_id = ?'
    );
    $stmt->execute([$configByte, $bridgeNodeId]);
    echo json_encode(['success' => true]);
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
