# Upgrading to 1.8.4

Make sure you've made a backup of your database and files before upgrading.

## Summary of Changes

**Security Fixes**
- Username and real name are now cross-checked for uniqueness at the
  database level to prevent misrouting of netmail

**Bug Fixes**
- MRC: initial room list not populated on daemon connect

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
