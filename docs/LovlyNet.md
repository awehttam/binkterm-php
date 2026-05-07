# LovlyNet Network Integration

## Table of Contents

- [What is LovlyNet?](#what-is-lovlynet)
- [Quick Start](#quick-start)
- [Public vs Passive Nodes](#public-vs-passive-nodes)
  - [Public Nodes](#public-nodes)
  - [Passive Nodes](#passive-nodes)
- [Registration Process](#registration-process)
  - [Step-by-Step](#step-by-step)
  - [What You Receive](#what-you-receive)
  - [Verification Endpoint](#verification-endpoint)
- [Managing LovlyNet (Web Interface)](#managing-lovlynet-web-interface)
  - [Echo Areas Tab](#echo-areas-tab)
  - [File Areas Tab](#file-areas-tab)
  - [Setup Tab](#setup-tab)
- [Updating Registration](#updating-registration)
- [Checking Status](#checking-status)
- [Configuration Files](#configuration-files)
  - [config/lovlynet.json](#configlovlynetjson)
  - [config/binkpjson](#configbinkpjson)
- [Polling Configuration](#polling-configuration)
  - [Manual Poll](#manual-poll)
  - [Automatic Polling (Recommended)](#automatic-polling-recommended)
- [Manual AreaFix Commands](#manual-areafix-commands)
  - [Default Areas](#default-areas)
  - [Subscribing via Netmail](#subscribing-via-netmail)
  - [Areafix Commands](#areafix-commands)
- [Troubleshooting](#troubleshooting)
- [Advanced Usage](#advanced-usage)
  - [Importing Existing Manual Configuration](#importing-existing-manual-configuration)
  - [Multiple Networks](#multiple-networks)
  - [Changing Node Type](#changing-node-type)
- [Getting Help](#getting-help)
- [Network Information](#network-information)
- [References](#references)

## What is LovlyNet?

LovlyNet is a FidoNet Technology Network (FTN) operating in Zone 227. It provides an automated registration system that allows BBS operators to join the network and start exchanging mail with other systems without manual coordination with a hub sysop.

**Key Features:**
- Automatic FTN address assignment (227:1/10 and up)
- Self-service registration via CLI tool
- Automatic uplink configuration
- Echo area subscription
- Support for both public and passive nodes
- Web-based management via **Admin → LovlyNet** after initial setup

## Quick Start

Initial registration is a one-time CLI step:

```bash
php scripts/lovlynet_setup.php
```

The script will guide you through registration and automatically configure your system.

**After registration, all ongoing management is done through the web interface** at **Admin → LovlyNet** — including echo area subscriptions, registration updates, and connectivity health checks. You do not need to re-run the CLI tool for routine management.

## Public vs Passive Nodes

### Public Nodes

**Choose public node if:**
- Your BBS is accessible from the internet
- You have a static IP or domain name
- You can accept inbound binkp connections on port 24554 (or custom port)

**Benefits:**
- Hub can deliver mail directly to you (faster delivery)
- More efficient mail flow
- Full two-way connectivity

**Requirements:**
- Your BinktermPHP installation must be publicly accessible
- Public hostname or IP address
- Port forwarding configured (if behind NAT)
- Firewall allows inbound on binkp port
- Tunnel/proxy voodoo

### Passive Nodes

**Choose passive node if:**
- Behind NAT without port forwarding
- Dynamic IP address
- Silo'd or garden network (ie: Community wireless networks)
- Firewall restrictions prevent inbound connections
- Development or testing system

**How it works:**
- You poll the hub periodically (every 15-30 minutes recommended)
- Hub cannot initiate connections to you
- Mail delivery depends on your polling schedule
- Still fully functional for sending and receiving

**Note:** Passive nodes must set up regular polling via cron or scheduled task.

## Registration Process

### Step-by-Step

1. **Run the setup script:**
   ```bash
   php scripts/lovlynet_setup.php
   ```

2. **Verify information:**
   - System Name (from your BBS config)
   - Sysop Name (your name)
   - Site URL (your BBS web address)

3. **Choose node type:**
   - Answer "Y" if publicly accessible (default)
   - Answer "N" if passive/poll-only

4. **For public nodes:**
   - Provide your public hostname (e.g., `bbs.example.com`)
   - Provide your binkp port (default: 24554)
   - Script verifies your `/api/verify` endpoint locally
   - If verification fails, fix your hostname/port and try again

5. **For passive nodes:**
   - Script uses placeholder hostname automatically
   - No verification required

6. **Review and confirm:**
   - Check the summary of your registration
   - Type "Y" to proceed

7. **Automatic configuration:**
   - Receives FTN address from registry (e.g., 227:1/10)
   - Saves credentials to `config/lovlynet.json`
   - Configures uplink in `config/binkp.json`
   - Creates echo areas in database
   - Sends areafix request to hub

### What You Receive

After successful registration:
- **FTN Address**: Your unique address (e.g., 227:1/10)
- **API Key**: For future updates (saved automatically)
- **Binkp Password**: For secure mailer authentication
- **Areafix Password**: For echo area management
- **Echo Areas**: LVLY_BINKTERMPHP, LVLY_ANNOUNCE, LVLY_TEST (auto-subscribed)

### Verification Endpoint

Public nodes must have a working verification endpoint. BinktermPHP provides this automatically at `/api/verify`.

**Test your endpoint:**
```bash
curl https://yourbbs.example.com/api/verify
```

**Expected response:**
```json
{
  "system_name": "Your BBS Name",
  "software": "BinktermPHP v1.7.9"
}
```

If this doesn't work, check:
- Web server is running and accessible
- HTTPS certificate is valid (if using HTTPS)
- Router/firewall allows HTTP/HTTPS traffic
- BBS software is properly configured

## Managing LovlyNet (Web Interface)

**Admin → LovlyNet** is the primary interface for managing your LovlyNet membership after the initial CLI registration. It shows your node number, hub address, server URL, and subscribed area count at a glance, and provides three tabs for managing areas and your registration details.

You should rarely need to touch the CLI again once your node is registered. The web interface covers the full management lifecycle: subscribing and unsubscribing echo areas, requesting rescans, updating your registration details, and monitoring connectivity health.

### Echo Areas Tab

Lists all echo areas available from the hub with their subscription status. Each row shows the area tag, description, and whether you are currently subscribed. From this tab you can subscribe or unsubscribe individual areas, request a **rescan** (replay messages from the last N days, last N messages, or all messages), and send freeform AreaFix commands to the hub via the **Request** button.

### File Areas Tab

The same layout as the Echo Areas tab, but for file areas managed through FileFix.

### Setup Tab

The Setup tab lets you update your LovlyNet registration without running `lovlynet_setup.php` from the command line. Fields include:

- **System Name** — the name of your BBS
- **Sysop Name** — your name as it appears in the nodelist
- **Hostname** — your BBS's public hostname
- **BinkP Port** — the port your BinkP server listens on
- **Site URL** — your BBS's public web address
- **Passive Node** — check this if your node does not accept inbound connections

Saving the form sends the updated information to the LovlyNet registry. A checklist panel alongside the form shows the current health of your registration (reachability, certificate, BinkP connectivity, etc.) to help diagnose configuration problems.

## Updating Registration

**Preferred method:** Use the **Setup tab** in **Admin → LovlyNet** to update your hostname, node type, sysop name, or port. Changes take effect immediately without requiring CLI access.

**CLI alternative:** If you need to update from the command line (e.g., during initial server setup before the web interface is accessible):

```bash
php scripts/lovlynet_setup.php --update
```

The script will:
- Use your existing API key and node ID
- Re-verify public nodes (if applicable)
- Update configuration on the hub
- Preserve your FTN address

**Common update scenarios:**
- Hostname changed (new domain, IP address)
- Switching from passive to public (or vice versa)
- Sysop name changed
- Port changed

## Checking Status

**Via the web interface:** The **Admin → LovlyNet** dashboard shows your FTN address, registration date, subscribed area count, and a connectivity health checklist at a glance.

**Via the CLI:**

```bash
php scripts/lovlynet_setup.php --status
```

Shows:
- FTN address
- Registration date
- Last update time
- API key (masked)

## Configuration Files

### config/lovlynet.json

Stores your registration details:
```json
{
  "node_id": 123,
  "api_key": "64-character-hex-string",
  "ftn_address": "227:1/10",
  "hub_address": "227:1/1",
  "hub_hostname": "lovlynet.lovelybits.org",
  "hub_port": 24554,
  "binkp_password": "16-char-hex",
  "areafix_password": "16-char-hex",
  "registered_at": "2026-02-06T12:34:56+00:00",
  "updated_at": "2026-02-06T12:34:56+00:00"
}
```

**Important:** Keep this file secure. The API key allows updates to your registration.

### config/binkp.json

The setup script automatically adds a LovlyNet uplink:
```json
{
  "uplinks": [
    {
      "me": "227:1/10",
      "address": "227:1/1",
      "hostname": "lovlynet.lovelybits.org",
      "port": 24554,
      "password": "your-binkp-password",
      "domain": "lovlynet",
      "networks": ["227:*/*"],
      "enabled": true,
      "compression": false,
      "crypt": false,
      "poll_schedule": "*/15 * * * *"
    }
  ]
}
```

## Polling Configuration

### Manual Poll

To manually poll the hub:
```bash
php scripts/binkp_poll.php
```

### Automatic Polling (Recommended)

**For passive nodes**, set up a cron job:

```bash
# Edit crontab
crontab -e

# Add line to poll every 15 minutes
*/15 * * * * cd /path/to/binkterm-php && php scripts/binkp_poll.php >> data/logs/poll.log 2>&1
```

**For public nodes**, polling is optional but recommended as a fallback:
```bash
# Poll every 30 minutes
*/30 * * * * cd /path/to/binkterm-php && php scripts/binkp_poll.php >> data/logs/poll.log 2>&1
```

## Manual AreaFix Commands

> **Tip:** Most echo area management can be done graphically from the **Echo Areas** tab in **Admin → LovlyNet**. The commands below are for advanced use or when web access is unavailable.

### Default Areas

- **LVLY_BINKTERMPHP** - General discussion about BinktermPHP and FTN systems
- **LVLY_ANNOUNCE** - Network announcements and sysop information
- **LVLY_TEST** - Testing and experiments

### Subscribing via Netmail

Send a netmail to AreaFix at 227:1/1:
- **To:** AreaFix
- **Subject:** [Your areafix password from config]
- **Body:** `+AREA_TAG` (to subscribe) or `-AREA_TAG` (to unsubscribe)

### Areafix Commands

Send these in the body of a netmail to AreaFix:

- `%HELP` - Get help and list of commands
- `%LIST` - List all available echo areas
- `%QUERY` - Show your current subscriptions
- `+AREA_TAG` - Subscribe to an area
- `-AREA_TAG` - Unsubscribe from an area
- `%RESCAN AREA_TAG` - Request rescan of area

## Troubleshooting

### Registration Fails with "Failed to verify node ownership"

**Problem:** Registry couldn't verify your `/api/verify` endpoint

**Solutions:**
1. Test endpoint manually: `curl https://yourbbs.example.com/api/verify`
2. Check web server is accessible from internet
3. Verify HTTPS certificate is valid
4. Check firewall rules
5. If unavailable publicly, register as passive node instead

### "This system is already registered"

**Problem:** Node name already exists in registry

**Solutions:**
1. Use `--update` flag to update existing registration
2. If you lost your API key, contact the hub sysop
3. Registry will auto-increment name (e.g., My_BBS → My_BBS_2)

### "Requested node number X is already in use"

**Problem:** Trying to import existing node, but number is taken

**Solutions:**
1. Remove the existing uplink from `config/binkp.json` before registering
2. Let registry auto-assign a new number
3. Contact hub sysop if you need that specific number

### Hub Never Connects

**For public nodes:**
1. Verify binkp server is running: `ps aux | grep binkp_server`
2. Check port is open: `netstat -an | grep 24554`
3. Test from outside your network: `telnet yourbbs.example.com 24554`
4. Check firewall/router port forwarding
5. Verify binkp password in `config/binkp.json` matches registry

**For passive nodes:**
1. This is normal - you must poll the hub
2. Set up polling via cron (see Polling Configuration above)

### No Mail Flowing

**Check these:**
1. Binkp server running: `php scripts/binkp_server.php &`
2. Poll the hub manually: `php scripts/binkp_poll.php`
3. Check logs: `tail -f data/logs/binkp.log`
4. Verify echo area subscriptions in admin interface
5. Send test message to TEST echo area

### Re-registering After Moving Systems

If you're migrating to a new server:

1. Copy `config/lovlynet.json` to new server
2. Copy `config/binkp.json` to new server
3. Run `php scripts/lovlynet_setup.php --update`
4. Update hostname if changed
5. Restart binkp server

## Advanced Usage

### Importing Existing Manual Configuration

If you obtained a node number without using `lovlynet_setup.php`, or were manually added to the network, you will not have a full LovlyNet configuration including a `config/lovlynet.json` file and API key. Without these, the setup tool cannot prove ownership of your existing node number and cannot safely import your configuration.

Contact the LovlyNet administrator to have a `config/lovlynet.json` generated for your node. Once you have that file, place it in `config/` and you will have full access to the web interface and CLI tools without needing to re-register or change your FTN address.

### Multiple Networks

BinktermPHP supports multiple uplinks. LovlyNet is just one network. You can:
- Join other FTN networks (different zones)
- Maintain separate uplinks for each
- Use domain routing to separate mail

Each network should have its own domain identifier in `config/binkp.json`.

### Changing Node Type

**From passive to public:**
1. Configure port forwarding and firewall
2. Test `/api/verify` endpoint externally
3. Update via **Admin → LovlyNet → Setup tab** (or run `php scripts/lovlynet_setup.php --update`)
4. Set public hostname and uncheck Passive Node
5. Hub will start delivering mail directly

**From public to passive:**
1. Update via **Admin → LovlyNet → Setup tab** (or run `php scripts/lovlynet_setup.php --update`)
2. Check the Passive Node option
3. Set up polling via cron
4. Hub will stop attempting inbound connections

## Getting Help

- **Echo Area:** Post questions in BINKTERMPHP echo area
- **Netmail:** Send netmail to sysop at 227:1/1
- **GitHub:** https://github.com/awehttam/binkterm-php/issues

## Network Information

- **Network Name:** LovlyNet
- **Zone:** 227
- **Hub Address:** 227:1/1
- **Hub Hostname:** lovlynet.lovelybits.org
- **Hub Port:** 24554
- **Protocol:** Binkp over TCP/IP

## References

- [FidoNet Technical Standards](http://www.ftsc.org/)
- [Binkp Protocol](http://www.filegate.net/info/binkp/)
- [BinktermPHP Documentation](README.md)
- [LovlyNet Registry Technical Docs](https://github.com/awehttam/LovlyNet)
