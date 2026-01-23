# binkterm-php - Modern Fidonet Mailer & Web Interface

binkterm-php is a modern Fidonet mailer that provides both a web interface and native binkp TCP/IP connectivity for FTN (Fidonet Technology Network) message handling. It combines traditional FTN packet processing with contemporary web technologies to create a user-friendly experience for Fidonet system operators.

 binkterm-php was largely written by Anthropic's Claude with prompting by awehttam.  It was meant to be a fun little excercise to see what Claude would come up with for an older technology mixed up with a modern interface.

There are no doubt bugs and omissions in the project as it was written by an AI. YMMV.  This code is released under the terms of a [BSD License](LICENSE.md).

awehttam runs an instance of BinktermPHP over at https://mypoint.lovelybits.org

## Table of Contents

- [Screen shots](#screen-shots)
- [Features](#features)
- [Installation](#installation)
- [Configuration](#configuration)
- [Upgrading to Multinetwork (v1.6.7)](#upgrading-to-multinetwork-v167)
- [Database Management](#database-management)
- [Command Line Scripts](#command-line-scripts)
- [Operation](#operation)
- [Troubleshooting](#troubleshooting)
- [Customization](#customization)
- [Security Considerations](#security-considerations)
- [File Structure](#file-structure)
- [Contributing](#contributing)
- [License](#license)
- [Support](#support)
- [Acknowledgments](#acknowledgments)

## Screen shots

Here are some screen shots showing various aspects of the interface.

<table>
  <tr>
    <td align="center"><b>Echomail list</b><br><img src="docs/screenshots/echomail.png" width="400"></td>
    <td align="center"><b>Echomail</b><br><img src="docs/screenshots/read_echomail.png" width="400"></td>
  </tr>
  <tr>
    <td align="center"><b>Netmail</b><br><img src="docs/screenshots/read_netmail.png" width="400"></td>
    <td align="center"><b>Custom Themes</b><br><img src="docs/screenshots/cyberpunk.png" width="400"></td>
  </tr>
  <tr>
    <td align="center"><b>User Management</b><br><img src="docs/screenshots/usermanagement.png" width="400"></td>
    <td align="center"><b>Echoarea management</b><br><img src="docs/screenshots/echomanagement.png" width="400"></td>
  </tr>
  <tr>
    <td align="center"><b>Mobile Echoread</b><br><img src="docs/screenshots/mobile_echoread.png" width="400"></td>
    <td align="center"><b>Mobile Echolist</b><br><img src="docs/screenshots/moble_echolist.png" width="400"></td>
  </tr>
  <tr>
    <td align="center"><b>BinkP management</b><br><img src="docs/screenshots/binkp_management.png" width="400"></td>
    <td align="center"><b>Node List Browser</b><br><img src="docs/screenshots/nodelist.png" width="400"></td>
  </tr>
</table>


## Features

### Web Interface
- **Modern Bootstrap 5 UI** - Clean, responsive interface accessible from any device including mobile phones. 
- **Netmail Management** - Send and receive private network mail messages
- **Echomail Support** - Participate in public discussion areas (forums).  Sortable and threaded view available.
- **Address Book Support** A handy address book to keep track of your netmail contacts
- **Message Sharing** - Share echomail messages via secure web links with privacy controls
- **Message Saving** - Ability to save messages
- **Search Capabilities** - Full-text search across messages and echo areas
- **Web Terminal** - SSH terminal access through the web interface with configurable proxy support
- **Installable PWA** - Installable both on mobile and desktop for a more seamless application experience

### Native Binkp Protocol Support
- **FTS-1026 Compliant** - Full (really?)  binkp/1.0 protocol implementation
- **TCP/IP Connectivity** - Direct connections over internet (port 24554)
- **Automated Scheduling** - Cron-style polling with configurable intervals
- **File Transfer** - Reliable packet exchange with resume support (not FREQIT)
- **Password Authentication** - Uplink authentication
- **Connection Management** - Multiple concurrent connections with limits

### Command Line Tools
- **Message Posting** - CLI tool for automated netmail/echomail posting
- **Connection Testing** - Debug and test binkp connections
- **Server Management** - Start/stop binkp server daemon (Linux/UNIX only)
- **Status Monitoring** - Real-time system and connection status
- **Scheduling Control** - Manage automated polling schedules
- **Weather Reports** - Configurable weather forecast generator for posting to echomail areas ([details](scripts/README_weather.md))
- **Echomail Maintenance** - Purge old messages by age or count limits to manage database size ([details](scripts/README_echomail_maintenance.md))

## Installation

### Requirements
- **PHP 8.0+** with extensions: PDO, PostgreSQL, Sockets, JSON, DOM
- **Web Server** - Apache, Nginx, or PHP built-in server
- **Composer** - For dependency management
- **Operating System** - Windows, Linux, or macOS

### Step 1: Clone Repository
```bash
git clone https://github.com/awehttam/binkterm-php
cd binkterm-php
```

### Step 2: Install Dependencies
```bash
composer install
```

### Step 3: Set Up Database and Admin User
Use the installation script for automated setup:
```bash
# Interactive installation (prompts for admin credentials)
php scripts/install.php

# Non-interactive installation (creates admin/admin123 - CHANGE IMMEDIATELY!)
php scripts/install.php --non-interactive
```

Alternatively, use the setup script which auto-detects whether to install or upgrade:
```bash
php scripts/setup.php
```

### Step 5: Configure Web Server

#### Apache
```apache
<VirtualHost *:80>
    ServerName binktest.local
    DocumentRoot /path/to/binktest/public_html
    
    <Directory /path/to/binktest/public_html>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

#### Nginx
```nginx
server {
    listen 80;
    server_name binktest.local;
    root /path/to/binktest/public_html;
    index index.php;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

#### PHP Built-in Server (Development)
```bash
cd public_html
php -S localhost:8080
```

## Configuration

### Basic System Configuration
Edit `config/binkp.json` to configure your system. See `config/binkp.json.example` for a complete reference.

```json
{
    "system": {
        "name": "My BBS System",
        "address": "1:123/456.57599",
        "sysop": "Your Name",
        "location": "Your City, State",
        "hostname": "your.domain.com",
        "website": "https://yourbbs.com",
        "timezone": "UTC"
    },
    "binkp": {
        "port": 24554,
        "timeout": 300,
        "max_connections": 10,
        "bind_address": "0.0.0.0",
        "inbound_path": "data/inbound",
        "outbound_path": "data/outbound",
        "preserve_processed_packets": false
    },
    "uplinks": [
        {
            "me": "1:123/456.57599",
            "networks": [
                "1:*/*",
                "2:*/*",
                "3:*/*",
                "4:*/*"
            ],
            "address": "1:123/456",
            "domain": "fidonet",
            "hostname": "hub.fidonet.org",
            "port": 24554,
            "password": "your_password",
            "poll_schedule": "0 */4 * * *",
            "enabled": true,
            "compression": false,
            "crypt": false,
            "default": true
        }
    ]
}
```

### Configuration Options

#### System Settings
| Field | Required | Description |
|-------|----------|-------------|
| `name` | Yes | Your system's display name |
| `address` | Yes | Your primary FTN address (zone:net/node.point) |
| `sysop` | Yes | System operator name. **Must match the real name on your sysop user account** for netmail addressed to "sysop" to be delivered correctly |
| `location` | No | Geographic location (displayed in system info) |
| `hostname` | Yes | Your internet hostname or IP address |
| `website` | No | Website URL (included in message origin lines) |
| `timezone` | Yes | System timezone ([PHP timezone list](https://www.php.net/manual/en/timezones.php)) |

**Note**: When the `website` field is configured, it will be included in FidoNet message origin lines:
- Without website: `* Origin: My BBS System (1:234/567)`
- With website: `* Origin: My BBS System <https://mybbs.com> (1:234/567)`

#### Binkp Settings
| Field | Default | Description |
|-------|---------|-------------|
| `port` | 24554 | TCP port for binkp server |
| `timeout` | 300 | Connection timeout in seconds |
| `max_connections` | 10 | Maximum simultaneous connections |
| `bind_address` | 0.0.0.0 | IP address to bind to (0.0.0.0 for all interfaces) |
| `inbound_path` | data/inbound | Directory for incoming packets |
| `outbound_path` | data/outbound | Directory for outgoing packets |
| `preserve_processed_packets` | false | If true, moves processed packets to a `processed/` subdirectory instead of deleting |

#### Uplink Configuration
Each uplink in the `uplinks` array supports the following fields:

| Field | Required | Description |
|-------|----------|-------------|
| `me` | Yes | Your FTN address as presented to this uplink |
| `address` | Yes | The uplink's FTN address |
| `hostname` | Yes | Uplink hostname or IP address |
| `port` | Yes | Uplink port (typically 24554) |
| `password` | Yes | Authentication password (shared secret) |
| `domain` | Yes | Network domain (e.g., "fidonet", "fsxnet", "agoranet") |
| `networks` | Yes | Array of address patterns this uplink routes (e.g., `["1:*/*", "2:*/*"]`) |
| `poll_schedule` | No | Cron expression for automated polling (e.g., `"0 */4 * * *"` = every 4 hours) |
| `enabled` | No | Whether uplink is active (default: true) |
| `default` | No | Whether this is the default uplink for unrouted messages |
| `compression` | No | Enable compression (not yet implemented) |
| `crypt` | No | Enable encryption (not yet implemented) |

**Network Patterns**: The `networks` field uses wildcard patterns to define which addresses route through this uplink:
- `1:*/*` - All Zone 1 addresses
- `21:*/*` - All Zone 21 addresses (FSXNet)
- `46:*/*` - All Zone 46 addresses (AgoraNet)

**Multiple Networks Example**:
```json
{
    "uplinks": [
        {
            "me": "1:123/456.57599",
            "address": "1:123/456",
            "domain": "fidonet",
            "networks": ["1:*/*", "2:*/*", "3:*/*", "4:*/*"],
            "hostname": "fidonet-hub.example.com",
            "port": 24554,
            "password": "fido_password",
            "default": true,
            "enabled": true
        },
        {
            "me": "21:1/999",
            "address": "21:1/100",
            "domain": "fsxnet",
            "networks": ["21:*/*"],
            "hostname": "fsxnet-hub.example.com",
            "port": 24554,
            "password": "fsx_password",
            "enabled": true
        }
    ]
}
```

### Web Terminal Configuration

The web terminal feature provides SSH access through the browser interface. This requires both configuration in the `.env` file and a proxy server to handle WebSocket-to-SSH connections.

#### .env Configuration
Add these settings to your `.env` file:

```bash
# Web Terminal Configuration
TERMINAL_ENABLED=true
TERMINAL_HOST=your.ssh.server.com
TERMINAL_PORT=22
TERMINAL_PROXY_HOST=your.proxy.server.com
TERMINAL_PROXY_PORT=443
TERMINAL_TITLE=Terminal Gateway
```

#### Configuration Options
- **TERMINAL_ENABLED**: Set to `true` to enable terminal access, `false` to disable
- **TERMINAL_HOST**: The SSH server hostname/IP that users will connect to
- **TERMINAL_PORT**: SSH server port (typically 22)
- **TERMINAL_PROXY_HOST**: WebSocket proxy server hostname/IP
- **TERMINAL_PROXY_PORT**: WebSocket proxy server port (typically 443 for HTTPS)
- **TERMINAL_TITLE**: Custom title displayed on the terminal page

#### Custom Welcome Messages

You can customize the welcome messages displayed to users in various parts of the system by creating optional text files in the `config/` directory:

##### Terminal Welcome Message
Create `config/terminal_welcome.txt` to display a custom message on the terminal login page. If this file exists, it replaces the default "SSH Connection to host:port" message. The content supports multiple lines and will be displayed exactly as written.

Example `config/terminal_welcome.txt`:
```
Welcome to MyBBS Terminal Gateway!

Connect to our shell server to access:
- Email and messaging systems
- File areas and downloads  
- Games and utilities
- Community forums

Enter your credentials below to connect.
```

##### New User Welcome Email Template
Create `config/newuser_welcome.txt` to customize the welcome email sent to newly registered users. This email template is sent automatically after user registration is approved by an administrator and can include instructions, rules, or helpful information for new users. The template supports basic text formatting and will be sent via the configured SMTP server.

##### General Welcome Message
Create `config/welcome.txt` to display a custom welcome message on the main page or login screen. This can be used for general announcements, system information, or greeting messages for all users.

#### Proxy Server Requirement

The web terminal requires a WebSocket-to-SSH proxy server to bridge browser WebSocket connections to SSH servers. You can use a proxy server like [Terminal Gateway](https://github.com/awehttam/terminalgateway) which provides:

- WebSocket to SSH connection bridging
- Session management and authentication
- Security isolation between web and SSH connections
- Support for multiple concurrent sessions

#### Security Considerations

- Users must be authenticated in the web interface to access the terminal
- The terminal is disabled by default (`TERMINAL_ENABLED=false`)
- SSH authentication is handled separately from web authentication
- Consider network security for both the proxy server and target SSH server
- The proxy server should be properly secured and regularly updated

## Upgrading to Multinetwork (v1.6.7)

Version 1.6.7 introduces support for multiple FTN networks (FidoNet, FSXNet, AgoraNet, etc.) running simultaneously. This requires both database schema changes and configuration updates.

### Step 1: Run Database Migration

```bash
php scripts/upgrade.php
```

This migration adds `domain` fields to the `echoareas`, `nodelist`, and `nodelist_metadata` tables, and updates the unique constraint on echoareas to allow the same tag across different networks.

### Step 2: Update binkp.json Configuration

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

### Step 3: Update Echoarea Domains (Optional)

If you have existing echoareas, they will be automatically assigned to the "fidonet" domain during migration. To assign echoareas to different networks, use the admin interface or update the database directly:

```sql
UPDATE echoareas SET domain = 'fsxnet' WHERE tag LIKE 'FSX_%';
UPDATE echoareas SET domain = 'agoranet' WHERE tag LIKE 'AGN_%';
```

### Step 4: Import Network-Specific Nodelists

When importing nodelists for different networks, specify the domain:

```bash
php scripts/import_nodelist.php --file=FSXNET.023 --domain=fsxnet
php scripts/import_nodelist.php --file=NODELIST.365 --domain=fidonet
```

### Benefits of Multinetwork Support

- **Simultaneous Networks**: Connect to FidoNet, FSXNet, AgoraNet, and other FTN networks at the same time
- **Proper Routing**: Messages are routed to the correct uplink based on destination address
- **Network Isolation**: Echoareas are scoped to their network domain, preventing tag collisions
- **Per-Network Nodelists**: Each network maintains its own nodelist for proper addressing

## Database Management

### Installation and Upgrades
BinktermPHP includes scripts for database installation and schema migrations:

### Composer packaging

```bash
composer install
```
### Configuration
#### System configuration

Postgres, SMTP, web terminal, and other miscellaneous settings are controlled through ".env" in the main directory.  See .env.example for reference.

#### Node configuration

Create config/binkp.json (see binkp.json.example for reference) with appropriate parameters.  

### Schema installation

```bash
# Install fresh database with admin user
php scripts/install.php                    # Interactive mode
php scripts/install.php --non-interactive  # Uses defaults

# Auto-detect and run install or upgrade
php scripts/setup.php                      # Smart setup
php scripts/setup.php status               # Show system status

# Database migrations
php scripts/upgrade.php                    # Apply pending migrations
php scripts/upgrade.php status             # Show migration status
php scripts/upgrade.php create 1.3.0 "add feature"  # Create new migration
```

### File system permissions

The directories:

data/outbound
data/logs

must be writable by the web server and the user that runs the binkp_ scripts.  Quick but not optimal permissions would be:

chmod 777 data/outbound
chmod g+s data/outbound
chmod 777 data/logs

but a better model would be to collaborate with the group id your web server or php runner runs as.

### Migration System
Database changes are managed through versioned SQL migration files stored in `database/migrations/`:

- **Filename format**: `vX.Y.Z_description.sql` (e.g., `v1.1.0_add_user_preferences.sql`)
- **Automatic tracking**: Migration status is recorded in `database_migrations` table
- **Safe execution**: Each migration runs in a transaction with rollback on failure
- **Comment support**: SQL comments are automatically stripped during execution

## Command Line Scripts

### Message Posting Tool
Post netmail or echomail from command line:

```bash
# Send netmail
php scripts/post_message.php --type=netmail \
  --from=1:153/149.57599 --from-name="John Doe" \
  --to=1:153/149 --to-name="Jane Smith" \
  --subject="Test Message" \
  --text="Hello, this is a test!"

# Post to echomail
php scripts/post_message.php --type=echomail \
  --from=1:153/149.57599 --from-name="John Doe" \
  --echoarea=GENERAL --subject="Discussion Topic" \
  --file=message.txt

# List available users and echo areas
php scripts/post_message.php --list-users
php scripts/post_message.php --list-areas
```

### Weather Report Generator
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

The weather script is fully configurable via JSON configuration files, supporting any worldwide locations with descriptive forecasts and current conditions. See [scripts/README_weather.md](scripts/README_weather.md) for detailed setup instructions and configuration examples.

### Echomail Maintenance Utility
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

The maintenance script provides flexible echomail cleanup with age-based deletion, count-based limits, dry-run preview mode, and per-echo or bulk processing. See [scripts/README_echomail_maintenance.md](scripts/README_echomail_maintenance.md) for detailed documentation, cron job examples, and best practices.

### User Management Tool
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

### Binkp Server Management

Polling is supported on all platforms, except for Windows where binkp_server will not operate.

#### Start Binkp Server
```bash
# Start server in foreground
php scripts/binkp_server.php

# Start as daemon (Unix-like systems)
php scripts/binkp_server.php --daemon

# Custom port and logging
php scripts/binkp_server.php --port=24554 --log-level=DEBUG
```

#### Manual Polling
```bash
# Poll specific uplink
php scripts/binkp_poll.php 1:153/149

# Poll all configured uplinks
php scripts/binkp_poll.php --all

# Test connection without polling
php scripts/binkp_poll.php --test 1:153/149
```

#### System Status
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

#### Automated Scheduler
```bash
# Start scheduler daemon
php scripts/binkp_scheduler.php --daemon

# Run once and exit
php scripts/binkp_scheduler.php --once

# Show schedule status
php scripts/binkp_scheduler.php --status

# Custom interval (seconds)
php scripts/binkp_scheduler.php --interval=120
```

#### Debug Connection Issues
```bash
# Detailed connection debugging
php scripts/debug_binkp.php 1:153/149
```

### Packet Processing
```bash
# Process inbound packets
php scripts/process_packets.php
```

### Nodelist Updates
Automatically download and import nodelists from configured sources.

#### Configuration
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

#### URL Macros
URLs support date macros for dynamic nodelist filenames:

| Macro | Description | Example |
|-------|-------------|---------|
| `\|DAY\|` | Day of year (1-366) | 23 |
| `\|YEAR\|` | 4-digit year | 2026 |
| `\|YY\|` | 2-digit year | 26 |
| `\|MONTH\|` | 2-digit month | 01 |
| `\|DATE\|` | 2-digit day of month | 22 |

Example: `https://example.com/NODELIST.Z|DAY|` becomes `NODELIST.Z23` on day 23.

#### Usage
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

## Operation

### Starting the System

1. **Start Web Server**: Ensure Apache/Nginx is running, or use PHP built-in server
2. **Start Binkp Server**: `php scripts/binkp_server.php --daemon` - not fully tested, see alternative polling
3. **Polling** Configure cron to run `scripts/binkp_poll.php --all` periodically
4. **Start Scheduler**: `php scripts/binkp_scheduler.php --daemon`
5. **Process Packets**: Set up cron job for `php scripts/process_packets.php`

### Daily Operations

#### Via Web Interface
1. Navigate to your binktest URL
2. Login with your credentials
3. Use the Binkp tab to monitor connections and manage uplinks
4. Send/receive messages via Netmail and Echomail tabs

#### Via Command Line
- Monitor status: `php scripts/binkp_status.php`
- Manual poll: `php scripts/binkp_poll.php --all`
- Post messages: `php scripts/post_message.php [options]`

### Cron Job Setup
Add these entries to your crontab for automated operation:

```bash
# Process inbound packets every 3 minutes
*/3 * * * * /usr/bin/php /path/to/binktest/scripts/process_packets.php

# Poll uplinks every 5 minutes
*/5 * * * * /usr/bin/php /path/to/binktest/scripts/binkp_poll.php

# Update nodelists daily at 4am
0 4 * * * /usr/bin/php /path/to/binktest/scripts/update_nodelists.php --quiet

# Backup database daily at 2am
0 2 * * * cp /path/to/binktest/data/binktest.db /path/to/backups/binktest-$(date +\%Y\%m\%d).db

# Rotate logs weekly
0 0 * * 0 find /path/to/binktest/data/logs -name "*.log" -mtime +7 -delete
```

## Troubleshooting

### Common Issues

#### Connection Problems
**Problem**: Cannot connect to uplink
**Solutions**:
1. Check network connectivity: `ping uplink.hostname.com`
2. Verify port is open: `telnet uplink.hostname.com 24554`
3. Run debug script: `php scripts/debug_binkp.php 1:153/149`
4. Check logs in `data/logs/` directory
5. Verify password in configuration

#### Authentication Failures
**Problem**: Password mismatch errors
**Solutions**:
1. Verify password in `config/binkp.json` matches uplink configuration
2. Check that uplink address is correct
3. Ensure uplink has your address and password configured
4. Run debug script to see exact authentication flow

#### File Transfer Issues
**Problem**: Files not transferring properly
**Solutions**:
1. Check file permissions on inbound/outbound directories
2. Verify disk space availability
3. Check for firewall blocking data transfer
4. Review transfer logs for specific error messages
5. Test with smaller files first

#### Web Interface Problems
**Problem**: Cannot access web interface
**Solutions**:
1. Check web server error logs
2. Verify PHP extensions are installed
3. Check file permissions on web directory
4. Test PHP configuration: `php -m`
5. Verify database file permissions

### Log Files
Monitor these log files for troubleshooting:

- `data/logs/binkp_server.log` - Server daemon logs
- `data/logs/binkp_poll.log` - Polling activity
- `data/logs/binkp_scheduler.log` - Automated scheduling
- `data/logs/binkp_debug.log` - Debug connection issues
- `data/logs/binkp_web.log` - Web interface API calls

### Debug Mode
Enable detailed logging for troubleshooting:

```bash
# Start server with debug logging
php scripts/binkp_server.php --log-level=DEBUG

# Debug specific connection
php scripts/debug_binkp.php 1:153/149

# Monitor logs in real-time
tail -f data/logs/binkp_server.log
```

### Analytics

You can inject analytics tracking code into the page header by creating a template named `templates/custom/header.insert.twig`.
See `templates/custom/header.insert.twig.example` for reference with Google Analytics and other tracking examples.

## Customization

BinktermPHP provides several ways to customize the look and feel without modifying core files:

- **Custom Stylesheet**: Set `STYLESHEET=/css/mytheme.css` in `.env` (includes built-in dark theme at `/css/dark.css`)
- **Template Overrides**: Copy any template to `templates/custom/` to override it
- **Custom Routes**: Create `routes/web-routes.local.php` to add new pages
- **System News**: Create `templates/custom/systemnews.twig` for dashboard content
- **Header Insertions**: Add CSS/JS via `templates/custom/header.insert.twig`
- **Welcome Messages**: Customize login page via `config/welcome.txt`

All customizations are upgrade-safe and won't be overwritten when updating BinktermPHP.

For detailed instructions including Bootstrap 5 components, Twig template variables, and code examples, see **[CUSTOMIZING.md](CUSTOMIZING.md)**.

### Performance Tuning

#### High Traffic Systems
1. Increase `max_connections` in configuration
2. Use faster storage for inbound/outbound directories
3. Consider SSD storage for database
4. Monitor system resources during peak times
5. Optimize PHP opcache settings

#### Memory Issues
1. Monitor PHP memory usage
2. Process packets more frequently to avoid large queues
3. Clean up old log files regularly
4. Consider increasing PHP memory limit

### Getting Help
If you encounter issues not covered here:

1. Check the debug logs with maximum verbosity
2. Test with minimal configuration (one uplink)
3. Verify your FTN address is correct and authorized
4. Contact your uplink administrator to verify connectivity
5. Create issues on the project GitHub repository with:
   - Full error messages
   - Configuration details (remove passwords)
   - Debug log excerpts
   - System information (OS, PHP version)

## Security Considerations

### Network Security
- Binkp server listens on all interfaces by default
- Consider firewall rules to restrict access
- Monitor connection logs for unauthorized attempts
- Use strong passwords for uplink authentication

### File Security
- Inbound directory should not be web-accessible
- Set appropriate file permissions (755 for directories, 644 for files)
- Regular backup of database and configuration files
- Monitor disk space to prevent DoS via large files

### Web Security
- Use HTTPS in production environments
- Implement proper session management
- Regular security updates of dependencies
- Consider rate limiting for API endpoints

## File Structure

```
binktest/
├── config/
│   └── binkp.json              # Binkp configuration
├── src/
│   ├── Auth.php                # Authentication
│   ├── Database.php            # Database connection
│   ├── MessageHandler.php      # Message processing
│   ├── BinkdProcessor.php      # Packet processing
│   ├── Template.php            # Twig rendering
│   └── Binkp/                  # Binkp implementation
│       ├── Protocol/           # Protocol classes
│       ├── Connection/         # Connection management
│       ├── Config/            # Configuration
│       ├── Queue/             # Queue processing
│       └── Web/               # Web interface
├── scripts/
│   ├── binkp_server.php       # Binkp server daemon
│   ├── binkp_poll.php         # Manual polling
│   ├── binkp_status.php       # System status
│   ├── binkp_scheduler.php    # Automated scheduler
│   ├── debug_binkp.php        # Debug connections
│   ├── post_message.php       # Message posting tool
│   └── process_packets.php    # Packet processor
├── templates/                  # Twig templates
│   └── custom/                # Custom template insertions
├── public_html/               # Web root
│   ├── css/                   # Stylesheets
│   ├── js/                    # JavaScript
│   └── index.php             # Main application
├── data/
│   ├── inbound/              # Incoming packets
│   ├── outbound/             # Outgoing packets (shared permissions between web server and shell account)
│   ├── logs/                 # Log files  (shared permissions between web server and shell account)
└── database/
    └── migrations/                # Database migration files
```

## Contributing

We welcome contributions to BinktermPHP! Please see our [Contributing Guide](CONTRIBUTING.md) for detailed information on:

- Development setup and code conventions
- Pull request workflow
- Database migrations
- Testing guidelines
- Security considerations

All contributions must be submitted via pull request and will be reviewed by project maintainers.

## License

This project is licensed under a BSD License. See LICENSE.md for more information.

## Support

- **Documentation**: This README and inline code comments
- **Issues**: GitHub issue tracker
- **Community**: Fidonet echo areas and developer forums

## Acknowledgments

- Fidonet Technical Standards Committee for protocol specifications
- Original binkd developers for reference implementation
- Bootstrap and jQuery communities for web interface components
- PHP community for excellent documentation and tools