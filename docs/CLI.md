# Command Line Scripts

BinktermPHP includes a full suite of CLI tools for managing your system from the terminal.

## Table of Contents

- [Message Posting Tool](#message-posting-tool)
- [Weather Report Generator](#weather-report-generator)
- [Activity Digest Generator](#activity-digest-generator)
- [Activity Report Sender](#activity-report-sender)
- [Echomail Maintenance Utility](#echomail-maintenance-utility)
- [Subscribe Users to Echo Areas](#subscribe-users-to-echo-areas)
- [Move Messages Between Echo Areas](#move-messages-between-echo-areas)
- [User Management Tool](#user-management-tool)
- [Binkp Server Management](#binkp-server-management)
- [Packet Processing](#packet-processing)
- [Admin Daemon](#admin-daemon)
- [Admin Client](#admin-client)
- [Nodelist Updates](#nodelist-updates)
- [Geocoding](#geocoding)
- [Database Backup](#database-backup)
- [Crashmail Poll](#crashmail-poll)
- [FREQ File Pickup](#freq-file-pickup)
- [Outbound FREQ (File Request)](#outbound-freq-file-request)
- [Echomail Robots](#echomail-robots)
- [Create Translation Catalog](#create-translation-catalog)
- [Generate Ad](#generate-ad)
- [Log Rotate](#log-rotate)
- [Post Ad](#post-ad)
- [Restart Daemons](#restart-daemons)
- [Who](#who)

## Message Posting Tool
Post netmail or echomail from command line:

```bash
# Send netmail
php scripts/post_message.php --type=netmail \
  --from=1:153/149.500 --from-name="John Doe" \
  --to=1:153/149 --to-name="Jane Smith" \
  --subject="Test Message" \
  --text="Hello, this is a test!"

# Post to echomail
php scripts/post_message.php --type=echomail \
  --from=1:153/149.500 --from-name="John Doe" \
  --echoarea=GENERAL --subject="Discussion Topic" \
  --file=message.txt

# List available users and echo areas
php scripts/post_message.php --list-users
php scripts/post_message.php --list-areas
```

## Weather Report Generator
Generate detailed weather forecasts for posting to echomail areas:

```bash
# Generate weather report using configured locations
php scripts/weather_report.php

# Test with demo data (no API key required)
php scripts/weather_report.php --demo

# Post weather report to echomail area
php scripts/weather_report.php --post --areas=WEATHER --user=admin

# Use custom configuration file
php scripts/weather_report.php --config=/path/to/custom/weather.json
```

The weather script is fully configurable via JSON configuration files, supporting any worldwide locations with descriptive forecasts and current conditions. See [scripts/README_weather.md](../scripts/README_weather.md) for detailed setup instructions and configuration examples.

## Activity Digest Generator
Generate a monthly (or custom) digest covering polls, shoutbox, chat, and message activity:

```bash
# Default: last 30 days, ASCII to stdout
php scripts/activity_digest.php

# Custom time range and output file
php scripts/activity_digest.php --from=2026-01-01 --to=2026-01-31 --output=digests/january.txt

# ANSI output for BBS posting
php scripts/activity_digest.php --since=30d --format=ansi --output=digests/last_month.ans
```

## Activity Report Sender
Generate an ANSI digest and send it as netmail to the sysop (weekly by default):

```bash
# Default weekly digest to sysop
php scripts/send_activityreport.php

# Custom range and recipient
php scripts/send_activityreport.php --from=2026-01-01 --to=2026-01-31 --to-name=sysop
```

Weekly cron example:

```bash
0 9 * * 1 /usr/bin/php /path/to/binkterm/scripts/send_activityreport.php --since=7d
```

## Echomail Maintenance Utility
Manage echomail storage by purging old messages based on age or message count limits:

```bash
# Delete messages older than 90 days from all echoes
php scripts/echomail_maintenance.php --echo=all --max-age=90

# Keep only newest 500 messages per echo
php scripts/echomail_maintenance.php --echo=all --max-count=500

# Preview what would be deleted (dry run)
php scripts/echomail_maintenance.php --echo=COOKING --max-age=180 --dry-run

# Combined age and count limits for specific echo
php scripts/echomail_maintenance.php --echo=SYNCDATA --max-age=90 --max-count=2000
```

The maintenance script provides flexible echomail cleanup with age-based deletion, count-based limits, dry-run preview mode, and per-echo or bulk processing. See [scripts/README_echomail_maintenance.md](../scripts/README_echomail_maintenance.md) for detailed documentation, cron job examples, and best practices.

## Subscribe Users to Echo Areas
Forcefully subscribe users to echo areas for important announcements or required areas:

```bash
# List all echo areas with subscriber counts
php scripts/subscribe_users.php list

# Show detailed stats for a specific area
php scripts/subscribe_users.php stats ANNOUNCE@lovlynet

# Subscribe all active users to an area
php scripts/subscribe_users.php all ANNOUNCE@lovlynet

# Subscribe a specific user to an area
php scripts/subscribe_users.php user john GENERAL@fidonet
```

The subscription tool allows administrators to:
- Bulk subscribe all active users to important areas (announcements, general discussion, etc.)
- Subscribe individual users to specific areas
- View subscription statistics and current subscriber lists
- Skip users who are already subscribed (idempotent)
- Mark admin-forced subscriptions with `subscription_type = 'admin'`

This is particularly useful for:
- Ensuring all users see system announcements
- Pre-subscribing new users to recommended areas
- Managing default subscriptions for community areas

## Move Messages Between Echo Areas
Move all messages from one echo area to another for reorganization or consolidation:

```bash
# Move messages by echo area ID
php scripts/move_messages.php --from=15 --to=23

# Move messages by echo tag and domain
php scripts/move_messages.php --from-tag=OLD_ECHO --to-tag=NEW_ECHO --domain=fidonet

# Preview what would be moved (dry run)
php scripts/move_messages.php --from=15 --to=23 --dry-run

# Move messages quietly (suppress output)
php scripts/move_messages.php --from-tag=TEST --to-tag=GENERAL --domain=fsxnet --quiet
```

The move_messages script transfers all messages from a source echo area to a destination area, automatically updating message counts for both areas. This is useful for consolidating duplicate echoes, reorganizing areas, or fixing misrouted messages. The script supports both echo area IDs and tag-based lookups, includes confirmation prompts, and provides dry-run mode for safe previewing.

## User Management Tool
Manage user accounts from the command line:

```bash
# List all active non-admin users
php scripts/user-manager.php list

# List all users including admins and inactive
php scripts/user-manager.php list --show-admin --show-inactive

# Show detailed information for a user
php scripts/user-manager.php show username

# Change a user's password (interactive)
php scripts/user-manager.php passwd username

# Create a new user (interactive)
php scripts/user-manager.php create alice --real-name="Alice Smith" --email=alice@example.com

# Create an admin user with password (non-interactive)
php scripts/user-manager.php create sysop --admin --password=secret123

# Delete a user (requires confirmation)
php scripts/user-manager.php delete testuser --confirm

# Activate or deactivate user accounts
php scripts/user-manager.php activate username
php scripts/user-manager.php deactivate username
```

Options:
- `--password=<pwd>`: Set password non-interactively
- `--real-name=<name>`: Set real name for new users
- `--email=<email>`: Set email for new users
- `--admin`: Create user as administrator
- `--show-admin`: Include admins in user list
- `--show-inactive`: Include inactive users in list
- `--confirm`: Confirm destructive operations
- `--non-interactive`: Don't prompt for input

## Binkp Server Management

The binkp server (`binkp_server.php`) listens for incoming connections from other FTN nodes. When another system wants to send you mail or pick up outbound packets, they connect to your binkp server. This is essential for receiving crashmail (direct delivery) and for other nodes to poll your system.

Polling (`binkp_poll.php`) makes outbound connections to your uplinks to send and receive mail. You can run polling manually or via cron. Polling works on all platforms.

**Note:** The binkp server requires `pcntl_fork()` which is not available on Windows.

### Start Binkp Server
```bash
# Start server in foreground
php scripts/binkp_server.php

# Start as daemon (Unix-like systems)
php scripts/binkp_server.php --daemon

# Custom port and logging
php scripts/binkp_server.php --port=24554 --log-level=DEBUG
```

### Running as a System Service

To run the binkp server automatically on system startup, create a systemd service file:

```bash
sudo nano /etc/systemd/system/binkp.service
```

```ini
[Unit]
Description=BinktermPHP Binkp Server
After=network.target postgresql.service

[Service]
Type=simple
User=yourusername
Group=yourusername
WorkingDirectory=/path/to/binkterm
ExecStart=/usr/bin/php /path/to/binkterm/scripts/binkp_server.php
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Replace `yourusername` with the user account that runs CLI scripts (the same user that owns the `data/outbound` directory).

Enable and start the service:
```bash
sudo systemctl daemon-reload
sudo systemctl enable binkp
sudo systemctl start binkp
sudo systemctl status binkp
```

### Manual Polling
```bash
# Poll specific uplink
php scripts/binkp_poll.php 1:153/149

# Poll all configured uplinks
php scripts/binkp_poll.php --all

# Poll all configured uplinks only if there are packets in the outbound queue
php scripts/binkp_poll.php --all --queued-only

# Test connection without polling
php scripts/binkp_poll.php --test 1:153/149
```

### System Status
```bash
# Show all status information
php scripts/binkp_status.php

# Show specific information
php scripts/binkp_status.php --uplinks
php scripts/binkp_status.php --queues
php scripts/binkp_status.php --config

# JSON output for scripting
php scripts/binkp_status.php --json
```

### Automated Scheduler
```bash
# Start scheduler daemon
php scripts/binkp_scheduler.php --daemon

# Run once and exit
php scripts/binkp_scheduler.php --once

# Show schedule status
php scripts/binkp_scheduler.php --status

# Custom interval to check outgoing queues (seconds)
php scripts/binkp_scheduler.php --interval=120
```

### Debug Connection Issues
```bash
# Detailed connection debugging
php scripts/debug_binkp.php 1:153/149
```

## Packet Processing
```bash
# Process inbound packets
php scripts/process_packets.php
```

By default, leftover unprocessed files in `data/inbound/` are moved to `data/inbound/unprocessed/` after they have been untouched for 24 hours.
Set `BINKP_DELETE_UNPROCESSED_FILES=true` in `.env` to delete those stale files instead.

### Fidonet Bundle Extraction
Fidonet day bundles (e.g., `.su0`, `.mo1`, `.we1`) and legacy archives like `.arc`, `.arj`, `.lzh`, `.rar` may contain `.pkt` files. BinktermPHP will try ZIP first, then fall back to external extractors.

Configure extractors via `.env`:
```bash
ARCMAIL_EXTRACTORS=["7z x -y -o{dest} {archive}","unzip -o {archive} -d {dest}"]
```

Install a compatible extractor (7-Zip recommended) so non-ZIP bundles can be unpacked.

## Admin Daemon
The admin daemon is a lightweight control socket that accepts authenticated commands to run backend tasks from inside the app. It listens on a Unix socket by default (Linux/macOS) and TCP on Windows.

```bash
# Start in foreground (default)
php scripts/admin_daemon.php

# Specify socket and secret
php scripts/admin_daemon.php --socket=unix:///tmp/binkterm_admin.sock --secret=change_me

# Windows example (TCP loopback)
php scripts/admin_daemon.php --socket=tcp://127.0.0.1:9065 --secret=change_me
```

Example usage from PHP:
```php
$client = new \BinktermPHP\Admin\AdminDaemonClient();
$client->processPackets();
$client->binkPoll('1:153/149');
```

Command line client:
```bash
php scripts/admin_client.php process-packets
php scripts/admin_client.php binkp-poll 1:153/149
```

Environment options:
- `ADMIN_DAEMON_SOCKET`: `unix:///path.sock` or `tcp://127.0.0.1:PORT`
- `ADMIN_DAEMON_SOCKET_PERMS`: Unix socket permissions (octal, e.g. `0660`)
- `ADMIN_DAEMON_SECRET`: Shared secret required on connect
- `ADMIN_DAEMON_PID_FILE`: Optional PID file location

## Nodelist Updates

> **Note:** The recommended method for updating nodelists is through file area rules combined with the `import_nodelist` tool, which processes nodelists received via TIC file distribution. `update_nodelists.php` is an alternative for sysops who prefer to pull nodelists directly from URL feeds.

Downloads and imports nodelists from configured URL sources.

### Configuration
Create `config/nodelists.json` (or run the script once to generate an example):

```json
{
    "sources": [
        {
            "name": "FidoNet",
            "domain": "fidonet",
            "url": "https://example.com/NODELIST.Z|DAY|",
            "enabled": true
        },
        {
            "name": "FSXNet",
            "domain": "fsxnet",
            "url": "https://bbs.nz/fsxnet/FSXNET.ZIP",
            "enabled": true
        }
    ],
    "settings": {
        "keep_downloads": 3,
        "timeout": 300,
        "user_agent": "BinktermPHP Nodelist Updater"
    }
}
```

### URL Macros
URLs support date macros for dynamic nodelist filenames:

| Macro | Description | Example |
|-------|-------------|---------|
| `\|DAY\|` | Day of year (1-366) | 23 |
| `\|YEAR\|` | 4-digit year | 2026 |
| `\|YY\|` | 2-digit year | 26 |
| `\|MONTH\|` | 2-digit month | 01 |
| `\|DATE\|` | 2-digit day of month | 22 |

Example: `https://example.com/NODELIST.Z|DAY|` becomes `NODELIST.Z23` on day 23.

### Usage
```bash
# Run nodelist update (downloads and imports all enabled sources)
php scripts/update_nodelists.php

# Quiet mode (for cron jobs)
php scripts/update_nodelists.php --quiet

# Force update even if recently updated
php scripts/update_nodelists.php --force

# Show help and available macros
php scripts/update_nodelists.php --help
```

## Geocoding

Both the BBS Directory and the Nodelist map use coordinates resolved from location strings via the [Nominatim](https://nominatim.openstreetmap.org/) geocoding API. Results are permanently cached in the `geocode_cache` table, so a given location string is only ever looked up once regardless of which script processed it first.

The Nominatim API is rate-limited to **one request per second**. Scripts enforce this automatically.

Environment variables (all optional):

| Variable | Default | Description |
|---|---|---|
| `BBS_DIRECTORY_GEOCODING_ENABLED` | `true` | Set to `false` to disable all geocoding |
| `BBS_DIRECTORY_GEOCODER_EMAIL` | _(none)_ | Contact email sent in API requests (good practice) |
| `BBS_DIRECTORY_GEOCODER_URL` | Nominatim endpoint | Override with a self-hosted instance |
| `BBS_DIRECTORY_GEOCODER_USER_AGENT` | Auto-generated | Custom `User-Agent` header |

### Geocode Nodelist

Populates `latitude`/`longitude` on nodelist entries that have a `location` field but no coordinates yet.

```bash
# Geocode all pending nodelist entries
php scripts/geocode_nodelist.php

# Limit to 100 entries per run (good for cron)
php scripts/geocode_nodelist.php --limit=100

# Re-geocode entries that already have coordinates
php scripts/geocode_nodelist.php --force

# Preview without writing changes
php scripts/geocode_nodelist.php --dry-run
```

Options:
- `--limit=N` — Process at most N nodes (default: all pending)
- `--force` — Re-geocode nodes that already have coordinates
- `--dry-run` — Show what would be processed without making changes

Cron example (nightly, 100 nodes at a time):

```
0 3 * * * /usr/bin/php /path/to/binkterm/scripts/geocode_nodelist.php --limit=100
```

### Geocode BBS Directory

Backfills coordinates for BBS Directory entries that have a location set but no coordinates.

```bash
# Geocode all pending BBS directory entries
php scripts/geocode_bbs_directory.php

# Limit to N entries
php scripts/geocode_bbs_directory.php --limit=50

# Preview without writing changes
php scripts/geocode_bbs_directory.php --dry-run
```

Options:
- `--limit=N` — Process at most N entries
- `--dry-run` — Show how many rows would be updated without writing changes

## Database Backup

Creates PostgreSQL database backups using `pg_dump` with connection settings from `.env`. Backups are saved to the `backups/` directory with a timestamp in the filename.

```bash
# Default SQL backup
php scripts/backup_database.php

# Custom format with compression
php scripts/backup_database.php --format=custom --compress

# Clean up backups older than 14 days
php scripts/backup_database.php --cleanup=14

# Quiet mode for cron
php scripts/backup_database.php --quiet
```

Options:
- `--format=TYPE` — Backup format: `sql`, `custom`, or `tar` (default: `sql`)
- `--compress` — Enable compression
- `--cleanup=DAYS` — Delete backups older than X days (default: 30)
- `--quiet` — Suppress output except errors

## Crashmail Poll

Processes the crashmail queue, attempting direct delivery of messages marked with the crash attribute.

```bash
# Process crashmail queue
php scripts/crashmail_poll.php

# Limit items processed
php scripts/crashmail_poll.php --limit=5

# Preview without delivering
php scripts/crashmail_poll.php --dry-run --verbose
```

Options:
- `--limit=N` — Maximum items to process (default: 10)
- `--verbose` — Show detailed output
- `--dry-run` — Check queue without attempting delivery

## FREQ File Pickup

Use this script when you have sent a FREQ request to a remote node that cannot
reach you via crashmail. The remote system queues the requested files for you;
run this script to connect outbound and collect them.

```bash
# Basic pickup — hostname resolved from nodelist
php scripts/freq_pickup.php 1:123/456

# Specify hostname manually
php scripts/freq_pickup.php 1:123/456 --hostname=bbs.example.com

# Custom port and session password
php scripts/freq_pickup.php 1:123/456 --hostname=bbs.example.com --port=24554 --password=secret

# Verbose debug output
php scripts/freq_pickup.php 1:123/456 --log-level=DEBUG
```

Options:
- `--hostname=HOST` — Hostname or IP to connect to (auto-resolved from nodelist if omitted)
- `--port=PORT` — Port number (default: `24554`)
- `--password=PASS` — Session password
- `--log-level=LVL` — `DEBUG`, `INFO`, `WARNING`, or `ERROR` (default: `INFO`)

The script resolves your local address from the same network as the destination
so the remote system recognises you by the correct AKA. Any outbound packets
queued for that node are also sent during the session.

## Outbound FREQ (File Request)

Requests one or more files from a remote binkp node. Two modes are supported:

- **Default (.req file)** — builds a Bark-style `.req` file (FTS-0008) and
  sends it to the remote node as a regular file transfer. The remote FREQ
  handler processes the request and sends the files back, either in the same
  session or the next time it connects. Use this with any FTN node.
- **-g (M_GET / live-session)** — sends binkp `M_GET` commands during the
  active session (FSP-1011). The remote must support binkp M_GET FREQ natively.
  Use this when connecting to another BinktermPHP node or a known-compatible
  system.

Received files that are not FidoNet infrastructure files (`.pkt`, `.tic`,
day-of-week bundles, etc.) are stored in the specified user's private file area
under the **FREQ Responses** (`incoming`) subfolder. Infrastructure files are
left in `data/inbound/` for `process_packets` to handle.

```bash
# Request a file by magic name (default .req mode)
php scripts/freq_getfile.php 3:770/220@fidonet NZINTFAQ

# Request multiple files
php scripts/freq_getfile.php 1:123/456 ALLFILES FILES

# Store received files for a specific user
php scripts/freq_getfile.php --user=john 1:123/456 ALLFILES

# Use a session password
php scripts/freq_getfile.php --password=SECRET 1:123/456 MYFILE.ZIP

# Use binkp M_GET (live-session FREQ)
php scripts/freq_getfile.php -g 1:123/456 ALLFILES

# Override hostname and port
php scripts/freq_getfile.php --hostname=bbs.example.com --port=24554 1:123/456 ALLFILES
```

Options:
- `-g` — Use binkp M_GET (live-session FREQ) instead of `.req` file
- `--user=USERNAME` — Store received files for this user (default: first admin)
- `--password=PASS` — Area password sent with the request
- `--hostname=HOST` — Override hostname (skip nodelist/DNS lookup)
- `--port=PORT` — Override port (default: `24554`)
- `--log-level=LVL` — `DEBUG`, `INFO`, `WARNING`, or `ERROR` (default: `INFO`)
- `--log-file=FILE` — Log file path (default: `data/logs/freq_getfile.log`)
- `--no-console` — Suppress console output

## Echomail Robots

Runs the echomail robot processors — a rule-based framework that watches echo areas for matching messages and dispatches them to configured processors.

```bash
# Run all active robots
php scripts/echomail_robots.php

# Run a specific robot by ID
php scripts/echomail_robots.php --robot-id=3

# Preview without making changes
php scripts/echomail_robots.php --dry-run

# Debug message parsing
php scripts/echomail_robots.php --debug
```

See [docs/Robots.md](Robots.md) for information on creating custom processors.

## Create Translation Catalog

Generates i18n translation catalogs for new locales using AI (Claude or OpenAI). Translates from the `en` baseline catalog.

```bash
# Generate a French translation catalog
php scripts/create_translation_catalog.php fr

# Use a specific model
php scripts/create_translation_catalog.php fr --model=claude-sonnet-4-6

# Set batch size for large catalogs
php scripts/create_translation_catalog.php de --batch-size=50
```

## Generate Ad

Generates ANSI advertisement art from current system settings (BBS name, node address, networks, etc.).

```bash
# Output ANSI ad to stdout
php scripts/generate_ad.php --stdout

# Save to file
php scripts/generate_ad.php --output=bbs_ads/myad.ans
```

## Log Rotate

Rotates and archives log files in `data/logs/`, keeping a configurable number of old logs.

```bash
# Rotate logs, keep 10 most recent
php scripts/logrotate.php

# Keep only 5 logs
php scripts/logrotate.php --keep=5

# Preview without rotating
php scripts/logrotate.php --dry-run
```

## Post Ad

Posts an ANSI advertisement from the `bbs_ads/` directory to an echomail area.

```bash
# Post a random ad
php scripts/post_ad.php --echoarea=BBS_ADS --domain=fidonet

# Post a specific ad file
php scripts/post_ad.php --echoarea=BBS_ADS --domain=fidonet --ad=claudes1.ans --subject="BBS Advertisement"
```

## Restart Daemons

Stops and restarts BinktermPHP daemons (admin daemon, scheduler, BinkP server, telnet, SSH, MRC, DOS bridge, Gemini). Uses PID files in `data/run/` to manage processes.

```bash
# Restart all services
bash scripts/restart_daemons.sh

# Restart a single service
bash scripts/restart_daemons.sh binkp_server

# Start a single service
bash scripts/restart_daemons.sh --start telnet

# Stop a single service without restarting
bash scripts/restart_daemons.sh --stop mrc

# List available services
bash scripts/restart_daemons.sh --list
```

## Who

Shows currently active users — those who have been active within the last N minutes.

```bash
# Show users active in the last 15 minutes (default)
php scripts/who.php

# Custom time window
php scripts/who.php --minutes=30
```

## Admin Client

Sends commands to the running admin daemon from the command line.

```bash
php scripts/admin_client.php process-packets
php scripts/admin_client.php binkp-poll 1:153/149
```
