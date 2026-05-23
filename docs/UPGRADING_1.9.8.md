# Upgrading to 1.9.8

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Web Interface](#web-interface)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Web Interface

- The FTP daemon now accepts QWK reply packets uploaded directly to the FTP root (`/`) as well as `/qwk/upload/`, improving compatibility with clients that do not change into the upload subdirectory before sending `.REP` or `.ZIP` files.
- Echo area deletion now offers a message-handling choice for populated areas. Sysops can delete the remaining messages or move them into another local echo area before removing the area itself.

---

## Core Platform

BinktermPHP now identifies itself as version `1.9.8` in runtime version helpers, package metadata, and operator-facing release references.

## External Delivery

External delivery now follows a stricter split between local-only areas and networked areas:

- `is_local = true` means the area is never propagated through any external transport layer.
- Local-only areas do not spool to FTN uplinks.
- Local-only areas do not fan out to inter-BBS QWK mailboxes.
- The only QWK behavior still allowed for a local-only area is the logged-in user's own offline-reader workflow on this BBS: the area can appear in that user's personal QWK packet, and replies uploaded by that same user can be imported back into the same local area.

For non-local areas, external delivery is transport-specific:

- FTN spooling is used only when the area's domain is backed by an FTN network type.
- QWK fanout is used when the area has QWK conference subscriptions.
- A single non-local area may participate in both transports if it is configured that way.

## Web Interface

The FTP daemon now accepts `.REP` and `.ZIP` uploads dropped directly into the FTP root (`/`) in addition to the existing `/qwk/upload/` path. Previously, uploads to the root were rejected, blocking QWK client software — such as Synchronet's `qnet-ftp.js` — that stores the reply packet in the current working directory without issuing a `CWD` command first.

Clients that already target `/qwk/upload/` are unaffected. Clients that upload to root now have their packet routed through the same REP import pipeline as a `/qwk/upload/` transfer, including the same conference-map validation and deduplication checks.

The Echo Areas admin page now allows populated areas to be deleted without manual SQL cleanup. When deleting an area that still contains echomail, the dialog offers two explicit choices:

- delete the messages together with the area
- move the messages into another local echo area before deleting the original area

The move option is a local reassignment only. It does not re-gate, re-spool, or republish the historical messages into the destination area’s outbound network paths.


## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
