# Join the BinktermPHP Project — Contributors Wanted

**BinktermPHP** is an open source modern web-based BBS and FidoNet mailer — a love letter to the golden age of bulletin board systems, rebuilt for the web with PHP, PostgreSQL, and Bootstrap. If you grew up on BBSes, or you're just drawn to niche protocol work and community-driven software, this might be your kind of project.

We're looking for experienced developers who want to build something genuinely interesting.

---

## What We're Building

BinktermPHP gives users a full BBS experience through a web browser: FidoNet echomail forums, private netmail, file areas, door games, nodelist browsing, and real-time chat — all running on a self-hosted stack. It speaks the BinkP protocol natively, exchanges packets with FidoNet nodes worldwide, and is actively deployed on live BBS systems.

---

## Areas We'd Love Help With

**FTN Networking & Protocol Work**
Deep work on the BinkP mailer, packet processing, routing tables, and FidoNet standards compliance. If low-level binary protocols and RFC-style specs sound like fun rather than work, there's plenty here — outbound packet organisation, multi-network routing, crash mail, and more.

**WebDoors — Asynchronous Game Development**
WebDoors is our system for embedding HTML5/JavaScript games into the BBS via iframes and a lightweight SDK. We want more games: turn-based strategy, asynchronous multiplayer, puzzles, text adventures. If you enjoy building self-contained game experiences with a server-side PHP backend and a JS frontend, the WebDoors SDK gives you a clean foundation to work from.

**DOS Door Integration**
We run classic DOS door games in the browser via DOSBox-X and a Node.js WebSocket multiplexing bridge. There's room to improve session management, expand the game library, and improve the drop-file and inter-process communication layer.

**Real-Time & Multi-User Features**
MRC (Multi-Relay Chat) integration, shoutbox improvements, live presence indicators, and inter-BBS messaging. We're exploring what real-time collaboration looks like in a FidoNet-connected BBS context.

**Echomail Processing & Automation**
A plugin-based echomail processor architecture is in the works — think bots, RSS bridges, automated responses, and analytics that hook into the message flow without touching the core handler. Good territory if you like event-driven design.

**Themes & UI Customisation**
BinktermPHP ships with multiple themes (dark, amber, green terminal, cyberpunk) built on Bootstrap 5. We want richer customisation tools, a better theme editor, and community-contributed themes. Frontend developers with an eye for retro-meets-modern aesthetics will feel right at home.

**Telnet Daemon & Terminal Experience**
We have a PHP-based telnet daemon with ANSI support, door game relay, and anti-bot challenges. There's meaningful work around improving the terminal experience, ANSI/CP437 rendering, and the telnet-to-web bridge.

**Infrastructure & DevOps**
Installer improvements, upgrade tooling, Docker support, and making self-hosting genuinely smooth for non-technical sysops.

---

## Who We're Looking For

- A few years of PHP under your belt — you're comfortable with OOP, PDO, and working in an existing codebase
- Familiarity with PostgreSQL
- Comfortable using **agentic AI tools like Claude as part of your workflow** — we work with AI as a genuine team member, not just a code autocomplete
- Self-directed — this is an open source project, contributions happen async and on your own schedule
- Curiosity about old protocols, retro computing, or BBS culture is a big plus, but not required

You don't need to know FidoNet. You just need to be the kind of developer who finds it interesting once you do.

---

## Get Involved

The project is hosted on GitHub at **[github.com/awehttam/binkterm-php](https://github.com/awehttam/binkterm-php)**. Browse the code, open an issue, or reach out directly. You can also see it running live at **[claudes.lovelybits.org](https://claudes.lovelybits.org)**.

All skill levels welcome for smaller contributions — but for the areas above we're specifically hoping to find developers ready to take ownership of a domain and run with it.

---

*BinktermPHP is BSD licensed and free to self-host.*
