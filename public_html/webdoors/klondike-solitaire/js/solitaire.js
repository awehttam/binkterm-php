const suits = ["♠","♥","♦","♣"];
const ranks = ["A","2","3","4","5","6","7","8","9","10","J","Q","K"];
const redSuits = new Set(["♥","♦"]);

let state = null;

/**
 * selection:
 *  - { source: "waste", index: number }  (always top card)
 *  - { source: "tableau", pile: number, index: number } (stack from index..end)
 */
let selection = null;

// Undo stack stores previous state snapshots (cheap + safe for reference app)
let undoStack = [];

// UI prefs (persist in memory only; you can store in save_state too if you want)
let prefs = {
    drawCount: 1,         // 1 or 3
    autoFoundation: true  // if true, after each valid move try to push obvious cards
};

function deepClone(obj) {
    return JSON.parse(JSON.stringify(obj));
}

function pushUndo() {
    undoStack.push(deepClone({ state, selection, prefs }));
    if (undoStack.length > 200) undoStack.shift();
}

function popUndo() {
    const prev = undoStack.pop();
    if (!prev) return false;
    state = prev.state;
    selection = prev.selection;
    prefs = prev.prefs;
    return true;
}

function setStatus(msg = "") {
    const el = document.getElementById("status");
    if (!el) return;
    el.textContent = msg;
}

function showWin(show) {
    const el = document.getElementById("win");
    if (!el) return;
    el.classList.toggle("hidden", !show);
}

function cardValue(r) {
    return ranks.indexOf(r);
}

function isRed(card) {
    return redSuits.has(card.s);
}

function newDeck() {
    let deck = [];
    for (const s of suits)
        for (const r of ranks)
            deck.push({ s, r, face: false });
    return deck.sort(() => Math.random() - 0.5);
}

function deal() {
    state = {
        stock: newDeck(),
        waste: [],
        foundations: [[],[],[],[]],
        tableau: [[],[],[],[],[],[],[]]
    };

    for (let i = 0; i < 7; i++) {
        for (let j = 0; j <= i; j++) {
            const c = state.stock.pop();
            c.face = (j === i);
            state.tableau[i].push(c);
        }
    }

    selection = null;
    undoStack = [];
    setStatus("");
    showWin(false);
}

function canStack(card, target) {
    // tableau descending, alternating colors
    return (
        cardValue(card.r) === cardValue(target.r) - 1 &&
        isRed(card) !== isRed(target)
    );
}

function canPlaceOnEmptyTableau(card) {
    // Klondike: only King can go on empty tableau
    return card.r === "K";
}

function canFoundation(card, pile) {
    // foundations ascending by suit
    if (!pile.length) return card.r === "A";
    const top = pile[pile.length - 1];
    return card.s === top.s && cardValue(card.r) === cardValue(top.r) + 1;
}

function revealTopIfNeeded(pile) {
    if (!pile.length) return;
    const top = pile[pile.length - 1];
    if (!top.face) top.face = true;
}

function isWon() {
    return state.foundations.every(f => f.length === 13);
}

function shakeElement(el) {
    if (!el) return;
    el.classList.remove("shake");
    // force reflow
    void el.offsetWidth;
    el.classList.add("shake");
}

function getSelectedCards() {
    if (!selection) return null;

    if (selection.source === "waste") {
        if (!state.waste.length) return null;
        const card = state.waste[state.waste.length - 1];
        return { cards: [card], from: "waste" };
    }

    if (selection.source === "tableau") {
        const pile = state.tableau[selection.pile];
        const cards = pile.slice(selection.index);
        return { cards, from: "tableau" };
    }

    return null;
}

function clearSelection() {
    selection = null;
}

function drawFromStock() {
    pushUndo();

    if (!state.stock.length) {
        // recycle waste -> stock
        state.stock = state.waste.map(c => ({ ...c, face: false })).reverse();
        state.waste = [];
        clearSelection();
        setStatus("Recycled waste back into stock.");
        render();
        return;
    }

    const n = Math.min(prefs.drawCount, state.stock.length);
    for (let i = 0; i < n; i++) {
        const c = state.stock.pop();
        c.face = true;
        state.waste.push(c);
    }

    clearSelection();
    setStatus(`Drew ${n} card${n === 1 ? "" : "s"}.`);
    render();
}

function selectFromWaste() {
    if (!state.waste.length) return;
    const card = state.waste[state.waste.length - 1];
    if (!card.face) return;

    selection = { source: "waste", index: state.waste.length - 1 };
    setStatus(`Selected ${card.r}${card.s} from waste.`);
    render();
}

function selectFromTableau(pileIndex, cardIndex) {
    const pile = state.tableau[pileIndex];
    const card = pile[cardIndex];
    if (!card || !card.face) return;

    selection = { source: "tableau", pile: pileIndex, index: cardIndex };
    setStatus(`Selected ${pile.slice(cardIndex).length} card(s) from tableau.`);
    render();
}

function moveSelectionToFoundation(fIndex, foundationElForShake = null) {
    if (!selection) return false;

    const picked = getSelectedCards();
    if (!picked || picked.cards.length !== 1) {
        // only a single card can go to foundation
        shakeElement(foundationElForShake);
        setStatus("Only a single card can move to a foundation.");
        return false;
    }

    const card = picked.cards[0];
    const target = state.foundations[fIndex];

    if (!canFoundation(card, target)) {
        shakeElement(foundationElForShake);
        setStatus("Invalid foundation move.");
        return false;
    }

    pushUndo();

    // remove from source
    if (selection.source === "waste") {
        state.waste.pop();
    } else {
        const src = state.tableau[selection.pile];
        src.pop();
        revealTopIfNeeded(src);
    }

    target.push(card);
    clearSelection();

    setStatus(`Moved ${card.r}${card.s} to foundation.`);
    if (prefs.autoFoundation) autoFoundationSweep();
    render();
    return true;
}

function moveSelectionToTableau(tIndex, tableauElForShake = null) {
    if (!selection) return false;

    const picked = getSelectedCards();
    if (!picked || !picked.cards.length) return false;

    const moving = picked.cards;
    const target = state.tableau[tIndex];
    const first = moving[0];

    const ok =
        (target.length === 0 && canPlaceOnEmptyTableau(first)) ||
        (target.length > 0 && canStack(first, target[target.length - 1]));

    if (!ok) {
        shakeElement(tableauElForShake);
        setStatus("Invalid tableau move.");
        return false;
    }

    pushUndo();

    // remove from source
    if (selection.source === "waste") {
        state.waste.pop();
    } else {
        const src = state.tableau[selection.pile];
        src.splice(selection.index); // remove moving stack
        revealTopIfNeeded(src);
    }

    target.push(...moving);
    clearSelection();

    setStatus(`Moved ${moving.length} card(s) to tableau.`);
    if (prefs.autoFoundation) autoFoundationSweep();
    render();
    return true;
}

function autoFoundationSweep() {
    // Keep trying to move any obvious single-card moves to foundation:
    // - top waste card
    // - top tableau cards
    // until no changes
    let changed = true;
    let safety = 200;

    while (changed && safety-- > 0) {
        changed = false;

        // waste top
        if (state.waste.length) {
            const c = state.waste[state.waste.length - 1];
            for (let i = 0; i < 4; i++) {
                if (canFoundation(c, state.foundations[i])) {
                    state.waste.pop();
                    state.foundations[i].push(c);
                    changed = true;
                    break;
                }
            }
        }

        // tableau tops
        for (let t = 0; t < 7; t++) {
            const pile = state.tableau[t];
            if (!pile.length) continue;
            const c = pile[pile.length - 1];
            if (!c.face) continue;

            for (let f = 0; f < 4; f++) {
                if (canFoundation(c, state.foundations[f])) {
                    pile.pop();
                    state.foundations[f].push(c);
                    revealTopIfNeeded(pile);
                    changed = true;
                    break;
                }
            }
        }
    }
}

function autoMoveAllOnce() {
    // One button that tries a useful auto-sweep:
    // 1) Foundation sweep (safe-ish)
    // 2) If stock empty, recycle (optional) — we won’t do that automatically
    pushUndo();
    autoFoundationSweep();
    clearSelection();
    setStatus("Auto-moved eligible cards to foundations.");
    render();
}

function checkWinAndUpdate() {
    showWin(isWon());
}

function render() {
    const board = document.getElementById("board");
    board.innerHTML = "";

    const top = document.createElement("div");
    top.className = "row";

    // stock
    const stock = document.createElement("div");
    stock.className = "pile clickable";
    stock.title = "Stock (click to draw)";
    stock.onclick = drawFromStock;
    top.appendChild(stock);

    // waste (show last up to 3 for draw3 visibility)
    const wasteWrap = document.createElement("div");
    wasteWrap.className = "pile clickable";
    wasteWrap.title = "Waste (click top card to select)";
    wasteWrap.onclick = (e) => {
        selectFromWaste();
        e.stopPropagation();
    };

    const wasteToShow = state.waste.slice(-Math.max(1, prefs.drawCount));
    wasteToShow.forEach((c, i) => {
        const card = document.createElement("div");
        card.className = "card";
        if (isRed(c)) card.classList.add("red"); // ✅ NEW

        card.textContent = `${c.r}${c.s}`;
        card.style.top = `${0}px`;
        card.style.left = `${i * 14}px`; // slight fanning

        const isTop = (i === wasteToShow.length - 1);
        if (isTop && selection && selection.source === "waste") card.classList.add("selected");

        wasteWrap.appendChild(card);
    });

    top.appendChild(wasteWrap);

    // foundations
    state.foundations.forEach((f, i) => {
        const pileEl = document.createElement("div");
        pileEl.className = "pile clickable";
        pileEl.title = `Foundation ${i + 1} (click to move selected card here)`;
        pileEl.onclick = () => moveSelectionToFoundation(i, pileEl);

        const topCard = f.length ? f[f.length - 1] : null;
        if (topCard) {
            const card = document.createElement("div");
            card.className = "card";
            if (isRed(topCard)) card.classList.add("red"); // ✅ NEW

            card.textContent = `${topCard.r}${topCard.s}`;
            pileEl.appendChild(card);
        }

        top.appendChild(pileEl);
    });

    board.appendChild(top);

    // tableau
    const bottom = document.createElement("div");
    bottom.className = "row";

    state.tableau.forEach((t, tIndex) => {
        const col = document.createElement("div");
        col.className = "tableau clickable";
        col.title = "Tableau (click a face-up card to select, or click column to place selection)";

        col.onclick = () => {
            if (selection) moveSelectionToTableau(tIndex, col);
        };

        t.forEach((c, cIndex) => {
            const card = document.createElement("div");
            card.className = "card " + (c.face ? "" : "back");

            if (c.face && isRed(c)) card.classList.add("red"); // ✅ NEW

            card.textContent = c.face ? `${c.r}${c.s}` : "";
            card.style.top = `${cIndex * 22}px`;

            const isSelected =
                selection &&
                selection.source === "tableau" &&
                selection.pile === tIndex &&
                selection.index === cIndex;

            if (isSelected) card.classList.add("selected");

            card.onclick = (e) => {
                e.stopPropagation();
                if (!c.face) return;
                selectFromTableau(tIndex, cIndex);
            };

            col.appendChild(card);
        });

        bottom.appendChild(col);
    });

    board.appendChild(bottom);

    checkWinAndUpdate();
}


async function start() {
    await WebDoor.load();

    const saved = await WebDoor.loadSave();
    if (saved) {
        state = saved.state || saved; // tolerate either shape
        // optional: load prefs if stored
        if (saved.prefs) prefs = saved.prefs;
    } else {
        deal();
    }

    // wire controls
    const draw3 = document.getElementById("draw3");
    const autoFoundation = document.getElementById("autoFoundation");

    draw3.checked = (prefs.drawCount === 3);
    autoFoundation.checked = !!prefs.autoFoundation;

    draw3.onchange = () => {
        prefs.drawCount = draw3.checked ? 3 : 1;
        setStatus(`Draw mode: ${prefs.drawCount}.`);
        render();
    };

    autoFoundation.onchange = () => {
        prefs.autoFoundation = autoFoundation.checked;
        setStatus(`Auto to foundation: ${prefs.autoFoundation ? "on" : "off"}.`);
        render();
    };

    document.getElementById("new").onclick = async () => {
        await WebDoor.deleteSave();
        deal();
        render();
    };

    document.getElementById("save").onclick = () => {
        // store prefs in save if you want; host should treat this as opaque JSON
        WebDoor.save({ state, prefs });
        setStatus("Saved.");
    };

    document.getElementById("undo").onclick = () => {
        if (popUndo()) {
            setStatus("Undid last action.");
            render();
        } else {
            setStatus("Nothing to undo.");
        }
    };

    document.getElementById("autoMove").onclick = () => {
        autoMoveAllOnce();
    };

    render();
}

start();
