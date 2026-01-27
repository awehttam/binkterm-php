
const suits = ["♠","♥","♦","♣"];
const ranks = ["A","2","3","4","5","6","7","8","9","10","J","Q","K"];
const redSuits = new Set(["♥","♦"]);

const SAVE_SLOT = 0;
const BOARD_ID = "blackjack_bankroll";

let sessionInfo = null;

let state = {
  bankroll: 1000,
  deck: [],
  player: [],
  dealer: [],
  bet: 50,
  inRound: false,
  revealDealer: false,
  lastMessage: "Click Deal to start.",
  handsPlayed: 0,
  handsWon: 0,
  handsLost: 0,
  bestBankroll: 1000
};

function setStatus(msg) {
  state.lastMessage = msg;
  const el = document.getElementById("status");
  if (el) el.textContent = msg;
}

function newDeck() {
  let d = [];
  for (let s of suits) for (let r of ranks) d.push({ s, r });
  for (let i = d.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [d[i], d[j]] = [d[j], d[i]];
  }
  return d;
}

function handValue(hand) {
  let total = 0, aces = 0;
  for (let c of hand) {
    if (c.r === "A") { aces++; total += 11; }
    else if (c.r === "K" || c.r === "Q" || c.r === "J") total += 10;
    else total += parseInt(c.r, 10);
  }
  while (total > 21 && aces > 0) { total -= 10; aces--; }
  return total;
}

function draw(hand) {
  if (!state.deck.length) state.deck = newDeck();
  hand.push(state.deck.pop());
}

function canAffordBet(bet) {
  return Number.isFinite(bet) && bet > 0 && bet <= state.bankroll;
}

function updateButtons() {
  const hit = document.getElementById("hit");
  const stand = document.getElementById("stand");
  const deal = document.getElementById("deal");
  if (hit) hit.disabled = !state.inRound;
  if (stand) stand.disabled = !state.inRound;
  if (deal) deal.disabled = state.inRound;
}

function render() {
  const renderHand = (el, hand, hideSecondCard=false) => {
    el.innerHTML = "";
    hand.forEach((c, idx) => {
      const div = document.createElement("div");
      const isHidden = hideSecondCard && idx === 1;
      div.className = "card" + (isHidden ? " back" : "");
      if (!isHidden && redSuits.has(c.s)) div.classList.add("red");
      div.textContent = isHidden ? "" : (c.r + c.s);
      el.appendChild(div);
    });
  };

  renderHand(document.getElementById("dealer"), state.dealer, state.inRound && !state.revealDealer);
  renderHand(document.getElementById("player"), state.player, false);

  document.getElementById("bankroll").textContent = "Bankroll: $" + state.bankroll;

  const pv = state.player.length ? handValue(state.player) : 0;
  const dv = state.dealer.length ? handValue(state.dealer) : 0;

  document.getElementById("playerValue").textContent = state.player.length ? `(${pv})` : "";
  document.getElementById("dealerValue").textContent =
    state.dealer.length ? ((state.inRound && !state.revealDealer) ? "" : `(${dv})`) : "";

  setStatus(state.lastMessage);
  updateButtons();
}

function payout(outcome) {
  const bet = state.bet;
  if (outcome === "push") return 0;
  if (outcome === "lose") return -bet;
  if (outcome === "win") return bet;
  if (outcome === "blackjack") return Math.floor(bet * 1.5);
  return 0;
}

async function autosaveAndMaybeScore(outcome) {
  // Save to storage slot 0 per spec
  const metadata = {
    save_name: "Auto-save",
    handsPlayed: state.handsPlayed,
    bestBankroll: state.bestBankroll
  };

  try {
    await WebDoor.saveSlot(SAVE_SLOT, state, metadata);
  } catch (e) {
    // keep playing even if save fails
  }

  // Submit leaderboard score as "best bankroll achieved"
  // Only submit when a round ends.
  try {
    await WebDoor.submitScore(BOARD_ID, state.bestBankroll, {
      outcome,
      bankroll: state.bankroll,
      handsPlayed: state.handsPlayed
    });
  } catch (e) {
    // leaderboard may not be implemented yet; ignore
  }
}

async function endRound(outcome) {
  state.revealDealer = true;
  state.inRound = false;

  state.handsPlayed += 1;
  if (outcome === "win" || outcome === "blackjack") state.handsWon += 1;
  if (outcome === "lose") state.handsLost += 1;

  const delta = payout(outcome);
  state.bankroll += delta;
  state.bestBankroll = Math.max(state.bestBankroll, state.bankroll);

  const pv = handValue(state.player);
  const dv = handValue(state.dealer);

  let msg = "";
  if (outcome === "blackjack") msg = `Blackjack! You win $${delta}. (You: ${pv}, Dealer: ${dv})`;
  else if (outcome === "win") msg = `You win $${delta}. (You: ${pv}, Dealer: ${dv})`;
  else if (outcome === "lose") msg = `Dealer wins. You lose $${-delta}. (You: ${pv}, Dealer: ${dv})`;
  else msg = `Push. No change. (You: ${pv}, Dealer: ${dv})`;

  setStatus(msg);
  render();
  await autosaveAndMaybeScore(outcome);
  await refreshLeaderboardBestEffort();
}

function startRound() {
  const betInput = document.getElementById("bet");
  const bet = parseInt(betInput.value, 10);
  if (!canAffordBet(bet)) {
    setStatus("Invalid bet. It must be between $1 and your bankroll.");
    render();
    return;
  }

  state.bet = bet;
  state.player = [];
  state.dealer = [];
  state.revealDealer = false;
  state.inRound = true;

  if (state.deck.length < 15) state.deck = newDeck();

  draw(state.player);
  draw(state.dealer);
  draw(state.player);
  draw(state.dealer);

  const pv = handValue(state.player);
  const dv = handValue(state.dealer);

  const playerBJ = (pv === 21 && state.player.length === 2);
  const dealerBJ = (dv === 21 && state.dealer.length === 2);

  if (playerBJ && dealerBJ) { endRound("push"); return; }
  if (playerBJ) { endRound("blackjack"); return; }
  if (dealerBJ) { endRound("lose"); return; }

  setStatus("Round started. Hit or Stand.");
  render();
}

function playerHit() {
  if (!state.inRound) return;
  draw(state.player);
  const pv = handValue(state.player);
  if (pv > 21) { endRound("lose"); return; }
  setStatus(`Hit. Your total is ${pv}.`);
  render();
}

function dealerPlay() {
  while (handValue(state.dealer) < 17) draw(state.dealer);
}

function playerStand() {
  if (!state.inRound) return;
  dealerPlay();

  const pv = handValue(state.player);
  const dv = handValue(state.dealer);

  if (dv > 21) endRound("win");
  else if (pv > dv) endRound("win");
  else if (pv < dv) endRound("lose");
  else endRound("push");
}

async function refreshLeaderboardBestEffort() {
  try {
    const lb = await WebDoor.getLeaderboard(BOARD_ID, 10, "all");
    if (!lb || !Array.isArray(lb.entries)) return;

    const ul = document.getElementById("leaderboard");
    ul.innerHTML = "";

    lb.entries.forEach((e) => {
      const li = document.createElement("li");
      const name = e.display_name ?? e.user ?? e.name ?? "Player";
      li.textContent = `${name} — $${e.score}`;
      ul.appendChild(li);
    });
  } catch (e) {
    // ignore
  }
}

document.getElementById("deal").onclick = startRound;
document.getElementById("hit").onclick = playerHit;
document.getElementById("stand").onclick = playerStand;
document.getElementById("save").onclick = async () => {
  try {
    await WebDoor.saveSlot(SAVE_SLOT, state, { save_name: "Manual save" });
    setStatus("Saved.");
  } catch (e) {
    setStatus("Save failed.");
  }
  render();
};

(async () => {
  // session call is part of spec; do it first so the host can validate/auth
  try { sessionInfo = await WebDoor.session(); } catch (e) {}

  // load autosave slot 0 if present
  try {
    const loaded = await WebDoor.loadSlot(SAVE_SLOT);
    if (loaded && loaded.data) state = loaded.data;
  } catch (e) {}

  render();
  await refreshLeaderboardBestEffort();
})();
