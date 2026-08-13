# Upgrading to 1.10.2

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [AI Bots](#ai-bots)
- [Files](#files)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

- **AI Bots**: Fixed a bug that prevented the AI bot daemon from starting on PHP 8.1 and later.
- **Files**: Added a "File Requests" page where any logged-in user can request a file from another FidoNet node and have it delivered automatically to their private file area.

---

## AI Bots

### AI bot daemon fails to start with "PostgreSQL event listener: pg_connect failed"

On PHP 8.1 and later, the AI bot daemon's PostgreSQL LISTEN/NOTIFY connection could fail to establish even though the underlying database connection itself was working correctly. The daemon would log `PostgreSQL event listener: pg_connect failed` and exit immediately on startup.

The cause was a compatibility issue with how the daemon checked whether its native PostgreSQL connection succeeded. PHP 8.1 changed the `pgsql` extension to return connection objects instead of the older resource type, but the check used by the daemon still only recognized the older resource type, so a successful connection was incorrectly treated as a failure. This has been corrected so the daemon recognizes both connection types.

If you have previously seen the AI bot daemon fail to start with this error, restart it after upgrading and it should connect normally.

---

## Files

### Outbound file requests (FREQ) now available to all users

Any logged-in user can now request a file from another FidoNet node directly from the web interface, under **Files → File Requests**. Enter the remote node's address and the filename or magic name to request (e.g. `ALLFILES`, `ALLFILES`), choose whether to send the request as a classic `.req` file (the default, and the most broadly compatible option) or as a live-session `M_GET` request, and submit. Once the remote fulfils the request, the file is delivered to the requesting user's private file area and the File Requests page links directly to it.

A request that isn't fulfilled on the first attempt is retried automatically in the background, and requests that never succeed are eventually marked failed rather than retrying indefinitely. Users can delete their own request entries at any time; this only removes the tracking entry, not a file that was already received.

This feature is on by default and is controlled by the following optional `.env` settings:

| Variable | Default | Description |
|---|---|---|
| `FREQ_ENABLE_REQUESTS_WEB` | `true` | Set to `false` to hide the File Requests page and disable its API entirely |
| `FREQ_MAX_CONCURRENT_PER_USER` | `2` | Maximum number of requests a single user may have in progress at once |
| `FREQ_MAX_ATTEMPTS` | `5` | Number of retry attempts before an unfulfilled request is marked failed |
| `FREQ_POLL_INTERVAL` | `300` | Seconds between automatic retry attempts for a pending request |

This feature relies on the existing `binkp_scheduler` daemon to retry pending requests, so make sure it is running (see [Upgrade Instructions](#upgrade-instructions) below — restarting daemons after upgrading picks this up automatically).

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
