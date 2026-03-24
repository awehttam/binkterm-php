# BinktermPHP Frequently Asked Questions

## Troubleshooting

### Q: The page looks broken after an upgrade — missing features, broken menus, or "loadI18nNamespaces is not defined" errors

**A:** This is a stale service worker cache issue. The old service worker is still serving cached JavaScript and CSS from before the upgrade. You need to unregister the service worker so the browser fetches fresh files.

**Desktop browsers (Chrome / Edge)**

1. Open DevTools — press `F12` or right-click → Inspect
2. Go to **Application** → **Service Workers**
3. Click **Unregister** next to the BinktermPHP service worker
4. Reload the page (`F5`)

**Desktop browsers (Firefox)**

1. Open `about:debugging#/runtime/this-firefox` in the address bar
2. Find the BinktermPHP worker and click **Unregister**
3. Reload the page

**Desktop — quick alternative (all browsers)**

A hard refresh bypasses the cache without unregistering the service worker:
- Windows/Linux: `Ctrl + Shift + R`
- Mac: `Cmd + Shift + R`

**Mobile (Chrome on Android)**

1. Open Chrome's menu (three dots) → **Settings** → **Privacy and security** → **Clear browsing data**
2. Select **Cached images and files** and **Cookies and site data** for the BinktermPHP site
3. Tap **Clear data**, then reload

**Mobile (Safari on iOS)**

1. Go to **Settings** → **Safari** → **Clear History and Website Data**
2. Reload the BinktermPHP site

**Note:** After clearing, you will be logged out and will need to sign in again. This is normal.

### Q: How do I handle LHA/LZH archives?
### Q: I can't unpack the AmigaNet Node List
### Q: I can't view Amiga archives
**A:** Install `lhasa`.

On Debian/Ubuntu:
```bash
apt-get install lhasa
```

---

## Support

### Q: Where can I get support?
**A:** There are several ways to get help with BinktermPHP:

- **Discussions**: For general questions, help, and community support, visit the [Claude's BBS](https://claudes.lovelybits.org) or [GitHub Discussions](https://github.com/awehttam/binkterm-php/discussions)
- **Bug Reports**: If you've found a bug, please file an issue at [GitHub Issues](https://github.com/awehttam/binkterm-php/issues)
- **Feature Requests**: Have an idea for a new feature? Submit it at [GitHub Issues](https://github.com/awehttam/binkterm-php/issues)

---

## Installation & Setup

### Q: What are the minimum requirements for BinktermPHP?
**A:** BinktermPHP requires:
- PHP 8.1 or higher
- PostgreSQL 12 or higher
- Composer for dependency management
- A web server (Apache, Nginx, etc.)
- Operating System: Linux/UNIX is recommended.  MacOS and *Windows should also work

### Q: How do I configure the database connection?
**A:** Edit `.env` and configure the database settings:
```
DB_HOST=localhost
DB_PORT=5432
DB_NAME=binktest
DB_USER=your_username
DB_PASS=your_password
```

Use `.env.example` as a reference or copy it over to `.env` to get started.

### Q: What directory permissions are required?
**A:** The `data/outbound` directory needs special permissions for the mailer to work:
```bash
chmod a+rwxt data/outbound
```
This allows the web server and CLI scripts to create and manage outbound packets.

### Q: How do I run database migrations?
**A:** Run the migration script:
```bash
php scripts/setup.php
```
This will apply all pending migrations from the `database/migrations/` directory.

### Q: How do I move my BinktermPHP installation from one system to another?

**A:** The simplest approach is to copy the entire BinktermPHP directory to the new server along with a database dump.

**Step 1 — Dump the database on the old system**

```bash
pg_dump -U your_db_user your_db_name > binkterm_backup.sql
```

**Step 2 — Stop the daemons on the old system**

Stop the BinkP server, admin daemon, and any other BinktermPHP processes before copying to avoid transferring files mid-write.

**Step 3 — Copy the directory to the new system**

Copy the entire BinktermPHP directory including the dump file:

```bash
rsync -av /path/to/binkterm-php/ newserver:/path/to/binkterm-php/
```

Or use `scp`, a tarball, or whatever transfer method suits your setup.

**Step 4 — Create the database and user on the new system**

```bash
sudo -u postgres psql
```

```sql
CREATE USER your_db_user WITH PASSWORD 'your_password';
CREATE DATABASE your_db_name OWNER your_db_user;
GRANT ALL PRIVILEGES ON DATABASE your_db_name TO your_db_user;
\q
```

**Step 5 — Restore the database**

Run the restore as the database user you just created (the same user BinktermPHP connects as):

```bash
psql -U your_db_user -d your_db_name < binkterm_backup.sql
```

**Step 6 — Update `.env` for the new environment**

Edit `.env` on the new system and update anything that is host-specific:

- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` — if the database details differ on the new server
- `SITE_URL` — update to the new hostname or URL

**Step 7 — Run setup**

```bash
php scripts/setup.php
```

This ensures file permissions are correct and applies any pending migrations.

**Step 8 — Start the daemons**

Start the BinkP server, admin daemon, and any other services on the new system.

**Notes:**
- `vendor/` does not need to be reinstalled — Composer's autoloader uses relative paths and works correctly regardless of where the installation directory lives on the filesystem.
- If the new system has a different public hostname or IP address, update your LovlyNet registration (`php scripts/lovlynet_setup.php --update`) and notify any downlinks of the change.
- Verify the web server on the new system points to `public_html/` as its document root.

### Q: How do I switch from installer/zip file installation to git based installation?
**A:** The safest method is to do a fresh git clone and then copy your local
runtime and configuration files into it.

Recommended steps:

1. Back up your current installation, especially `.env`, `data/`, and any local customizations.
2. Clone the repository into a new directory:
```bash
git clone https://github.com/awehttam/binkterm-php.git
```
3. Copy these from the old installation into the new clone:
- `.env`
- `data/`
- any intentional custom templates or local files
4. Install dependencies if needed:
```bash
composer install
```
5. Run setup:
```bash
php scripts/setup.php
```
6. Restart the daemons and web services.

This is preferred over converting the existing ZIP-based directory in place,
because ZIP installs usually do not contain `.git` history and may already
have local file changes that are hard to reconcile cleanly.

If you must convert the existing directory in place, you can initialize git,
add the remote, fetch, and check out the branch:

```bash
git init
git remote add origin https://github.com/awehttam/binkterm-php.git
git fetch origin
git checkout -b main origin/main
```

That approach is riskier and more likely to leave you with a dirty tree or
conflicts if the ZIP-installed files differ from the branch you are checking
out. After converting in place, run:

```bash
composer install
php scripts/setup.php
```

### Q: How do I switch from git install to installer/zip based install?
**A:** You can do this in place by unzipping the ZIP release over the existing
git-based installation and then deleting the `.git` directory. That is the
quickest method and it will usually preserve your existing `vendor/`
dependencies.

Quick in-place method:

1. Back up `.env`, `data/`, and any local custom files.
2. Unzip the ZIP release over the existing git-based installation.
3. Delete the `.git/` directory.
4. Run:
```bash
php scripts/setup.php
```
5. Restart the daemons and web services.

If `vendor/` is missing, dependencies changed, or you want to ensure the
installed packages match `composer.lock`, also run:

```bash
composer install
```

The safer method is to unpack the ZIP release into a new directory and then
copy your local runtime and configuration files into it.

Recommended steps:

1. Back up your current installation, especially `.env`, `data/`, and any local customizations.
2. Download and extract the BinktermPHP ZIP release into a new directory.
3. Copy these from the git-based installation into the new ZIP-based install:
- `.env`
- `data/`
- any intentional custom templates or local files
4. If the resulting install does not already have a working `vendor/` directory, run:
```bash
composer install
```
5. Run setup:
```bash
php scripts/setup.php
```
6. Restart the daemons and web services.

Do not copy the old `.git/` directory into the ZIP-based install. If you later
decide to return to git-based updates, it is better to clone the repository
fresh and then copy `.env` and `data/` back into that clone.

---

## Configuration

### Q: Where is the binkp mailer configured?
**A:** The binkp mailer is configured in `data/binkp.json`. This file contains:
- System information (name, address, sysop, location)
- Binkp server settings (port, timeout, max connections)
- Uplink configurations (addresses, passwords, domains)
- Security settings

### Q: How do I add a new FTN network/uplink?

**A:** Use the BBS Settings page from the Admin Menu

### Q: What is the SITE_URL setting for?
**A:** `SITE_URL` in `.env` is used for generating full URLs (share links, password reset emails, etc.). This is important if your server is behind a reverse proxy or load balancer where `$_SERVER['HTTPS']` may not be set correctly.

### Q: How do I enable additional themes?
**A:** Copy the example themes configuration and customize it:

```bash
cp config/themes.json.example config/themes.json
```

Then edit `config/themes.json` to add or modify available themes:

```json
{
    "Amber": "/css/amber.css",
    "Cyberpunk": "/css/cyberpunk.css",
    "Dark": "/css/dark.css",
    "Green Term": "/css/greenterm.css",
    "Regular": "/css/style.css",
    "My Custom Theme": "/css/mycustom.css"
}
```

**Key points:**
- The key is the display name shown to users in their settings
- The value is the path to the CSS file (relative to `public_html/`)
- CSS files must be placed in `public_html/css/`
- Users can select themes from their account settings page
- The "Regular" theme should always point to `/css/style.css` (the base theme)

**Creating a new theme:**
1. Copy an existing theme CSS file (e.g., `public_html/css/style.css`)
2. Rename it to your theme name (e.g., `mytheme.css`)
3. Customize the colors and styles
4. Add an entry to `config/themes.json`

Users will see the new theme in their settings dropdown immediately after the config file is updated.

---

## Echo Areas

### Q: How do I create a local-only echo area?
**A:** When creating or editing an echo area, check the "Local Only" checkbox and set the domain to "local". Local echo areas:
- Store messages in the database normally
- Do NOT transmit messages to uplinks
- Still require a domain to be set (use a name like "local")
- Use the system address for message origin

### Q: Why do I get "Can not determine sending address for this network - missing uplink?"
**A:** This error occurs when posting to an echo area whose domain has no configured uplink. Solutions:
1. For local-only areas: Enable the "Local Only" flag on the echo area
2. For networked areas: Add an uplink configuration in `binkp.json` for that domain

### Q: What is the uplink_address field on an echo area actually used for?
**A:** It is a per-echo-area override for the outbound packet destination. For most installations it should be left blank.

When BinktermPHP spools an outbound echomail message it determines where to send it using this priority order:

1. **Echo area `uplink_address`** — if set, this address is used for that area only
2. **Domain-level uplink** — the uplink in `binkp.json` whose `domain` matches the echo area's domain
3. **Fallback** — the uplink in `binkp.json` marked `"default": true`, or if none is marked, the first enabled uplink
4. **None** — message is not sent upstream (effectively local delivery)

In practice, for a typical setup, step 2 handles everything and `uplink_address` is always left blank. It would only be needed if you had two different uplinks on the same domain and wanted specific echo areas routed to a specific one — an uncommon scenario.

**Note:** The `uplink_address` stored here is just the FTN address (e.g. `21:1/100`). The actual connection details (hostname, port, password) are always defined in `binkp.json` — this field only selects *which* of those configured uplinks to use.

### Q: Why does ANSI art render incorrectly?
**A:**
- Make sure you're using a monospace font such as Courier new.  Non mono-space fonts will not render ANSI correctly.
- 
---

## Netmail

### Q: How does crashmail work?
**A:** Crashmail attempts direct delivery to the destination node instead of routing through your uplink. Enable it in `binkp.json`:
```json
"crashmail": {
  "enabled": true,
  "max_attempts": 3,
  "retry_interval": 3600
}
```

(see binkp.json.example for complete configuration reference)

When sending netmail, check the "Crash" option to attempt direct delivery.

### Q: Why isn't my netmail being delivered?
**A:** Check:
1. The destination address is valid
2. Your uplink is configured correctly in `binkp.json`
3. The outbound directory is writable
4. Run `php cli/binkp_poll.php --domain=<domain>` to poll your uplink
5. Check `data/logs/packets.log` and `data/logs/binkp_poll.log` for errors

### Q: If a packet contains multiple messages and one fails, are other messages affected?
**A:** It depends on the failure type:

- **Single message exception** (e.g. database error, malformed message data): Only that message is skipped. Processing continues normally for all remaining messages in the packet.
- **Undeliverable netmail** (no matching local user found by address or name): The message is dropped with a detailed log entry (from/to/subject/date/MSGID) and processing continues. The original `.pkt` file is also preserved to `data/undeliverable/` for manual inspection.
- **Echomail from an insecure session** (security rejection): Processing stops immediately — the rest of the packet is abandoned and moved to the error directory.

In the first two cases the packet is still considered successfully processed even if individual messages were skipped.

---

## Binkp Server & Polling

### Q: How do I only connnect to my uplink if traffic available?
**A:**

Use the --queued-only switch to binkp_poll.php.  In this mode binkp_poll will only poll the uplink if there are packets
in the queue.

### Q: If an uplink is configured to use CRAM-MD5 (`crypt`), can a remote system still dial in using plaintext authentication?
**A:** Yes, by default it can.

When the server sees that CRAM-MD5 is available it sends a challenge, but it will still accept a plaintext password response unless `security.allow_plaintext_fallback` is set to `false` in `binkp.json`.

So the current behavior is:

- `crypt` enabled and `allow_plaintext_fallback = true` (default): CRAM-MD5 is offered, but plaintext is still accepted
- `crypt` enabled and `allow_plaintext_fallback = false`: if a challenge was sent, the remote must use CRAM-MD5

Note: the server currently sends a CRAM-MD5 challenge if any configured uplink has `crypt` enabled, not only the specific remote that connected.

### Q: What's the difference between polling and the binkp server?
**A:**
- **Polling** (`binkp_poll.php`): Your system initiates a connection to your uplink to send/receive mail
- **Binkp server** (`binkp_server.php`): Listens for incoming connections from other systems (downlinks, direct connects)

---

## Users & Authentication

### Q: Why can't a user register with their name?
**A:** Usernames and real names must be unique (case-insensitive). If "John Doe" exists, another user cannot register as "john doe" or "JOHN DOE". This prevents impersonation in FidoNet messages.

### Q: How do gateway tokens work?
**A:** Gateway tokens provide temporary authentication for external services:
1. For example, User visits `/bbslink` while logged in
2. System generates a one-time token (valid for 5 minutes)
3. User is redirected to the external service with the token
4. External service calls back to verify the token via API
5. Token is marked as used (single-use)


### Q: How do I make a user an admin?
**A:** Set the 'admin' flag when editing the user through the web interface
**A:** Update the user's `is_admin` flag in the database:
```sql
UPDATE users SET is_admin = TRUE WHERE username = 'username';
```

---

## Troubleshooting

### Q: Where are the log files?
**A:**
- System daemons and tools: `data/logs/`
- PHP errors: Check your web server's error log
- Binkp sessions: The session logs are always recorded by the binkp daemons.

### Q: Messages aren't being imported from packets
**A:** Check:
1. The inbound directory path in `binkp.json` is correct
2. The packet processor is running: `php cli/process_packets.php`
3. Check `data/logs/packets.log` for parsing errors
4. Verify the echo area exists and is active

### Q: My timestamps are wrong
**A:** Ensure:
1. Your server's timezone is set correctly
2. PostgreSQL timezone matches your expectations
3. The `timezone` setting in `binkp.json` is correct
4. PHP's `date.timezone` is configured in `php.ini`

### Q: How do I debug binkp connection issues?
**A:**
1. Enable verbose logging in your uplink's configuration
2. Check `data/logs/binkp_poll.log` after a poll attempt and `data/logs/binkp_server.log` for incoming connections
3. Try connecting manually: `telnet hub.example.com 24554`
4. Verify your password matches what your uplink expects
5. Check firewall rules for accept policies for outbound and inbound port 24554

---

## Maintenance

### Q: How do I clean up old messages?
**A:** Use the echomail maintenance script:
```bash
# Preview what would be deleted (dry run)
php scripts/echomail_maintenance.php --echo=all --domain=fidonet --max-age=365 --dry-run

# Actually delete old messages
php scripts/echomail_maintenance.php --echo=all --domain=fidonet --max-age=365
```

### Q: I can't delete an echo area because it has messages. How do I remove it?
**A:** Purge the echo's messages first, then delete the area:
```bash
# Preview purge for a single echo (dry run)
php scripts/echomail_maintenance.php --echo=YOUR_ECHO_TAG --domain=fidonet --max-count=0 --dry-run

# Purge all messages for that echo
php scripts/echomail_maintenance.php --echo=YOUR_ECHO_TAG --domain=fidonet --max-count=0
```
Then delete the echo area in the admin UI.

### Q: How do I import a nodelist?
**A:**
```bash
php cli/import_nodelist.php --domain=fidonet
```
This reads the nodelist configuration from `data/nodelists.json` and imports entries.

### Q: How do I automatically install nodelists when they arrive?
**A:** Use file area rules to automatically process nodelist files. Edit `config/filearea_rules.json` and add a rule for your NODELIST file area:

```json
{
  "area_rules": {
    "NODELIST@fidonet": [
      {
        "name": "Auto-import FidoNet Nodelist",
        "pattern": "/^NODELIST\\.(Z|A|L|R|J)[0-9]{2}$/i",
        "script": "php %basedir%/scripts/import_nodelist.php %filepath% %domain% --force",
        "success_action": "delete",
        "fail_action": "keep+notify",
        "enabled": true,
        "timeout": 300
      }
    ]
  }
}
```

**Key points:**
- Use `TAG@DOMAIN` format for the area key (e.g., `NODELIST@fidonet`)
- Pattern matches compressed nodelist formats (Z=arc, A=zip, L=lha, R=rar, J=7z)
- `%basedir%` macro expands to your BinktermPHP root directory
- `%filepath%` is the full path to the received file
- `%domain%` is the file area's domain (e.g., "fidonet")
- `--force` flag makes the import script overwrite existing nodelist data
- `success_action: "delete"` removes the file after successful import
- `fail_action: "keep+notify"` keeps the file and notifies sysop on failure
- `timeout: 300` allows up to 5 minutes for large nodelists

The rule runs automatically when a file matching the pattern is uploaded or received via TIC.

For more details on file area rules, see `docs/FileAreas.md`.

---

### Q: How do I configure web based nodelist downloading?
**A:** Edit `data/nodelists.json` to specify nodelist sources and import settings. See the README for detailed configuration options.



## File Areas

### Q: How do I increase the maximum file size in a file area?
**A:** There are two places to update:

**1. File area setting (Admin → Area Management → File Areas)**

Edit the file area and increase the **Max File Size** field. This controls the per-upload limit enforced by BinktermPHP itself.

**2. PHP upload limits (`php.ini`)**

PHP imposes its own limits that must be at least as large as the value above. Edit your `php.ini` (typically `/etc/php/8.x/fpm/php.ini` or `/etc/php/8.x/apache2/php.ini`):

```ini
upload_max_filesize = 100M
post_max_size = 110M
```

- `upload_max_filesize` — maximum size of a single uploaded file
- `post_max_size` — maximum size of the entire POST request body; set this slightly larger than `upload_max_filesize`

After editing `php.ini`, restart your PHP process:

```bash
# PHP-FPM
sudo systemctl restart php8.x-fpm

# Apache mod_php
sudo systemctl restart apache2
```

You can verify the active values with:
```bash
php -r "echo ini_get('upload_max_filesize'), ' / ', ini_get('post_max_size'), PHP_EOL;"
```

---

## Multi-Network Support

### Q: Can I connect to multiple FTN networks?
**A:** Yes. Configure multiple uplinks in `binkp.json` with different `domain` values:
```json
"uplinks": [
  { "address": "1:234/567", "domain": "fidonet", ... },
  { "address": "21:1/100", "domain": "fsxnet", ... }
]
```
Each echo area is associated with a domain, and messages are routed to the appropriate uplink.

### Q: How do I move an echo area to a different network?
**A:** Edit the echo and file area and change its domain. Note that existing messages will retain their original routing information.

---

## LovlyNet Network

### Q: What is LovlyNet?
**A:** LovlyNet is a FidoNet Technology Network (FTN) operating in Zone 227 that provides automated registration and configuration. You can join the network and get an FTN address assigned automatically without manual coordination with a hub sysop.

For complete details, see **[docs/LovlyNet.md](docs/LovlyNet.md)**

### Q: How do I join LovlyNet?
**A:** Run the setup script:
```bash
php scripts/lovlynet_setup.php
```

The script will guide you through registration, automatically configure your uplink, and subscribe you to default echo areas. See **[docs/LovlyNet.md](docs/LovlyNet.md)** for detailed instructions.

### Q: What's the difference between public and passive nodes?
**A:**
- **Public nodes**: Accept inbound connections from the hub. Requires publicly accessible hostname/IP and working `/api/verify` endpoint. Hub can deliver mail directly to you.
- **Passive nodes**: Poll-only mode for systems behind NAT, firewalls, or with dynamic IPs. No inbound connections accepted. Must poll the hub regularly.

See the "Public vs Passive Nodes" section in **[docs/LovlyNet.md](docs/LovlyNet.md)** for more details.

### Q: How do I update my LovlyNet registration?
**A:** Run:
```bash
php scripts/lovlynet_setup.php --update
```

This allows you to change your hostname, switch between public/passive modes, or update other registration details while keeping your FTN address.

### Q: Why does LovlyNet verification fail?
**A:** Public nodes must have a working `/api/verify` endpoint. Test it:
```bash
curl https://yourbbs.example.com/api/verify
```

If this fails, check:
- Web server is accessible from the internet
- HTTPS certificate is valid
- Firewall allows HTTP/HTTPS traffic
- If unavailable publicly, register as a passive node instead

See the "Troubleshooting" section in **[docs/LovlyNet.md](docs/LovlyNet.md)** for more help.

### Q: How do I set up polling for LovlyNet?
**A:** For passive nodes, set up a cron job:
```bash
# Poll every 15 minutes
*/15 * * * * cd /path/to/binkterm-php && php scripts/binkp_poll.php >> data/logs/poll.log 2>&1
```

Public nodes can also poll as a fallback, though the hub will deliver mail directly via inbound connections.

---

## Database

### Q: The database stats page shows high sequential scan counts on some tables. Is this a problem?
**A:** Not necessarily. PostgreSQL uses sequential scans when they are more efficient than index scans, which is often the case for small tables or queries where a large fraction of rows would be returned. Here are the common cases you may see in BinktermPHP:

**Small tables (users_meta, user_settings, mrc_state)**
For tables with only a few hundred or thousand rows, PostgreSQL's query planner will almost always prefer a sequential scan — the overhead of walking an index is greater than simply reading the table directly. High seq scan counts here are expected and correct behaviour, not an indexing gap.

**High-frequency daemon tables (mrc_outbound, mrc_state)**
The MRC daemon polls these tables continuously (multiple times per second). Because these tables are very small, every poll results in a sequential scan. The counts look alarming but reflect normal operation.

**OR-condition queries (chat_messages, shared_messages)**
Queries that filter on `col_a = ? OR col_b = ?` cannot use a single B-tree index to satisfy both conditions simultaneously. PostgreSQL must either do a seq scan or merge two separate index scans (bitmap OR). For moderate table sizes or if the planner estimates a large result set, it will choose a seq scan even when indexes exist on both columns individually. This is a structural query pattern — adding more indexes will not change the planner's decision.

**When to investigate further**
A high seq scan ratio is worth investigating when:
- The table is large (tens of thousands of rows or more)
- The query is selecting a small, specific subset of rows (highly selective filter)
- You can see a long-running or slow query in the Query Performance tab that targets that table
- `pg_stat_user_tables.seq_tup_read` is very large relative to `idx_tup_fetch`

In those cases, review the actual queries and consider adding a targeted index. For the tables listed above, no additional indexing is needed.

---

## WebDoors

### Q: How do I add a connection to my text-based BBS?
**A:** You can use the RevPol webdoor to provide web-based terminal access to your BBS. Configure it in `config/webdoors.json`:

```json
"revpol": {
    "enabled": true,
    "display_name": "My BBS Name",
    "display_description": "Connect to My BBS",
    "host": "mybbs.example.com",
    "port": "23",
    "proto": "telnet"
}
```

**Configuration options:**
- `display_name`: The name shown in the games list (overrides the default "Reverse Polarity")
- `display_description`: Description shown to users
- `host`: Your BBS hostname or IP address
- `port`: Connection port (typically 23 for telnet, 22 for SSH)
- `proto`: Protocol to use - either `"telnet"` or `"ssh"`

**Protocol differences:**
- **Telnet**: Connects directly without authentication. Users authenticate once connected to your BBS.
- **SSH**: Requires username and password upfront before establishing connection.

Once configured, users can access your BBS through the WebDoors menu at `/games` on your BinktermPHP site.

**Note**: This requires an external terminal proxy server (such as terminalgateway) configured via `TERMINAL_ENABLED`, `TERMINAL_PROXY_HOST`, and `TERMINAL_PROXY_PORT` in your `.env` file.
