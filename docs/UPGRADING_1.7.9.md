# UPGRADING_1.7.9

This upgrade note covers changes introduced in version 1.7.9.

## Summary of Changes Since 1.7.8

- **LovlyNet Automatic Provisioning**: New automated registration tool for joining the LovlyNet network (Zone 227). The `lovlynet_setup.php` script automates network registration, uplink configuration, echo area creation, and initial areafix requests.
- **Subscribe Users Tool**: New sysop utility (`scripts/subscribe_users.php`) to force-join users to specific echo areas, useful for mandatory areas like ANNOUNCE.
- **NetRealm RPG**: New turn-based RPG WebDoor game. Players fight monsters, level up to 50, collect loot with rarity tiers (common through legendary), equip gear across 3 slots (weapon, armor, accessory), buy and sell items at a shop, and battle other local players in PvP combat.
  - NetRealm uses a daily turn system (configurable, default 25/day) with optional credit-based extra turn purchases.
  - PvP results are posted to a `NETREALM_SYNC` local echo area for future cross-node networking support.
  - Four leaderboard types: overall, PvP, wealth, and monster slayer.
- **Uppercase File Name Fix**: Resolved issue with upper case file names in received packet bundles and TIC files.
- **Reload Config Button**: Added "Reload Config" button to binkp configuration page for easier configuration updates without restarting daemons.
- **Telnet User Registration**: Implemented user registration support in the telnet daemon, allowing new users to create accounts via telnet.
- **Telnet Daemon Improvements**: Miscellaneous fixes and stability improvements in telnet daemon.
- **BinkP Password Handling**: Protocol update for improved password handling and security.
- **Outgoing TIC File Compliance**: TIC file generation now complies with FSC-87 specification.
- **ANSI Ad Generator**: Added basic advertisement generator with ANSI coloring support (`scripts/ansi_ad_generator.php`).
- **Various Fixes and Enhancements**: Bug fixes, performance improvements, and general stability enhancements throughout the system.


## Database Migration

This release includes migration `v1.9.3` which creates the following tables:

- `netrealm_characters` - Player characters (one per user)
- `netrealm_inventory` - Item storage and equipment
- `netrealm_combat_log` - PvE and PvP fight history
- `netrealm_network_players` - PvP player registry
- `netrealm_pvp_cooldowns` - PvP challenge cooldowns
- `netrealm_processed_messages` - Echomail deduplication

The migration also creates a `NETREALM_SYNC` echo area (local-only, sysop-only) used for PvP message sync.

## Upgrade Steps

1. Pull the latest code: `git pull`
2. Install dependencies: `composer install`
3. Run setup: `php scripts/setup.php`

**Important:** Step 2 is required. This release adds a new composer dependency. Skipping it will cause a fatal error when running setup.php.

## Enabling NetRealm

After upgrading, NetRealm must be enabled in the WebDoors admin configuration:

1. Go to **Admin > WebDoors**
2. Enable the NetRealm RPG game
3. Adjust game settings as desired (daily turns, PvP toggle, credit costs, etc.)

The game will then appear in the `/games` page for all users.

## Configuration Options

The following settings are configurable via the WebDoors admin panel or `config/webdoors.json`:

| Setting | Default | Description |
|---------|---------|-------------|
| `daily_turns` | 25 | Turns granted per day |
| `starting_gold` | 100 | Gold given to new characters |
| `pvp_enabled` | true | Enable/disable PvP combat |
| `credits_per_extra_turn` | 5 | Credit cost per extra turn |
| `max_extra_turns_per_day` | 10 | Maximum purchasable turns/day |
| `max_inventory_size` | 20 | Maximum items per character |
| `max_rest_uses_per_day` | 3 | Rest actions allowed per day |
| `rest_heal_percent` | 50 | HP percentage restored per rest |
| `pvp_turn_cost` | 3 | Turns consumed per PvP fight |
| `pvp_level_range` | 5 | Max level difference for PvP |
| `sell_price_percent` | 50 | Percentage of buy price when selling |

## LovlyNet Setup Tool

A new automated setup tool has been added for easy registration with the LovlyNet network (Zone 227).

### What it does:
- Registers your BBS with the LovlyNet registry
- Receives a unique FTN address (227:1/xxx format)
- Configures the hub uplink in `config/binkp.json`
- Creates all LovlyNet echo areas in the database
- Sends an initial areafix request to the hub
- Sends a welcome message with network information

### Usage:

**Interactive Registration:**
```bash
php scripts/lovlynet_setup.php
```

**Check Registration Status:**
```bash
php scripts/lovlynet_setup.php --status
```

**Update Existing Registration:**
```bash
php scripts/lovlynet_setup.php --update
```

### Node Types:

**Public Nodes:**
- Accept inbound binkp connections from the hub
- Must have a publicly accessible hostname/IP
- Requires binkp port (default 24554) to be open
- Hub delivers mail automatically

**Passive Nodes:**
- Poll-only nodes (community wireless, NAT, dynamic IP, testing)
- Cannot accept inbound connections
- Must poll the hub regularly for mail (recommended: every 15-30 minutes)
- Suitable for nodes behind firewalls or on dynamic IPs

### Configuration Files:

After registration, the tool creates:
- `config/lovlynet.json` - Registration details (FTN address, API key, passwords)
- Updates `config/binkp.json` - Adds/updates LovlyNet uplink configuration

### Next Steps After Registration:

1. Restart the admin daemon:
   ```bash
   php scripts/restart_daemons.sh
   ```

2. Test connectivity by polling the hub:
   ```bash
   php scripts/binkp_poll.php
   ```

3. Check for messages in the LovlyNet echo areas

### Notes:
- Registration requires internet connectivity to https://lovlynet.lovelybits.org
- For public nodes, the `/api/verify` endpoint must be accessible for verification
- Passive nodes should set up a cron job for regular polling
- The tool can update existing registrations (e.g., to change from passive to public)

## Subscribe Users Tool

A new sysop utility has been added to force-join users to specific echo areas. This is useful for mandatory areas like ANNOUNCE, GENERAL, or network-required areas.

### Usage:

**Subscribe all users to an echo area:**
```bash
php scripts/subscribe_users.php AREA_TAG
```

**Subscribe specific users by ID:**
```bash
php scripts/subscribe_users.php AREA_TAG --users=1,2,3
```

**Preview mode (dry run):**
```bash
php scripts/subscribe_users.php AREA_TAG --dry-run
```

### Features:
- Subscribes users to echo areas regardless of their current subscription status
- Supports subscribing all users or specific user IDs
- Includes dry-run mode to preview changes before applying
- Skips users who are already subscribed (avoids duplicates)
- Provides detailed feedback on subscription results

### Common Use Cases:
- Subscribing all users to a new ANNOUNCE area
- Adding users to mandatory network areas after joining a new network
- Bulk subscription management for sysop-defined required reading areas

## Telnet Daemon Improvements

The telnet daemon has been enhanced with several new features and fixes:

### User Registration Support:
- New users can now register accounts directly via telnet
- Provides a terminal-based registration flow
- Validates usernames and passwords according to system requirements
- Integrates with the existing user management system

### Stability Improvements:
- Miscellaneous bug fixes for improved reliability
- Better error handling and connection management
- Enhanced terminal compatibility

## ANSI Ad Generator

A new tool for generating ANSI-colored advertisement messages has been added.

### Usage:
```bash
php scripts/ansi_ad_generator.php
```

### Features:
- Generates ANSI-colored text advertisements
- Customizable border styles and accent levels
- Output suitable for bulletin displays, login screens, or echomail messages
- Configurable color schemes and formatting options

### Use Cases:
- Creating eye-catching BBS announcements
- Generating colorful bulletins for the main menu
- Creating promotional messages for echo areas
- Designing login screen banners

## Other Notable Changes

### BinkP Configuration Reload:
- Added "Reload Config" button to the admin binkp configuration page
- Allows configuration updates to take effect without manual daemon restarts
- Improves sysop workflow when adjusting uplink settings

### File Name Handling:
- Fixed issue with uppercase file names in received packet bundles and TIC files
- Ensures proper processing of files regardless of case sensitivity
- Improves compatibility with various FTN mailer software

### BinkP Password Security:
- Updated password handling protocol for improved security
- Better compliance with binkp protocol specifications

### TIC File Compliance:
- Outgoing TIC file generation now fully complies with FSC-87 specification
- Improves file transfer compatibility across FTN networks
- Ensures proper metadata handling for file echoes
