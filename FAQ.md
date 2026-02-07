# BinktermPHP Frequently Asked Questions

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

### Q: What's the difference between uplink_address on an echo area vs in binkp.json?
**A:**
- The `uplink_address` on an echo area is an optional (experimental) override for where to send messages for that specific area
- The uplink in `binkp.json` defines the actual connection details (hostname, password) for polling and sending mail
- If an echo area has no `uplink_address`, messages go to the default uplink for that domain

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

---

## Binkp Server & Polling

### Q: How do I only connnect to my uplink if traffic available?
**A:**

Use the --queued-only switch to binkp_poll.php.  In this mode binkp_poll will only poll the uplink if there are packets
in the queue.


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

Configure in `.env`:
```
BBSLINK_GATEWAY_URL=https://gateway.example.com/
BBSLINK_API_KEY=your-secret-api-key
```
Note that BBSLINK support is experimental and not enabled in the code base.  The above is provided as an example for how gateway tokens work and the gateway token facility itself may be used and developed for.

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
php cli/echomail_maintenance.php --domain=fidonet --max-age=365 --dry-run

# Actually delete old messages
php cli/echomail_maintenance.php --domain=fidonet --max-age=365
```

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
