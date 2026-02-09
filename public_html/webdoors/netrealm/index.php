<?php

/**
 * NetRealm RPG - Entry point.
 *
 * GET:  Serves the full HTML game page.
 * POST: JSON API routing via 'action' field.
 */

// Include WebDoor SDK (handles autoload, database, and session initialization)
require_once __DIR__ . '/../_doorsdk/php/helpers.php';

use BinktermPHP\Auth;
use BinktermPHP\BbsConfig;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Database;
use BinktermPHP\GameConfig;
use BinktermPHP\Template;
use BinktermPHP\UserCredit;

$auth = new Auth();
$user = $auth->getCurrentUser();

if (!$user) {
    header('Location: /login');
    exit;
}

if (!GameConfig::isGameSystemEnabled() || !GameConfig::isEnabled('netrealm')) {
    $template = new Template();
    $template->renderResponse('error.twig', [
        'error' => 'Sorry, the game system is not enabled.'
    ]);
    exit;
}

// Load game config
$gameConfig = GameConfig::getGameConfig('netrealm') ?? [];
$dailyTurns        = (int)($gameConfig['daily_turns'] ?? 25);
$startingGold      = (int)($gameConfig['starting_gold'] ?? 100);
$startingLevel     = (int)($gameConfig['starting_level'] ?? 1);
$pvpEnabled        = (bool)($gameConfig['pvp_enabled'] ?? true);
$creditsPerTurn    = (int)($gameConfig['credits_per_extra_turn'] ?? 5);
$maxExtraTurns     = (int)($gameConfig['max_extra_turns_per_day'] ?? 10);
$maxInventory      = (int)($gameConfig['max_inventory_size'] ?? 20);
$maxRestUses       = (int)($gameConfig['max_rest_uses_per_day'] ?? 3);
$restHealPercent   = (int)($gameConfig['rest_heal_percent'] ?? 50);
$pvpTurnCost       = (int)($gameConfig['pvp_turn_cost'] ?? 3);
$pvpLevelRange     = (int)($gameConfig['pvp_level_range'] ?? 5);
$sellPricePercent  = (int)($gameConfig['sell_price_percent'] ?? 50);

$userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
$creditsConfig = BbsConfig::getConfig()['credits'] ?? [];
$creditsEnabled = !empty($creditsConfig['enabled']);
$symbol = $creditsConfig['symbol'] ?? '$';

// Get node address for PvP sync
$nodeAddress = '0:0/0';
try {
    $binkpConfig = BinkpConfig::getInstance();
    $nodeAddress = $binkpConfig->getSystemAddress() ?: '0:0/0';
} catch (\Throwable $e) {
    // Non-fatal
}

// Load game classes
require_once __DIR__ . '/src/ItemDatabase.php';
require_once __DIR__ . '/src/MonsterDatabase.php';
require_once __DIR__ . '/src/Character.php';
require_once __DIR__ . '/src/TurnManager.php';
require_once __DIR__ . '/src/Combat.php';
require_once __DIR__ . '/src/Inventory.php';
require_once __DIR__ . '/src/Shop.php';
require_once __DIR__ . '/src/Pvp.php';
require_once __DIR__ . '/src/Leaderboard.php';

$db = Database::getInstance()->getPdo();
$charManager   = new Character($db);
$turnManager   = new TurnManager($db);
$combat        = new Combat($db, $charManager, $turnManager);
$inventory     = new Inventory($db);
$shop          = new Shop($db, $charManager, $inventory);
$pvp           = new Pvp($db, $charManager, $turnManager, $combat);
$leaderboard   = new Leaderboard($db);

/**
 * Send JSON response and exit.
 *
 * @param array $data
 * @param int $code HTTP status code
 */
function jsonResponse(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ===== POST: API Routes =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $action = strtolower(trim((string)($input['action'] ?? '')));

    // Helper: get current character or null
    $getChar = function () use ($charManager, $turnManager, $userId, $dailyTurns, $pvp, $nodeAddress): ?array {
        $char = $charManager->getByUserId($userId);
        if ($char) {
            // Check daily reset
            $turnManager->checkDailyReset($char['id'], $dailyTurns);
            $char = $charManager->getByUserId($userId); // Reload after reset

            // Sync to network players for PvP
            $pvp->syncLocalPlayer($char, $nodeAddress);
        }
        return $char;
    };

    try {
        switch ($action) {
            case 'init': {
                $char = $getChar();
                $data = ['success' => true, 'character' => null];
                if ($char) {
                    $data['character'] = $charManager->getStatus($char);
                }
                $data['config'] = [
                    'daily_turns' => $dailyTurns,
                    'pvp_enabled' => $pvpEnabled,
                    'credits_per_extra_turn' => $creditsPerTurn,
                    'max_extra_turns_per_day' => $maxExtraTurns,
                    'max_inventory_size' => $maxInventory,
                    'max_rest_uses_per_day' => $maxRestUses,
                    'rest_heal_percent' => $restHealPercent,
                    'pvp_turn_cost' => $pvpTurnCost,
                    'pvp_level_range' => $pvpLevelRange,
                    'sell_price_percent' => $sellPricePercent,
                    'credits_enabled' => $creditsEnabled,
                    'credit_symbol' => $symbol,
                ];
                jsonResponse($data);
            }

            case 'create': {
                $name = trim((string)($input['name'] ?? ''));
                $char = $charManager->create($userId, $name, $gameConfig);
                $pvp->syncLocalPlayer($char, $nodeAddress);
                jsonResponse([
                    'success' => true,
                    'character' => $charManager->getStatus($char),
                ]);
            }

            case 'status': {
                $char = $getChar();
                if (!$char) {
                    jsonResponse(['success' => false, 'error' => 'No character found.'], 400);
                }
                jsonResponse([
                    'success' => true,
                    'character' => $charManager->getStatus($char),
                ]);
            }

            case 'monsters': {
                $char = $getChar();
                if (!$char) {
                    jsonResponse(['success' => false, 'error' => 'No character found.'], 400);
                }
                $monsters = MonsterDatabase::getForLevel($char['level']);
                jsonResponse(['success' => true, 'monsters' => array_values($monsters)]);
            }

            case 'fight': {
                $char = $getChar();
                if (!$char) {
                    jsonResponse(['success' => false, 'error' => 'No character found.'], 400);
                }
                $monsterKey = trim((string)($input['monster_key'] ?? ''));
                $result = $combat->fight($char['id'], $monsterKey, $gameConfig);
                jsonResponse(['success' => true] + $result);
            }

            case 'inventory': {
                $char = $getChar();
                if (!$char) {
                    jsonResponse(['success' => false, 'error' => 'No character found.'], 400);
                }
                $items = $inventory->getAll($char['id']);
                jsonResponse([
                    'success' => true,
                    'items' => $items,
                    'count' => count($items),
                    'max' => $maxInventory,
                ]);
            }

            case 'equip': {
                $char = $getChar();
                if (!$char) {
                    jsonResponse(['success' => false, 'error' => 'No character found.'], 400);
                }
                $itemId = (int)($input['item_id'] ?? 0);
                $result = $inventory->equip($itemId, $char['id']);
                if (!$result['success']) {
                    jsonResponse($result, 400);
                }
                $updatedChar = $charManager->getByUserId($userId);
                $result['character'] = $charManager->getStatus($updatedChar);
                jsonResponse($result);
            }

            case 'unequip': {
                $char = $getChar();
                if (!$char) {
                    jsonResponse(['success' => false, 'error' => 'No character found.'], 400);
                }
                $itemId = (int)($input['item_id'] ?? 0);
                $result = $inventory->unequip($itemId, $char['id']);
                if (!$result['success']) {
                    jsonResponse($result, 400);
                }
                $updatedChar = $charManager->getByUserId($userId);
                $result['character'] = $charManager->getStatus($updatedChar);
                jsonResponse($result);
            }

            case 'shop': {
                $char = $getChar();
                if (!$char) {
                    jsonResponse(['success' => false, 'error' => 'No character found.'], 400);
                }
                $items = $shop->getAvailableItems($char['level']);
                jsonResponse([
                    'success' => true,
                    'items' => $items,
                    'gold' => $char['gold'],
                    'sell_percent' => $sellPricePercent,
                ]);
            }

            case 'buy': {
                $char = $getChar();
                if (!$char) {
                    jsonResponse(['success' => false, 'error' => 'No character found.'], 400);
                }
                $itemKey = trim((string)($input['item_key'] ?? ''));
                $result = $shop->buy($char['id'], $itemKey, $gameConfig);
                if (!$result['success']) {
                    jsonResponse($result, 400);
                }
                $updatedChar = $charManager->getByUserId($userId);
                $result['character'] = $charManager->getStatus($updatedChar);
                jsonResponse($result);
            }

            case 'sell': {
                $char = $getChar();
                if (!$char) {
                    jsonResponse(['success' => false, 'error' => 'No character found.'], 400);
                }
                $itemId = (int)($input['item_id'] ?? 0);
                $result = $shop->sell($char['id'], $itemId, $sellPricePercent);
                if (!$result['success']) {
                    jsonResponse($result, 400);
                }
                $updatedChar = $charManager->getByUserId($userId);
                $result['character'] = $charManager->getStatus($updatedChar);
                jsonResponse($result);
            }

            case 'rest': {
                $char = $getChar();
                if (!$char) {
                    jsonResponse(['success' => false, 'error' => 'No character found.'], 400);
                }
                $result = $turnManager->rest($char['id'], $maxRestUses, $restHealPercent);
                if (!$result['success']) {
                    jsonResponse($result, 400);
                }
                $updatedChar = $charManager->getByUserId($userId);
                $result['character'] = $charManager->getStatus($updatedChar);
                jsonResponse($result);
            }

            case 'buy-turns': {
                $char = $getChar();
                if (!$char) {
                    jsonResponse(['success' => false, 'error' => 'No character found.'], 400);
                }
                if (!$creditsEnabled) {
                    jsonResponse(['success' => false, 'error' => 'Credits system is disabled.'], 400);
                }
                $amount = (int)($input['amount'] ?? 1);
                $result = $turnManager->buyTurns($char['id'], $userId, $amount, $creditsPerTurn, $maxExtraTurns);
                if (!$result['success']) {
                    jsonResponse($result, 400);
                }
                $updatedChar = $charManager->getByUserId($userId);
                $result['character'] = $charManager->getStatus($updatedChar);
                jsonResponse($result);
            }

            case 'pvp-list': {
                if (!$pvpEnabled) {
                    jsonResponse(['success' => false, 'error' => 'PvP is disabled.'], 400);
                }
                $char = $getChar();
                if (!$char) {
                    jsonResponse(['success' => false, 'error' => 'No character found.'], 400);
                }
                $players = $pvp->getChallengeable($char['id'], $char['level'], $pvpLevelRange);
                jsonResponse(['success' => true, 'players' => $players]);
            }

            case 'pvp-challenge': {
                if (!$pvpEnabled) {
                    jsonResponse(['success' => false, 'error' => 'PvP is disabled.'], 400);
                }
                $char = $getChar();
                if (!$char) {
                    jsonResponse(['success' => false, 'error' => 'No character found.'], 400);
                }
                $playerId = (int)($input['player_id'] ?? 0);
                $result = $pvp->challenge($char['id'], $userId, $playerId, $gameConfig);
                jsonResponse(['success' => true] + $result);
            }

            case 'pvp-log': {
                $char = $getChar();
                if (!$char) {
                    jsonResponse(['success' => false, 'error' => 'No character found.'], 400);
                }
                $log = $pvp->getPvpLog($char['id']);
                jsonResponse(['success' => true, 'log' => $log]);
            }

            case 'leaderboard': {
                $type = trim((string)($input['type'] ?? 'overall'));
                $rankings = $leaderboard->getRankings($type);
                jsonResponse(['success' => true, 'rankings' => $rankings, 'type' => $type]);
            }

            case 'combat-log': {
                $char = $getChar();
                if (!$char) {
                    jsonResponse(['success' => false, 'error' => 'No character found.'], 400);
                }
                $log = $combat->getCombatLog($char['id']);
                jsonResponse(['success' => true, 'log' => $log]);
            }

            default:
                jsonResponse(['success' => false, 'error' => 'Unknown action.'], 400);
        }
    } catch (\Exception $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

// ===== GET: Serve HTML =====
$userName = htmlspecialchars($user['username'] ?? 'Adventurer');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NetRealm RPG</title>
    <link href="/vendor/bootstrap-5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="/vendor/fontawesome-6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/webdoors/netrealm/css/netrealm.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid px-2 px-md-3">
        <!-- Header -->
        <div class="nr-header text-center py-2">
            <h1 class="nr-title mb-0"><i class="fas fa-shield-halved"></i> NetRealm RPG</h1>
            <small class="text-muted">Welcome, <?php echo $userName; ?></small>
        </div>

        <!-- Loading -->
        <div id="view-loading" class="text-center py-5">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-muted">Loading NetRealm...</p>
        </div>

        <!-- Character Creation -->
        <div id="view-create" class="nr-view" style="display:none">
            <div class="card nr-card mx-auto" style="max-width:500px">
                <div class="card-body text-center">
                    <h3><i class="fas fa-user-plus"></i> Create Your Character</h3>
                    <p class="text-muted">Choose a name for your hero. 3-20 characters, letters, numbers, and spaces.</p>
                    <div class="mb-3">
                        <input type="text" id="create-name" class="form-control nr-input" placeholder="Character Name" maxlength="20" autocomplete="off">
                    </div>
                    <div id="create-error" class="text-danger mb-2" style="display:none"></div>
                    <button id="btn-create" class="btn btn-primary btn-lg"><i class="fas fa-scroll"></i> Begin Adventure</button>
                </div>
            </div>
        </div>

        <!-- Town View (Main Hub) -->
        <div id="view-town" class="nr-view" style="display:none">
            <!-- Status Bar -->
            <div class="nr-status-bar mb-2">
                <div class="row g-1 align-items-center">
                    <div class="col-auto">
                        <strong id="char-name" class="nr-char-name"></strong>
                        <span class="badge bg-secondary" id="char-level"></span>
                    </div>
                    <div class="col">
                        <div class="nr-bar-group">
                            <div class="nr-bar">
                                <div class="nr-bar-label"><i class="fas fa-heart"></i> HP</div>
                                <div class="progress nr-progress">
                                    <div id="hp-bar" class="progress-bar bg-danger" role="progressbar"></div>
                                </div>
                                <span id="hp-text" class="nr-bar-value"></span>
                            </div>
                            <div class="nr-bar">
                                <div class="nr-bar-label"><i class="fas fa-star"></i> XP</div>
                                <div class="progress nr-progress">
                                    <div id="xp-bar" class="progress-bar bg-info" role="progressbar"></div>
                                </div>
                                <span id="xp-text" class="nr-bar-value"></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row g-1 mt-1">
                    <div class="col-auto nr-stat"><i class="fas fa-sword fa-swords"></i> <span id="stat-attack"></span></div>
                    <div class="col-auto nr-stat"><i class="fas fa-shield"></i> <span id="stat-defense"></span></div>
                    <div class="col-auto nr-stat"><i class="fas fa-coins"></i> <span id="stat-gold"></span></div>
                    <div class="col-auto nr-stat"><i class="fas fa-hourglass-half"></i> <span id="stat-turns"></span></div>
                    <div class="col-auto nr-stat"><i class="fas fa-skull-crossbones"></i> <span id="stat-kills"></span></div>
                </div>
            </div>

            <!-- Navigation Buttons -->
            <div class="nr-nav mb-3">
                <div class="d-flex flex-wrap gap-2 justify-content-center">
                    <button class="btn btn-outline-danger nr-nav-btn" data-view="combat"><i class="fas fa-dragon"></i> Hunt</button>
                    <button class="btn btn-outline-warning nr-nav-btn" data-view="inventory"><i class="fas fa-backpack"></i> Inventory</button>
                    <button class="btn btn-outline-success nr-nav-btn" data-view="shop"><i class="fas fa-store"></i> Shop</button>
                    <button class="btn btn-outline-info nr-nav-btn" data-view="pvp"><i class="fas fa-swords"></i> PvP Arena</button>
                    <button class="btn btn-outline-primary nr-nav-btn" data-view="leaderboard"><i class="fas fa-trophy"></i> Rankings</button>
                    <button class="btn btn-outline-secondary nr-nav-btn" data-view="combat-log"><i class="fas fa-scroll"></i> Log</button>
                    <button class="btn btn-outline-success nr-nav-btn" id="btn-rest"><i class="fas fa-bed"></i> Rest <span id="rest-count" class="badge bg-secondary"></span></button>
                    <button class="btn btn-outline-warning nr-nav-btn" id="btn-buy-turns" style="display:none"><i class="fas fa-bolt"></i> Buy Turns</button>
                </div>
            </div>

            <!-- Sub-Views (within town) -->
            <div id="town-content"></div>
        </div>

        <!-- Combat View -->
        <div id="view-combat" class="nr-view" style="display:none">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h4 class="mb-0"><i class="fas fa-dragon"></i> Monster Hunt</h4>
                <button class="btn btn-sm btn-outline-secondary btn-back" data-view="town"><i class="fas fa-arrow-left"></i> Town</button>
            </div>
            <div id="monster-list" class="row g-2"></div>
        </div>

        <!-- Combat Result View -->
        <div id="view-combat-result" class="nr-view" style="display:none">
            <div id="combat-result-content"></div>
            <div class="text-center mt-3">
                <button class="btn btn-danger btn-back" data-view="combat"><i class="fas fa-dragon"></i> Hunt Again</button>
                <button class="btn btn-outline-secondary btn-back" data-view="town"><i class="fas fa-arrow-left"></i> Town</button>
            </div>
        </div>

        <!-- Inventory View -->
        <div id="view-inventory" class="nr-view" style="display:none">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h4 class="mb-0"><i class="fas fa-backpack"></i> Inventory <span id="inv-count" class="badge bg-secondary"></span></h4>
                <button class="btn btn-sm btn-outline-secondary btn-back" data-view="town"><i class="fas fa-arrow-left"></i> Town</button>
            </div>
            <div id="inventory-list" class="row g-2"></div>
        </div>

        <!-- Shop View -->
        <div id="view-shop" class="nr-view" style="display:none">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h4 class="mb-0"><i class="fas fa-store"></i> Shop <span class="badge bg-warning text-dark"><i class="fas fa-coins"></i> <span id="shop-gold"></span></span></h4>
                <button class="btn btn-sm btn-outline-secondary btn-back" data-view="town"><i class="fas fa-arrow-left"></i> Town</button>
            </div>
            <div id="shop-list" class="row g-2"></div>
        </div>

        <!-- PvP View -->
        <div id="view-pvp" class="nr-view" style="display:none">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h4 class="mb-0"><i class="fas fa-swords"></i> PvP Arena</h4>
                <button class="btn btn-sm btn-outline-secondary btn-back" data-view="town"><i class="fas fa-arrow-left"></i> Town</button>
            </div>
            <div id="pvp-list"></div>
        </div>

        <!-- PvP Result View -->
        <div id="view-pvp-result" class="nr-view" style="display:none">
            <div id="pvp-result-content"></div>
            <div class="text-center mt-3">
                <button class="btn btn-info btn-back" data-view="pvp"><i class="fas fa-swords"></i> Arena</button>
                <button class="btn btn-outline-secondary btn-back" data-view="town"><i class="fas fa-arrow-left"></i> Town</button>
            </div>
        </div>

        <!-- Leaderboard View -->
        <div id="view-leaderboard" class="nr-view" style="display:none">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h4 class="mb-0"><i class="fas fa-trophy"></i> Rankings</h4>
                <button class="btn btn-sm btn-outline-secondary btn-back" data-view="town"><i class="fas fa-arrow-left"></i> Town</button>
            </div>
            <div class="btn-group mb-2 w-100" role="group">
                <button class="btn btn-outline-primary active nr-lb-tab" data-type="overall">Overall</button>
                <button class="btn btn-outline-primary nr-lb-tab" data-type="pvp">PvP</button>
                <button class="btn btn-outline-primary nr-lb-tab" data-type="wealth">Wealth</button>
                <button class="btn btn-outline-primary nr-lb-tab" data-type="monster_slayer">Slayer</button>
            </div>
            <div id="leaderboard-content"></div>
        </div>

        <!-- Combat Log View -->
        <div id="view-combat-log" class="nr-view" style="display:none">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h4 class="mb-0"><i class="fas fa-scroll"></i> Combat Log</h4>
                <button class="btn btn-sm btn-outline-secondary btn-back" data-view="town"><i class="fas fa-arrow-left"></i> Town</button>
            </div>
            <div id="combat-log-content"></div>
        </div>

        <!-- Toast Container -->
        <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toast-container"></div>
    </div>

    <script src="/webdoors/netrealm/js/game.js"></script>
</body>
</html>
