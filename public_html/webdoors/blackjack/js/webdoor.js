
class WebDoor {
  static apiBase() {
    const u = new URL(window.location.href);
    // spec supports passing webdoor_api for cross-origin games
    return u.searchParams.get("webdoor_api") || u.searchParams.get("api") || "/api/webdoor";
  }

  static async session() {
    const base = this.apiBase();
    const r = await fetch(`${base}/session`, { credentials: "include" });
    if (!r.ok) throw new Error(`session failed: HTTP ${r.status}`);
    return await r.json();
  }

  // Storage API (spec): GET/PUT/DELETE /storage/{slot}
  static async loadSlot(slot = 0) {
    const base = this.apiBase();
    const r = await fetch(`${base}/storage/${slot}`, { credentials: "include" });
    if (!r.ok) return null;
    return await r.json(); // {slot,data,metadata,saved_at}
  }

  static async saveSlot(slot = 0, data = {}, metadata = {}) {
    const base = this.apiBase();
    const r = await fetch(`${base}/storage/${slot}`, {
      method: "PUT",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ data, metadata })
    });
    if (!r.ok) throw new Error(`saveSlot failed: HTTP ${r.status}`);
    return await r.json().catch(() => ({}));
  }

  static async deleteSlot(slot = 0) {
    const base = this.apiBase();
    const r = await fetch(`${base}/storage/${slot}`, { method: "DELETE", credentials: "include" });
    if (!r.ok) throw new Error(`deleteSlot failed: HTTP ${r.status}`);
    return await r.json().catch(() => ({}));
  }

  // Leaderboard API (spec): GET/POST /leaderboard/{board}
  static async getLeaderboard(board = "blackjack", limit = 10, scope = "all") {
    const base = this.apiBase();
    const r = await fetch(`${base}/leaderboard/${encodeURIComponent(board)}?limit=${encodeURIComponent(limit)}&scope=${encodeURIComponent(scope)}`, { credentials: "include" });
    if (!r.ok) return null;
    return await r.json(); // {board, entries: [...]}
  }

  static async submitScore(board = "blackjack", score = 0, metadata = {}) {
    const base = this.apiBase();
    const r = await fetch(`${base}/leaderboard/${encodeURIComponent(board)}`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ score, metadata })
    });
    if (!r.ok) throw new Error(`submitScore failed: HTTP ${r.status}`);
    return await r.json().catch(() => ({}));
  }
}
