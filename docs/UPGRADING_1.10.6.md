# Upgrading to 1.10.6

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

<!--
Group bullet points by major feature area as changes land during this release
cycle. Each bullet should be self-contained: state what changed, why it matters,
and what (if anything) the upgrader must do.
-->

This release is in development. Feature-area details will be added here as
changes are made.

---

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
