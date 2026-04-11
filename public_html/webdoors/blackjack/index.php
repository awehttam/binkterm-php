<?php

// Include WebDoor SDK (handles autoload, database, and session initialization)
require_once __DIR__ . '/../_doorsdk/php/helpers.php';

use BinktermPHP\Auth;
use BinktermPHP\GameConfig;
use BinktermPHP\Template;

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

$gameConfig = GameConfig::getGameConfig('blackjack') ?? [];
$startBet = isset($gameConfig['start_bet']) ? (int)$gameConfig['start_bet'] : 10;
if ($startBet <= 0) {
    $startBet = 10;
}
$startingChips = isset($gameConfig['starting_chips']) ? (int)$gameConfig['starting_chips'] : 1000;
if ($startingChips <= 0) {
    $startingChips = 1000;
}

/**
 * Build the initial game state for a new session.
 */
function blackjack_default_state(int $startBet, int $startingChips): array
{
    return [
        'bankroll' => $startingChips,
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
        'sessionWinnings' => 0,
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

function blackjack_get_state(int $startBet, int $startingChips): array
{
    if (!isset($_SESSION['blackjack_state']) || !is_array($_SESSION['blackjack_state'])) {
        $_SESSION['blackjack_state'] = blackjack_default_state($startBet, $startingChips);
    }

    $state = $_SESSION['blackjack_state'];

    // Ensure bankroll is present (upgrade from older sessions that had no bankroll)
    if (!isset($state['bankroll']) || (int)$state['bankroll'] <= 0) {
        $state['bankroll'] = $startingChips;
    }

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

/**
 * Send a JSON response. Balance is read directly from the session chip count in state.
 */
function blackjack_response(array $state, bool $ok = true, ?string $error = null, int $code = 200): void
{
    $payload = [
        'success' => $ok,
        'balance' => (int)$state['bankroll'],
        'state' => array_merge($state, ['deck' => []])
    ];
    if ($error !== null) {
        $payload['error'] = $error;
    }

    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
}

/**
 * Settle a round, adjust the session chip count, and compose the outcome message.
 * If the player runs out of chips they are automatically rebought to $startingChips.
 *
 * @return array Modified game state.
 */
function blackjack_end_round(array $state, string $outcome, int $startingChips): array
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

    $state['bankroll'] = (int)$state['bankroll'] + $delta;

    // Accumulate only chip gains (losses never subtract from session score)
    if ($delta > 0) {
        $state['sessionWinnings'] = ($state['sessionWinnings'] ?? 0) + $delta;
    }

    $pv = blackjack_hand_value($state['player']);
    $dv = blackjack_hand_value($state['dealer']);

    if ($outcome === 'blackjack') {
        $state['lastMessage'] = "Blackjack! You win {$delta} chips. (You: {$pv}, Dealer: {$dv})";
    } elseif ($outcome === 'win') {
        $state['lastMessage'] = "You win {$delta} chips. (You: {$pv}, Dealer: {$dv})";
    } elseif ($outcome === 'lose') {
        $state['lastMessage'] = "Dealer wins. You lose " . abs($delta) . " chips. (You: {$pv}, Dealer: {$dv})";
    } else {
        $state['lastMessage'] = "Push. No chips exchanged. (You: {$pv}, Dealer: {$dv})";
    }

    // Auto-rebuy if the player has run out of chips
    if ($state['bankroll'] <= 0) {
        $state['bankroll'] = $startingChips;
        $state['lastMessage'] .= " You're out of chips — restarting with {$startingChips}.";
    }

    $state['roundId'] += 1;
    $state['lastOutcome'] = $outcome;

    return $state;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $action = strtolower(trim((string)($input['action'] ?? '')));
    $state = blackjack_get_state($startBet, $startingChips);
    $state['lastOutcome'] = null;

    if ($action === '' || $action === 'init') {
        blackjack_store_state($state);
        blackjack_response($state);
        exit;
    }

    if ($action === 'deal') {
        if ($state['inRound']) {
            blackjack_response($state, false, 'Round already in progress', 400);
            exit;
        }

        $bet = (int)($input['bet'] ?? 0);
        if ($bet <= 0) {
            $state['lastMessage'] = 'Invalid bet. It must be at least 1 chip.';
            blackjack_store_state($state);
            blackjack_response($state, false, $state['lastMessage'], 400);
            exit;
        }

        if ($bet > (int)$state['bankroll']) {
            $state['lastMessage'] = 'Invalid bet. It must not exceed your chip count.';
            blackjack_store_state($state);
            blackjack_response($state, false, $state['lastMessage'], 400);
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

        if ($playerBJ && $dealerBJ) {
            $state = blackjack_end_round($state, 'push', $startingChips);
        } elseif ($playerBJ) {
            $state = blackjack_end_round($state, 'blackjack', $startingChips);
        } elseif ($dealerBJ) {
            $state = blackjack_end_round($state, 'lose', $startingChips);
        }

        if (!$playerBJ && !$dealerBJ) {
            $state['lastMessage'] = 'Round started. Hit or Stand.';
        }

        blackjack_store_state($state);
        blackjack_response($state);
        exit;
    }

    if ($action === 'hit') {
        if (!$state['inRound']) {
            blackjack_response($state, false, 'No active round', 400);
            exit;
        }

        blackjack_draw($state, 'player');
        $pv = blackjack_hand_value($state['player']);
        if ($pv > 21) {
            $state = blackjack_end_round($state, 'lose', $startingChips);
        } else {
            $state['lastMessage'] = "Hit. Your total is {$pv}.";
        }

        blackjack_store_state($state);
        blackjack_response($state);
        exit;
    }

    if ($action === 'stand') {
        if (!$state['inRound']) {
            blackjack_response($state, false, 'No active round', 400);
            exit;
        }

        while (blackjack_hand_value($state['dealer']) < 17) {
            blackjack_draw($state, 'dealer');
        }

        $pv = blackjack_hand_value($state['player']);
        $dv = blackjack_hand_value($state['dealer']);

        if ($dv > 21) {
            $state = blackjack_end_round($state, 'win', $startingChips);
        } elseif ($pv > $dv) {
            $state = blackjack_end_round($state, 'win', $startingChips);
        } elseif ($pv < $dv) {
            $state = blackjack_end_round($state, 'lose', $startingChips);
        } else {
            $state = blackjack_end_round($state, 'push', $startingChips);
        }

        blackjack_store_state($state);
        blackjack_response($state);
        exit;
    }

    blackjack_response($state, false, 'Unknown action', 400);
    exit;
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
    <div id="bankroll">Chips: <?php echo htmlspecialchars((string)$startingChips); ?></div>
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
  <script src="../_doorsdk/js/messaging.js"></script>

  <!-- Game Scripts -->
  <script src="js/webdoor.js"></script>
  <script src="js/blackjack.js"></script>
</body>
</html>
