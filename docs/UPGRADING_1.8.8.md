# Upgrading to 1.8.8

⚠️ Make sure you've made a backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [File Areas & TIC Processing](#file-areas--tic-processing)
  - [FILE_ID.DIZ Selection for Incoming TIC ZIPs](#file_iddiz-selection-for-incoming-tic-zips)
- [FTN Networking](#ftn-networking)
  - [BinkP Handshake Timeout Handling](#binkp-handshake-timeout-handling)
- [QWK Offline Mail](#qwk-offline-mail)
  - [Stable Per-User Conference Numbers](#stable-per-user-conference-numbers)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

**File Areas & TIC Processing**
- Incoming TIC ZIP processing now uses `FILE_ID.DIZ` only from the archive root.

**FTN Networking**
- BinkP now handles exported socket timeouts more consistently during handshake reads.

**QWK Offline Mail**
- QWK conference numbers are now stored persistently per user so subscribe and unsubscribe changes do not renumber existing conferences.

## File Areas & TIC Processing

### FILE_ID.DIZ Selection for Incoming TIC ZIPs

BinktermPHP can use `FILE_ID.DIZ` inside an incoming TIC ZIP to fill in missing
`Desc` and `LDesc` values when the TIC metadata does not provide them.

In earlier 1.8.x builds, the TIC incoming processor matched `FILE_ID.DIZ` by
basename only. That meant a ZIP containing multiple copies of `FILE_ID.DIZ`
could use the wrong one, including files nested deeper inside subdirectories.

Version 1.8.8 tightens that lookup logic:

- Prefer `/FILE_ID.DIZ` at the ZIP root.
- If no root-level `FILE_ID.DIZ` exists, allow `/directory/FILE_ID.DIZ` only
  when that single directory is the only directory at the archive root.
- Never descend more than one directory level when selecting `FILE_ID.DIZ` for
  TIC description import.

This change improves compatibility with common single-top-level-directory ZIP
layouts while avoiding incorrect descriptions from nested archive content.

## FTN Networking

### BinkP Handshake Timeout Handling

Version 1.8.8 also tightens timeout handling for binkp sessions after sockets
are converted to stream resources. The exported stream now relies on stream
timeouts consistently instead of mixing native socket timeouts with stream I/O.

This helps avoid false handshake timeouts where the remote has already sent a
valid frame, but the local side fails the read during handshake processing.

## QWK Offline Mail

### Stable Per-User Conference Numbers

Version 1.8.8 now stores QWK conference numbers in a persistent per-user map
instead of rebuilding them from the current subscription order on every packet
download.

This means:

- Existing conference numbers stay stable for a given user.
- Unsubscribing from an area does not renumber later conferences.
- Re-subscribing to a previously mapped area reuses the same conference number.
- New subscriptions receive the next available conference number for that user.

The system still records a per-download conference map in `qwk_download_log`
for REP reply import safety, but packet generation and the QWK status view now
use the persistent mapping table.

## Upgrade Instructions

This release adds a database migration for the persistent QWK conference map.
Run setup during upgrade so existing users are backfilled from their latest QWK
download state where available.

### From Git

1. Pull the latest code: `git pull`
2. Run setup: `php scripts/setup.php`
3. Restart the daemons if they are running: `bash scripts/restart_daemons.sh`

### Using the Installer

Re-run the BinktermPHP installer to upgrade the application files, then restart
the daemons if your deployment manages them separately.
