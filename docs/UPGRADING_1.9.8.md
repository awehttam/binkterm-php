# Upgrading to 1.9.8

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Release Planning](#release-planning)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Release Planning

- This document starts the 1.9.8 release note set. Add sysop-facing upgrade notes here as changes land during the 1.9.8 cycle so deployers have one place to review required actions before upgrading.

---

## Release Planning

This file is the release-note shell for BinktermPHP 1.9.8. As features, fixes, migrations, configuration changes, and dependency updates are added during the 1.9.8 cycle, record them here grouped by feature area.

Document each change in deployer-facing terms:

- what changed
- why it matters
- what the upgrader needs to do

If a future change introduces a Composer dependency update, add `composer update` to the relevant upgrade guidance before `php scripts/setup.php`.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically.
