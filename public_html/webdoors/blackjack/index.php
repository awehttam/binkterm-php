<?php

// Include WebDoor SDK (handles autoload, database, and session initialization)
require_once __DIR__ . '/../_doorsdk/php/helpers.php';

use BinktermPHP\Auth;
use BinktermPHP\BbsConfig;
use BinktermPHP\GameConfig;
use BinktermPHP\Template;
use BinktermPHP\UserCredit;

$auth = new Auth();
$user = $auth->getCurrentUser();

if (!$user) {
    header('Location: /login');
    exit;
}

if (!GameConfig::isGameSystemEnabled() || !GameConfig::isEnabled('blackjack')) {
    $template = new Template();
    $template->renderResponse('error.twig', [
        'error' => 'Sorry, the game system is not enabled.'
    ]);
    exit;
}

$creditsConfig = BbsConfig::getConfig()['credits'] ?? [];
if (empty($creditsConfig['enabled'])) {
    $template = new Template();
    $template->renderResponse('error.twig', [
        'error' => 'Credits system is currently disabled.'
    ]);
    exit;
}

$gameConfig = GameConfig::getGameConfig('blackjack') ?? [];
$startBet = isset($gameConfig['start_bet']) ? (int)$gameConfig['start_bet'] : 10;
if ($startBet <= 0) {
    $startBet = 10;
}

$userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
$symbol = $creditsConfig['symbol'] ?? '$';

function blackjack_default_state(int $startBet): array
{
    return [
        'deck' => [],
        'player' => [],
        'dealer' => [],
        'bet' => $startBet,
        'inRound' => false,
        'revealDealer' => false,
        'lastMessage' => 'Click Deal to start.',
        'handsPlayed' => 0,
        'handsWon' => 0,
        'handsLost' => 0,
        'sessionEarnings' => 0,  // net credits won/lost from blackjack this session
        'bestEarnings' => 0,     // peak sessionEarnings (leaderboard score)
        'roundId' => 0,
        'lastOutcome' => null
    ];
}

function blackjack_new_deck(): array
{
    $suits = ['S', 'H', 'D', 'C'];
    $ranks = ['A', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K'];
    $deck = [];
    foreach ($suits as $suit) {
        foreach ($ranks as $rank) {
            $deck[] = ['s' => $suit, 'r' => $rank];
        }
    }

    for ($i = count($deck) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $deck[$i];
        $deck[$i] = $deck[$j];
        $deck[$j] = $tmp;
    }

    return $deck;
}

function blackjack_hand_value(array $hand): int
{
    $total = 0;
    $aces = 0;
    foreach ($hand as $card) {
        $rank = $card['r'] ?? '';
        if ($rank === 'A') {
            $aces++;
            $total += 11;
        } elseif (in_array($rank, ['K', 'Q', 'J'], true)) {
            $total += 10;
        } else {
            $total += (int)$rank;
        }
    }

    while ($total > 21 && $aces > 0) {
        $total -= 10;
        $aces--;
    }

    return $total;
}

function blackjack_draw(array &$state, string $handKey): void
{
    if (empty($state['deck'])) {
        $state['deck'] = blackjack_new_deck();
    }

    $card = array_pop($state['deck']);
    $state[$handKey][] = $card;
}

function blackjack_get_state(int $startBet): array
{
    if (!isset($_SESSION['blackjack_state']) || !is_array($_SESSION['blackjack_state'])) {
        $_SESSION['blackjack_state'] = blackjack_default_state($startBet);
    }

    $state = $_SESSION['blackjack_state'];
    $previousStart = isset($state['startBet']) ? (int)$state['startBet'] : null;
    $currentBet = isset($state['bet']) ? (int)$state['bet'] : null;

    if ($currentBet === null || $currentBet <= 0) {
        $state['bet'] = $startBet;
    } elseif ($previousStart !== null && $previousStart !== $startBet) {
        $hasCustomBet = $currentBet !== $previousStart;
        if (!$hasCustomBet && empty($state['inRound'])) {
            $state['bet'] = $startBet;
        }
    } elseif ($previousStart === null) {
        $handsPlayed = isset($state['handsPlayed']) ? (int)$state['handsPlayed'] : 0;
        if ($handsPlayed === 0 && empty($state['inRound'])) {
            $state['bet'] = $startBet;
        }
    }

    $state['startBet'] = $startBet;
    $_SESSION['blackjack_state'] = $state;

    return $state;
}

function blackjack_store_state(array $state): void
{
    $_SESSION['blackjack_state'] = $state;
}

function blackjack_response(array $state, int $balance, string $symbol, bool $ok = true, ?string $error = null, int $code = 200): void
{
    $state['deck'] = [];
    $payload = [
        'success' => $ok,
        'balance' => $balance,
        'symbol' => $symbol,
        'state' => $state
    ];
    if ($error !== null) {
        $payload['error'] = $error;
    }

    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
}

function blackjack_end_round(array $state, string $outcome, int $userId, int $balance, string $symbol): array
{
    $state['revealDealer'] = true;
    $state['inRound'] = false;
    $state['handsPlayed'] += 1;
    if ($outcome === 'win' || $outcome === 'blackjack') {
        $state['handsWon'] += 1;
    }
    if ($outcome === 'lose') {
        $state['handsLost'] += 1;
    }

    $bet = (int)$state['bet'];
    $delta = 0;
    if ($outcome === 'win') {
        $delta = $bet;
    } elseif ($outcome === 'lose') {
        $delta = -$bet;
    } elseif ($outcome === 'blackjack') {
        $delta = (int)floor($bet * 1.5);
    }

    if ($delta !== 0) {
        $txType = $delta > 0 ? UserCredit::TYPE_SYSTEM_REWARD : UserCredit::TYPE_PAYMENT;
        UserCredit::transact(
            $userId,
            $delta,
            "Blackjack hand ({$outcome})",
            null,
            $txType
        );
        $balance = UserCredit::getBalance($userId);
    }

    // Track earnings from blackjack only (independent of external credit sources)
    $state['sessionEarnings'] = ($state['sessionEarnings'] ?? 0) + $delta;
    if ($state['sessionEarnings'] > ($state['bestEarnings'] ?? 0)) {
        $state['bestEarnings'] = $state['sessionEarnings'];
    }

    $pv = blackjack_hand_value($state['player']);
    $dv = blackjack_hand_value($state['dealer']);

    if ($outcome === 'blackjack') {
        $state['lastMessage'] = "Blackjack! You win {$symbol}{$delta}. (You: {$pv}, Dealer: {$dv})";
    } elseif ($outcome === 'win') {
        $state['lastMessage'] = "You win {$symbol}{$delta}. (You: {$pv}, Dealer: {$dv})";
    } elseif ($outcome === 'lose') {
        $state['lastMessage'] = "Dealer wins. You lose {$symbol}" . abs($delta) . ". (You: {$pv}, Dealer: {$dv})";
    } else {
        $state['lastMessage'] = "Push. No change. (You: {$pv}, Dealer: {$dv})";
    }

    $state['roundId'] += 1;
    $state['lastOutcome'] = $outcome;

    return [$state, $balance];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $action = strtolower(trim((string)($input['action'] ?? '')));
    $state = blackjack_get_state($startBet);
    $state['lastOutcome'] = null;

    try {
        $balance = UserCredit::getBalance($userId);
    } catch (\Throwable $e) {
        blackjack_response($state, 0, $symbol, false, 'Unable to load balance', 500);
        exit;
    }

    if ($action === '' || $action === 'init') {
        blackjack_store_state($state);
        blackjack_response($state, $balance, $symbol);
        exit;
    }

    if ($action === 'deal') {
        if ($state['inRound']) {
            blackjack_response($state, $balance, $symbol, false, 'Round already in progress', 400);
            exit;
        }

        $bet = (int)($input['bet'] ?? 0);
        if ($bet <= 0) {
            $state['lastMessage'] = 'Invalid bet. It must be at least 1 credit.';
            blackjack_store_state($state);
            blackjack_response($state, $balance, $symbol, false, $state['lastMessage'], 400);
            exit;
        }

        if ($bet > $balance) {
            $state['lastMessage'] = 'Invalid bet. It must not exceed your balance.';
            blackjack_store_state($state);
            blackjack_response($state, $balance, $symbol, false, $state['lastMessage'], 400);
            exit;
        }

        $state['bet'] = $bet;
        $state['player'] = [];
        $state['dealer'] = [];
        $state['revealDealer'] = false;
        $state['inRound'] = true;

        if (count($state['deck']) < 15) {
            $state['deck'] = blackjack_new_deck();
        }

        blackjack_draw($state, 'player');
        blackjack_draw($state, 'dealer');
        blackjack_draw($state, 'player');
        blackjack_draw($state, 'dealer');

        $pv = blackjack_hand_value($state['player']);
        $dv = blackjack_hand_value($state['dealer']);

        $playerBJ = ($pv === 21 && count($state['player']) === 2);
        $dealerBJ = ($dv === 21 && count($state['dealer']) === 2);

        try {
            if ($playerBJ && $dealerBJ) {
                [$state, $balance] = blackjack_end_round($state, 'push', $userId, $balance, $symbol);
            } elseif ($playerBJ) {
                [$state, $balance] = blackjack_end_round($state, 'blackjack', $userId, $balance, $symbol);
            } elseif ($dealerBJ) {
                [$state, $balance] = blackjack_end_round($state, 'lose', $userId, $balance, $symbol);
            }
        } catch (\Throwable $e) {
            $state['lastMessage'] = 'Credit transaction failed.';
            $state['inRound'] = false;
            blackjack_store_state($state);
            blackjack_response($state, $balance, $symbol, false, $state['lastMessage'], 400);
            exit;
        }

        if (!$playerBJ && !$dealerBJ) {
            $state['lastMessage'] = 'Round started. Hit or Stand.';
        }

        blackjack_store_state($state);
        blackjack_response($state, $balance, $symbol);
        exit;
    }

    if ($action === 'hit') {
        if (!$state['inRound']) {
            blackjack_response($state, $balance, $symbol, false, 'No active round', 400);
            exit;
        }

        blackjack_draw($state, 'player');
        $pv = blackjack_hand_value($state['player']);
        if ($pv > 21) {
            try {
                [$state, $balance] = blackjack_end_round($state, 'lose', $userId, $balance, $symbol);
            } catch (\Throwable $e) {
                $state['lastMessage'] = 'Credit transaction failed.';
                $state['inRound'] = false;
                blackjack_store_state($state);
                blackjack_response($state, $balance, $symbol, false, $state['lastMessage'], 400);
                exit;
            }
        } else {
            $state['lastMessage'] = "Hit. Your total is {$pv}.";
        }

        blackjack_store_state($state);
        blackjack_response($state, $balance, $symbol);
        exit;
    }

    if ($action === 'stand') {
        if (!$state['inRound']) {
            blackjack_response($state, $balance, $symbol, false, 'No active round', 400);
            exit;
        }

        while (blackjack_hand_value($state['dealer']) < 17) {
            blackjack_draw($state, 'dealer');
        }

        $pv = blackjack_hand_value($state['player']);
        $dv = blackjack_hand_value($state['dealer']);

        try {
            if ($dv > 21) {
                [$state, $balance] = blackjack_end_round($state, 'win', $userId, $balance, $symbol);
            } elseif ($pv > $dv) {
                [$state, $balance] = blackjack_end_round($state, 'win', $userId, $balance, $symbol);
            } elseif ($pv < $dv) {
                [$state, $balance] = blackjack_end_round($state, 'lose', $userId, $balance, $symbol);
            } else {
                [$state, $balance] = blackjack_end_round($state, 'push', $userId, $balance, $symbol);
            }
        } catch (\Throwable $e) {
            $state['lastMessage'] = 'Credit transaction failed.';
            $state['inRound'] = false;
            blackjack_store_state($state);
            blackjack_response($state, $balance, $symbol, false, $state['lastMessage'], 400);
            exit;
        }

        blackjack_store_state($state);
        blackjack_response($state, $balance, $symbol);
        exit;
    }

    blackjack_response($state, $balance, $symbol, false, 'Unknown action', 400);
    exit;
}

try {
    $balance = UserCredit::getBalance($userId);
} catch (\Throwable $e) {
    $balance = 0;
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>WebDoor Blackjack</title>
  <link rel="stylesheet" href="css/blackjack.css">
</head>
<body>
  <header>
    <h1>Blackjack</h1>
    <div id="bankroll">Bankroll: <?php echo htmlspecialchars($symbol . $balance); ?></div>
  </header>

  <section id="statusbar">
    <div id="status">Click Deal to start.</div>
  </section>

  <div class="table">
    <div class="seat">
      <h2>Dealer <span id="dealerValue" class="value"></span></h2>
      <div id="dealer" class="hand" aria-label="Dealer hand"></div>
    </div>
    <div class="seat">
      <h2>Player <span id="playerValue" class="value"></span></h2>
      <div id="player" class="hand" aria-label="Player hand"></div>
    </div>
  </div>

  <div class="controls">
    <label>
      Bet:
      <input type="number" id="bet" value="<?php echo htmlspecialchars((string)$startBet); ?>" min="1" step="1">
    </label>
    <button id="deal">Deal</button>
    <button id="hit">Hit</button>
    <button id="stand">Stand</button>
    <button id="save">Save</button>
  </div>

  <h2>Leaderboard</h2>
  <ul id="leaderboard"></ul>

  <!-- WebDoor SDK -->
  <script src="../_doorsdk/js/api.js"></script>
  <script src="../_doorsdk/js/credits.js"></script>
  <script src="../_doorsdk/js/messaging.js"></script>

  <!-- Game Scripts -->
  <script src="js/webdoor.js"></script>
  <script src="js/blackjack.js"></script>
</body>
</html>
