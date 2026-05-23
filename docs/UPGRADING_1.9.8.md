# Upgrading to 1.9.8

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Core Platform](#core-platform)
- [Web Interface](#web-interface)
- [Developer Notes](#developer-notes)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Core Platform

- Version metadata now reports BinktermPHP `1.9.8` across the application and release tooling.

### Web Interface

- The FTP daemon now accepts QWK reply packets uploaded directly to the FTP root (`/`) as well as `/qwk/upload/`, improving compatibility with clients that do not change into the upload subdirectory before sending `.REP` or `.ZIP` files.

### Developer Notes

- This document starts the `1.9.8` release cycle and should be expanded incrementally as upgrade-relevant changes land.

---

## Core Platform

BinktermPHP now identifies itself as version `1.9.8` in runtime version helpers, package metadata, and operator-facing release references.

## Web Interface

The FTP daemon now accepts `.REP` and `.ZIP` uploads dropped directly into the FTP root (`/`) in addition to the existing `/qwk/upload/` path. Previously, uploads to the root were rejected, blocking QWK client software — such as Synchronet's `qnet-ftp.js` — that stores the reply packet in the current working directory without issuing a `CWD` command first.

Clients that already target `/qwk/upload/` are unaffected. Clients that upload to root now have their packet routed through the same REP import pipeline as a `/qwk/upload/` transfer, including the same conference-map validation and deduplication checks.

## Developer Notes

`docs/UPGRADING_1.9.8.md` is now the active upgrade-notes document for the current release cycle. Future changes that affect deployment, configuration, dependencies, migrations, or operator workflows should be documented here as they are introduced.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
