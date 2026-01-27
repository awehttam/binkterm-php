// -------------------- External dictionary loading --------------------
const WORDS_JSON_URL = "words.json"; // relative to hangman/ folder
const MAX_WRONG = 6;

// Fallback minimal list (used only if words.json fails to load)
const FALLBACK_WORDS = {
    easy: [
        { word: "APPLE", category: "Food" },
        { word: "HOUSE", category: "General" },
        { word: "RIVER", category: "Nature" }
    ],
    normal: [
        { word: "JAVASCRIPT", category: "Tech" },
        { word: "VANCOUVER", category: "Places" },
        { word: "TELESCOPE", category: "Science" }
    ],
    hard: [
        { word: "INTEROPERABILITY", category: "Tech" },
        { word: "THERMODYNAMICS", category: "Science" },
        { word: "JURISPRUDENCE", category: "Law" }
    ]
};

// Loaded dictionary in this normalized form:
// { easy: [{word,category}...], normal: [...], hard: [...] }
let DICTIONARY = null;

function normalizeWord(raw) {
    // Keep A-Z only (optionally allow hyphens/spaces if you want later)
    const cleaned = String(raw).toUpperCase().replace(/[^A-Z]/g, "");
    return cleaned.length ? cleaned : null;
}

function difficultyForWord(word) {
    const n = word.length;
    if (n <= 6) return "easy";
    if (n <= 9) return "normal";
    return "hard";
}

async function loadDictionary() {
    try {
        const res = await fetch(WORDS_JSON_URL, { cache: "no-store" });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();

        // Expect: { version: 1, categories: { "Cat": ["WORD", ...], ... } }
        const categories = data?.categories;
        if (!categories || typeof categories !== "object") throw new Error("Invalid words.json format");

        const dict = { easy: [], normal: [], hard: [] };

        for (const [category, words] of Object.entries(categories)) {
            if (!Array.isArray(words)) continue;

            for (const raw of words) {
                const w = normalizeWord(raw);
                if (!w) continue;

                const diff = difficultyForWord(w);
                dict[diff].push({ word: w, category });
            }
        }

        // Must have at least a little content
        const total = dict.easy.length + dict.normal.length + dict.hard.length;
        if (total < 20) throw new Error("Dictionary too small (need more words)");

        DICTIONARY = dict;
        return true;
    } catch (err) {
        console.warn("Failed to load words.json, using fallback list:", err);
        DICTIONARY = FALLBACK_WORDS;
        return false;
    }
}

// -------------------- WebDoor API wrapper assumed in js/webdoor.js --------------------
// WebDoor.load(), WebDoor.loadSave(), WebDoor.save({state}), WebDoor.deleteSave()

// -------------------- Game state --------------------
let state = null;
// {
//   difficulty: "easy"|"normal"|"hard",
//   target: { word: "STRING", category: "STRING" },
//   guessed: ["A","B"],
//   wrong: 0,
//   startedAt: epoch_ms,
//   finished: null | { outcome: "win"|"lose"|"forfeit", endedAt: epoch_ms }
// }

function setStatus(msg = "") {
    const el = document.getElementById("status");
    if (el) el.textContent = msg;
}

function setResult(text, show) {
    const el = document.getElementById("result");
    if (!el) return;
    el.textContent = text;
    el.classList.toggle("hidden", !show);
}

function randomPick(arr) {
    return arr[Math.floor(Math.random() * arr.length)];
}

function newGame(difficulty) {
    const pool = DICTIONARY?.[difficulty] || FALLBACK_WORDS[difficulty];
    const target = randomPick(pool);
    state = {
        difficulty,
        target,
        guessed: [],
        wrong: 0,
        startedAt: Date.now(),
        finished: null
    };
}

function normalizeLetter(ch) {
    const up = ch.toUpperCase();
    return /^[A-Z]$/.test(up) ? up : null;
}

function getMaskedWord() {
    const w = state.target.word;
    const guessed = new Set(state.guessed);
    return [...w].map(c => (guessed.has(c) ? c : "_")).join(" ");
}

function isWin() {
    return !getMaskedWord().includes("_");
}

function isOver() {
    return !!state.finished || state.wrong >= MAX_WRONG || isWin();
}

function finish(outcome) {
    state.finished = { outcome, endedAt: Date.now() };
}

function autoSave() {
    // Save opaque JSON: include everything we need
    WebDoor.save({ state }).catch(() => {});
}

function addGuess(letter) {
    if (!letter || isOver()) return;

    if (state.guessed.includes(letter)) {
        setStatus(`You already guessed ${letter}.`);
        return;
    }

    state.guessed.push(letter);

    if (!state.target.word.includes(letter)) {
        state.wrong += 1;
        setStatus(`${letter} is not in the word.`);
    } else {
        setStatus(`Nice — ${letter} is in the word.`);
    }

    if (isWin()) {
        finish("win");
        setResult("✅ You win!", true);
        setStatus(`Solved: ${state.target.word}`);
    } else if (state.wrong >= MAX_WRONG) {
        finish("lose");
        setResult("💀 Game over.", true);
        setStatus(`The word was: ${state.target.word}`);
    }

    render();
    autoSave();
}

function hint() {
    if (isOver()) return;

    const w = state.target.word;
    const guessed = new Set(state.guessed);
    const remaining = [...new Set([...w].filter(c => !guessed.has(c)))];

    if (!remaining.length) {
        setStatus("No hints available.");
        return;
    }

    // Cost 1 wrong guess
    state.wrong = Math.min(MAX_WRONG, state.wrong + 1);
    const reveal = randomPick(remaining);
    state.guessed.push(reveal);

    setStatus(`Hint revealed: ${reveal} (cost 1 wrong guess).`);

    if (isWin()) {
        finish("win");
        setResult("✅ You win!", true);
        setStatus(`Solved: ${state.target.word}`);
    } else if (state.wrong >= MAX_WRONG) {
        finish("lose");
        setResult("💀 Game over.", true);
        setStatus(`The word was: ${state.target.word}`);
    }

    render();
    autoSave();
}

function forfeit() {
    if (isOver()) return;

    finish("forfeit");
    setResult("🏳️ Forfeit.", true);
    setStatus(`The word was: ${state.target.word}`);
    render();
    autoSave();
}

// -------------------- Rendering --------------------
function drawHangman() {
    const c = document.getElementById("gallows");
    const ctx = c.getContext("2d");
    ctx.clearRect(0, 0, c.width, c.height);

    ctx.lineWidth = 6;
    ctx.strokeStyle = "rgba(231,238,252,0.9)";

    // gallows
    ctx.beginPath();
    ctx.moveTo(40, 290); ctx.lineTo(160, 290);
    ctx.moveTo(90, 290); ctx.lineTo(90, 40);
    ctx.lineTo(230, 40);
    ctx.lineTo(230, 80);
    ctx.stroke();

    const w = state.wrong;

    if (w >= 1) { ctx.beginPath(); ctx.arc(230, 105, 25, 0, Math.PI * 2); ctx.stroke(); }
    if (w >= 2) { ctx.beginPath(); ctx.moveTo(230, 130); ctx.lineTo(230, 205); ctx.stroke(); }
    if (w >= 3) { ctx.beginPath(); ctx.moveTo(230, 150); ctx.lineTo(200, 175); ctx.stroke(); }
    if (w >= 4) { ctx.beginPath(); ctx.moveTo(230, 150); ctx.lineTo(260, 175); ctx.stroke(); }
    if (w >= 5) { ctx.beginPath(); ctx.moveTo(230, 205); ctx.lineTo(205, 245); ctx.stroke(); }
    if (w >= 6) { ctx.beginPath(); ctx.moveTo(230, 205); ctx.lineTo(255, 245); ctx.stroke(); }
}

function renderLetters() {
    const letters = document.getElementById("letters");
    letters.innerHTML = "";
    const guessed = new Set(state.guessed);

    for (let i = 65; i <= 90; i++) {
        const ch = String.fromCharCode(i);
        const b = document.createElement("button");
        b.textContent = ch;

        const used = guessed.has(ch) || isOver();
        if (used) b.classList.add("used");
        b.disabled = used;

        b.onclick = () => addGuess(ch);
        letters.appendChild(b);
    }
}

function render() {
    document.getElementById("word").textContent = getMaskedWord();
    document.getElementById("wrongCount").textContent = String(state.wrong);
    document.getElementById("maxWrong").textContent = String(MAX_WRONG);
    document.getElementById("category").textContent = state.target.category || "General";

    const guessedList = document.getElementById("guessedList");
    guessedList.innerHTML = "";
    state.guessed.forEach(ch => {
        const span = document.createElement("span");
        span.className = "badge";
        span.textContent = ch;
        guessedList.appendChild(span);
    });

    drawHangman();
    renderLetters();

    if (!state.finished) setResult("", false);
    else {
        const o = state.finished.outcome;
        setResult(o === "win" ? "✅ You win!" : o === "lose" ? "💀 Game over." : "🏳️ Forfeit.", true);
    }
}

// -------------------- Boot + events --------------------
async function start() {
    await WebDoor.load();

    // Load dictionary first (so new games have the big pool)
    const loaded = await loadDictionary();

    // Load saved state if present
    const saved = await WebDoor.loadSave();
    if (saved?.state) {
        state = saved.state;

        // If dictionary changed and word is missing category, keep playing anyway
        if (!state.target?.word) {
            newGame("normal");
        }
    } else {
        newGame("normal");
    }

    // Sync UI controls
    const difficulty = document.getElementById("difficulty");
    difficulty.value = state.difficulty || "normal";

    difficulty.onchange = () => {
        newGame(difficulty.value);
        setStatus(`New ${difficulty.value} game started. (${loaded ? "Large dictionary" : "Fallback list"})`);
        setResult("", false);
        render();
        autoSave();
    };

    document.getElementById("new").onclick = () => {
        newGame(difficulty.value);
        setStatus(`New game started. (${loaded ? "Large dictionary" : "Fallback list"})`);
        setResult("", false);
        render();
        autoSave();
    };

    document.getElementById("hint").onclick = hint;
    document.getElementById("forfeit").onclick = forfeit;

    document.getElementById("save").onclick = async () => {
        await WebDoor.save({ state });
        setStatus("Saved.");
    };

    // Keyboard input
    window.addEventListener("keydown", (e) => {
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        const letter = normalizeLetter(e.key);
        if (!letter) return;
        addGuess(letter);
    });

    render();
    setStatus(`Type a letter or click buttons to guess. (${loaded ? "Loaded words.json" : "Using fallback list"})`);
    autoSave();
}

start();
