# Upgrading to 1.9.8

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [InterBBS QWK](#interbbs-qwk)
- [Gating](#gating)
- [External Delivery](#external-delivery)
- [QWK FTP Service](#qwk-ftp-service)
- [Web Interface](#web-interface)
  - [Echo Area Deletion](#echo-area-deletion)
  - [Subscription Manager](#subscription-manager)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### InterBBS QWK

- BinktermPHP now supports inter-BBS QWK networking. The system can poll remote QWK mailboxes, import `.QWK` packets into mapped local echo areas, queue outbound replies into `.REP` packets, and upload those reply packets back to the remote BBS.
- Echo Areas now include per-area QWK conference mappings, and the scheduler can poll QWK mailboxes automatically when a mailbox `poll_schedule` is configured.

### Gating

- Echo Areas now support gates that mirror newly posted or newly imported messages into another local echo area.
- Gates are transport-neutral. After the mirrored copy is created, the destination area applies its own FTN or QWK outbound rules.

### QWK FTP Service

- QWK FTP service uploads now accept reply packets dropped directly into the FTP root (`/`) as well as `/qwk/upload/`, improving compatibility with clients that do not change into the upload subdirectory before sending `.REP` or `.ZIP` files.
- QWK FTP directory listings now eagerly build the user's QWK packet and report the actual packet size instead of a placeholder or stale size.

### Web Interface

- Echo area deletion now offers a message-handling choice for populated areas. Sysops can delete the remaining messages or move them into another local echo area before removing the area itself.
- The user-facing echoarea subscription manager at `/subscriptions` now uses a more compact filter layout modeled after `/echolist`, with network filtering and an option to show only interest groups that currently have message traffic.
- Subscribing or unsubscribing from an echoarea in `/subscriptions` now updates in place instead of reloading the page, preserving the current scroll position and active search/filter state.


---

## InterBBS QWK

BinktermPHP can now participate in inter-BBS QWK exchange as an external message transport. This is separate from the existing same-BBS offline-reader workflow.

The QWK networking implementation adds:

- QWK mailbox definitions for remote BBS peers, including host, credentials, remote path, passive FTP mode, and optional poll schedule
- per-area QWK conference mappings so a local echo area can be linked to one or more remote mailbox conference numbers
- inbound `.QWK` packet import into mapped local areas
- outbound `.REP` packet generation and upload back to the remote BBS
- QWK source tracking and deduplication so imported messages are not re-imported or echoed straight back to the same mailbox

Operational notes:

- The scheduler now evaluates QWK mailbox `poll_schedule` entries. A blank schedule remains manual-only.
- Unknown inbound conferences can be auto-created into the built-in `qwk` network as placeholder mappings for later review.
- Outbound REP formatting was corrected for Synchronet-compatible conference parsing during reply import.

## Gating

Echo Areas now support gates between distinct local area records. A gate mirrors new traffic from one area into another area while keeping the two areas separate in the database.

This is intended for cases such as:

- carrying the same topic under different tags on different networks
- mirroring a local area into a networked area
- relaying between an FTN-backed area and a QWK-backed area

Gate behavior:

- Gates apply to new messages only. Historical messages are not replayed.
- The mirrored copy is stored as a separate local message in the destination area.
- Once stored, the destination area evaluates its own outbound routing. That means a gated copy may then spool to FTN, queue for inter-BBS QWK, or both.
- Loop protection uses source message identity so a gated copy returning from another network is not imported endlessly.
- Self-gates are not allowed.

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

## QWK FTP Service

The FTP daemon now accepts `.REP` and `.ZIP` uploads dropped directly into the FTP root (`/`) in addition to the existing `/qwk/upload/` path. Previously, uploads to the root were rejected, blocking QWK client software — such as Synchronet's `qnet-ftp.js` — that stores the reply packet in the current working directory without issuing a `CWD` command first.

Clients that already target `/qwk/upload/` are unaffected. Clients that upload to root now have their packet routed through the same REP import pipeline as a `/qwk/upload/` transfer, including the same conference-map validation and deduplication checks.

The FTP-side QWK service now also builds the current user's outbound QWK packet eagerly when presenting the packet in a directory listing. That means FTP clients see the real downloadable packet size instead of a placeholder or an outdated size from a previous build.

## Web Interface

### Echo Area Deletion

The Echo Areas admin page now allows populated areas to be deleted without manual SQL cleanup. When deleting an area that still contains echomail, the dialog offers two explicit choices:

- delete the messages together with the area
- move the messages into another local echo area before deleting the original area

The move option is a local reassignment only. It does not re-gate, re-spool, or republish the historical messages into the destination area’s outbound network paths.

### Subscription Manager

The `/subscriptions` page now presents its filtering tools in a compact filter panel instead of a long row of controls and per-interest buttons.

The updated page adds:

- a network filter for narrowing the visible echoareas by network
- a compact interest picker instead of a button wall
- an `Only show groups with messages` filter that limits the visible results to interest-grouped areas with message activity
- the search and sort controls inside the same filter panel for a tighter layout
- in-place subscribe/unsubscribe updates that do not reset the current search, filters, or scroll position

This change is user-facing only. It does not alter subscriptions, interest membership, or message access rules.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
