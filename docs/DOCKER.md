# Docker Deployment Guide for BinktermPHP

This guide covers deploying BinktermPHP using Docker and Docker Compose.

## Table of Contents

- [Prerequisites](#prerequisites)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [First Run Setup](#first-run-setup)
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

### 2. Configure Environment Variables

```bash
# Copy the example environment file
cp .env.docker.example .env

# Edit the .env file with your settings
nano .env
```

**Important**: Change at least these values:
- `DB_PASSWORD` - Use a strong password
- `SITE_URL` - Your public URL (e.g., https://bbs.example.com)
- `SITE_NAME` - Your BBS name
- `SYSOP_NAME` - Your name
- `FIDONET_ADDRESS` - Your FidoNet address (if applicable)

### 3. First Run (Initialize Database)

```bash
# Set RUN_SETUP=true for first run only
RUN_SETUP=true docker-compose up -d

# Watch the logs to ensure setup completes
docker-compose logs -f binkterm
```

Wait for the message "Initialization complete!" in the logs.

### 4. Access Your BBS

Open your browser to http://localhost (or the configured SITE_URL).

The default admin account must be created through the registration page on first use.

## Configuration

### Environment Variables

Edit the `.env` file to configure your deployment:

#### Database Configuration
```bash
DB_NAME=binkterm          # Database name
DB_USER=binkterm          # Database username
DB_PASSWORD=changeme      # CHANGE THIS!
```

#### Site Configuration
```bash
SITE_URL=http://localhost           # Public URL of your BBS
SITE_NAME=BinktermPHP BBS          # Name displayed on your BBS
SYSOP_NAME=Sysop                   # Your name/handle
FIDONET_ADDRESS=1:2/3.4            # Your FidoNet address
```

#### Port Mappings
```bash
HTTP_PORT=80              # Web interface (default: 80)
BINKP_PORT=24554          # BinkP server (default: 24554)
DOSDOOR_WS_PORT=24555     # DOS Door WebSocket (default: 24555)
```

If you need to use different ports (e.g., 8080 instead of 80):
```bash
HTTP_PORT=8080:80         # Map host port 8080 to container port 80
```

#### DOS Door Configuration
```bash
DOSDOOR_DEBUG_KEEP_FILES=false    # Set to true to keep session files for debugging
```

#### Development/Debug
```bash
APP_DEBUG=false           # Set to true for verbose error messages
```

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

**Important**: Only run setup once. After the initial setup, leave `RUN_SETUP=false` in your `.env` file.

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

### Updating the Application

```bash
# Pull latest code
git pull

# Rebuild and restart
docker-compose down
docker-compose build --no-cache
docker-compose up -d

# Run any new migrations
docker exec -it binkterm-app php /var/www/html/scripts/setup.php
```

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
- Port already in use: Change `HTTP_PORT` in `.env`
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
