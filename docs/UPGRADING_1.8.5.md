# Upgrading to 1.8.5

Make sure you've made a backup of your database and files before upgrading.

## Summary of Changes

**Bug Fixes**
- Markdown renderer: fixed inline code parsing so identifiers with
  underscores such as `send_domain_in_addr` and `M_ADR` render correctly
  in upgrade notes and other locally rendered Markdown documents

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
