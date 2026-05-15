# Matterbridge

Matterbridge support lets local BinktermPHP chat rooms relay messages to and from other chat platforms (Discord, Slack, IRC, etc.) through Matterbridge's API gateway.

## What It Covers

- Global Matterbridge API settings stored in `config/matterbridge.json`
- Per-room bridge settings on **Admin → Chat Rooms**
- Outbound relay: local chat messages sent to remote platforms
- Inbound relay: remote platform messages injected into local chat via `scripts/matterbridge_daemon.php`

Direct messages are not bridged — only room messages.

## Architecture

There are **two separate processes** you must run alongside your web server:

| Process | What it is | Your responsibility |
|---|---|---|
| `matterbridge` binary | Third-party Go program; bridges external platforms (Discord, Slack, IRC, …) to its own HTTP API | Download, configure, and run separately |
| `matterbridge_daemon.php` | BinktermPHP PHP script; polls the Matterbridge API and injects inbound messages into local chat | Managed by `restart_daemons.sh` |

```
                  BinktermPHP (web / term)
                        │  ▲
           outbound     │  │ inbound
       ChatMessageService  │  matterbridge_daemon.php
        POST /api/message  │  GET /api/messages (polls)
                        ▼  │
              ┌─────────────────────┐
              │  Matterbridge binary │  ← you run this separately
              │  (HTTP API :4240)    │
              └─────────────────────┘
                        │
              Discord / Slack / IRC / …
```

**Outbound** — `ChatMessageService` POSTs to Matterbridge's `/api/message` whenever a bridged room receives a local message.

**Inbound** — `scripts/matterbridge_daemon.php` polls Matterbridge's `/api/messages` every few seconds, matches incoming messages to rooms by gateway name, and inserts them into `chat_messages` under a dedicated bridge user account.

Both processes must be running for bidirectional bridging to work. If only the Matterbridge binary is running, outbound messages will reach Discord but nothing will come back. If only the PHP daemon is running, it will log connection errors because the API is unreachable.

## Install Matterbridge

Download a Matterbridge release from https://github.com/42wim/matterbridge/releases and place the binary in the `matterbridge/` directory:

```bash
mv matterbridge-1.26.0-linux-64bit matterbridge/matterbridge
chmod +x matterbridge/matterbridge
```

A sample TOML config is at `matterbridge/matterbridge.toml`.

## Discord Bot Setup

Before you can configure the Discord section of `matterbridge.toml`, you need a Discord bot application with the appropriate permissions and an invite link to add it to your server.

### 1. Create a Discord Application

1. Go to the [Discord Developer Portal](https://discord.com/developers/applications) and sign in.
2. Click **New Application** in the top-right corner.
3. Give it a name (e.g. `MyBBS Bridge`) and click **Create**.

### 2. Create a Bot User

1. In the left sidebar, click **Bot**.
2. Click **Add Bot**, then confirm with **Yes, do it!**
3. Under the **TOKEN** heading, click **Reset Token** (or **Copy** if it is already shown). Save this token — you will never be able to see it again. This becomes the `Token` value under `[discord.mydiscord]` in `matterbridge.toml`.
4. Scroll down to **Privileged Gateway Intents** and enable:
   - **Server Members Intent** — lets the bot see member join/leave events and resolve usernames.
   - **Message Content Intent** — required so the bot can read message text. Without this, inbound Discord messages will arrive empty.
5. Click **Save Changes**.

### 3. Set Bot Permissions

1. In the left sidebar, click **OAuth2 → URL Generator**.
2. Under **Scopes**, check `bot`.
3. Under **Bot Permissions**, check:
   - **Read Messages/View Channels**
   - **Send Messages**
   - **Read Message History**
4. Copy the generated URL at the bottom of the page.

### 4. Invite the Bot to Your Server

1. Paste the URL from the previous step into a browser while logged in to Discord as a server admin.
2. Select your server from the dropdown and click **Authorize**.
3. Complete the CAPTCHA if prompted.

The bot will now appear as a member of your server (shown as offline until Matterbridge is running).

### 5. Get Channel Names

Discord channel names used in `matterbridge.toml` are the plain text names shown in the channel list (without the `#` prefix), e.g. `general`, `bbs-lobby`. You do not need channel IDs.

The bot must have **View Channel** and **Send Messages** permission in every channel you intend to bridge. If a channel is hidden from the bot role, Matterbridge will log an error on startup.

---

## Configure Matterbridge (matterbridge.toml)

A minimal bidirectional setup bridging one local room to a Discord channel:

```toml
[API.binktermphp]
BindAddress = "127.0.0.1:4240"
Token       = "your-secret-token"

[discord.mydiscord]
Token  = "your-discord-bot-token"
Server = "Your Server Name"
PrefixMessagesWithNick = true

[general]
RemoteNickFormat = "[{PROTOCOL}] <{NICK}> "

[[gateway]]
name   = "my-room"
enable = true

[[gateway.inout]]
account = "api.binktermphp"
channel = "api"

[[gateway.inout]]
account = "discord.mydiscord"
channel = "your-channel-name"
```

To bridge multiple rooms, add a `[[gateway]]` block for each one. Each gateway maps one local BinktermPHP room to one Discord channel (or channel on any other platform):

```toml
[[gateway]]
name   = "binkterm-testing"
enable = true

[[gateway.inout]]
account = "api.binktermphp"
channel = "testing"

[[gateway.inout]]
account = "discord.mydiscord"
channel = "claudes-testing"

[[gateway]]
name   = "binkterm-lobby"
enable = true

[[gateway.inout]]
account = "api.binktermphp"
channel = "lobby"

[[gateway.inout]]
account = "discord.mydiscord"
channel = "claudes-lobby"
```

Each gateway's `name` must be unique and must match the **Matterbridge gateway** field set on the corresponding BinktermPHP chat room in the admin. The API `channel` value can be anything — it is a label Matterbridge uses internally to distinguish which gateway a message belongs to; use something descriptive that matches the room.

Key points:

- The Discord `channel` value is the **channel name**, not the channel ID.
- The `[API.binktermphp]` section name must match the `account` value used in `[[gateway.inout]]` (`api.binktermphp`).
- `channel = "api"` works for a single-gateway setup, but when bridging multiple rooms give each API channel a unique name (e.g. `"lobby"`, `"testing"`) so Matterbridge can route inbound messages to the right gateway.
- The `Token` here must match `api.token` in `config/matterbridge.json`.
- The gateway `name` must match the **Matterbridge gateway** field set on the local chat room in the BinktermPHP admin.

## Run Matterbridge

Once the binary is in place and `matterbridge/matterbridge.toml` is configured, start it:

```bash
# Foreground (useful for first-run testing — watch for connection errors)
./matterbridge/matterbridge -conf matterbridge/matterbridge.toml

# Background via nohup
nohup ./matterbridge/matterbridge -conf matterbridge/matterbridge.toml \
    > data/logs/matterbridge.log 2>&1 &
```

Matterbridge must be running before the inbound daemon (`matterbridge_daemon.php`) will successfully poll — the daemon will log errors on each poll cycle if Matterbridge is unreachable.

### systemd example (Matterbridge binary)

```ini
[Unit]
Description=Matterbridge
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/binkterm
ExecStart=/var/www/binkterm/matterbridge/matterbridge -conf matterbridge/matterbridge.toml
Restart=on-failure
RestartSec=5
StandardOutput=append:/var/www/binkterm/data/logs/matterbridge.log
StandardError=inherit

[Install]
WantedBy=multi-user.target
```

This is separate from the BinktermPHP inbound daemon (see [Running the Inbound Daemon](#running-the-inbound-daemon) below), which handles the PHP side of the bridge.

## Configure BinktermPHP

### 1. Global settings (Admin → Chat Rooms → Matterbridge Bridge Settings)

| Field | Description |
|---|---|
| Enable Matterbridge relay | Master on/off switch |
| Matterbridge API URL | URL where Matterbridge's API listens, e.g. `http://127.0.0.1:4240` |
| API token | Must match the `Token` in `matterbridge.toml` |
| Bridge user | The BinktermPHP user account that posts inbound messages into local chat |
| Default username suffix | Appended to local usernames on outbound messages, e.g. ` @ MyBBS` |

These are stored in `config/matterbridge.json`.

### 2. Bridge user (required for inbound)

Create a dedicated user account in BinktermPHP for the bridge bot (e.g. username `Bridge`). Select it in the **Bridge user** field of the Matterbridge settings panel. Inbound messages will appear in local chat posted by this account, with the body formatted as:

```
[DISCORD] <DiscordNick> message text
```

### 3. Per-room settings (Admin → Chat Rooms → edit room)

| Field | Description |
|---|---|
| Enable Matterbridge | Whether this room relays messages |
| Matterbridge gateway | Must match a `[[gateway]] name` in `matterbridge.toml` |
| Username template | Optional override, e.g. `{username} @ MyBBS`. Tokens: `{username}`, `{room_name}` |

## Running the Inbound Daemon

The daemon polls Matterbridge for incoming messages and injects them into local chat:

```bash
# Foreground (for testing)
php scripts/matterbridge_daemon.php

# Background daemon
php scripts/matterbridge_daemon.php --daemon --pid-file=data/run/matterbridge_daemon.pid

# Custom poll interval (default: 2 seconds)
php scripts/matterbridge_daemon.php --poll-interval=1
```

`restart_daemons.sh` treats `matterbridge_daemon` as an **optional** service — it only restarts if it was already running:

```bash
# Start
scripts/restart_daemons.sh --start matterbridge_daemon

# Stop
scripts/restart_daemons.sh --stop matterbridge_daemon

# Restart (only if running)
scripts/restart_daemons.sh matterbridge_daemon

# Restart all daemons — includes matterbridge_daemon if it was running
scripts/restart_daemons.sh
```

Options:

| Flag | Default | Description |
|---|---|---|
| `--daemon` | off | Detach from terminal (POSIX only) |
| `--pid-file=PATH` | `data/run/matterbridge_daemon.pid` | PID file path |
| `--log-level=LEVEL` | `INFO` | `DEBUG`, `INFO`, `WARNING`, `ERROR`, `CRITICAL` |
| `--poll-interval=N` | `2` | Seconds between polls |

The daemon logs to `data/logs/server.log`. It exits immediately if Matterbridge is disabled in `config/matterbridge.json` or if no bridge user is configured.

### systemd example

```ini
[Unit]
Description=BinktermPHP Matterbridge Inbound Daemon
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/binkterm
ExecStart=/usr/bin/php scripts/matterbridge_daemon.php
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

## PHP Usage

Use `BinktermPHP\Chat\ChatMessageService` when application code needs to send local chat messages or talk to Matterbridge directly.

```php
$service = new \BinktermPHP\Chat\ChatMessageService();

// Send a local room message. If the room is bridged, it also relays outbound through Matterbridge.
$service->sendMessage($fromUserId, $roomId, null, 'Hello from BinktermPHP');

// Send directly to a Matterbridge gateway without creating a local chat message.
$service->sendMatterbridgeMessage('my-room', 'Maintenance starts in 10 minutes.', 'System');
```

Pass `$bridgeOutbound = false` to `sendMessage()` when inserting bridge-originated messages to prevent echo loops:

```php
$service->sendMessage($bridgeUserId, $roomId, null, '[DISCORD] <Nick> text', false);
```

## Matterbridge API Endpoints Used

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/api/message` | Send outbound message from BinktermPHP to Matterbridge |
| `GET` | `/api/messages` | Poll inbound messages from remote platforms (drains the queue) |

When a token is configured, BinktermPHP sends it as `Authorization: Bearer <token>`.
