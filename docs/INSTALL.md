# Installing BinktermPHP

BinktermPHP can be installed using two methods: the automated installer (recommended for most sysops) or from Git (recommended for developers and contributors).

BinktermPHP is developed and tested on Debian-based Linux distributions, including Debian, Ubuntu, and Linux Mint. The instructions in this guide assume a Debian-based system. Other distributions can run BinktermPHP, but package names, paths, and service management commands will differ and you will need to adapt the instructions accordingly.

## Table of Contents

- [Requirements](#requirements)
  - [Ubuntu/Debian package requirements](#ubuntudebian-package-requirements)
- [Create BBS User Account](#create-bbs-user-account)
- [PostgreSQL Database Setup](#postgresql-database-setup)
- [Method 1: Using the Installer (Recommended)](#method-1-using-the-installer-recommended)
- [Method 2: From Git](#method-2-from-git)
- [Configure Web Server](#configure-web-server)
  - [Caddy](#caddy)
  - [Other web servers (unsupported)](#other-web-servers-unsupported)
    - [Nginx](#nginx)
    - [Apache](#apache-libapache2-php)
  - [PHP Built-in Server (Development)](#php-built-in-server-development)
- [Common System Daemon Locations (Caddy, PHP, etc)](#common-system-daemon-locations-caddy-php-etc)
- [Set Up Cron Jobs](#set-up-cron-jobs-recommended)
- [Network Ports](#network-ports)
- [Getting Help](#getting-help)
- [Next Steps](#next-steps)

---

## Requirements
- **PHP 8.2+** with extensions: PDO, PostgreSQL, Sockets, JSON, DOM, Zip, OpenSSL, GMP
- **NodeJS** for DOS Doors support (optional)
- **PostgreSQL** - Database server
- **Web Server** - Caddy, Apache, Nginx, etc.
- **Composer** - For dependency management
- **libsixel** (`libsixel-bin`) - Optional, enables Sixel image rendering in the telnet/SSH terminal reader
- **Feature-specific dependencies** - Some optional features, such as DOS Doors, may require additional installation steps. See each feature's documentation for its specific requirements.
- **Hardware Recommendation** - If you are running all services, we recommend at least 2 GB of RAM and 2 CPU cores
- **Sizing Note** - Running fewer services generally requires less RAM
- **Hosting Recommendation** - Use a VPS, dedicated server, Raspberry Pi, or other environment where you control the OS, web server, firewall, and background services
- **Shared Hosting** - Not recommended. BinktermPHP requires PostgreSQL and uses multiple service ports and long-running daemons that many shared hosting environments do not permit
- **Operating System** - Designed with Linux in mind, should also run on MacOS, Windows (with some caveats)
- **Operating User** - BinktermPHP should run under its own dedicated user account, not as root or your personal login. See [Create BBS User Account](#create-bbs-user-account) below.

### Ubuntu/Debian package requirements

> **Run as your admin user** — the account you log in with that has `sudo` access.

```bash
sudo apt-get update

# Choose a web server and PHP runner (recommended)
#  If caddy is not available in your distro, see https://caddyserver.com/download
sudo apt-get install caddy php-fpm

# -or- Apache and PHP runner (not recommended)
sudo apt-get install libapache2-mod-php apache2

# Install required packages
sudo apt-get install php-zip php-mcrypt php-iconv php-mbstring php-pdo php-xml php-pgsql php-dom php-gmp postgresql composer
sudo apt-get install -y unzip p7zip-full

# Optional: Sixel image rendering in telnet/SSH terminal reader
sudo apt-get install -y libsixel-bin
```

The `unzip` and `p7zip-full` packages are required for Fidonet bundle extraction.

---

## Create BBS User Account

> **Run as your admin user.**

BinktermPHP should run under its own dedicated user account. This keeps its files and processes isolated from the rest of the system. The account does not need sudo access.

```bash
sudo adduser binktermphp
```

`adduser` will prompt you to set a password and fill in optional details. Once created, you can switch to this account at any time with:

```bash
sudo su - binktermphp
```

BinktermPHP will be installed into this user's home directory (e.g. `/home/binktermphp/binkterm-php`). Make note of this path — you will need it when configuring the web server and cron jobs.

---

## PostgreSQL Database Setup

> **Run as your admin user.**

This creates a dedicated PostgreSQL user and database for BinktermPHP. These are database credentials, separate from the system user account created earlier. Choose a strong, unguessable password — a random string of at least 20 characters is recommended.

Connect to PostgreSQL as the superuser:

```bash
sudo -u postgres psql
```

The example below uses `binktermphp` for both the database user and database name. Replace `changeme` with a strong password of your choosing:

```sql
CREATE USER binktermphp WITH PASSWORD 'changeme';
CREATE DATABASE binktermphp OWNER binktermphp;
\q
```

Verify the connection works:

```bash
psql -U binktermphp -d binktermphp -h 127.0.0.1
```

Make note of the database name, username, and password — you will need them during install or when configuring `.env`.

---

## Method 1: Using the Installer (Recommended)

> **Run as the `binktermphp` user.** Switch accounts first if you have not already:
> ```bash
> sudo su - binktermphp
> ```

The installer is the recommended method for most sysops. It provides a fully automated setup process that downloads, configures, and installs BinktermPHP — including handling upgrades when you re-run it on an existing installation.

```bash
# Download the installer
wget https://raw.githubusercontent.com/awehttam/binkterm-php-installer/main/binkterm-installer.phar

# Run the installer
php binkterm-installer.phar

# Or make it executable and run directly
chmod +x binkterm-installer.phar
./binkterm-installer.phar
```

The installer will:
- Check system requirements (PHP version, extensions)
- Download the latest release from GitHub
- Configure the `.env` file with your database credentials and environment settings
- Set up the initial admin user
- Configure initial BinkP and BBS settings

The installer will prompt for your `SITE_URL` — the URL your BBS will be accessed from. Have it ready before you begin. See the [SITE_URL examples in Method 2](#step-3-configure-environment) if you are unsure what value to use.

---

## Method 2: From Git

> **Run as the `binktermphp` user.** Switch accounts first if you have not already:
> ```bash
> sudo su - binktermphp
> ```

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

Copy the example environment and BinkP config files:
```bash
cp .env.example .env
cp config/binkp.json.example config/binkp.json
```

Edit `.env` and set at minimum your PostgreSQL database credentials (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`) to match what you created in the PostgreSQL setup step.

Also set `SITE_URL` to the URL your BBS will be accessed from. This is used for share links, password-reset emails, and any absolute URL the server generates. The right value depends on your setup:

| Scenario | Example `SITE_URL` |
|---|---|
| Public server with a domain name | `https://mybbs.example.com` |
| Public server, no domain yet (IP only) | `http://123.45.67.89` |
| Local machine, testing only | `http://localhost` |
| Local machine, PHP built-in server | `http://localhost:8080` |

```bash
SITE_URL=https://mybbs.example.com
```

See [CONFIGURATION.md](CONFIGURATION.md) for all available options. Once the system is running you can adjust BBS settings and BinkP configuration through the administration interface.

### Step 4: Install the database schema and create the initial admin user

```bash
# Interactive installation (prompts for admin username and password)
php scripts/install.php

# Non-interactive installation (creates admin/admin123 — change this immediately)
php scripts/install.php --non-interactive
```

Then run the schema upgrader to apply any pending migrations:

```bash
php scripts/upgrade.php
```

---

## Configure Web Server

> **Return to your admin user** for this section. If you are still in the `binktermphp` session, exit it first:
> ```bash
> exit
> ```

Replace `/home/binktermphp/binkterm-php` in the examples below with the actual path where BinktermPHP was installed.

### Caddy

Caddy has been tested with BinktermPHP and works well. It handles HTTPS automatically and does not buffer SSE responses by default. The example config below excludes the SSE endpoint from compression, which would otherwise buffer the stream.

Update the `php8.2-fpm.sock` path to match your installed PHP version.

```caddyfile
# /etc/caddy/Caddyfile

yourdomain.com {
    bind 0.0.0.0

    root * /home/binktermphp/binkterm-php/public_html

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

### Other web servers (unsupported)

The configurations below are provided as a starting point but are untested and not officially supported. Caddy is strongly recommended.

#### Nginx

```nginx
server {
    listen 443 ssl;
    server_name yourdomain.com;
    root /home/binktermphp/binkterm-php/public_html;
    index index.php;

    # Service worker must revalidate on every load so updated cached assets
    # can be discovered promptly.
    location = /sw.js {
        add_header Cache-Control "no-cache" always;
        try_files $uri =404;
    }

    # CSS/JS are versioned by the service worker; require revalidation so
    # browser and proxy caches do not pin old frontend code indefinitely.
    location ~* \.(css|js)$ {
        add_header Cache-Control "max-age=0, must-revalidate" always;
        try_files $uri =404;
    }

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

#### Apache (libapache2-php)

Requires `mod_proxy`, `mod_proxy_fcgi`, and `mod_proxy_wstunnel`. The two WebSocket proxies must appear before the PHP handler.

```apache
<VirtualHost *:443>
    ServerName yourdomain.com
    DocumentRoot /home/binktermphp/binkterm-php/public_html

    # Realtime WebSocket daemon (scripts/realtime_server.php)
    ProxyPass        /ws      ws://127.0.0.1:6010/
    ProxyPassReverse /ws      ws://127.0.0.1:6010/

    # DOS door multiplexing bridge (scripts/dosbox-bridge/multiplexing-server.js)
    ProxyPass        /dosdoor ws://127.0.0.1:6001/
    ProxyPassReverse /dosdoor ws://127.0.0.1:6001/

    <Directory /home/binktermphp/binkterm-php/public_html>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Set `BINKSTREAM_WS_PUBLIC_URL=/ws` and `DOSDOOR_WS_URL=wss://yourdomain.com/dosdoor` in `.env`.

### PHP Built-in Server (Development)

> **Warning:** The PHP built-in web server is intended for local development only. It is single-threaded, has no access controls, and is **not safe for use in a production environment**. Do not expose it to the internet.

```bash
cd public_html
php -S localhost:8080
```

---

## Common System Daemon Locations (Caddy, PHP, etc)

Paths use `<version>` as a placeholder for the installed version number (e.g. `8.2` for PHP, `16` for PostgreSQL).

### Caddy

| File / Path | Purpose |
|---|---|
| `/etc/caddy/Caddyfile` | Main site configuration (virtual hosts, TLS, FastCGI) |
| `/var/lib/caddy/.local/share/caddy/` | Automatic TLS certificate storage |
| `/var/log/caddy/` | Access and error logs |
| `/etc/systemd/system/caddy.service` | Systemd unit override (if customised) |

### php-fpm

| File / Path | Purpose |
|---|---|
| `/etc/php/<version>/fpm/php-fpm.conf` | Master php-fpm configuration (global settings, pid file) |
| `/etc/php/<version>/fpm/pool.d/www.conf` | Default worker pool (user, socket path, process limits) |
| `/etc/php/<version>/fpm/php.ini` | PHP runtime settings for FPM requests |
| `/run/php/php<version>-fpm.sock` | Unix socket used by the web server (must match Caddyfile/vhost) |
| `/var/log/php<version>-fpm.log` | php-fpm startup and worker error log |

### PostgreSQL

| File / Path | Purpose |
|---|---|
| `/etc/postgresql/<version>/main/postgresql.conf` | Server tuning (memory, connections, logging) |
| `/etc/postgresql/<version>/main/pg_hba.conf` | Client authentication rules (local trust, md5, scram-sha-256) |
| `/etc/postgresql/<version>/main/pg_ident.conf` | OS-to-database username mapping (rarely needed) |
| `/var/lib/postgresql/<version>/main/` | Data directory (tablespace, WAL) |
| `/var/log/postgresql/` | Server log files |

---

## Set Up Cron Jobs (Recommended)

> **Run as the `binktermphp` user.** Switch accounts if you are not already there, then open the crontab editor:
> ```bash
> sudo su - binktermphp
> crontab -e
> ```

Add the following entries. Replace `/home/binktermphp/binkterm-php` with your actual install path if it differs.

```cron
# Start admin daemon on boot
@reboot /usr/bin/php /home/binktermphp/binkterm-php/scripts/admin_daemon.php --daemon

# Start scheduler on boot
@reboot /usr/bin/php /home/binktermphp/binkterm-php/scripts/binkp_scheduler.php --daemon

# Start binkp server on boot
@reboot /usr/bin/php /home/binktermphp/binkterm-php/scripts/binkp_server.php --daemon

# Start realtime WebSocket server on boot
@reboot /usr/bin/php /home/binktermphp/binkterm-php/scripts/realtime_server.php --daemon

# Optional: start FTP daemon on boot (remove the leading # to enable)
# @reboot /usr/bin/php /home/binktermphp/binkterm-php/scripts/ftp_daemon.php --daemon

# Optional: update nodelists daily at 3am (requires nodelist URLs to be configured)
# 0 3 * * * /usr/bin/php /home/binktermphp/binkterm-php/scripts/update_nodelists.php --quiet
```

If you enable optional features such as telnet, SSH, Gemini, or DOS doors, add their daemon `@reboot` entries to this crontab as well.

---

## Network Ports

The table below lists every port BinktermPHP may use. Most are optional — only open what you actually run. For each inbound service you enable, add a firewall rule to allow that port. BinkP (`24554`) requires both inbound and outbound rules since it connects to and receives connections from other nodes. Internal services (PostgreSQL, admin daemon, DOSBox bridge) should never be exposed to the network — bind them to `127.0.0.1` and leave their ports firewalled.

On Ubuntu/Debian with `ufw`, for example:
```bash
sudo ufw allow 80/tcp        # HTTP
sudo ufw allow 443/tcp       # HTTPS
sudo ufw allow 24554/tcp     # BinkP (in + out)
sudo ufw allow 2323/tcp      # Telnet (if enabled)
sudo ufw allow 2022/tcp      # SSH (if enabled)
```

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

## Getting Help

If you run into trouble during installation:

- Check [FAQ.md](FAQ.md) for common installation issues and solutions.
- Review the relevant log file under `data/logs/` — `server.log` for general errors, `binkp_server.log` for BinkP connection issues.
- Search or open an issue on the [GitHub repository](https://github.com/awehttam/binkterm-php/issues). Include your OS and PHP version, the full error message, and any relevant log excerpts (remove passwords and private keys before posting).
- Visit the support BBS at [claudes.lovelybits.org](https://claudes.lovelybits.org) and post in the BinktermPHP support area.

---

## Next Steps

With BinktermPHP installed and your web server configured, head to [GettingStarted.md](GettingStarted.md) to log in for the first time, configure your BBS settings, and connect to a FidoNet-style network.

---

[Return to Documentation index](index.md)
