# Docker Deployment Guide for BinktermPHP

This guide covers deploying BinktermPHP using Docker and Docker Compose.

**Note:** Docker is a best-effort deployment option, not the primary target — the bare-metal install (`docs/INSTALL.md`) receives the most testing. Docker support is improving as issues are reported; if you run into problems, please report them in the **LVLY_BINKTERMPHP** echo area or on [GitHub](https://github.com/awehttam/binkterm-php/issues).

## Table of Contents

- [Prerequisites](#prerequisites)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Included Services](#included-services)
- [First Run Setup](#first-run-setup)
- [Upgrading](#updating-the-application)
  - [Upgrading from 1.10 or Earlier](#upgrading-from-110-or-earlier)
- [Managing the Application](#managing-the-application)
- [Volumes and Data Persistence](#volumes-and-data-persistence)
- [Troubleshooting](#troubleshooting)
- [Production Considerations](#production-considerations)

## Prerequisites

- Docker Engine 20.10 or newer
- Docker Compose 2.0 or newer
- At least 2GB of available RAM
- 10GB of available disk space

## Quick Start

### 1. Clone the Repository

```bash
git clone https://github.com/awehttam/binkterm-php.git
cd binkterm-php
```

### 2. Configure BinktermPHP

`.env` is BinktermPHP's own application configuration — the exact same file a
bare-metal install uses. It's loaded directly into the container and contains
nothing Docker-specific.

```bash
# Copy the example environment file
cp .env.example .env

# Edit the .env file with your settings
nano .env
```

**Important**: Change at least these values:
- `DB_PASS` - Use a strong password (also set `DB_PASS` to the same value for the `postgres` container -- see [Configuration](#configuration))
- `SITE_URL` - Your public URL (e.g., https://bbs.example.com)
- `ADMIN_DAEMON_SECRET` - Leave blank to have Docker generate one for you, or set your own

### 3. Configure Docker (optional daemons, ports, cron schedules)

Docker-only settings — which optional daemons run, which host ports are
published, scheduled-job timing — live separately in
`docker-compose.override.yml`, never in `.env`:

```bash
cp docker-compose.override.yml.example docker-compose.override.yml
nano docker-compose.override.yml
```

The defaults in `docker-compose.yml` work without any changes here: no
optional daemons, scheduled jobs on. See [Included Services](#included-services)
for the full list of what's configurable.

### 4. First Run (Initialize Database)

```bash
# Set RUN_SETUP=true for first run only
RUN_SETUP=true docker-compose up -d

# Watch the logs to ensure setup completes
docker-compose logs -f binkterm
```

Wait for the message "Initialization complete!" in the logs.

### 5. Access Your BBS

Open your browser to http://localhost (or the configured SITE_URL).

First Run Setup (see below) creates a default administrator account (`admin` / `admin123`). Log in and change this password immediately from **Admin → Users**.

## Configuration

Docker deployments use two separate, purpose-specific files. They're kept
apart deliberately so a Docker-only setting can never collide with a
same-named application setting:

| File | Contains | Loaded how |
|---|---|---|
| `.env` (from `.env.example`) | BinktermPHP application configuration -- `SITE_URL`, session settings, feature flags, per-daemon internal ports (`SSH_PORT`, `GEMINI_PORT`, etc.), everything documented in [CONFIGURATION.md](CONFIGURATION.md) | Bind-mounted live into the container (`docker-compose.yml`), re-read by `entrypoint.sh` on every start |
| `docker-compose.override.yml` (from `docker-compose.override.yml.example`) | Docker-only settings: optional daemon toggles, published host ports, scheduled-job timing, volumes, resource limits | Baked into the container's environment at creation time by Compose |

`.env` is never docker-aware and contains nothing Docker-specific. A setting
like `SSH_PORT` in `.env` controls the *internal* port `ssh_daemon.php` binds
to inside the container; the *host*-facing port it's published on is a
completely separate concern configured in `docker-compose.override.yml` (see
[Included Services](#included-services)). Changing one never affects the
other.

**Editing `.env`** only needs a restart, not a recreate, since it's bind-mounted live:

```bash
nano .env
docker-compose restart binkterm
```

**Editing `docker-compose.override.yml`** needs `docker-compose up -d` instead, since Compose only re-resolves those values when it recreates the container:

```bash
nano docker-compose.override.yml
docker-compose up -d
```

`.env` must exist (`cp .env.example .env`) before the very first `docker-compose up` -- Docker creates an empty directory at the mount point instead of erroring if the source file is missing, which breaks startup in a confusing way. `entrypoint.sh` checks for this and fails with a clear message if it happens.

### Database Credentials

`DB_NAME`, `DB_USER`, and `DB_PASS` in `.env` are used both by the app (to
connect to the database) and by Compose (to provision the `postgres`
container with matching credentials) -- Compose reads them from `.env`
automatically since that's the file it auto-loads for `${...}` substitution.
Set them once in `.env`; there's nothing to duplicate elsewhere. `DB_HOST` and
`DB_PORT` in your `.env` are ignored inside Docker -- Compose always points
the app at the `postgres` container regardless of what's set there.

### Publishing a Different Host Port

The core services (web interface, Telnet, BinkP, DOS doors, BinkStream) are
published on their standard ports in `docker-compose.yml`. To remap one (e.g.
serve the web interface on host port 8080), add the new mapping to
`docker-compose.override.yml` and comment out the corresponding line in
`docker-compose.yml` -- Compose appends list-valued keys like `ports:` across
files rather than replacing them, so leaving the original in place would
publish both.

## Included Services

`docker/supervisord.conf` always starts the core set of services needed for the web interface and FTN networking. Everything else is opt-in via `ENABLE_*` variables set in `docker-compose.override.yml` (never in `.env` -- see [Configuration](#configuration)), read by `docker/entrypoint.sh` on every container start (no rebuild required to toggle one on or off).

### Always on

- **apache** — the web interface
- **admin_daemon** — configuration/management daemon (writes `config/*.json` on behalf of the web process)
- **realtime_server** — BinkStream WebSocket server (live updates in the browser interface)
- **binkp_scheduler** — schedules periodic BinkP mail polls
- **binkp_server** — FidoNet mail server (BinkP protocol)
- **dosdoor_bridge** — DOS door game multiplexing server (Node.js)
- **telnet_daemon** — Telnet BBS server

### Optional, off by default

Uncomment the matching `ENABLE_*` line (and its port, if it has one) in `docker-compose.override.yml` and run `docker-compose up -d` to enable. Each daemon ships as a template in `docker/conf.d.available/`.

| Variable | Daemon | Port to publish in the override file | Notes |
|---|---|---|---|
| `ENABLE_GEMINI` | gemini_daemon | `1965:1965` | Gemini protocol server. Internal port comes from `GEMINI_PORT` in `.env` (default `1965`) |
| `ENABLE_SSH` | ssh_daemon | `2022:2022` | Shares terminal-side code with the Telnet daemon; see `ssh/CLAUDE.md`. Internal port comes from `SSH_PORT` in `.env` (default `2022`) |
| `ENABLE_FTP` | ftp_daemon | `2121:2121` (control) + `2122-2149:2122-2149` (passive data range) | Internal ports come from `FTPD_PORT`/`FTPD_PASSIVE_PORT_START`/`FTPD_PASSIVE_PORT_END` in `.env` |
| `ENABLE_MRC` | mrc_daemon | none needed | Outbound-only Multi Relay Chat client |
| `ENABLE_AI_BOT` | ai_bot_daemon | none needed | Reactive via Postgres NOTIFY |
| `ENABLE_MATTERBRIDGE` | matterbridge_daemon | none needed | Polls the Matterbridge API |
| `ENABLE_MCP_SERVER` | mcp_server | `3740:3740` | See `docs/MCPServer.md`. Internal port comes from `MCP_SERVER_PORT` in `.env` (default `3740`). **Requires a valid `data/license.json`** — the daemon checks for one on startup and exits if unlicensed, so enabling it without a license just fails gracefully rather than breaking the container |

The internal port each daemon binds to inside the container is always controlled by `.env` (application config, same as bare metal); the host-facing port it's reachable on is always controlled by `docker-compose.override.yml` (Docker-only). The two are independent -- changing the host port never changes what the daemon binds to internally, and vice versa.

If you need a daemon that isn't in the table above, add a `[program:...]` block for it — see [Adding a New Service to Supervisor](../docker/README.md#adding-a-new-service-to-supervisor) in `docker/README.md`.

### Scheduled maintenance jobs (cron)

On bare metal these run via crontab entries (see `docs/CLI.md`); in Docker they're driven by a `cron` process under supervisor, on by default. `docker/entrypoint.sh` regenerates `/etc/cron.d/binkterm` from these env vars (set in `docker-compose.yml`/`docker-compose.override.yml`, never in `.env`) on every container start, so changing a schedule or disabling a job is a `docker-compose up -d`, not a rebuild.

| Job | Enable variable | Schedule variable | Default schedule | Notes |
|---|---|---|---|---|
| `scripts/rss_poster.php` | `ENABLE_RSS_POSTER` | `RSS_POSTER_SCHEDULE` | `0 * * * *` (hourly) | Polls RSS feeds configured under Auto Feed; see `docs/Autofeed.md` |
| `scripts/echomail_robots.php` | `ENABLE_ECHOMAIL_ROBOTS` | `ECHOMAIL_ROBOTS_SCHEDULE` | `*/5 * * * *` (every 5 minutes) | Runs enabled echomail robots; see `docs/Robots.md` |
| `scripts/logrotate.php` | `ENABLE_LOGROTATE` | `LOGROTATE_SCHEDULE` | `0 0 * * 0` (Sundays at midnight) | Also reads `LOGROTATE_KEEP` (default `52`) for the `--keep` count |

Schedule variables take standard 5-field cron syntax. Job output is appended to its own log under `data/logs/` (e.g. `data/logs/rss_poster.log`).

## First Run Setup

### Option 1: Environment Variable (Recommended)

```bash
RUN_SETUP=true docker-compose up -d
```

### Option 2: Manual Setup

```bash
# Start containers
docker-compose up -d

# Run setup manually
docker exec -it binkterm-app php /var/www/html/scripts/setup.php
```

**Important**: Only run setup once. `RUN_SETUP` is passed inline (`RUN_SETUP=true docker-compose up -d`), not stored in a file -- just omit it on later runs.

## Managing the Application

### Starting the Services

```bash
docker-compose up -d
```

### Stopping the Services

```bash
docker-compose down
```

### Viewing Logs

```bash
# All services
docker-compose logs -f

# Just the BinktermPHP app
docker-compose logs -f binkterm

# Just the database
docker-compose logs -f postgres
```

### Restarting Services

```bash
# Restart everything
docker-compose restart

# Restart just the app
docker-compose restart binkterm
```

This is also how you pick up a `.env` change (see [Configuration](#configuration)) -- `docker-compose restart binkterm` is enough, no `up -d` needed.

### Updating the Application

**Review version-specific upgrade notes** in [docs/index.md](index.md#upgrading) before upgrading — individual versions may have specific steps you must take.

```bash
# Pull latest code
git pull

# Rebuild the image and recreate the container
docker-compose build
docker-compose up -d

# Run any new database migrations
docker exec -it binkterm-app php /var/www/html/scripts/setup.php
```

`docker-compose build` followed by `up -d` is enough to pick up code changes — there's no need to `docker-compose down` first, since `up -d` recreates any container whose image changed and leaves the rest running. Use `docker-compose build --no-cache` instead if a change touched system packages or PHP extensions in the `Dockerfile` and you want a fully clean rebuild.

`scripts/setup.php` applies any pending database migrations and is safe to run every time you upgrade, even if there's nothing new to apply. Do not set `RUN_SETUP=true` for this — that variable is only meant for the very first `up -d` (see [First Run Setup](#first-run-setup)); running `setup.php` directly like this works against the already-running container without needing to touch `.env`.

### Upgrading from 1.10 or Earlier

Versions through 1.10 used a single `.env` (copied from `.env.docker.example`) that mixed real BinktermPHP settings together with Docker-only settings (optional daemon toggles, published ports, cron schedules). As of 1.10.1, those are two separate files (see [Configuration](#configuration)), and `.env` is bind-mounted live into the container instead of being folded in once at container creation. This is a one-time migration, not something you'll need to repeat on later upgrades.

#### 1. Pull the New Code

```bash
git pull
```

#### 2. Rebuild Your `.env` as Pure Application Config

If your existing `.env` came from the old `.env.docker.example`, it has Docker-only keys mixed into it that no longer belong there. Start fresh from the same file bare-metal installs use, and re-enter your BinktermPHP settings (`SITE_URL`, credentials, feature flags, etc.):

```bash
cp .env.example .env
nano .env
```

#### 3. Create `docker-compose.override.yml`

Move anything Docker-only you had set in the old `.env` here — optional daemon toggles (`ENABLE_SSH`, `ENABLE_FTP`, etc.), non-default host ports, cron schedules:

```bash
cp docker-compose.override.yml.example docker-compose.override.yml
nano docker-compose.override.yml
```

#### 4. Rebuild the Image

This release also changed `Dockerfile`, `entrypoint.sh`, and `supervisord.conf`, all of which are baked into the image at build time — a plain `up -d` isn't enough on its own this time:

```bash
docker-compose build
docker-compose up -d
```

#### 5. Verify the Migration Took Effect

```bash
docker exec -it binkterm-app cat /var/www/html/.env
```

This should match your new `.env`, with no leftover Docker-only keys.

```bash
docker exec -it binkterm-app supervisorctl status
```

`cron` should show `RUNNING`; any daemons you enabled in `docker-compose.override.yml` should show `RUNNING` too.

After this one-time migration, editing `.env` only needs `docker-compose restart binkterm`, and editing `docker-compose.override.yml` only needs `docker-compose up -d` — see [Configuration](#configuration).

### Accessing the Container Shell

```bash
docker exec -it binkterm-app bash
```

## Volumes and Data Persistence

Docker Compose creates three persistent volumes:

- **postgres_data** - PostgreSQL database files
- **binkterm_data** - Application data (logs, packets, uploads, etc.)
- **binkterm_config** - Configuration files (bbs.json, webdoors.json, etc.)

### Backing Up Data

```bash
# Backup database
docker exec binkterm-postgres pg_dump -U binkterm binkterm > backup_$(date +%Y%m%d).sql

# Backup data volume
docker run --rm -v binkterm_data:/data -v $(pwd):/backup alpine tar czf /backup/binkterm_data_$(date +%Y%m%d).tar.gz -C /data .

# Backup config volume
docker run --rm -v binkterm_config:/config -v $(pwd):/backup alpine tar czf /backup/binkterm_config_$(date +%Y%m%d).tar.gz -C /config .
```

### Restoring Data

```bash
# Restore database
cat backup.sql | docker exec -i binkterm-postgres psql -U binkterm binkterm

# Restore data volume
docker run --rm -v binkterm_data:/data -v $(pwd):/backup alpine tar xzf /backup/binkterm_data.tar.gz -C /data

# Restore config volume
docker run --rm -v binkterm_config:/config -v $(pwd):/backup alpine tar xzf /backup/binkterm_config.tar.gz -C /config
```

## Troubleshooting

### Container Won't Start

Check the logs:
```bash
docker-compose logs binkterm
```

Common issues:
- Database not ready: Wait for PostgreSQL health check to pass
- Port already in use: Remap the port in `docker-compose.override.yml` (see [Publishing a Different Host Port](#publishing-a-different-host-port))
- Permission issues: Ensure data directories are writable

### Database Connection Errors

Verify database is running:
```bash
docker-compose ps postgres
docker-compose logs postgres
```

Test database connection:
```bash
docker exec -it binkterm-postgres psql -U binkterm -d binkterm
```

### DOS Doors Not Working

Check DOSBox-X installation:
```bash
docker exec -it binkterm-app dosbox-x --version
```

Check DOS door bridge logs:
```bash
docker exec -it binkterm-app cat /var/www/html/data/logs/dosdoor_bridge.log
```

Verify SDL is configured for headless:
```bash
docker exec -it binkterm-app printenv | grep SDL
# Should show: SDL_VIDEODRIVER=dummy
```

### Reset Everything (Nuclear Option)

**WARNING**: This deletes all data!

```bash
docker-compose down -v
rm -rf data/ config/
docker-compose up -d
```

## Production Considerations

### Security

1. **Change Default Passwords**: Always use strong passwords in `.env`

2. **Use HTTPS**: Put a reverse proxy (nginx, Caddy, Traefik) in front of BinktermPHP:

```yaml
# Example nginx reverse proxy in docker-compose.yml
  nginx:
    image: nginx:alpine
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf:ro
      - ./ssl:/etc/nginx/ssl:ro
    depends_on:
      - binkterm
```

3. **Firewall**: Only expose necessary ports
   - 80/443 for web access
   - 24554 for BinkP (if accepting FidoNet connections)

4. **Regular Updates**: Keep Docker images and BinktermPHP up to date

### Performance

1. **Resource Limits**: Add resource constraints in docker-compose.yml:

```yaml
  binkterm:
    deploy:
      resources:
        limits:
          cpus: '2'
          memory: 2G
        reservations:
          cpus: '1'
          memory: 512M
```

2. **PostgreSQL Tuning**: Mount custom PostgreSQL config:

```yaml
  postgres:
    volumes:
      - ./postgresql.conf:/etc/postgresql/postgresql.conf:ro
    command: postgres -c config_file=/etc/postgresql/postgresql.conf
```

### Monitoring

1. **Health Checks**: Already configured in docker-compose.yml

2. **Logs**: Use log aggregation (e.g., Loki, ELK stack)

3. **Metrics**: Consider adding Prometheus exporters

### Scaling

For high-traffic deployments:
- Use external PostgreSQL instance (remove postgres service from compose)
- Consider load balancing multiple binkterm containers
- Use shared storage (NFS, S3) for data volumes
- Separate DOS door bridge to dedicated server

## Additional Resources

- [BinktermPHP Documentation](../README.md)
- [DOS Doors Documentation](DOSDoors.md)
- [Docker Documentation](https://docs.docker.com/)
- [Docker Compose Reference](https://docs.docker.com/compose/compose-file/)
