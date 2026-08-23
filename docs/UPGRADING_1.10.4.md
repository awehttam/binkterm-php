# Upgrading to 1.10.4

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [RLogin Doors](#rlogin-doors)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Doors

- Added **RLogin Doors**, a new door type that connects out to a remote BBS or service (such as a linked Synchronet system) over the rlogin protocol instead of running a local process.

---

## RLogin Doors

BinktermPHP can now link out to a remote BBS or service over the rlogin protocol (RFC 1282) as a new door type, alongside DOS Doors and Native Doors. This lets users reach a separate system — such as a Synchronet BBS — without leaving their BinktermPHP terminal session.

Unlike the other door types, RLogin doors have no filesystem footprint — there's no executable or manifest directory, just connection settings. Because of that, RLogin doors are stored directly in a new `rlogin_doors` database table rather than as manifest/config files, and are managed through a dedicated **Admin → RLogin Doors** page with a standard add/edit/delete form — including uploading a custom icon and screenshot for each door, stored directly in the database.

Each RLogin door is configured with a target host/port, an rlogin username/terminal-type handshake, and an optional **pre-login command** that runs server-side before every connection to provision or sync the remote account just-in-time. A bundled reference client script (`scripts/rlogin_synchronet_service_client.php`) is included for sysops linking to Synchronet via a companion `services.ini` service; see [RLoginDoors.md](RLoginDoors.md) for the full field reference, BBS Type presets, and the pre-login command's wire protocol.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
