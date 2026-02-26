# Docker Configuration Files

This directory contains Docker-specific configuration files for BinktermPHP.

WARNING: Docker is UNTESTED and UNSUPPORTED - it is present because Claude generated the files once upon a time and maybe someone will want to fiddle with this.


## Files

### supervisord.conf
Supervisor configuration that manages all BinktermPHP services:
- **apache**: Web server for PHP application
- **admin_daemon**: BBS configuration and management daemon
- **binkp_scheduler**: Schedules periodic BinkP mail polls
- **binkp_server**: FidoNet mail server (BinkP protocol)
- **dosdoor_bridge**: DOS door game multiplexing server (Node.js)

All services run as `www-data` user except Apache (must run as root).

### entrypoint.sh
Container initialization script that:
- Waits for PostgreSQL to be ready
- Creates `.env` file from environment variables
- Runs database setup/migrations (if `RUN_SETUP=true`)
- Sets correct file permissions
- Starts supervisor

## Usage

These files are automatically used by the Dockerfile and docker-compose.yml.

For detailed Docker deployment instructions, see [docs/DOCKER.md](../docs/DOCKER.md).

## Quick Start

```bash
# From project root directory
cp .env.docker.example .env
# Edit .env with your configuration
nano .env

# First run (initialize database)
RUN_SETUP=true docker-compose up -d

# Subsequent runs
docker-compose up -d
```

## Customization

### Adding Services to Supervisor

Edit `supervisord.conf` and add a new `[program:name]` section:

```ini
[program:my_service]
command=/path/to/command
autostart=true
autorestart=true
stdout_logfile=/var/www/html/data/logs/my_service.log
stderr_logfile=/var/www/html/data/logs/my_service_error.log
user=www-data
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

```bash
docker run --rm -it \
  -e DB_HOST=postgres \
  -e DB_PASSWORD=test \
  --entrypoint /usr/local/bin/entrypoint.sh \
  binkterm-app \
  /bin/bash
```
