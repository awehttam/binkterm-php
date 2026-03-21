# Upgrading to 1.8.8

⚠️ Make sure you've made a backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [File Areas & TIC Processing](#file-areas--tic-processing)
  - [FILE_ID.DIZ Selection for Incoming TIC ZIPs](#file_iddiz-selection-for-incoming-tic-zips)
- [FTN Networking](#ftn-networking)
  - [BinkP Handshake Timeout Handling](#binkp-handshake-timeout-handling)
- [QWK Offline Mail](#qwk-offline-mail)
  - [Stable BBS-Wide Conference Numbers](#stable-bbs-wide-conference-numbers)
  - [QWKE Subject Extended Header in REP Packets](#qwke-subject-extended-header-in-rep-packets)
  - [QWKE From/To Headers No Longer Include FTN Address](#qwke-fromto-headers-no-longer-include-ftn-address)
  - [FTN Address in To Field for New Netmail](#ftn-address-in-to-field-for-new-netmail)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

**File Areas & TIC Processing**
- Incoming TIC ZIP processing now uses `FILE_ID.DIZ` only from the archive root.

**FTN Networking**
- BinkP now handles exported socket timeouts more consistently during handshake reads.

**QWK Offline Mail**
- QWK conference numbers are now stored as canonical BBS-wide IDs on echo areas so packets use the system's conference numbering instead of subscription position.
- REP packet processing now reads QWKE plain-text `Subject:` headers from the message body, fixing subject truncation to 25 characters for clients such as MultiMail.
- QWKE outgoing `From:` and `To:` extended headers now contain only the name; the FTN address is no longer appended, preventing it from appearing as part of the reply-to name in offline readers.
- New netmail sent via QWK can now specify the FTN destination by writing `Name@zone:net/node[.point]` in the To field.

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

### Stable BBS-Wide Conference Numbers

Version 1.8.8 now stores QWK conference numbers as canonical BBS-wide values on
echo areas instead of rebuilding them from the current subscription order on
every packet download.

This means:

- Conference numbers are stable across the entire BBS, not just per user.
- Subscribing or unsubscribing from an area no longer affects conference IDs.
- Every user sees the same QWK conference number for the same echo area.
- New echo areas receive the next available canonical conference number.

The system still records a per-download conference map in `qwk_download_log`
for REP reply import safety, but packet generation and the QWK status view now
use the canonical echo area conference number.

### QWKE Subject Extended Header in REP Packets

The QWK format limits the subject field in the message header to 25 characters.
QWKE-capable clients such as MultiMail write a plain-text `Subject:` line at the
top of the message body when the subject exceeds that limit. Previous builds of
BinktermPHP ignored this line and always used the truncated 25-character header
field, causing long subjects to be silently cut off after a REP upload.

Version 1.8.8 now reads the plain-text `Subject:` (and `To:`/`From:`) extended
headers from the message body before falling back to the fixed header fields.

### QWKE From/To Headers No Longer Include FTN Address

When generating QWKE packets, BinktermPHP previously appended the sender's FTN
address to the plain-text `From:` and `To:` extended headers in the form
`Name <zone:net/node>`. The QWKE specification defines these headers as
name-extension fields only; the FTN address belongs in the `^A`-prefixed kludge
lines (`^AINTL`, `^AORIG`, etc.).

Including the address caused offline readers to store `Name <zone:net/node>` as
the from-name. When composing a reply, the full string then appeared verbatim in
the To field of the outgoing REP packet.

Version 1.8.8 now writes only the name in these headers, matching the spec.

### FTN Address in To Field for New Netmail

QWK has no dedicated field for a FTN destination address. When composing new
netmail (conference 0) in an offline reader, there is no standard way to
communicate the routing address to the BBS.

Version 1.8.8 supports a convention for specifying the destination: if the To
field of a conference-0 message matches the pattern `Name@zone:net/node[.point]`
the address portion is extracted and used for FTN routing. The name portion is
used as the recipient name. This allows users to address new netmail to arbitrary
FTN nodes directly from their QWK reader.

Replies to received netmail continue to resolve the destination via the message
index as before; this convention applies only to new messages with no reply
reference.

## Upgrade Instructions

This release adds a database migration for canonical QWK conference numbers on
echo areas. Run setup during upgrade so existing areas are backfilled with BBS-
wide conference IDs before users download new packets.

### From Git

1. Pull the latest code: `git pull`
2. Run setup: `php scripts/setup.php`
3. Restart the daemons if they are running: `bash scripts/restart_daemons.sh`

### Using the Installer

Re-run the BinktermPHP installer to upgrade the application files, then restart
the daemons if your deployment manages them separately.
