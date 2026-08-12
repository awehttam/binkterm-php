#!/bin/bash
set -e

echo "BinktermPHP Docker Container Initialization"
echo "==========================================="

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

# .env is bind-mounted from the host at /var/www/html/.env.source (see
# docker-compose.yml) -- it's the same application config file bare-metal
# installs use (from .env.example), live-synced by Docker so a host-side
# edit followed by `docker-compose restart binkterm` is enough to pick it
# up, with no recreate or rebuild needed.
#
# Docker doesn't create a bind-mount target that doesn't already exist as a
# file -- it silently creates an empty directory instead, which breaks
# everything below in a confusing way. Fail loudly instead.
SRC=/var/www/html/.env.source
if [ ! -f "$SRC" ]; then
    echo "ERROR: $SRC not found (or not a regular file)."
    echo "Copy .env.example to .env in the project directory before starting the container."
    exit 1
fi

# ADMIN_DAEMON_SECRET is a real application setting (see .env.example). If
# it's unset or still the .env.example placeholder in the mounted .env,
# reuse a previously-generated value from an existing /var/www/html/.env
# (so restarting the container doesn't rotate it) before generating a new
# random one.
SRC_SECRET=$(grep '^ADMIN_DAEMON_SECRET=' "$SRC" | tail -1 | cut -d= -f2-)
if [ -z "$SRC_SECRET" ] || [ "$SRC_SECRET" = "change_me" ]; then
    EXISTING_SECRET=""
    if [ -f /var/www/html/.env ]; then
        EXISTING_SECRET=$(grep '^ADMIN_DAEMON_SECRET=' /var/www/html/.env | tail -1 | cut -d= -f2-)
    fi

    if [ -n "$EXISTING_SECRET" ] && [ "$EXISTING_SECRET" != "change_me" ]; then
        RESOLVED_SECRET="$EXISTING_SECRET"
        echo "Reusing existing ADMIN_DAEMON_SECRET"
    else
        RESOLVED_SECRET=$(openssl rand -hex 32)
        echo "Generated random ADMIN_DAEMON_SECRET"
    fi
else
    RESOLVED_SECRET="$SRC_SECRET"
fi

# Write /var/www/html/.env from the mounted source, with DB_HOST/DB_PORT
# (Compose always points the app at the "postgres" service, regardless of
# what's in .env) and the resolved ADMIN_DAEMON_SECRET layered on top.
echo "Writing application .env..."

{
    grep -Ev '^(DB_HOST|DB_PORT|ADMIN_DAEMON_SECRET)=' "$SRC"
    echo "DB_HOST=${DB_HOST:-postgres}"
    echo "DB_PORT=${DB_PORT:-5432}"
    echo "ADMIN_DAEMON_SECRET=$RESOLVED_SECRET"
} > /var/www/html/.env

chown binkterm:binkterm /var/www/html/.env
chmod 640 /var/www/html/.env
echo ".env written"

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

# Activate optional daemons requested via ENABLE_* environment variables (set
# in docker-compose.yml/docker-compose.override.yml -- Docker-only, never in
# .env). Each daemon ships as a disabled-by-default template in
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

# Generate the scheduled-job crontab from the ENABLE_*/*_SCHEDULE env vars
# (Docker-only, set in docker-compose.yml/docker-compose.override.yml).
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
