# Building Your First WebDoor

This tutorial walks through building a complete WebDoor from scratch. By the end you'll have a working coin-flip game that charges 1 credit per play, awards 2 credits on a win, and is accessible from the BBS games list.

For the full WebDoor specification and manifest reference, see [WebDoors.md](WebDoors.md).

---

## Table of Contents

1. [What You're Building](#what-youre-building)
2. [Warning: Credits with Monetary Value](#warning-credits-with-monetary-value)
3. [Prerequisites](#prerequisites)
4. [Step 1: Create the Directory](#step-1-create-the-directory)
5. [Step 2: Write the Manifest](#step-2-write-the-manifest)
6. [Step 3: Write the Entry Point](#step-3-write-the-entry-point)
7. [Step 4: Write the Game API](#step-4-write-the-game-api)
8. [Step 5: Enable in Admin](#step-5-enable-in-admin)
9. [Step 6: Test It](#step-6-test-it)
10. [Going Further](#going-further)

---

## What You're Building

**Coin Flip** — a dead-simple game to illustrate the WebDoor plumbing:

- The user pays 1 credit to flip a coin.
- Heads: the user wins 2 credits (net +1).
- Tails: the user loses the 1 credit (net -1).
- The current credit balance is shown before and after each flip.

The game uses a PHP entry point (so the server handles credit logic) and a minimal HTML/JavaScript frontend.

---

## Warning: Credits with Monetary Value

> **If credits on your BBS have real monetary value** (e.g. they are purchased with money, redeemable for prizes, or equivalent to currency), awarding or wagering them in games may constitute gambling under applicable laws. Regulations vary widely by jurisdiction.
>
> Before deploying any WebDoor that awards credits based on chance or skill, consult a legal professional in your jurisdiction to determine whether your BBS and its games comply with local gambling, prize, and sweepstakes laws.
>
> This tutorial is provided for educational purposes. The BinktermPHP project makes no representations about the legality of credit-based game mechanics on your system.

---

## Prerequisites

- A working BinktermPHP installation.
- Credits enabled in BBS settings (`credits.enabled = true` in `config/bbs.json`).
- Sysop (admin) access to enable the door.

---

## Step 1: Create the Directory

WebDoors live in `public_html/webdoors/`. Create a subdirectory for your game:

```bash
mkdir public_html/webdoors/coinflip
```

The directory name becomes the game ID and the URL slug (`/games/coinflip`).

---

## Step 2: Write the Manifest

Create `public_html/webdoors/coinflip/webdoor.json`:

```json
{
  "webdoor_version": "1.0",
  "game": {
    "id": "coinflip",
    "name": "Coin Flip",
    "version": "1.0.0",
    "author": "Your Name",
    "description": "Flip a coin. Win or lose credits.",
    "entry_point": "index.php",
    "icon": "icon.svg"
  },
  "requirements": {
    "min_host_version": "1.0",
    "features": ["credits"],
    "permissions": []
  }
}
```

The `features` array tells the BBS that this door uses the credits system. The `entry_point` is what gets served when a user visits `/games/coinflip`.

---

## Step 3: Write the Entry Point

Create `public_html/webdoors/coinflip/index.php`. This file does two things: it handles the API action (if `?action=flip` is in the URL) and renders the HTML game UI.

```php
<?php
require_once __DIR__ . '/../_doorsdk/php/helpers.php';

$user = \WebDoorSDK\requireAuth();
$userId = (int)$user['id'];

// Handle the flip action as a JSON API call
if (isset($_GET['action']) && $_GET['action'] === 'flip') {
    header('Content-Type: application/json');

    // Charge 1 credit to play
    $charged = \BinktermPHP\UserCredit::debit($userId, 1, 'Coin Flip — play');
    if (!$charged) {
        \WebDoorSDK\jsonResponse(['error' => 'Not enough credits'], 402);
    }

    // Flip the coin
    $result = random_int(0, 1) === 1 ? 'heads' : 'tails';

    $won = false;
    if ($result === 'heads') {
        \BinktermPHP\UserCredit::credit($userId, 2, 'Coin Flip — win');
        $won = true;
    }

    $balance = \BinktermPHP\UserCredit::getBalance($userId);

    \WebDoorSDK\jsonResponse([
        'result'  => $result,
        'won'     => $won,
        'balance' => $balance,
    ]);
}

// Render the game UI
$balance = \BinktermPHP\UserCredit::getBalance($userId);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Coin Flip</title>
    <style>
        body { font-family: monospace; background: #111; color: #ccc; text-align: center; padding: 2rem; }
        h1 { color: #fff; }
        button { font-size: 1.2rem; padding: 0.5rem 2rem; margin-top: 1rem; cursor: pointer; }
        #result { font-size: 2rem; margin: 1rem 0; min-height: 3rem; }
        #balance { color: #aaa; }
        .win  { color: #4f4; }
        .lose { color: #f44; }
    </style>
</head>
<body>
    <h1>Coin Flip</h1>
    <p>Cost: 1 credit per flip. Win 2 credits on heads.</p>
    <p id="balance">Balance: <?= htmlspecialchars((string)$balance) ?> credits</p>
    <div id="result"></div>
    <button id="flip-btn">Flip!</button>

    <script>
    document.getElementById('flip-btn').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;

        fetch('?action=flip')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    document.getElementById('result').textContent = data.error;
                    document.getElementById('result').className = 'lose';
                } else {
                    var el = document.getElementById('result');
                    el.textContent = data.result.toUpperCase() + (data.won ? ' — You win!' : ' — You lose.');
                    el.className = data.won ? 'win' : 'lose';
                    document.getElementById('balance').textContent =
                        'Balance: ' + data.balance + ' credits';
                }
            })
            .catch(function () {
                document.getElementById('result').textContent = 'Error — try again.';
            })
            .finally(function () {
                btn.disabled = false;
            });
    });
    </script>
</body>
</html>
```

A few things to note:

- `\WebDoorSDK\requireAuth()` handles authentication. If the user is not logged in, it returns a 401 and exits — you never need to check auth manually.
- `\BinktermPHP\UserCredit::debit()` returns `false` if the user has insufficient credits or credits are disabled. Always check the return value before awarding a win.
- Credit operations happen server-side only. The JavaScript never requests a credit change directly — it requests a game action (`?action=flip`) and the server decides whether credits are involved.
- `htmlspecialchars()` around any user-derived output is mandatory.

---

## Step 4: Write the Game API

The entry point above doubles as the API (via `?action=flip`). For a more complex game you might split the PHP into separate files:

```
public_html/webdoors/coinflip/
├── webdoor.json
├── index.php        # HTML UI
└── api.php          # JSON API endpoints
```

The URL for `api.php` would be `/games/coinflip/api.php`. WebDoor files are served directly — there is no WebDoor-specific routing layer.

---

## Step 5: Enable in Admin

1. Log in as an admin and go to **Admin → WebDoors**.
2. Click **Refresh** to scan for new doors — `coinflip` should appear.
3. Toggle it to **Enabled**.
4. Save.

The door is now live at `/games/coinflip` for all logged-in users.

---

## Step 6: Test It

1. Log in as a regular user (not admin) and navigate to `/games`.
2. Click **Coin Flip**.
3. Verify that credits are deducted on each flip and awarded on a win.
4. Try flipping with zero credits — you should see the "Not enough credits" message and no credit change should occur.

To watch credit transactions as they happen:

```sql
SELECT * FROM user_transactions WHERE user_id = <your_user_id> ORDER BY id DESC LIMIT 10;
```

---

## Going Further

**Add an icon** — Create `public_html/webdoors/coinflip/icon.svg`. The games list uses it as the door thumbnail.

**Leaderboard** — Use the BBS leaderboard API to submit a score. See `WebDoors.md` for the endpoint.

**Config overrides** — Add a `config` section to `webdoor.json` to expose sysop-configurable options (e.g. cost per flip). Read them with `\WebDoorSDK\getDoorConfig('coinflip')`.

**Persistent state** — Use the BBS storage API to save per-user game data (win/loss record, lifetime flips, etc.). See `WebDoors.md` for the storage API reference.

**Custom credits symbol** — Read it from the BBS config with `\BinktermPHP\UserCredit::getConfig()['symbol']` and display it instead of the word "credits".
