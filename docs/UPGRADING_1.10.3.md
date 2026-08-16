# Upgrading to 1.10.3

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [FREQ](#freq)
- [Activity Log](#activity-log)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### FREQ

- `scripts/freq_getfile.php` and `scripts/freq_pickup.php` now connect anonymously by default, even when the target address matches one of your configured uplinks. A new `--authenticated` flag opts back into using that uplink's real session password/CRAM-MD5 for a single run, and a new `FREQ_AUTHENTICATE_UPLINKS` `.env` setting lets you make that the default everywhere, including the web/terminal File Requests UI.

### Activity Log

- Submitting a FREQ via the web/terminal File Requests UI now records a `freq_request` entry in the user activity log, matching the other file-area actions (view/download/upload).
- Viewing the public BBS Directory list, viewing an individual BBS's detail page, and submitting a new BBS listing now record activity log entries (`bbs_directory_view` / `bbs_directory_entry_view` / `bbs_directory_submit`) for logged-in users, shown as a new "BBS Directory" row in **Admin → Activity Stats**.
- Entering a local chat room now records a `chat_room_enter` entry, alongside the existing chat-message-sent tracking.
- Uploading or generating a PGP key, and changing your primary key or deleting a key, now record activity log entries (`pgp_key_upload` / `pgp_key_generate` / `pgp_key_primary` / `pgp_key_delete`), shown as a new "PGP" row in **Admin → Activity Stats**.

## FREQ

FREQ requests made with `scripts/freq_getfile.php` or `scripts/freq_pickup.php` now default to an anonymous binkp session, matching standard FTN convention for file requests. Previously, if the address you were FREQing happened to match one of your configured uplinks, the script would automatically use that uplink's real session password and CRAM-MD5 credentials for the session — not what most sysops expect from a simple file request. This is separate from the FREQ area password (`--password`), which is still carried inside the `.req`/`M_GET` request itself and unaffected by this change.

If you specifically want a FREQ run to use your real uplink session credentials, pass the new `--authenticated` flag. To change the default for every FREQ (including the web/terminal File Requests UI, which shells out to `freq_getfile.php`), set `FREQ_AUTHENTICATE_UPLINKS=true` in `.env`; the new `--anonymous` flag forces an anonymous session for a single run even when that setting is enabled. See [docs/FREQ.md](FREQ.md#configuration-reference) for the full reference and the tradeoff to consider before enabling it.

## Activity Log

Several gaps in `user_activity_log` coverage are fixed in this release:

- Submitting an outbound FREQ via the web/terminal File Requests UI is now tracked (`freq_request`, category `file`).
- Viewing the public BBS Directory list page, viewing an individual BBS's detail page, and submitting a new BBS listing for approval are now tracked (`bbs_directory_view`, `bbs_directory_entry_view`, `bbs_directory_submit`; new category `bbs_directory`) for logged-in users. Anonymous visits are not tracked, matching the existing nodelist-view tracking behavior.
- Entering a local chat room (switching to it, or restoring it on page load) is now tracked (`chat_room_enter`, existing category `chat`). Sending a chat message was already tracked; loading older history while scrolling, and direct-message threads, are not tracked.
- Uploading an existing PGP public key or generating a managed keypair, and changing your primary key or deleting a key, are now tracked (`pgp_key_upload`, `pgp_key_generate`, `pgp_key_primary`, `pgp_key_delete`; new category `pgp`). Looking up or viewing another user's PGP key is not tracked.

None of these change any user-facing behavior; they only affect what shows up in a user's activity history and in **Admin → Activity Stats**.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
