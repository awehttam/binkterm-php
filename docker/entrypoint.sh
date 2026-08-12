#!/bin/bash
set -e

echo "BinktermPHP Docker Container Initialization"
echo "==========================================="

# Generate ADMIN_DAEMON_SECRET if not set
if [ -z "$ADMIN_DAEMON_SECRET" ]; then
    export ADMIN_DAEMON_SECRET=$(openssl rand -hex 32)
    echo "Generated random ADMIN_DAEMON_SECRET"
fi

# Wait for PostgreSQL to be ready
if [ -n "$DB_HOST" ]; then
    echo "Waiting for PostgreSQL at $DB_HOST:${DB_PORT:-5432}..."

    for i in {1..30}; do
        if pg_isready -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "${DB_USER:-postgres}" > /dev/null 2>&1; then
            echo "PostgreSQL is ready!"
            break
        fi

        if [ $i -eq 30 ]; then
            echo "ERROR: PostgreSQL did not become ready in time"
            exit 1
        fi

        echo "Waiting for PostgreSQL... attempt $i/30"
        sleep 2
    done
fi

# Create .env file if it doesn't exist
if [ ! -f /var/www/html/.env ]; then
    echo "Creating .env file from environment variables..."

    cat > /var/www/html/.env <<EOF
# Database Configuration
DB_HOST=${DB_HOST:-localhost}
DB_PORT=${DB_PORT:-5432}
DB_NAME=${DB_NAME:-binkterm}
DB_USER=${DB_USER:-binkterm}
DB_PASS=${DB_PASS:-changeme}

# Site Configuration
SITE_URL=${SITE_URL:-http://localhost}
SITE_NAME=${SITE_NAME:-BinktermPHP BBS}

# BBS Configuration
SYSOP_NAME=${SYSOP_NAME:-Sysop}
FIDONET_ADDRESS=${FIDONET_ADDRESS:-}

# Session Configuration
SESSION_NAME=${SESSION_NAME:-BINKTERMPHP}
SESSION_LIFETIME=${SESSION_LIFETIME:-86400}

# DOS Door Configuration
DOSDOOR_HEADLESS=${DOSDOOR_HEADLESS:-true}
DOSDOOR_DEBUG_KEEP_FILES=${DOSDOOR_DEBUG_KEEP_FILES:-false}
DOSDOOR_DOSBOX_PATH=${DOSDOOR_DOSBOX_PATH:-/usr/bin/dosbox-x}
DOSDOOR_WS_PORT=${DOSDOOR_WS_PORT:-24555}

# Realtime WebSocket (BinkStream) Configuration
BINKSTREAM_WS_PORT=${BINKSTREAM_WS_PORT:-6010}
BINKSTREAM_WS_BIND_HOST=${BINKSTREAM_WS_BIND_HOST:-0.0.0.0}

# Credits System
CREDITS_ENABLED=${CREDITS_ENABLED:-true}

# Development/Debug
APP_DEBUG=${APP_DEBUG:-false}

# Admin Daemon
ADMIN_DAEMON_SECRET=${ADMIN_DAEMON_SECRET}
EOF

    chown binkterm:binkterm /var/www/html/.env
    chmod 640 /var/www/html/.env
    echo ".env file created successfully"
else
    echo ".env file already exists, skipping creation"
fi

# Run database setup/migrations if requested
if [ "$RUN_SETUP" = "true" ]; then
    echo "Running database setup and migrations..."
    su -s /bin/bash binkterm -c "php /var/www/html/scripts/setup.php"
    echo "Setup completed"
else
    echo "Skipping database setup (set RUN_SETUP=true to enable)"
fi

# Sync i18n translation catalogs from the image into the persistent config volume.
# config/ is a named Docker volume so sysop settings (binkp.json, bbs.json, etc.)
# survive image rebuilds, but that also shadows config/i18n/ -- the translation
# catalogs, which are application code that changes every release. Docker only
# seeds a named volume from the image on its first creation, so without this sync
# the volume would stay frozen at whatever catalogs existed when it was first
# created and never pick up new/changed translation keys on later upgrades.
# config/i18n/overrides/ holds sysop-customized phrases and is excluded so it is
# never touched.
if [ -d /opt/binkterm-i18n-src ]; then
    echo "Syncing i18n translation catalogs from image..."
    mkdir -p /var/www/html/config/i18n
    rsync -a --delete --exclude=overrides/ /opt/binkterm-i18n-src/ /var/www/html/config/i18n/
fi

# Verify critical directories exist with correct permissions.
# Volumes may be mounted empty on first run, so re-create and re-own as needed.
# binkterm owns the files; www-data (in binkterm group) gets write access via 775.
echo "Verifying directory permissions..."
mkdir -p \
    /var/www/html/data/logs \
    /var/www/html/data/run \
    /var/www/html/data/inbound \
    /var/www/html/data/outbound \
    /var/www/html/dosbox-bridge/dos/DROPS \
    /var/www/html/dosbox-bridge/dos/DOORS

chown -R binkterm:binkterm /var/www/html/data /var/www/html/config /var/www/html/dosbox-bridge
chmod -R 775 /var/www/html/data /var/www/html/config /var/www/html/dosbox-bridge

# Activate optional daemons requested via ENABLE_* environment variables.
# Each daemon ships as a disabled-by-default template in
# /opt/binkterm-conf.d-available/ (see docker/conf.d.available/); this loop
# copies the requested ones into the live supervisor include directory so
# supervisord picks them up on startup. Nothing here overrides the always-on
# core services (apache, admin_daemon, binkp_scheduler, binkp_server,
# telnet_daemon, dosdoor_bridge, realtime_server), which are defined directly
# in supervisord.conf.
echo "Configuring optional daemons..."
mkdir -p /etc/supervisor/conf.d/enabled
rm -f /etc/supervisor/conf.d/enabled/*.conf

declare -A OPTIONAL_DAEMONS=(
    [ENABLE_GEMINI]=gemini_daemon
    [ENABLE_SSH]=ssh_daemon
    [ENABLE_FTP]=ftp_daemon
    [ENABLE_MRC]=mrc_daemon
    [ENABLE_AI_BOT]=ai_bot_daemon
    [ENABLE_MATTERBRIDGE]=matterbridge_daemon
    [ENABLE_MCP_SERVER]=mcp_server
)

for var in "${!OPTIONAL_DAEMONS[@]}"; do
    daemon="${OPTIONAL_DAEMONS[$var]}"
    if [ "${!var}" = "true" ]; then
        cp "/opt/binkterm-conf.d-available/${daemon}.conf" /etc/supervisor/conf.d/enabled/
        echo "  enabled: $daemon (via $var)"
    fi
done

# Generate the scheduled-job crontab from the ENABLE_*/*_SCHEDULE env vars.
# Regenerated on every container start so schedule changes just need a
# `docker-compose up -d`, not a rebuild. Jobs run as the binkterm user;
# cron itself (supervisor's [program:cron]) runs as root to read /etc/cron.d.
echo "Configuring scheduled jobs..."

RSS_POSTER_SCHEDULE="${RSS_POSTER_SCHEDULE:-0 * * * *}"
ECHOMAIL_ROBOTS_SCHEDULE="${ECHOMAIL_ROBOTS_SCHEDULE:-*/5 * * * *}"
LOGROTATE_SCHEDULE="${LOGROTATE_SCHEDULE:-0 0 * * 0}"
LOGROTATE_KEEP="${LOGROTATE_KEEP:-52}"

{
    echo "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

    if [ "${ENABLE_RSS_POSTER:-true}" = "true" ]; then
        echo "$RSS_POSTER_SCHEDULE binkterm cd /var/www/html && php scripts/rss_poster.php >> /var/www/html/data/logs/rss_poster.log 2>&1"
    fi

    if [ "${ENABLE_ECHOMAIL_ROBOTS:-true}" = "true" ]; then
        echo "$ECHOMAIL_ROBOTS_SCHEDULE binkterm cd /var/www/html && php scripts/echomail_robots.php --quiet >> /var/www/html/data/logs/echomail_robots.log 2>&1"
    fi

    if [ "${ENABLE_LOGROTATE:-true}" = "true" ]; then
        echo "$LOGROTATE_SCHEDULE binkterm cd /var/www/html && php scripts/logrotate.php --keep=$LOGROTATE_KEEP >> /var/www/html/data/logs/logrotate.log 2>&1"
    fi

    echo ""
} > /etc/cron.d/binkterm

chmod 644 /etc/cron.d/binkterm

echo "Initialization complete!"
echo ""

# Execute the main command
exec "$@"
