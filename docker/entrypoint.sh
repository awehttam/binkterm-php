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

# Credits System
CREDITS_ENABLED=${CREDITS_ENABLED:-true}

# Development/Debug
APP_DEBUG=${APP_DEBUG:-false}

# Admin Daemon
ADMIN_DAEMON_SECRET=${ADMIN_DAEMON_SECRET}
EOF

    chown www-data:www-data /var/www/html/.env
    chmod 640 /var/www/html/.env
    echo ".env file created successfully"
else
    echo ".env file already exists, skipping creation"
fi

# Run database setup/migrations if requested
if [ "$RUN_SETUP" = "true" ]; then
    echo "Running database setup and migrations..."
    php /var/www/html/scripts/setup.php
    echo "Setup completed"
else
    echo "Skipping database setup (set RUN_SETUP=true to enable)"
fi

# Verify critical directories exist with correct permissions
echo "Verifying directory permissions..."
mkdir -p \
    /var/www/html/data/logs \
    /var/www/html/data/run \
    /var/www/html/data/inbound \
    /var/www/html/data/outbound \
    /var/www/html/scripts/dosbox-bridge/dos/drops

chown -R www-data:www-data /var/www/html/data /var/www/html/config
chmod -R 775 /var/www/html/data /var/www/html/config

echo "Initialization complete!"
echo ""

# Execute the main command
exec "$@"
