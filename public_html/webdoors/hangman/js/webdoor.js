class WebDoor {
    static async load() {
        const res = await fetch("/api/webdoor/session");
        this.session = await res.json();
        return this.session;
    }

    static async save(state) {
        return fetch("/api/webdoor/save", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ state })
        });
    }

    static async loadSave() {
        const res = await fetch("/api/webdoor/load");
        return res.ok ? (await res.json()).state : null;
    }

    static async deleteSave() {
        return fetch("/api/webdoor/delete", { method: "POST" });
    }
}
