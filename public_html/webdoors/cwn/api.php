<?php
/**
 * Community Wireless Node List - API Endpoint
 *
 * Self-contained API for CWN WebDoor
 * Handles all CRUD operations, validation, and credit integration
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use BinktermPHP\Auth;
use BinktermPHP\Database;
use BinktermPHP\UserCredit;
use BinktermPHP\Binkp\Config\BinkpConfig;

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Set JSON header
header('Content-Type: application/json');

// CORS headers (if needed)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Require authentication
$auth = new Auth();
$user = $auth->getCurrentUser();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$userId = $user['user_id'] ?? $user['id'];
$username = $user['username'];
$isAdmin = $user['is_admin'] ?? false;

// Get database connection
$db = Database::getInstance()->getPdo();

// Route the request
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            handleListNetworks($db);
            break;

        case 'get':
            handleGetNetwork($db);
            break;

        case 'submit':
            handleSubmitNetwork($db, $userId, $username);
            break;

        case 'update':
            handleUpdateNetwork($db, $userId, $isAdmin);
            break;

        case 'delete':
            handleDeleteNetwork($db, $userId, $isAdmin);
            break;

        case 'search':
            handleSearch($db, $userId);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

exit;

// ============================================================================
// HANDLER FUNCTIONS
// ============================================================================

/**
 * List networks
 */
function handleListNetworks($db)
{
    $params = $_GET;
    $limit = min((int)($params['limit'] ?? 100), 500);
    $offset = max((int)($params['offset'] ?? 0), 0);
    $activeOnly = ($params['active_only'] ?? 'true') === 'true';

    $sql = "SELECT * FROM cwn_networks WHERE 1=1";
    $sqlParams = [];

    if ($activeOnly) {
        $sql .= " AND is_active = TRUE";
    }

    // Bounding box filter
    if (!empty($params['bbox'])) {
        $bbox = explode(',', $params['bbox']);
        if (count($bbox) === 4) {
            $sql .= " AND latitude BETWEEN ? AND ? AND longitude BETWEEN ? AND ?";
            $sqlParams = array_merge($sqlParams, $bbox);
        }
    }

    $sql .= " ORDER BY date_added DESC LIMIT ? OFFSET ?";
    $sqlParams[] = $limit;
    $sqlParams[] = $offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($sqlParams);
    $networks = $stmt->fetchAll();

    // Get total count
    $countSql = "SELECT COUNT(*) FROM cwn_networks WHERE 1=1";
    if ($activeOnly) {
        $countSql .= " AND is_active = TRUE";
    }
    $total = $db->query($countSql)->fetchColumn();

    echo json_encode([
        'networks' => $networks,
        'total' => (int)$total,
        'limit' => $limit,
        'offset' => $offset
    ]);
}

/**
 * Get single network
 */
function handleGetNetwork($db)
{
    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        throw new Exception('Invalid network ID');
    }

    $stmt = $db->prepare("SELECT * FROM cwn_networks WHERE id = ?");
    $stmt->execute([$id]);
    $network = $stmt->fetch();

    if (!$network) {
        http_response_code(404);
        echo json_encode(['error' => 'Network not found']);
        return;
    }

    echo json_encode($network);
}

/**
 * Submit network
 */
function handleSubmitNetwork($db, $userId, $username)
{
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        throw new Exception('Invalid JSON data');
    }

    // Validate input
    validateNetworkData($data);

    // Check rate limit
    checkRateLimit($db, $userId, 'submission', 20);

    // Check for duplicate
    $existing = findDuplicate($db, $data['ssid'], $data['latitude'], $data['longitude']);
    if ($existing) {
        throw new Exception('A network with this SSID and location already exists');
    }

    // Get BBS name from config
    $binkpConfig = BinkpConfig::getInstance();
    $bbsName = $binkpConfig->getSystemName();

    // Insert network
    $stmt = $db->prepare("
        INSERT INTO cwn_networks
        (ssid, latitude, longitude, description, wifi_password, network_type,
         submitted_by, submitted_by_username, bbs_name)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        RETURNING id
    ");

    $stmt->execute([
        $data['ssid'],
        round($data['latitude'], 3),
        round($data['longitude'], 3),
        $data['description'],
        $data['wifi_password'] ?? null,
        $data['network_type'] ?? 'other',
        $userId,
        $username,
        $bbsName
    ]);

    $networkId = $stmt->fetch()['id'];

    // Award credits
    UserCredit::credit(
        $userId,
        3,
        "Submitted network: {$data['ssid']}",
        null,
        UserCredit::TYPE_SYSTEM_REWARD
    );

    echo json_encode([
        'success' => true,
        'network_id' => $networkId,
        'credits_earned' => 3,
        'message' => 'Network submitted successfully'
    ]);
}

/**
 * Update network
 */
function handleUpdateNetwork($db, $userId, $isAdmin)
{
    $id = (int)($_GET['id'] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true);

    if ($id <= 0) {
        throw new Exception('Invalid network ID');
    }

    if (!$data) {
        throw new Exception('Invalid JSON data');
    }

    // Get existing network
    $stmt = $db->prepare("SELECT * FROM cwn_networks WHERE id = ?");
    $stmt->execute([$id]);
    $network = $stmt->fetch();

    if (!$network) {
        throw new Exception('Network not found');
    }

    // Check authorization
    if (!$isAdmin && $network['submitted_by'] != $userId) {
        throw new Exception('You can only edit your own submissions');
    }

    // Validate input
    validateNetworkData($data, false);

    // Build update query
    $updates = [];
    $params = [];

    if (isset($data['description'])) {
        $updates[] = 'description = ?';
        $params[] = $data['description'];
    }

    if (isset($data['wifi_password'])) {
        $updates[] = 'wifi_password = ?';
        $params[] = $data['wifi_password'];
    }

    if (isset($data['network_type'])) {
        $updates[] = 'network_type = ?';
        $params[] = $data['network_type'];
    }

    $updates[] = 'date_updated = NOW()';
    $updates[] = 'date_verified = NOW()';

    $params[] = $id;

    $sql = "UPDATE cwn_networks SET " . implode(', ', $updates) . " WHERE id = ?";
    $db->prepare($sql)->execute($params);

    echo json_encode([
        'success' => true,
        'message' => 'Network updated successfully'
    ]);
}

/**
 * Delete network
 */
function handleDeleteNetwork($db, $userId, $isAdmin)
{
    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        throw new Exception('Invalid network ID');
    }

    // Get existing network
    $stmt = $db->prepare("SELECT * FROM cwn_networks WHERE id = ?");
    $stmt->execute([$id]);
    $network = $stmt->fetch();

    if (!$network) {
        throw new Exception('Network not found');
    }

    // Check authorization
    if (!$isAdmin && $network['submitted_by'] != $userId) {
        throw new Exception('You can only delete your own submissions');
    }

    // Soft delete
    $stmt = $db->prepare("UPDATE cwn_networks SET is_active = FALSE WHERE id = ?");
    $stmt->execute([$id]);

    echo json_encode([
        'success' => true,
        'message' => 'Network deleted successfully'
    ]);
}

/**
 * Search networks
 */
function handleSearch($db, $userId)
{
    $params = json_decode(file_get_contents('php://input'), true);

    if (!$params) {
        throw new Exception('Invalid JSON data');
    }

    // Check credits
    $balance = UserCredit::getBalance($userId);
    if ($balance < 1) {
        throw new Exception('Insufficient credits. You need 1 credit to search.');
    }

    // Check rate limit
    checkRateLimit($db, $userId, 'search', 50);

    $results = [];

    if ($params['type'] === 'radius') {
        $results = searchByRadius(
            $db,
            $params['latitude'],
            $params['longitude'],
            $params['radius_km'] ?? 5,
            $params['filters'] ?? []
        );
    } elseif ($params['type'] === 'keyword') {
        $results = searchByKeyword(
            $db,
            $params['keyword'],
            $params['filters'] ?? []
        );
    }

    // Deduct credit
    UserCredit::debit(
        $userId,
        1,
        "Searched networks ({$params['type']})",
        null,
        UserCredit::TYPE_PAYMENT
    );

    // Log search
    $stmt = $db->prepare("
        INSERT INTO cwn_searches (user_id, search_type, search_query, results_count)
        VALUES (?, ?, ?, ?)
        RETURNING id
    ");
    $stmt->execute([
        $userId,
        $params['type'],
        json_encode($params),
        count($results)
    ]);
    $searchId = $stmt->fetch()['id'];

    echo json_encode([
        'success' => true,
        'results' => $results,
        'count' => count($results),
        'credits_spent' => 1,
        'search_id' => $searchId
    ]);
}

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

/**
 * Search by radius using Haversine formula
 */
function searchByRadius($db, float $lat, float $lon, float $radiusKm, array $filters): array
{
    $sql = "
        SELECT *,
            (6371 * acos(
                cos(radians(?)) * cos(radians(latitude)) *
                cos(radians(longitude) - radians(?)) +
                sin(radians(?)) * sin(radians(latitude))
            )) AS distance_km
        FROM cwn_networks
        WHERE is_active = TRUE
        HAVING distance_km <= ?
        ORDER BY distance_km
        LIMIT 100
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([$lat, $lon, $lat, $radiusKm]);
    return $stmt->fetchAll();
}

/**
 * Search by keyword
 */
function searchByKeyword($db, string $keyword, array $filters): array
{
    $sql = "
        SELECT * FROM cwn_networks
        WHERE is_active = TRUE
        AND (
            ssid ILIKE ? OR
            description ILIKE ?
        )
        ORDER BY date_added DESC
        LIMIT 100
    ";

    $searchTerm = "%{$keyword}%";
    $stmt = $db->prepare($sql);
    $stmt->execute([$searchTerm, $searchTerm]);
    return $stmt->fetchAll();
}

/**
 * Validate network data
 */
function validateNetworkData(array $data, bool $requireAll = true): void
{
    if ($requireAll) {
        if (empty($data['ssid'])) {
            throw new Exception('SSID is required');
        }
        if (!isset($data['latitude'])) {
            throw new Exception('Latitude is required');
        }
        if (!isset($data['longitude'])) {
            throw new Exception('Longitude is required');
        }
        if (empty($data['description'])) {
            throw new Exception('Description is required');
        }
    }

    // SSID validation
    if (isset($data['ssid'])) {
        if (strlen($data['ssid']) < 1 || strlen($data['ssid']) > 100) {
            throw new Exception('SSID must be 1-100 characters');
        }
    }

    // Coordinate validation
    if (isset($data['latitude'])) {
        if ($data['latitude'] < -90 || $data['latitude'] > 90) {
            throw new Exception('Invalid latitude');
        }
    }

    if (isset($data['longitude'])) {
        if ($data['longitude'] < -180 || $data['longitude'] > 180) {
            throw new Exception('Invalid longitude');
        }
    }

    // Description validation
    if (isset($data['description'])) {
        $len = strlen($data['description']);
        if ($len < 10 || $len > 500) {
            throw new Exception('Description must be 10-500 characters');
        }
    }

    // Password validation (optional)
    if (isset($data['wifi_password']) && strlen($data['wifi_password']) > 100) {
        throw new Exception('WiFi password too long (max 100 characters)');
    }
}

/**
 * Check for duplicate network
 */
function findDuplicate($db, string $ssid, float $lat, float $lon): ?array
{
    $stmt = $db->prepare("
        SELECT * FROM cwn_networks
        WHERE ssid = ? AND latitude = ? AND longitude = ?
    ");
    $stmt->execute([$ssid, round($lat, 3), round($lon, 3)]);
    return $stmt->fetch() ?: null;
}

/**
 * Check rate limit
 */
function checkRateLimit($db, int $userId, string $type, int $maxPerDay): void
{
    $table = $type === 'submission' ? 'cwn_networks' : 'cwn_searches';
    $field = $type === 'submission' ? 'submitted_by' : 'user_id';

    $sql = "
        SELECT COUNT(*) FROM {$table}
        WHERE {$field} = ?
        AND created_at > NOW() - INTERVAL '1 day'
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([$userId]);
    $count = $stmt->fetchColumn();

    if ($count >= $maxPerDay) {
        throw new Exception("Rate limit exceeded. Maximum {$maxPerDay} {$type}s per day.");
    }
}
