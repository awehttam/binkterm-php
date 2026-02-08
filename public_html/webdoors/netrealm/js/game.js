/**
 * NetRealm RPG - SPA Controller
 *
 * Manages all views, API calls, and game state for the NetRealm RPG WebDoor.
 */
(function () {
    "use strict";

    // === State ===
    let character = null;
    let gameConfig = {};
    let currentView = "loading";

    // === Rarity Colors ===
    const RARITY_COLORS = {
        common: "#9d9d9d",
        uncommon: "#1eff00",
        rare: "#0070dd",
        epic: "#a335ee",
        legendary: "#ff8000"
    };

    // === API Layer ===
    async function api(action, payload = {}) {
        try {
            const r = await fetch("/webdoors/netrealm/index.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ action, ...payload })
            });
            let data = {};
            try {
                data = await r.json();
            } catch (e) {
                return { success: false, error: "Invalid response from server." };
            }
            if (!r.ok && !data.error) {
                data.error = "Request failed: HTTP " + r.status;
                data.success = false;
            }
            return data;
        } catch (e) {
            return { success: false, error: "Network error. Please try again." };
        }
    }

    // === View Management ===
    function showView(name) {
        document.querySelectorAll(".nr-view").forEach(v => v.style.display = "none");
        document.getElementById("view-loading").style.display = "none";
        const el = document.getElementById("view-" + name);
        if (el) {
            el.style.display = "block";
            currentView = name;
        }
    }

    // === Toast Notifications ===
    function toast(message, type = "info") {
        const container = document.getElementById("toast-container");
        const bgClass = {
            success: "bg-success",
            danger: "bg-danger",
            warning: "bg-warning text-dark",
            info: "bg-info text-dark"
        }[type] || "bg-secondary";

        const toastEl = document.createElement("div");
        toastEl.className = "toast show align-items-center text-white border-0 " + bgClass;
        toastEl.setAttribute("role", "alert");
        toastEl.innerHTML =
            '<div class="d-flex">' +
                '<div class="toast-body">' + escapeHtml(message) + '</div>' +
                '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
            '</div>';
        container.appendChild(toastEl);

        toastEl.querySelector(".btn-close").addEventListener("click", function () {
            toastEl.remove();
        });
        setTimeout(function () {
            if (toastEl.parentNode) toastEl.remove();
        }, 4000);
    }

    function escapeHtml(str) {
        const div = document.createElement("div");
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // === Status Bar Update ===
    function updateStatusBar(c) {
        if (!c) return;
        character = c;

        document.getElementById("char-name").textContent = c.name;
        document.getElementById("char-level").textContent = "Lv " + c.level + (c.level >= c.max_level ? " (MAX)" : "");

        // HP bar
        const hpPct = c.max_hp > 0 ? Math.round((c.hp / c.max_hp) * 100) : 0;
        const hpBar = document.getElementById("hp-bar");
        hpBar.style.width = hpPct + "%";
        hpBar.className = "progress-bar " + (hpPct > 50 ? "bg-success" : hpPct > 25 ? "bg-warning" : "bg-danger");
        document.getElementById("hp-text").textContent = c.hp + "/" + c.max_hp;

        // XP bar
        const xpPct = c.xp_next > 0 ? Math.round((c.xp / c.xp_next) * 100) : 100;
        document.getElementById("xp-bar").style.width = xpPct + "%";
        document.getElementById("xp-text").textContent = c.xp + "/" + c.xp_next;

        // Stats
        const atkText = c.attack_bonus > 0 ? c.attack + " (" + c.base_attack + "+" + c.attack_bonus + ")" : String(c.attack);
        const defText = c.defense_bonus > 0 ? c.defense + " (" + c.base_defense + "+" + c.defense_bonus + ")" : String(c.defense);
        document.getElementById("stat-attack").textContent = atkText;
        document.getElementById("stat-defense").textContent = defText;
        document.getElementById("stat-gold").textContent = c.gold;
        document.getElementById("stat-turns").textContent = c.turns;
        document.getElementById("stat-kills").textContent = c.monsters_killed;

        // Rest count
        const restRemaining = gameConfig.max_rest_uses_per_day - (c.rest_uses_today || 0);
        document.getElementById("rest-count").textContent = restRemaining + "/" + gameConfig.max_rest_uses_per_day;

        // Buy turns button
        const buyBtn = document.getElementById("btn-buy-turns");
        if (buyBtn) {
            buyBtn.style.display = gameConfig.credits_enabled ? "" : "none";
        }
    }

    // === Rarity Badge ===
    function rarityBadge(rarity) {
        const color = RARITY_COLORS[rarity] || "#9d9d9d";
        return '<span class="badge" style="background-color:' + color + '">' + capitalize(rarity) + '</span>';
    }

    function capitalize(s) {
        return s ? s.charAt(0).toUpperCase() + s.slice(1) : "";
    }

    // === Item Card HTML ===
    function itemCardHtml(item, actions) {
        const color = RARITY_COLORS[item.rarity] || "#9d9d9d";
        let bonuses = [];
        if (item.attack_bonus > 0) bonuses.push('<span class="text-danger">+' + item.attack_bonus + ' ATK</span>');
        if (item.defense_bonus > 0) bonuses.push('<span class="text-primary">+' + item.defense_bonus + ' DEF</span>');
        if (item.hp_bonus > 0) bonuses.push('<span class="text-success">+' + item.hp_bonus + ' HP</span>');

        let html = '<div class="col-12 col-sm-6 col-md-4">';
        html += '<div class="card nr-item-card" style="border-color:' + color + '">';
        html += '<div class="card-body p-2">';
        html += '<div class="d-flex justify-content-between align-items-start">';
        html += '<h6 class="mb-1" style="color:' + color + '">' + escapeHtml(item.item_name || item.name) + '</h6>';
        html += rarityBadge(item.rarity || "common");
        html += '</div>';
        html += '<div class="nr-item-type mb-1">' + capitalize(item.item_type || item.type) + '</div>';
        html += '<div class="nr-item-bonuses">' + (bonuses.length ? bonuses.join(" ") : '<span class="text-muted">No bonuses</span>') + '</div>';
        if (item.is_equipped) {
            html += ' <span class="badge bg-success">Equipped</span>';
        }
        if (actions) html += '<div class="mt-2">' + actions + '</div>';
        html += '</div></div></div>';
        return html;
    }

    // === Initialize Game ===
    async function init() {
        const data = await api("init");
        if (!data.success) {
            toast(data.error || "Failed to load game.", "danger");
            return;
        }

        gameConfig = data.config || {};

        if (data.character) {
            updateStatusBar(data.character);
            showView("town");
        } else {
            showView("create");
        }
    }

    // === Character Creation ===
    async function createCharacter() {
        const nameInput = document.getElementById("create-name");
        const name = nameInput.value.trim();
        const errorDiv = document.getElementById("create-error");
        errorDiv.style.display = "none";

        if (!name) {
            errorDiv.textContent = "Please enter a name.";
            errorDiv.style.display = "block";
            return;
        }

        const btn = document.getElementById("btn-create");
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Creating...';

        const data = await api("create", { name: name });
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-scroll"></i> Begin Adventure';

        if (!data.success) {
            errorDiv.textContent = data.error || "Creation failed.";
            errorDiv.style.display = "block";
            return;
        }

        updateStatusBar(data.character);
        showView("town");
        toast("Welcome to NetRealm, " + data.character.name + "!", "success");
    }

    // === Monster List (Hunt) ===
    async function loadMonsters() {
        showView("combat");
        const container = document.getElementById("monster-list");
        container.innerHTML = '<div class="col-12 text-center"><div class="spinner-border text-danger"></div></div>';

        const data = await api("monsters");
        if (!data.success) {
            container.innerHTML = '<div class="col-12"><div class="alert alert-danger">' + escapeHtml(data.error) + '</div></div>';
            return;
        }

        if (!data.monsters || data.monsters.length === 0) {
            container.innerHTML = '<div class="col-12"><div class="alert alert-info">No monsters available at your level.</div></div>';
            return;
        }

        let html = "";
        data.monsters.forEach(function (m) {
            html += '<div class="col-12 col-sm-6 col-md-4">';
            html += '<div class="card nr-monster-card">';
            html += '<div class="card-body p-2 text-center">';
            html += '<div class="nr-monster-icon"><i class="fas ' + (m.icon || 'fa-skull') + ' fa-2x"></i></div>';
            html += '<h6 class="mb-1">' + escapeHtml(m.name) + '</h6>';
            html += '<div class="small text-muted">Lv ' + m.min_level + '-' + m.max_level + '</div>';
            html += '<div class="nr-monster-stats">';
            html += '<span class="text-danger"><i class="fas fa-heart"></i> ' + m.hp + '</span> ';
            html += '<span class="text-warning"><i class="fas fa-fist-raised"></i> ' + m.attack + '</span> ';
            html += '<span class="text-primary"><i class="fas fa-shield"></i> ' + m.defense + '</span>';
            html += '</div>';
            html += '<div class="small text-success">' + m.xp_reward + ' XP / ' + m.gold_reward + ' Gold</div>';
            html += '<button class="btn btn-sm btn-danger mt-1 btn-fight" data-key="' + escapeHtml(m.key) + '">Fight</button>';
            html += '</div></div></div>';
        });
        container.innerHTML = html;
    }

    // === Fight Monster ===
    async function fightMonster(monsterKey) {
        // Disable all fight buttons
        document.querySelectorAll(".btn-fight").forEach(function (b) { b.disabled = true; });

        const data = await api("fight", { monster_key: monsterKey });
        if (!data.success) {
            toast(data.error || "Fight failed.", "danger");
            document.querySelectorAll(".btn-fight").forEach(function (b) { b.disabled = false; });
            return;
        }

        if (data.character) updateStatusBar(data.character);
        showCombatResult(data);
    }

    // === Combat Result Display ===
    function showCombatResult(data) {
        showView("combat-result");
        const container = document.getElementById("combat-result-content");

        const resultClass = data.victory ? "text-success" : "text-danger";
        const resultIcon = data.victory ? "fa-trophy" : "fa-skull-crossbones";
        const resultText = data.victory ? "Victory!" : "Defeat!";

        let html = '<div class="text-center mb-3">';
        html += '<h3 class="' + resultClass + '"><i class="fas ' + resultIcon + '"></i> ' + resultText + '</h3>';
        html += '<h5>vs ' + escapeHtml(data.monster_name || data.opponent_name || "Unknown") + '</h5>';
        html += '</div>';

        // Rewards
        if (data.victory) {
            html += '<div class="nr-rewards">';
            if (data.xp_gained > 0) html += '<span class="badge bg-info me-1">+' + data.xp_gained + ' XP</span>';
            if (data.gold_gained > 0) html += '<span class="badge bg-warning text-dark me-1">+' + data.gold_gained + ' Gold</span>';
            if (data.loot) {
                const lootColor = RARITY_COLORS[data.loot.rarity] || "#fff";
                html += '<span class="badge me-1" style="background-color:' + lootColor + '">Loot: ' + escapeHtml(data.loot.name) + '</span>';
            }
            if (data.gold_from_full_inventory) {
                html += '<div class="small text-warning mt-1"><i class="fas fa-exclamation-triangle"></i> Inventory full! ' +
                    escapeHtml(data.loot_converted_name || "Item") + ' converted to ' + data.loot_converted_gold + ' gold.</div>';
            }
            if (data.leveled_up) {
                html += '<div class="nr-level-up mt-2"><i class="fas fa-arrow-up"></i> Level Up! You are now level ' + data.new_level + '!</div>';
            }
            html += '</div>';
        } else {
            html += '<div class="text-danger">';
            if (data.gold_gained && data.gold_gained < 0) {
                html += '<span class="badge bg-danger">Lost ' + Math.abs(data.gold_gained) + ' Gold</span>';
            }
            html += '<div class="small mt-1">HP set to 1. Rest to recover.</div>';
            html += '</div>';
        }

        // Combat rounds summary
        if (data.rounds && data.rounds.length > 0) {
            html += '<div class="nr-combat-rounds mt-3">';
            html += '<h6>Combat Log (' + data.total_rounds + ' rounds)</h6>';
            html += '<div class="nr-rounds-scroll">';
            data.rounds.forEach(function (r) {
                html += '<div class="nr-round">';
                html += '<span class="text-muted">R' + r.round + '</span> ';
                html += 'You deal <span class="text-success">' + (r.player_damage || r.attacker_damage || 0) + '</span> dmg';
                const monsterDmg = r.monster_damage || r.defender_damage || 0;
                if (monsterDmg > 0) {
                    html += ', take <span class="text-danger">' + monsterDmg + '</span> dmg';
                }
                html += '</div>';
            });
            html += '</div></div>';
        }

        container.innerHTML = html;
    }

    // === Inventory ===
    async function loadInventory() {
        showView("inventory");
        const container = document.getElementById("inventory-list");
        container.innerHTML = '<div class="col-12 text-center"><div class="spinner-border text-warning"></div></div>';

        const data = await api("inventory");
        if (!data.success) {
            container.innerHTML = '<div class="col-12"><div class="alert alert-danger">' + escapeHtml(data.error) + '</div></div>';
            return;
        }

        document.getElementById("inv-count").textContent = data.count + "/" + data.max;

        if (!data.items || data.items.length === 0) {
            container.innerHTML = '<div class="col-12"><div class="alert alert-info">Your inventory is empty. Go hunt some monsters!</div></div>';
            return;
        }

        let html = "";
        data.items.forEach(function (item) {
            let actions = "";
            if (item.is_equipped) {
                actions = '<button class="btn btn-sm btn-outline-warning btn-unequip" data-id="' + item.id + '">Unequip</button>';
            } else {
                actions = '<button class="btn btn-sm btn-outline-success btn-equip" data-id="' + item.id + '">Equip</button>';
                actions += ' <button class="btn btn-sm btn-outline-danger btn-sell-inv" data-id="' + item.id + '">Sell</button>';
            }
            html += itemCardHtml(item, actions);
        });
        container.innerHTML = html;
    }

    // === Equip / Unequip ===
    async function equipItem(itemId) {
        const data = await api("equip", { item_id: itemId });
        if (!data.success) {
            toast(data.error || "Equip failed.", "danger");
            return;
        }
        if (data.unequipped) toast("Unequipped " + data.unequipped, "info");
        toast("Item equipped!", "success");
        if (data.character) updateStatusBar(data.character);
        loadInventory();
    }

    async function unequipItem(itemId) {
        const data = await api("unequip", { item_id: itemId });
        if (!data.success) {
            toast(data.error || "Unequip failed.", "danger");
            return;
        }
        toast("Item unequipped.", "info");
        if (data.character) updateStatusBar(data.character);
        loadInventory();
    }

    // === Shop ===
    async function loadShop() {
        showView("shop");
        const container = document.getElementById("shop-list");
        container.innerHTML = '<div class="col-12 text-center"><div class="spinner-border text-success"></div></div>';

        const data = await api("shop");
        if (!data.success) {
            container.innerHTML = '<div class="col-12"><div class="alert alert-danger">' + escapeHtml(data.error) + '</div></div>';
            return;
        }

        document.getElementById("shop-gold").textContent = data.gold;

        if (!data.items || data.items.length === 0) {
            container.innerHTML = '<div class="col-12"><div class="alert alert-info">No items available yet.</div></div>';
            return;
        }

        let html = "";
        data.items.forEach(function (item) {
            const actions = '<button class="btn btn-sm btn-success btn-buy" data-key="' + escapeHtml(item.item_key) + '">' +
                '<i class="fas fa-coins"></i> Buy (' + item.buy_price + 'g)</button>';

            // Build item card manually for shop (item has different field names)
            const color = "#9d9d9d"; // Shop items are always common
            let bonuses = [];
            if (item.attack_bonus > 0) bonuses.push('<span class="text-danger">+' + item.attack_bonus + ' ATK</span>');
            if (item.defense_bonus > 0) bonuses.push('<span class="text-primary">+' + item.defense_bonus + ' DEF</span>');
            if (item.hp_bonus > 0) bonuses.push('<span class="text-success">+' + item.hp_bonus + ' HP</span>');

            html += '<div class="col-12 col-sm-6 col-md-4">';
            html += '<div class="card nr-item-card" style="border-color:' + color + '">';
            html += '<div class="card-body p-2">';
            html += '<div class="d-flex justify-content-between align-items-start">';
            html += '<h6 class="mb-1">' + escapeHtml(item.name) + '</h6>';
            html += '<span class="badge bg-secondary">Lv ' + item.min_level + '+</span>';
            html += '</div>';
            html += '<div class="nr-item-type mb-1">' + capitalize(item.type) + '</div>';
            html += '<div class="nr-item-bonuses">' + (bonuses.length ? bonuses.join(" ") : '<span class="text-muted">No bonuses</span>') + '</div>';
            html += '<div class="mt-2">' + actions + '</div>';
            html += '</div></div></div>';
        });
        container.innerHTML = html;
    }

    // === Buy / Sell ===
    async function buyItem(itemKey) {
        const data = await api("buy", { item_key: itemKey });
        if (!data.success) {
            toast(data.error || "Purchase failed.", "danger");
            return;
        }
        toast("Purchased item!", "success");
        if (data.character) updateStatusBar(data.character);
        loadShop();
    }

    async function sellItem(itemId) {
        const data = await api("sell", { item_id: itemId });
        if (!data.success) {
            toast(data.error || "Sale failed.", "danger");
            return;
        }
        toast("Sold " + (data.item_name || "item") + " for " + data.gold_received + " gold.", "success");
        if (data.character) updateStatusBar(data.character);
        loadInventory();
    }

    // === Rest ===
    async function doRest() {
        const data = await api("rest");
        if (!data.success) {
            toast(data.error || "Rest failed.", "danger");
            return;
        }
        toast("Rested and healed " + data.healed + " HP. (" + data.rest_uses_remaining + " rests remaining)", "success");
        if (data.character) updateStatusBar(data.character);
    }

    // === Buy Turns ===
    async function doBuyTurns() {
        const amount = parseInt(prompt("How many extra turns? (Cost: " + gameConfig.credits_per_extra_turn + " credits each, max " + gameConfig.max_extra_turns_per_day + "/day)"), 10);
        if (!amount || amount <= 0) return;

        const data = await api("buy-turns", { amount: amount });
        if (!data.success) {
            toast(data.error || "Purchase failed.", "danger");
            return;
        }
        toast("Bought " + data.turns_bought + " turns for " + data.credits_spent + " credits.", "success");
        if (data.character) updateStatusBar(data.character);
    }

    // === PvP ===
    async function loadPvp() {
        showView("pvp");
        const container = document.getElementById("pvp-list");
        container.innerHTML = '<div class="text-center"><div class="spinner-border text-info"></div></div>';

        const data = await api("pvp-list");
        if (!data.success) {
            container.innerHTML = '<div class="alert alert-danger">' + escapeHtml(data.error) + '</div>';
            return;
        }

        if (!data.players || data.players.length === 0) {
            container.innerHTML = '<div class="alert alert-info">No challengers available in your level range. Keep leveling!</div>';
            return;
        }

        let html = '<div class="list-group">';
        data.players.forEach(function (p) {
            const disabled = p.on_cooldown ? ' disabled' : '';
            const cooldownBadge = p.on_cooldown ? ' <span class="badge bg-secondary">Cooldown</span>' : '';
            html += '<div class="list-group-item nr-pvp-item d-flex justify-content-between align-items-center">';
            html += '<div>';
            html += '<strong>' + escapeHtml(p.player_name) + '</strong> <span class="badge bg-secondary">Lv ' + p.level + '</span>' + cooldownBadge;
            html += '<div class="small text-muted">';
            html += 'ATK: ' + p.attack + ' | DEF: ' + p.defense + ' | HP: ' + p.hp + '/' + p.max_hp;
            html += ' | W/L: ' + p.pvp_wins + '/' + p.pvp_losses;
            html += '</div></div>';
            html += '<button class="btn btn-sm btn-danger btn-pvp-challenge" data-id="' + p.id + '"' + disabled + '>';
            html += '<i class="fas fa-swords"></i> Challenge (' + gameConfig.pvp_turn_cost + ' turns)</button>';
            html += '</div>';
        });
        html += '</div>';
        container.innerHTML = html;
    }

    async function pvpChallenge(playerId) {
        document.querySelectorAll(".btn-pvp-challenge").forEach(function (b) { b.disabled = true; });

        const data = await api("pvp-challenge", { player_id: playerId });
        if (!data.success) {
            toast(data.error || "Challenge failed.", "danger");
            document.querySelectorAll(".btn-pvp-challenge").forEach(function (b) { b.disabled = false; });
            return;
        }

        if (data.character) updateStatusBar(data.character);
        showPvpResult(data);
    }

    function showPvpResult(data) {
        showView("pvp-result");
        const container = document.getElementById("pvp-result-content");

        const resultClass = data.victory ? "text-success" : "text-danger";
        const resultText = data.victory ? "Victory!" : "Defeat!";

        let html = '<div class="text-center mb-3">';
        html += '<h3 class="' + resultClass + '"><i class="fas fa-swords"></i> ' + resultText + '</h3>';
        html += '<h5>vs ' + escapeHtml(data.opponent_name) + ' (Lv ' + data.opponent_level + ')</h5>';
        html += '</div>';

        if (data.gold_gained > 0) {
            html += '<div class="text-center"><span class="badge bg-warning text-dark">+' + data.gold_gained + ' Gold</span></div>';
        } else if (data.gold_gained < 0) {
            html += '<div class="text-center"><span class="badge bg-danger">Lost ' + Math.abs(data.gold_gained) + ' Gold</span></div>';
        }

        if (data.rounds && data.rounds.length > 0) {
            html += '<div class="nr-combat-rounds mt-3">';
            html += '<h6>Combat Log (' + data.total_rounds + ' rounds)</h6>';
            html += '<div class="nr-rounds-scroll">';
            data.rounds.forEach(function (r) {
                html += '<div class="nr-round">';
                html += '<span class="text-muted">R' + r.round + '</span> ';
                html += 'You deal <span class="text-success">' + r.attacker_damage + '</span> dmg';
                if (r.defender_damage > 0) {
                    html += ', take <span class="text-danger">' + r.defender_damage + '</span> dmg';
                }
                html += '</div>';
            });
            html += '</div></div>';
        }

        container.innerHTML = html;
    }

    // === Leaderboard ===
    async function loadLeaderboard(type) {
        type = type || "overall";
        const container = document.getElementById("leaderboard-content");
        container.innerHTML = '<div class="text-center"><div class="spinner-border text-primary"></div></div>';

        // Update active tab
        document.querySelectorAll(".nr-lb-tab").forEach(function (b) {
            b.classList.toggle("active", b.dataset.type === type);
        });

        const data = await api("leaderboard", { type: type });
        if (!data.success) {
            container.innerHTML = '<div class="alert alert-danger">' + escapeHtml(data.error) + '</div>';
            return;
        }

        if (!data.rankings || data.rankings.length === 0) {
            container.innerHTML = '<div class="alert alert-info">No rankings yet. Be the first!</div>';
            return;
        }

        let html = '<table class="table table-dark table-sm nr-table">';
        html += '<thead><tr><th>#</th><th>Name</th><th>Lv</th>';

        if (type === "pvp") {
            html += '<th>Wins</th><th>Losses</th><th>Win%</th>';
        } else if (type === "wealth") {
            html += '<th>Gold</th>';
        } else if (type === "monster_slayer") {
            html += '<th>Kills</th>';
        } else {
            html += '<th>XP</th><th>Kills</th>';
        }
        html += '</tr></thead><tbody>';

        data.rankings.forEach(function (r, i) {
            const rankIcon = i === 0 ? '<i class="fas fa-crown text-warning"></i> ' : (i + 1) + ".";
            html += '<tr><td>' + rankIcon + '</td>';
            html += '<td>' + escapeHtml(r.name) + '</td>';
            html += '<td>' + r.level + '</td>';

            if (type === "pvp") {
                html += '<td>' + r.pvp_wins + '</td><td>' + r.pvp_losses + '</td><td>' + r.win_rate + '%</td>';
            } else if (type === "wealth") {
                html += '<td><i class="fas fa-coins text-warning"></i> ' + r.gold + '</td>';
            } else if (type === "monster_slayer") {
                html += '<td><i class="fas fa-skull-crossbones text-danger"></i> ' + r.monsters_killed + '</td>';
            } else {
                html += '<td>' + r.xp + '</td><td>' + r.monsters_killed + '</td>';
            }
            html += '</tr>';
        });

        html += '</tbody></table>';
        container.innerHTML = html;
    }

    // === Combat Log ===
    async function loadCombatLog() {
        showView("combat-log");
        const container = document.getElementById("combat-log-content");
        container.innerHTML = '<div class="text-center"><div class="spinner-border text-secondary"></div></div>';

        const data = await api("combat-log");
        if (!data.success) {
            container.innerHTML = '<div class="alert alert-danger">' + escapeHtml(data.error) + '</div>';
            return;
        }

        if (!data.log || data.log.length === 0) {
            container.innerHTML = '<div class="alert alert-info">No combat history yet.</div>';
            return;
        }

        let html = '<div class="list-group">';
        data.log.forEach(function (entry) {
            const isVictory = entry.result === "victory";
            const icon = isVictory ? "fa-trophy text-success" : "fa-skull text-danger";
            const typeIcon = entry.combat_type === "pvp" ? "fa-swords" : "fa-dragon";
            const date = new Date(entry.created_at).toLocaleString();

            html += '<div class="list-group-item nr-log-item">';
            html += '<div class="d-flex justify-content-between">';
            html += '<div><i class="fas ' + icon + '"></i> <i class="fas ' + typeIcon + ' text-muted"></i> ';
            html += '<strong>' + escapeHtml(entry.opponent_name) + '</strong></div>';
            html += '<small class="text-muted">' + date + '</small>';
            html += '</div>';
            html += '<div class="small">';
            if (entry.xp_gained > 0) html += '<span class="text-info">+' + entry.xp_gained + ' XP</span> ';
            if (entry.gold_gained > 0) html += '<span class="text-warning">+' + entry.gold_gained + ' Gold</span> ';
            if (entry.gold_gained < 0) html += '<span class="text-danger">' + entry.gold_gained + ' Gold</span> ';
            if (entry.loot_item) html += '<span class="text-success">Loot: ' + escapeHtml(entry.loot_item) + '</span>';
            html += '</div>';
            html += '</div>';
        });
        html += '</div>';
        container.innerHTML = html;
    }

    // === Event Listeners ===
    document.addEventListener("DOMContentLoaded", function () {
        init();

        // Character creation
        document.getElementById("btn-create").addEventListener("click", createCharacter);
        document.getElementById("create-name").addEventListener("keypress", function (e) {
            if (e.key === "Enter") createCharacter();
        });

        // Navigation buttons
        document.querySelectorAll(".nr-nav-btn").forEach(function (btn) {
            btn.addEventListener("click", function () {
                const view = this.dataset.view;
                if (view === "combat") loadMonsters();
                else if (view === "inventory") loadInventory();
                else if (view === "shop") loadShop();
                else if (view === "pvp") loadPvp();
                else if (view === "leaderboard") { showView("leaderboard"); loadLeaderboard("overall"); }
                else if (view === "combat-log") loadCombatLog();
            });
        });

        // Back buttons
        document.addEventListener("click", function (e) {
            const backBtn = e.target.closest(".btn-back");
            if (backBtn) {
                const view = backBtn.dataset.view;
                if (view === "town") {
                    showView("town");
                    // Refresh status
                    api("status").then(function (d) {
                        if (d.success && d.character) updateStatusBar(d.character);
                    });
                } else if (view === "combat") {
                    loadMonsters();
                } else if (view === "pvp") {
                    loadPvp();
                }
            }
        });

        // Fight buttons (delegated)
        document.getElementById("monster-list").addEventListener("click", function (e) {
            const btn = e.target.closest(".btn-fight");
            if (btn) fightMonster(btn.dataset.key);
        });

        // Inventory actions (delegated)
        document.getElementById("inventory-list").addEventListener("click", function (e) {
            const equipBtn = e.target.closest(".btn-equip");
            const unequipBtn = e.target.closest(".btn-unequip");
            const sellBtn = e.target.closest(".btn-sell-inv");

            if (equipBtn) equipItem(parseInt(equipBtn.dataset.id));
            else if (unequipBtn) unequipItem(parseInt(unequipBtn.dataset.id));
            else if (sellBtn) sellItem(parseInt(sellBtn.dataset.id));
        });

        // Shop buy (delegated)
        document.getElementById("shop-list").addEventListener("click", function (e) {
            const btn = e.target.closest(".btn-buy");
            if (btn) buyItem(btn.dataset.key);
        });

        // PvP challenge (delegated)
        document.getElementById("pvp-list").addEventListener("click", function (e) {
            const btn = e.target.closest(".btn-pvp-challenge");
            if (btn && !btn.disabled) pvpChallenge(parseInt(btn.dataset.id));
        });

        // Leaderboard tabs
        document.querySelectorAll(".nr-lb-tab").forEach(function (btn) {
            btn.addEventListener("click", function () {
                loadLeaderboard(this.dataset.type);
            });
        });

        // Rest button
        document.getElementById("btn-rest").addEventListener("click", doRest);

        // Buy turns button
        document.getElementById("btn-buy-turns").addEventListener("click", doBuyTurns);
    });
})();
