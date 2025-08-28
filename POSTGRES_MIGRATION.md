# PostgreSQL Migration Guide

This guide helps you migrate from SQLite to PostgreSQL for binkterm-php.

## Prerequisites

1. PostgreSQL server installed and running
2. PHP with PostgreSQL extension (php-pgsql)
3. Existing SQLite database with data to migrate

## Step 1: Create PostgreSQL Database and User

Connect to PostgreSQL as superuser (usually `postgres` user):

```bash
# Connect as postgres superuser
sudo -u postgres psql

# Or on Windows:
psql -U postgres
```

Then run these PostgreSQL commands:

```sql
-- Create user
CREATE ROLE binktest WITH LOGIN PASSWORD 'binktest';

-- Create database owned by the user
CREATE DATABASE binktest OWNER binktest;

-- Grant necessary privileges
GRANT CONNECT ON DATABASE binktest TO binktest;
GRANT USAGE ON SCHEMA public TO binktest;
GRANT CREATE ON SCHEMA public TO binktest;

-- Connect to the database to set additional privileges
\c binktest

-- Grant privileges on future tables and sequences
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO binktest;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO binktest;

-- Exit psql
\q
```

## Step 2: Update Configuration

Create a `.env` file from the example and customize your database settings:

```bash
cp .env.example .env
```

Edit `.env` with your PostgreSQL connection details:

```ini
DB_HOST=localhost
DB_PORT=5432
DB_NAME=binktest
DB_USER=binktest
DB_PASS=binktest
DB_SSL=false
```

For production environments, consider enabling SSL:
```ini
DB_SSL=true
DB_SSL_CA=/path/to/ca-cert.pem
```

## Step 3: Install Dependencies

Run composer to update dependencies:

```bash
composer install
```

## Step 4: Initialize Database Schema

Run the setup script to create all necessary tables and initial data:

```bash
php scripts/setup.php
```

This will:
- Create all database tables using the PostgreSQL schema
- Insert default echo areas
- Create the initial admin user (interactive mode will prompt for credentials)

For non-interactive setup (uses default admin/admin123):
```bash
php scripts/install.php --non-interactive
```

## Step 5: Migrate Data from SQLite

Run the migration script to transfer all data:

```bash
php scripts/sqlite_to_postgres_migration.php
```

The script will:
- Connect to both SQLite and PostgreSQL databases
- Transfer all data preserving relationships  
- Convert SQLite data types to PostgreSQL equivalents
- Update auto-increment sequences
- Validate the migration

### Migration Options

- `--validate-only`: Only check if migration was successful
- `--help`: Show usage information

## Step 6: Test the Application

1. Start your web server
2. Access the application
3. Verify login works with existing users
4. Check that messages, echoareas, and other data display correctly

## Troubleshooting

### Connection Issues
- Verify PostgreSQL is running: `systemctl status postgresql`
- Check connection settings in `src/Config.php`
- Ensure user has proper permissions

### Migration Issues
- Check that SQLite database exists at `data/binktest.db`
- Verify PostgreSQL user has CREATE/INSERT permissions
- Run with `--validate-only` to check row counts

### Performance Issues
- Add indexes if needed (already included in schema)
- Consider connection pooling for high-traffic sites

## Rollback

If you need to rollback to SQLite temporarily:

1. Keep your original SQLite database file
2. Temporarily restore the old Database.php and Config.php from git
3. The SQLite database remains unchanged during migration

## Notes

- The migration script truncates PostgreSQL tables before importing
- Boolean values are properly converted (SQLite 0/1 → PostgreSQL true/false)  
- Sequences are updated to continue from the highest migrated ID
- Foreign key relationships are preserved