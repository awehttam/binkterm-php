# BinktermPHP Frequently Asked Questions

## Support

### Q: Where can I get support?
**A:** There are several ways to get help with BinktermPHP:

- **Discussions**: For general questions, help, and community support, visit the [GitHub Discussions](https://github.com/awehttam/binkterm-php/discussions)
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
php scripts/upgrade.php
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
**A:** Edit `data/binkp.json` and add an entry to the `uplinks` array:
```json
{
  "address": "55:234/567",
  "hostname": "hub.example.com",
  "port": 24554,
  "password": "your_session_password",
  "domain": "somenet",
  "me": "55:234/999",
  "networks": ["55:*/*"]
}
```
The `domain` field identifies which network this uplink belongs to.

### Q: How do I configure nodelists?
**A:** Edit `data/nodelists.json` to specify nodelist sources and import settings. See the README for detailed configuration options.

### Q: What is the SITE_URL setting for?
**A:** `SITE_URL` in `.env` is used for generating full URLs (share links, password reset emails, etc.). This is important if your server is behind a reverse proxy or load balancer where `$_SERVER['HTTPS']` may not be set correctly.

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

### Q: How do I run the binkp server as a daemon?
**A:** Create a systemd service file at `/etc/systemd/system/binkp.service`:
```ini
[Unit]
Description=BinktermPHP Binkp Server
After=network.target

[Service]
Type=simple
User=someuser
WorkingDirectory=/path/to/binktest
ExecStart=/usr/bin/php scripts/binkp_server.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```
Then enable and start it:
```bash
sudo systemctl enable binkp
sudo systemctl start binkp
```

### Q: How often should I poll my uplink?
**A:** This depends on your uplink's policies and your traffic volume. Common intervals:
- High traffic: Every 15-30 minutes
- Normal traffic: Every 1-2 hours
- Low traffic: Every 4-6 hours

Set up a cron job:
```cron
*/30 * * * * php /path/to/binktest/cli/binkp_poll.php --domain=fidonet
```

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
- Packet processing: `data/logs/packets.log`
- PHP errors: Check your web server's error log
- Binkp sessions: Enable logging in `binkp.json` with `"log_all_sessions": true`

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
2. Check `data/logs/packets.log` after a poll attempt
3. Try connecting manually: `telnet hub.example.com 24554`
4. Verify your password matches what your uplink expects
5. Check firewall rules for outbound port 24554

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

### Q: How do I rebuild message counts?
**A:** Run:
```bash
php cli/echomail_maintenance.php --domain=fidonet --rebuild-counts
```

### Q: How do I import a nodelist?
**A:**
```bash
php cli/import_nodelist.php --domain=fidonet
```
This reads the nodelist configuration from `data/nodelists.json` and imports entries.

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
**A:** Edit the echo area and change its domain. Note that existing messages will retain their original routing information.
