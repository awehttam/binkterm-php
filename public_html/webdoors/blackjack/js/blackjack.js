const suitSymbols = {
  S: "♠",
  H: "♥",
  D: "♦",
  C: "♣"
};
const redSuits = new Set(["H", "D"]);

const SAVE_SLOT = 0;
const BOARD_ID = "blackjack_bankroll";

let sessionInfo = null;
let lastRoundProcessed = 0;

let state = {
  bankroll: 0,
  symbol: "$",
  player: [],
  dealer: [],
  bet: 10,
  inRound: false,
  revealDealer: false,
  lastMessage: "Click Deal to start.",
  handsPlayed: 0,
  handsWon: 0,
  handsLost: 0,
  bestBankroll: 0,
  roundId: 0,
  lastOutcome: null
};

function setStatus(msg) {
  state.lastMessage = msg;
  const el = document.getElementById("status");
  if (el) el.textContent = msg;
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
      const suit = suitSymbols[c.s] || c.s;
      div.textContent = isHidden ? "" : (c.r + suit);
      el.appendChild(div);
    });
  };

  renderHand(document.getElementById("dealer"), state.dealer, state.inRound && !state.revealDealer);
  renderHand(document.getElementById("player"), state.player, false);

  document.getElementById("bankroll").textContent = `Bankroll: ${state.symbol}${state.bankroll}`;

  const pv = state.player.length ? handValue(state.player) : 0;
  const dv = state.dealer.length ? handValue(state.dealer) : 0;

  document.getElementById("playerValue").textContent = state.player.length ? `(${pv})` : "";
  document.getElementById("dealerValue").textContent =
    state.dealer.length ? ((state.inRound && !state.revealDealer) ? "" : `(${dv})`) : "";

  if (state.lastMessage) {
    setStatus(state.lastMessage);
  }
  updateButtons();
}

async function autosaveAndMaybeScore(outcome) {
  const metadata = {
    save_name: "Auto-save",
    handsPlayed: state.handsPlayed,
    bestBankroll: state.bestBankroll
  };

  try {
    await WebDoor.saveSlot(SAVE_SLOT, {
      handsPlayed: state.handsPlayed,
      handsWon: state.handsWon,
      handsLost: state.handsLost,
      bestBankroll: state.bestBankroll
    }, metadata);
  } catch (e) {}

  try {
    await WebDoor.submitScore(BOARD_ID, state.bestBankroll, {
      outcome,
      bankroll: state.bankroll,
      handsPlayed: state.handsPlayed
    });
  } catch (e) {}
}

async function apiAction(action, payload = {}) {
  const r = await fetch("index.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ action, ...payload })
  });
  let data = {};
  try {
    data = await r.json();
  } catch (e) {}
  if (!r.ok && !data.error) {
    data.error = `Request failed: HTTP ${r.status}`;
  }
  return data;
}

function applyServerState(data) {
  if (!data || !data.state) return;
  state = { ...state, ...data.state };
  if (Number.isFinite(data.balance)) state.bankroll = data.balance;
  if (data.symbol) state.symbol = data.symbol;
  if (data.error) {
    setStatus(data.error);
  } else if (state.lastMessage) {
    setStatus(state.lastMessage);
  }

  const betInput = document.getElementById("bet");
  if (betInput && Number.isFinite(state.bet)) {
    betInput.value = state.bet;
  }

  render();

  if (state.lastOutcome && state.roundId > lastRoundProcessed) {
    lastRoundProcessed = state.roundId;
    autosaveAndMaybeScore(state.lastOutcome);
    refreshLeaderboardBestEffort();
  }
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
      li.textContent = `${name} - ${state.symbol}${e.score}`;
      ul.appendChild(li);
    });
  } catch (e) {}
}

document.getElementById("deal").onclick = async () => {
  const betInput = document.getElementById("bet");
  const bet = parseInt(betInput.value, 10);
  if (!canAffordBet(bet)) {
    setStatus(`Invalid bet. It must be between ${state.symbol}1 and your bankroll.`);
    render();
    return;
  }

  const data = await apiAction("deal", { bet });
  applyServerState(data);
};

document.getElementById("hit").onclick = async () => {
  const data = await apiAction("hit");
  applyServerState(data);
};

document.getElementById("stand").onclick = async () => {
  const data = await apiAction("stand");
  applyServerState(data);
};

document.getElementById("save").onclick = async () => {
  try {
    await WebDoor.saveSlot(SAVE_SLOT, {
      handsPlayed: state.handsPlayed,
      handsWon: state.handsWon,
      handsLost: state.handsLost,
      bestBankroll: state.bestBankroll
    }, { save_name: "Manual save" });
    setStatus("Saved.");
  } catch (e) {
    setStatus("Save failed.");
  }
  render();
};

(async () => {
  try { sessionInfo = await WebDoor.session(); } catch (e) {}
  const data = await apiAction("init");
  applyServerState(data);
})();
