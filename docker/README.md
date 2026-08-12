# Docker Configuration Files

This directory contains Docker-specific configuration files for BinktermPHP.

**Note:** Docker is a best-effort deployment option, not the primary target — the bare-metal install (`docs/INSTALL.md`) receives the most testing. Docker support is improving as issues are reported; if you run into problems, please report them in the **LVLY_BINKTERMPHP** echo area or on [GitHub](https://github.com/awehttam/binkterm-php/issues).

> **[docs/DOCKER.md](../docs/DOCKER.md) is the authoritative Docker documentation** — deployment, configuration, upgrading, volumes/backups, and troubleshooting. This file only describes the configuration files in this directory and day-to-day debugging commands.

## Files

### supervisord.conf
Supervisor configuration that manages the always-on BinktermPHP services:
- **apache**: Web server for PHP application
- **admin_daemon**: BBS configuration and management daemon
- **realtime_server**: BinkStream WebSocket/SSE server
- **binkp_scheduler**: Schedules periodic BinkP mail polls
- **binkp_server**: FidoNet mail server (BinkP protocol)
- **dosdoor_bridge**: DOS door game multiplexing server (Node.js)
- **telnet_daemon**: Telnet BBS server
- **cron**: Runs the scheduled maintenance jobs (`rss_poster`, `echomail_robots`,
  `logrotate`) defined in `/etc/cron.d/binkterm`, which `entrypoint.sh`
  regenerates from `ENABLE_*`/`*_SCHEDULE` env vars on every container start

All services run as the `binkterm` user except Apache and cron (must run as
root; cron drops to `binkterm` per-job via the user field in its crontab). It
also `[include]`s `/etc/supervisor/conf.d/enabled/*.conf`, the directory
`entrypoint.sh` populates with optional daemons at container startup (see
`conf.d.available/` below).

### conf.d.available/
One supervisor template per optional daemon, each disabled unless its `ENABLE_*`
environment variable is `true`: `gemini_daemon.conf`, `ssh_daemon.conf`,
`ftp_daemon.conf`, `mrc_daemon.conf`, `ai_bot_daemon.conf`,
`matterbridge_daemon.conf`, `mcp_server.conf`. See
[docs/DOCKER.md#included-services](../docs/DOCKER.md#included-services) for the
full list of `ENABLE_*` variables. These files are shipped in the image at
`/opt/binkterm-conf.d-available/` and are not loaded by supervisord unless copied
into `/etc/supervisor/conf.d/enabled/`.

### entrypoint.sh
Container initialization script that:
- Waits for PostgreSQL to be ready
- Regenerates `/var/www/html/.env` (the application's own config, distinct
  from Docker-only settings -- see [docs/DOCKER.md#configuration](../docs/DOCKER.md#configuration))
  from the bind-mounted `.env.source` on every start, so `docker-compose
  restart binkterm` alone is enough to pick up a `.env` edit
- Runs database setup/migrations (if `RUN_SETUP=true`)
- Sets correct file permissions
- Activates optional daemons requested via `ENABLE_*` environment variables
- Starts supervisor

## Usage

These files are automatically used by the Dockerfile and docker-compose.yml.

For detailed Docker deployment instructions, see [docs/DOCKER.md](../docs/DOCKER.md).

## Quick Start

```bash
# From project root directory
cp .env.example .env
# Edit .env -- the same application config bare-metal installs use
nano .env

cp docker-compose.override.yml.example docker-compose.override.yml
# Edit docker-compose.override.yml -- Docker-only settings: optional
# daemons, published ports, cron schedules
nano docker-compose.override.yml

# First run (initialize database)
RUN_SETUP=true docker-compose up -d

# Subsequent runs
docker-compose up -d
```

## Customization

### Enabling an Optional Daemon

Uncomment its `ENABLE_*` variable (and port, if it has one) in
`docker-compose.override.yml` (see
[docs/DOCKER.md#included-services](../docs/DOCKER.md#included-services) for the
full list) and restart the container -- no rebuild needed, since `entrypoint.sh`
reads the flag on every container start:

```bash
docker-compose up -d
```

### Sysop-Local Compose Changes

Use `docker-compose.override.yml` (copy it from
`docker-compose.override.yml.example` in the project root) for host-specific
volumes, extra ports, or resource limits. It's gitignored and merges
automatically with `docker-compose.yml`, so it survives `git pull` / image
upgrades without touching tracked files.

### Adding a New Service to Supervisor

For a daemon not already covered by an `ENABLE_*` toggle, add a new
`[program:name]` block, either directly in `supervisord.conf` for something
that should always run, or as a new file in `conf.d.available/` (with a matching
entry in `entrypoint.sh`'s `OPTIONAL_DAEMONS` map) if it should be opt-in:

```ini
[program:my_service]
command=/path/to/command
autostart=true
autorestart=true
stdout_logfile=/var/www/html/data/logs/my_service.log
stderr_logfile=/var/www/html/data/logs/my_service_error.log
user=binkterm
directory=/var/www/html
```

Then rebuild the container:
```bash
docker-compose down
docker-compose build --no-cache
docker-compose up -d
```

### Modifying Entrypoint Behavior

Edit `entrypoint.sh` to add custom initialization logic. The script runs before supervisor starts, making it ideal for:
- Additional health checks
- Custom configuration file generation
- One-time setup tasks
- Environment validation

## Debugging

### View Supervisor Status

```bash
docker exec -it binkterm-app supervisorctl status
```

### Restart Individual Service

```bash
docker exec -it binkterm-app supervisorctl restart dosdoor_bridge
```

### View Service Logs

```bash
# Supervisor logs
docker exec -it binkterm-app cat /var/www/html/data/logs/supervisord.log

# Individual service logs
docker exec -it binkterm-app cat /var/www/html/data/logs/dosdoor_bridge.log
docker exec -it binkterm-app cat /var/www/html/data/logs/binkp_server.log
```

### Test Entrypoint Without Starting Services

`entrypoint.sh` requires `/var/www/html/.env.source` to exist (it's normally
the `.env` bind mount from `docker-compose.yml`) and fails fast if it
doesn't, so mount a real `.env` in manually:

```bash
docker run --rm -it \
  -e DB_HOST=postgres \
  -e DB_PASS=test \
  -v "$(pwd)/.env:/var/www/html/.env.source:ro" \
  --entrypoint /usr/local/bin/entrypoint.sh \
  binkterm-app \
  /bin/bash
```
