# Installing BinktermPHP

BinktermPHP can be installed using two methods: the automated installer (recommended for most sysops) or from Git (recommended for developers and contributors).

## Table of Contents

- [Requirements](#requirements)
  - [Ubuntu/Debian package requirements](#ubuntudebian-package-requirements)
  - [PostgreSQL database setup](#postgres-database-setup)
- [Method 1: Using the Installer (Recommended)](#method-1-using-the-installer-recommended)
- [Method 2: From Git](#method-2-from-git)
- [Configure Web Server](#configure-web-server)
  - [Caddy](#caddy)
  - [Nginx](#nginx)
  - [Apache](#apache-libapache2-php)
  - [PHP Built-in Server (Development)](#php-built-in-server-development)
- [Set Up Cron Jobs](#set-up-cron-jobs-recommended)
- [Database Management](#database-management)
- [Network Ports](#network-ports)

---

## Requirements
- **PHP 8.1+** with extensions: PDO, PostgreSQL, Sockets, JSON, DOM, Zip, OpenSSL, GMP
- **NodeJS** for DOS Doors support (optional)
- **PostgreSQL** - Database server
- **Web Server** - Caddy, Apache, Nginx, etc.
- **Composer** - For dependency management
- **libsixel** (`libsixel-bin`) - Optional, enables Sixel image rendering in the telnet/SSH terminal reader
- **Feature-specific dependencies** - Some optional features, such as DOS Doors, may require additional installation steps. See each feature's documentation for its specific requirements.
- **Hardware Recommendation** - If you are running all services, we recommend at least 2 GB of RAM and 2 CPU cores
- **Sizing Note** - Running fewer services generally requires less RAM
- **Operating System** - Designed with Linux in mind, should also run on MacOS, Windows (with some caveats)
- **Operating User** - It is recommended to run BinktermPHP out of its own user account

### Ubuntu/Debian package requirements
```bash
sudo apt-get update

# Choose a web server and PHP runner (recommended)
#  If caddy is not available in your distro, see https://caddyserver.com/download downloads
sudo apt-get install caddy php-fpm

# -or - Apache and PHP runner (not recommended)
sudo apt-get install libapache2-mod-php apache2

# Install required packages
sudo apt-get install  php-zip php-mcrypt php-iconv php-mbstring php-pdo php-xml php-pgsql php-dom php-gmp postgresql composer  
sudo apt-get install -y unzip p7zip-full

# Optional: Sixel image rendering in telnet/SSH terminal reader
sudo apt-get install -y libsixel-bin
```
The `unzip` and `p7zip-full` packages are required for Fidonet bundle extraction.

### Postgres Database setup

First, decide on a database name, username, and password. These will be used in your `.env` file later.

Connect to PostgreSQL as the superuser:

```bash
sudo -u postgres psql
```

Then run the following commands, replacing `your_username`, `your_password`, and `your_database` with your chosen values:

```sql
CREATE USER your_username WITH PASSWORD 'your_password';
CREATE DATABASE your_database OWNER your_username;
\q
```

Verify the connection works with the new credentials:

```bash
psql -U your_username -d your_database -h 127.0.0.1
```

Make note of your database name, username, and password as you may need to update `.env` later.

---

## Method 1: Using the Installer (Recommended)

The installer is the recommended method for most sysops. It provides a fully automated setup process that downloads, configures, and installs BinktermPHP — including handling upgrades when you re-run it on an existing installation.

```bash
# Download the installer
wget https://raw.githubusercontent.com/awehttam/binkterm-php-installer/main/binkterm-installer.phar

# Run the installer
php binkterm-installer.phar [options]

# Or make it executable (Linux/macOS)
chmod +x binkterm-installer.phar
./binkterm-installer.phar [options]
```

The installer will:
- Check system requirements (PHP version, extensions)
- Download the latest release from GitHub
- Configure the database and environment
- Set up initial admin user
- Configure FidoNet settings

---

## Method 2: From Git

This method is recommended for developers, contributors, and advanced users who want to track the latest changes or submit patches. Most sysops should use the installer instead.

### Step 1: Clone Repository
```bash
git clone https://github.com/awehttam/binkterm-php
cd binkterm-php
```

### Step 2: Install Dependencies
```bash
composer install
```

### Step 3: Configure Environment
Copy the example environment file and configure your settings:
```bash
cp .env.example .env
cp binkp.json.example binkp.json
```

Edit `.env` to configure your database connection, SMTP settings, and other options. At minimum, set the PostgreSQL database credentials. Once the system is up you can adjust your BBS settings and BinkP configuration through the administration interface.

### Step 4: Install the database schema and configure the initial Admin user

First, use the installation script for automated setup:
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

Then run the schema upgrader to ensure all schemas are up to date:

```bash
php scripts/upgrade.php
```

---

## Configure Web Server

### Caddy
Caddy has been tested with BinktermPHP and works well. It handles HTTPS automatically and does not buffer SSE responses by default. The example config below excludes the SSE endpoint from compression, which would otherwise buffer the stream.

 * Update the php8.2-fpm.sock location in the configuration below to match your version of PHP

```caddyfile
# /etc/caddy/Caddyfile

yourdomain.com {
    bind 0.0.0.0

    root * /path/to/binkterm-php/public_html

    # Compress normal pages and API responses.
    # Exclude SSE so the event stream is not buffered or delayed.
    @compressible {
        not path /api/stream
    }
    encode @compressible zstd gzip

    # Block dotfiles (.env, .git, etc.)
    @dotfiles {
        path_regexp (^|/)\..
    }
    respond @dotfiles 403

    # Service worker must not be cached
    @sw path /sw.js
    header @sw Cache-Control "no-cache"

    # CSS/JS versioned by service worker — revalidate on load
    @assets path_regexp \.(?:css|js)$
    header @assets Cache-Control "max-age=0, must-revalidate"

    # Remove trailing slashes for non-existent paths
    @trailingSlash {
        path_regexp slash ^(.+)/$
        not file
    }
    redir @trailingSlash {re.slash.1} 301

    # Realtime WebSocket daemon (scripts/realtime_server.php)
    reverse_proxy /ws 127.0.0.1:6010 {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
    }

    # DOS door multiplexing bridge (scripts/dosbox-bridge/multiplexing-server.js)
    reverse_proxy /dosdoor 127.0.0.1:6001 {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
    }

    @php path *.php
    handle @php {
        php_fastcgi unix//run/php/php8.2-fpm.sock {
            capture_stderr
        }
    }

    @static file {
        try_files {path} {path}/index.html
    }
    handle @static {
        file_server {
            index index.html
        }
    }

    # Everything else: try file, then fall back to index.php (for clean URLs)
    handle {
        try_files {path} {path}/ /index.php
        php_fastcgi unix//run/php/php8.2-fpm.sock {
            capture_stderr
        }
    }

    log {
        output file /var/log/caddy/binkterm-access.log
        format console
    }
}
```

Replace `yourdomain.com`, the `bind` address, `root` path, and php-fpm socket path to match your installation. Caddy obtains and renews TLS certificates automatically. Set `BINKSTREAM_WS_PUBLIC_URL=/ws` and `DOSDOOR_WS_URL=wss://yourdomain.com/dosdoor` in `.env`.

### Nginx

(untested)

```nginx
server {
    listen 443 ssl;
    server_name yourdomain.com;
    root /path/to/binktest/public_html;
    index index.php;

    # Realtime WebSocket daemon (scripts/realtime_server.php)
    location /ws {
        proxy_pass         http://127.0.0.1:6010;
        proxy_http_version 1.1;
        proxy_set_header   Upgrade $http_upgrade;
        proxy_set_header   Connection "upgrade";
        proxy_set_header   Host $host;
        proxy_read_timeout 3600s;
    }

    # DOS door multiplexing bridge (scripts/dosbox-bridge/multiplexing-server.js)
    location /dosdoor {
        proxy_pass         http://127.0.0.1:6001;
        proxy_http_version 1.1;
        proxy_set_header   Upgrade $http_upgrade;
        proxy_set_header   Connection "upgrade";
        proxy_set_header   Host $host;
        proxy_read_timeout 3600s;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

Set `BINKSTREAM_WS_PUBLIC_URL=/ws` and `DOSDOOR_WS_URL=wss://yourdomain.com/dosdoor` in `.env`.

### Apache (libapache2-php)

BinktermPHP recommends Caddy.

Requires `mod_proxy`, `mod_proxy_fcgi`, and `mod_proxy_wstunnel`. The two WebSocket proxies must appear before the PHP handler.

```apache
<VirtualHost *:443>
    ServerName yourdomain.com
    DocumentRoot /path/to/binktest/public_html

    # Realtime WebSocket daemon (scripts/realtime_server.php)
    ProxyPass        /ws      ws://127.0.0.1:6010/
    ProxyPassReverse /ws      ws://127.0.0.1:6010/

    # DOS door multiplexing bridge (scripts/dosbox-bridge/multiplexing-server.js)
    ProxyPass        /dosdoor ws://127.0.0.1:6001/
    ProxyPassReverse /dosdoor ws://127.0.0.1:6001/

    <Directory /path/to/binktest/public_html>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Set `BINKSTREAM_WS_PUBLIC_URL=/ws` and `DOSDOOR_WS_URL=wss://yourdomain.com/dosdoor` in `.env`.

### PHP Built-in Server (Development)
```bash
cd public_html
php -S localhost:8080
```

---

## Set Up Cron Jobs (Recommended)
Start the core long-running services at boot and keep cron for periodic maintenance tasks. If you enable optional features such as FTP, telnet, Gemini, or DOS doors, see the [Operation section in README.md](../README.md#operation) for the additional `@reboot` entries for those daemons.

```cron
# Start admin daemon on boot
@reboot /usr/bin/php /path/to/binkterm/scripts/admin_daemon.php --daemon

# Start scheduler on boot
@reboot /usr/bin/php /path/to/binkterm/scripts/binkp_scheduler.php --daemon

# Start binkp server on boot (Linux/macOS)
@reboot /usr/bin/php /path/to/binkterm/scripts/binkp_server.php --daemon

# Start realtime WebSocket server on boot
@reboot /usr/bin/php /path/to/binkterm/scripts/realtime_server.php --daemon

# Optional: start FTP daemon on boot
@reboot /usr/bin/php /path/to/binkterm/scripts/ftp_daemon.php --daemon

# Update nodelists daily at 3am
#0 3 * * * /usr/bin/php /path/to/binkterm/scripts/update_nodelists.php --quiet
```

Direct cron usage of `binkp_poll.php` and `process_packets.php` is deprecated but still supported. See the [Operation section in README.md](../README.md#operation) for the full daemon list and additional cron examples.

`update_nodelists` can be used if you have URLs configured to update from. Otherwise nodelists can be updated using file area actions.

---

## Database Management

### Database Scripts

```bash
# Fresh installation with admin user
php scripts/install.php                    # Interactive mode
php scripts/install.php --non-interactive  # Uses defaults (admin/admin123)

# Auto-detect install vs upgrade
php scripts/setup.php                      # Smart setup
php scripts/setup.php status               # Show system status

# Apply pending migrations
php scripts/upgrade.php                    # Run migrations
php scripts/upgrade.php status             # Show migration status

# Create a new migration (for developers)
php scripts/migration.php create "add feature"
php scripts/migration.php create "backfill feature data" php
```

### Migration System
Database changes are managed through timestamped SQL or PHP migration files stored in `database/migrations/`:

- **Filename format**: `vYYYYMMDDHHMMSS_description.sql` or `.php` (e.g., `v20260503143000_add_user_preferences.sql`)
- **Creation utility**: Use `php scripts/migration.php create "description"` so new migration IDs are generated consistently in UTC
- **Legacy support**: Existing `vX.Y.Z_description.sql` and `.php` migrations are still supported, but new migrations should use timestamp IDs
- **Automatic tracking**: Migration status is recorded in `database_migrations` table
- **Safe execution**: Each migration runs in a transaction with rollback on failure
- **Comment support**: SQL comments are automatically stripped during execution

---

## Network Ports

| Service | Default Port | Protocol | Direction | Configured In |
|---------|-------------|----------|-----------|---------------|
| Web interface (Apache/Caddy/Nginx) | `80`, `443` | HTTP/HTTPS | Inbound | Web server / reverse proxy |
| BinkP daemon | `24554` | TCP | In + Out | `config/binkp.json` → `binkp.port` |
| Telnet daemon (plain) | `2323` | TCP | Inbound | `.env` `TELNET_PORT` |
| Telnet daemon (TLS) | `8023` | TCP/TLS | Inbound | `.env` `TELNET_TLS_PORT` |
| SSH daemon | `2022` | SSH-2/TCP | Inbound | `.env` `SSH_PORT` |
| Gemini capsule daemon | `1965` | Gemini/TLS | Inbound | `.env` `GEMINI_PORT` |
| Realtime WebSocket daemon | `6010` | WebSocket/TCP | localhost | `.env` `BINKSTREAM_WS_PORT` — must be exposed via reverse proxy |
| FTP daemon | `2121` | FTP control/TCP | Inbound | `.env` `FTPD_PORT` |
| FTP passive range | `2122`–`2149` | FTP data/TCP | Inbound | `.env` `FTPD_PASSIVE_PORT_START` / `FTPD_PASSIVE_PORT_END` |
| DOS door WebSocket bridge | `6001` | WebSocket | Inbound | `.env` `DOSDOOR_WS_PORT` |
| DOSBox bridge session range | `5000–5100` | TCP | Internal | Between bridge and emulator |
| Admin daemon (TCP fallback) | `9065` | TCP | localhost | `.env` `ADMIN_DAEMON_SOCKET` |
| PostgreSQL | `5432` | TCP | Internal | `.env` `DB_PORT` |
| MRC relay (remote) | `5000` / `5001` | TCP / TLS | Outbound | `config/mrc.json` |

- Expose only the services you actually run.
- Bind internal services (admin daemon, DOSBox bridge, PostgreSQL) to `127.0.0.1`.
- Publish user-facing services through a reverse proxy with TLS.

---

[Return to Documentation index](index.md)
