# MRC Chat

MRC (Multi Relay Chat) is a real-time, multi-BBS chat network that connects users across different bulletin board systems. BinktermPHP includes a WebDoor-based MRC client that lets logged-in users join rooms and chat with users on other connected BBSes.

> **This feature is experimental.** Protocol behaviour, configuration format, and database schema may change in future releases.

## How It Works

MRC uses a persistent TCP connection from your BBS to a central MRC relay server. A background daemon (`scripts/mrc_daemon.php`) maintains that connection. When a user opens the MRC WebDoor in their browser, they interact with the chat through HTTP polling — the browser polls the BinktermPHP API every two seconds for new messages, and the daemon forwards outbound messages to the MRC network.

```
Browser ←→ BinktermPHP API ←→ mrc_daemon ←→ MRC server ←→ other BBSes
```

## Prerequisites

- PHP 8.x with the `openssl` extension (for SSL connections)
- PostgreSQL
- The MRC WebDoor enabled in Admin → WebDoors

## MRC Servers

| Server Version | Protocol | Environment | FQDN | Port | Security |
|---|---|---|---|---|---|
| 1.3.20 | 1.3 | **Production** | mrc.bottomlessabyss.net | 5000 | Plain |
| 1.3.20 | 1.3 | **Production** | mrc.bottomlessabyss.net | 5001 | SSL |
| 1.3.21-dev | 1.3 | Testing | mrc.bottomlessabyss.net | 50000 | Plain |
| 1.3.21-dev | 1.3 | Testing | mrc.bottomlessabyss.net | 50001 | SSL |

BinktermPHP ships configured for the **testing server** (port 50001, SSL) by default. To connect to the production server where most activity happens, update `config/mrc.json` or use Admin → MRC to set the port to `5000` (plain) or `5001` (SSL).

## Setup

### 1. Configure MRC

Configuration is stored in `config/mrc.json`. The file is created automatically with defaults on first use. Edit it directly or use **Admin → MRC** to configure through the web interface.

**Minimum required settings:**

```json
{
    "enabled": true,
    "server": {
        "host": "mrc.bottomlessabyss.net",
        "port": 5000,
        "use_ssl": false
    },
    "bbs": {
        "name": "My BBS",
        "sysop": "Sysop"
    }
}
```

**Full configuration reference:**

```json
{
    "enabled": true,
    "server": {
        "host": "mrc.bottomlessabyss.net",
        "port": 5000,
        "use_ssl": false,
        "ssl_port": 5001
    },
    "bbs": {
        "name": "My BBS",
        "platform": "BINKTERMPHP/Linux64/1.3.0",
        "sysop": "Sysop"
    },
    "connection": {
        "auto_reconnect": true,
        "reconnect_delay": 30,
        "ping_interval": 60,
        "handshake_timeout": 1,
        "keepalive_timeout": 125
    },
    "rooms": {
        "default": "lobby",
        "auto_join": ["lobby"]
    },
    "messages": {
        "max_length": 140,
        "history_limit": 1000,
        "prune_after_days": 30
    },
    "info": {
        "website": "https://mybbs.example.com",
        "telnet": "mybbs.example.com:23",
        "ssh": "mybbs.example.com:22",
        "description": "A BinktermPHP BBS"
    }
}
```

| Field | Description |
|---|---|
| `server.host` | MRC relay server hostname |
| `server.port` | Plain TCP port |
| `server.use_ssl` | Use SSL/TLS connection |
| `server.ssl_port` | SSL port |
| `bbs.name` | Your BBS name as it appears on the MRC network (max 64 chars; apostrophes are stripped automatically) |
| `bbs.platform` | Platform identifier string sent during handshake |
| `bbs.sysop` | Sysop name |
| `connection.reconnect_delay` | Seconds to wait before reconnecting after a disconnect |
| `connection.keepalive_timeout` | Seconds without a PING before treating the connection as dead |
| `rooms.auto_join` | Rooms to join when a user first enters the WebDoor |
| `messages.history_limit` | Maximum messages retained per room in the database |
| `messages.prune_after_days` | Messages older than this are deleted during maintenance |
| `info.*` | Optional BBS contact info returned by the MRC `INFO` command |

### 2. Enable the WebDoor

In **Admin → WebDoors**, find **MRC Chat** and enable it. Users can then access it via **Doors → MRC Chat** or directly at `/games/mrc`.

### 3. Start the daemon

```bash
php scripts/mrc_daemon.php
```

Options:

| Flag | Description |
|---|---|
| `--daemon` | Detach from terminal and run in the background |
| `--pid-file=PATH` | Write PID to this file (default: `data/run/mrc_daemon.pid`) |
| `--debug` | Log raw protocol send/receive for troubleshooting |
| `--log-level=LEVEL` | Log verbosity level |

#### Keeping the daemon running: crontab (preferred)

Add a `@reboot` entry to your crontab so the daemon starts automatically when the server boots:

```bash
crontab -e
```

Add this line (adjust the path):

```
@reboot /usr/bin/php /path/to/binktest/scripts/mrc_daemon.php --daemon --pid-file=/path/to/binktest/data/run/mrc_daemon.pid
```

#### Keeping the daemon running: systemd

Alternatively, create `/etc/systemd/system/mrc-daemon.service`:

```ini
[Unit]
Description=BinktermPHP MRC Chat Daemon
After=network.target postgresql.service

[Service]
Type=simple
User=binkterm
WorkingDirectory=/path/to/binktest
ExecStart=/usr/bin/php scripts/mrc_daemon.php
Restart=always
RestartSec=15
StandardOutput=journal
StandardError=journal
SyslogIdentifier=mrc-daemon

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable mrc-daemon
systemctl start mrc-daemon
```

## User Guide

### Joining a Room

When a user opens the MRC WebDoor (`/games/mrc`), the left sidebar lists available rooms. Click a room to join it. Alternatively, type a room name in the text field at the top of the sidebar and press Enter or click the arrow button.

Joining sends a `NEWROOM` packet to the MRC server, which broadcasts a join announcement to everyone in the room.

### Sending Messages

With a room joined, type a message in the input bar at the bottom (max 140 characters) and press Enter or click **Send**. Messages are delivered to all users in the room across the MRC network.

### Private Messages

Click a username in the **Users** sidebar to open a direct message conversation with that user. The chat header shows a **Direct:** badge while in private mode. Click the **×** on the badge to return to room chat.

Private unread message counts appear next to each username and in the Users header badge.

### Commands

Type a slash command in the message input field:

| Command | Description |
|---|---|
| `/motd` | Request and display the Message of the Day for the current room |

### Room and User Lists

- The **Rooms** sidebar shows all known rooms with their topic and user count. Rooms you have joined are highlighted with a green left border.
- The **Users** sidebar lists all users currently in your joined room, with their BBS name below.
- Join and part announcements appear inline in the message stream.

## Admin Integration

MRC can be configured through **Admin → MRC** without editing `config/mrc.json` directly. The admin page covers:

- Enable/disable MRC
- Server connection settings (host, port, SSL)
- BBS identification (name, sysop, platform)
- Connection timeouts
- Message retention settings
- BBS info (website, telnet/SSH addresses)

Changes take effect after restarting the daemon.

The MRC connection status indicator (connected / disconnected) appears in the main navigation bar header.

## Architecture

| Component | Purpose |
|---|---|
| `scripts/mrc_daemon.php` | Long-running daemon; maintains TCP connection to MRC server; reads/writes `mrc_outbound` table |
| `public_html/webdoors/mrc/api.php` | HTTP API serving the browser WebDoor; handles `poll`, `send`, `join`, `heartbeat`, `command` actions |
| `public_html/webdoors/mrc/mrc.js` | Browser-side chat client; polls the API every 2 seconds |
| `src/Mrc/MrcClient.php` | Low-level MRC protocol implementation (packet framing, NEWROOM, IAMHERE, LOGOFF, etc.) |
| `src/Mrc/MrcConfig.php` | Configuration manager for `config/mrc.json` |

### Database Tables

| Table | Purpose |
|---|---|
| `mrc_messages` | Inbound messages and server announcements stored for client polling |
| `mrc_outbound` | Queue of outbound packets waiting to be sent by the daemon |
| `mrc_users` | Active users per room (`is_local=true` for WebDoor users, `false` for remote) |
| `mrc_rooms` | Known rooms with topic and last activity |
| `mrc_state` | Key/value store for daemon state (connection status, last ping time) |

### Presence and Keepalives

- **IMALIVE** — Sent by the daemon to the MRC server every ~60 seconds to keep the BBS-level connection alive.
- **IAMHERE** — Sent for each local WebDoor user every ~50 seconds to keep their individual session alive on the MRC server.
- **Browser heartbeat** — The browser sends a lightweight heartbeat on user activity and via the poll loop to keep the `last_seen` timestamp current. Users whose `last_seen` exceeds 10 minutes are pruned and a `LOGOFF` packet is sent on their behalf.

## Troubleshooting

**Daemon won't connect**
- Check `data/logs/` or `journalctl -u mrc-daemon` for error messages
- Verify the server host and port in `config/mrc.json` (see the server table above)
- Ensure the port is not blocked by your firewall
- Try `--debug` flag to see raw protocol output

**Messages not appearing**
- Confirm the daemon is running: check `data/run/mrc_daemon.pid` and the `mrc_state` table `connected` key
- Confirm the WebDoor is enabled in Admin → WebDoors
- Check browser console for API errors

**BBS name rejected by server**
- Avoid apostrophes, quotes, and tildes (`~`) in `bbs.name` — apostrophes are stripped automatically, but other special characters may still cause server-side rejection

**Connection drops frequently**
- Increase `keepalive_timeout` in `config/mrc.json` if your network has high latency
- Ensure `auto_reconnect` is `true`
