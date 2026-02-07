# LovlyNet Network Integration

## What is LovlyNet?

LovlyNet is a FidoNet Technology Network (FTN) operating in Zone 227. It provides an automated registration system that allows BBS operators to join the network and start exchanging mail with other systems without manual coordination with a hub sysop.

**Key Features:**
- Automatic FTN address assignment (227:1/10 and up)
- Self-service registration via CLI tool
- Automatic uplink configuration
- Echo area subscription
- Support for both public and passive nodes

## Quick Start

To join LovlyNet, run the setup script:

```bash
php scripts/lovlynet_setup.php
```

The script will guide you through registration and automatically configure your system.

## Public vs Passive Nodes

### Public Nodes

**Choose public node if:**
- Your BBS is accessible from the internet
- You have a static IP or domain name
- You can accept inbound binkp connections on port 24554 (or custom port)
- Your `/api/verify` endpoint is working

**Benefits:**
- Hub can deliver mail directly to you (faster delivery)
- More efficient mail flow
- Full two-way connectivity

**Requirements:**
- Working `/api/verify` endpoint (automatically created by BinktermPHP)
- Public hostname or IP address
- Port forwarding configured (if behind NAT)
- Firewall allows inbound on binkp port

### Passive Nodes

**Choose passive node if:**
- Behind NAT without port forwarding
- Dynamic IP address
- Community wireless network
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
- **Echo Areas**: BINKTERMPHP, ANNOUNCE, TEST (auto-subscribed)

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

## Updating Registration

To update your registration (change hostname, switch public/passive, etc.):

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

View your current registration:

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

## Echo Area Management

### Viewing Available Areas

Check `config/lovlynet.json` after registration for the echo area list. You can also view areas in the web interface under Admin → Echo Areas.

### Subscribing to Areas

Send a netmail to AreaFix at 227:1/1:
- **To:** AreaFix
- **Subject:** [Your areafix password from config]
- **Body:** `+AREA_TAG` (to subscribe) or `-AREA_TAG` (to unsubscribe)

**Default areas:**
- **BINKTERMPHP** - General discussion about BinktermPHP and FTN systems
- **ANNOUNCE** - Network announcements and sysop information
- **TEST** - Testing and experiments

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

If you manually configured LovlyNet before the auto-setup tool existed:

1. Note your FTN address from `config/binkp.json` (e.g., 227:1/400)
2. Run `php scripts/lovlynet_setup.php`
3. Script will detect existing uplink and request that node number
4. If available, registry assigns your existing number
5. If taken, you'll get a new number (update binkp.json manually)

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
3. Run `php scripts/lovlynet_setup.php --update`
4. Answer "Y" for public access
5. Provide new hostname
6. Hub will start delivering mail directly

**From public to passive:**
1. Run `php scripts/lovlynet_setup.php --update`
2. Answer "N" for public access
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
