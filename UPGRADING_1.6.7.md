# What's New in v1.6.7
January 24 2026 

## Multi-Network Support
- Full multi-network routing for echomail and netmail (FidoNet, FSXNet, etc.)
- Domain-based packet filtering and address resolution
- Network domain badges in UI
- Point address support in routing and INTL kludge parsing

## Binkp Protocol Improvements
- Multi-connection support using fork for binkp_server
- Crashmail direct delivery via binkp
- Insecure binkp session handling for nodes without passwords
- Fixed handshake failures on Linux (stream timeout handling)
- Improved password validation and debug logging
- Clean signal handling for shutdown

## Nodelist Enhancements
- Automated nodelist download/import script with URL macro support
- Multi-domain nodelist support
- Display INA hostname with telnet links
- Last import date display per domain
- Zone dropdown ordering fix

## User Experience
- ANSI terminal renderer for BBS art
- Fullscreen toggle for message modals (with localStorage preference)
- Clickable hyperlinks in messages (XSS-safe)
- Theme fixes for dark, cyberpunk, and greenterm themes
- Wider message reading modal (80% on desktop)

## User Management
- User sessions tracking with `last_activity` for "who's online" feature
- Location field in user profiles and registration
- `scripts/who.php` CLI tool to show online users
- Automatic default echoarea subscriptions for new users

## System Notifications
- SysopNotificationService for netmail notices to sysop
- Email failure notifications

## Message Handling
- Enhanced logging for message sending (sender, subject, packet filenames)
- Outbound directory writability check before accepting messages
- Local system message delivery (no spooling needed)

## Installation & Maintenance
- Fresh install fixes (migrations after base schema)
- PostgreSQL boolean type fixes
- Standalone binkp test client and packet generator utilities

## Miscellaneous
 - Check the commit history for a full list of changes 

--

# Upgrading to BinktermPHP v1.6.7

Version 1.6.7 introduces support for multiple FTN networks (FidoNet, FSXNet, AgoraNet, etc.) running simultaneously. This requires both database schema changes and configuration updates.

> **WARNING:** Before upgrading, back up your database and configuration files. Use `scripts/backup_database.php` or your preferred backup method.

## Step 1: Update binkp.json Configuration

**This step can be done before updating the codebase and is recommended.** The new configuration format is backward compatible.

Your existing uplink configuration needs new fields. For each uplink, add:

| Field | Description |
|-------|-------------|
| `me` | Your FTN address as presented to this specific uplink |
| `domain` | Network domain identifier (e.g., "fidonet", "fsxnet") |
| `networks` | Array of address patterns routed through this uplink |

**Before (pre-1.6.7):**
```json
{
    "uplinks": [
        {
            "address": "1:123/456",
            "hostname": "hub.example.com",
            "port": 24554,
            "password": "secret",
            "default": true,
            "enabled": true
        }
    ]
}
```

**After (1.6.7+):**
```json
{
    "uplinks": [
        {
            "me": "1:123/456.57599",
            "address": "1:123/456",
            "domain": "fidonet",
            "networks": ["1:*/*", "2:*/*", "3:*/*", "4:*/*"],
            "hostname": "hub.example.com",
            "port": 24554,
            "password": "secret",
            "default": true,
            "enabled": true
        }
    ]
}
```

### New Configuration Sections

The following new sections should be added to `binkp.json`:

**`security`** - Password and session security settings:
```json
"security": {
    "require_password": true,
    "allow_insecure_sessions": false
}
```

**`crashmail`** - Direct delivery configuration:
```json
"crashmail": {
    "enabled": true,
    "max_attempts": 3,
    "retry_interval": 300
}
```

**`transit`** - Message transit/routing settings:
```json
"transit": {
    "enabled": false,
    "allowed_networks": []
}
```

See `configs/binkp.json.example` for the full configuration options and format.

## Step 2: Run Database Migration

```bash
php scripts/upgrade.php
```

This migration adds `domain` fields to the `echoareas`, `nodelist`, and `nodelist_metadata` tables, and updates the unique constraint on echoareas to allow the same tag across different networks.  It may also contain other schema upgrades for this release.

## Step 3: Update Echoarea Domains (Optional)

If you have existing echoareas, they will be automatically assigned to the "fidonet" domain during migration. To assign echoareas to different networks, use the admin interface or update the database directly:

```sql
UPDATE echoareas SET domain = 'fsxnet' WHERE tag LIKE 'FSX_%';
UPDATE echoareas SET domain = 'agoranet' WHERE tag LIKE 'AGN_%';
```

## Step 4: Configure Automated Nodelist Updates

Create `config/nodelists.json` to configure automatic nodelist downloads:

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
  ]
}
```

**URL Macros:**
- `|DAY|` - Day of year (1-366)
- `|YEAR|` - 4-digit year (2026)
- `|YY|` - 2-digit year (26)
- `|MONTH|` - 2-digit month (01-12)
- `|DATE|` - 2-digit day of month (01-31)

Run manually or via cron:
```bash
php scripts/update_nodelists.php [--quiet] [--force]
```

See `configs/nodelists.json.example` for more examples.

## Step 5: Verify File System Permissions

Ensure the web server has write access to the outbound spool directory:

```bash
# Check permissions
ls -la data/outbound

# Fix if needed
chmod 1777 data/outbound
# or
chmod a+rwxt data/outbound
```

The `data/outbound` directory must be writable by the web server for message spooling to work.

## Step 6: Import Network-Specific Nodelists

When importing nodelists manually for different networks, specify the domain as the second argument:

```bash
php scripts/import_nodelist.php FSXNET.023 fsxnet
php scripts/import_nodelist.php NODELIST.365 fidonet
php scripts/import_nodelist.php NODELIST.365 fidonet --force
```

## Step 7: Set Up Cron Jobs (Recommended)

Add cron jobs for automated nodelist updates and mail polling:

```cron
# Update nodelists daily at 3am
0 3 * * * /usr/bin/php /path/to/binkterm/scripts/update_nodelists.php --quiet

# Poll uplinks every 15 minutes
*/15 * * * * /usr/bin/php /path/to/binkterm/scripts/binkp_poll.php --quiet
```

## Step 8: Update Custom Themes (If Applicable)

If you have custom CSS themes, add the following Bootstrap table variable overrides to support the new unread message styling:

```css
/* Bootstrap table CSS variable overrides */
.table {
    --bs-table-bg: var(--bg-card);
    --bs-table-color: var(--text-color);
    --bs-table-hover-bg: var(--bg-secondary);
    --bs-table-hover-color: var(--text-color);
}

.table-light {
    --bs-table-bg: #3a3a3a;  /* adjust for your theme */
    --bs-table-color: var(--text-color);
    --bs-table-hover-bg: #444444;
    --bs-table-hover-color: var(--text-color);
}

.table-hover > tbody > tr:hover > * {
    --bs-table-bg-state: var(--bg-secondary) !important;
    --bs-table-color-state: var(--text-color) !important;
}
```

See `public_html/css/dark.css` for a complete example.

## Step 9: Update Custom Templates (If Applicable)

If you have customized any of the following templates, review and merge changes:

- `netmail.twig` - Removed hardcoded light theme colors, added threading styles
- `echomail.twig` - Similar theme and threading updates
- `register.twig` - Added location field
- `profile.twig` - Added location field
- `admin_users.twig` - Added location display for pending users

Custom templates in `templates/custom/` are not overwritten during upgrades, but may need manual updates to support new features.

## Benefits of Multinetwork Support

- **Simultaneous Networks**: Connect to FidoNet, FSXNet, AgoraNet, and other FTN networks at the same time
- **Proper Routing**: Messages are routed to the correct uplink based on destination address
- **Network Isolation**: Echoareas are scoped to their network domain, preventing tag collisions
- **Per-Network Nodelists**: Each network maintains its own nodelist for proper addressing



