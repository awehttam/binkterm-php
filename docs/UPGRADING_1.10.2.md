# Upgrading to 1.10.2

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [AI Bots](#ai-bots)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

<!-- Content is added incrementally as changes are made during this release cycle. -->

- **AI Bots**: Fixed a bug that prevented the AI bot daemon from starting on PHP 8.1 and later.

---

## AI Bots

### AI bot daemon fails to start with "PostgreSQL event listener: pg_connect failed"

On PHP 8.1 and later, the AI bot daemon's PostgreSQL LISTEN/NOTIFY connection could fail to establish even though the underlying database connection itself was working correctly. The daemon would log `PostgreSQL event listener: pg_connect failed` and exit immediately on startup.

The cause was a compatibility issue with how the daemon checked whether its native PostgreSQL connection succeeded. PHP 8.1 changed the `pgsql` extension to return connection objects instead of the older resource type, but the check used by the daemon still only recognized the older resource type, so a successful connection was incorrectly treated as a failure. This has been corrected so the daemon recognizes both connection types.

If you have previously seen the AI bot daemon fail to start with this error, restart it after upgrading and it should connect normally.

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
