# Upgrading to 1.8.6

Make sure you've made a backup of your database and files before upgrading.

## Summary of Changes

**Improvements**
- PWA manifest: added app shortcuts for Doors (`/games`) and Files (`/files`)

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

```bash
wget https://raw.githubusercontent.com/awehttam/binkterm-php-installer/main/binkterm-installer.phar
php binkterm-installer.phar
scripts/restart_daemons.sh
```
