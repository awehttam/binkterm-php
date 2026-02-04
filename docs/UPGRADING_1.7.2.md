# Upgrading to 1.7.2

This release adds sysop-only echo areas and an admin template editor (served through the admin daemon).

## Before You Upgrade
- Make a backup of your database and `templates/custom/` directory.
- Ensure your admin daemon is running and reachable (the template editor relies on it).

## Database Migration
- Run the upgrade script to apply migrations:
  - `php scripts/upgrade.php`
- This version includes migration `database/migrations/v1.8.7_echoarea_sysop_only.sql`
  - Adds `echoareas.is_sysop_only` to restrict echo areas to sysop/admin users.

## After You Upgrade
- Restart the admin daemon to pick up the new template editor commands.

## What's New
- **Sysop-only echo areas**: Echo areas can be flagged as sysop/admin only.
- **Admin template editor**: Edit `templates/custom/*.twig` from the web UI, with safe path handling and `.twig.example` install support (examples are read-only).

