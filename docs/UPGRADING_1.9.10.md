# Upgrading to 1.9.10

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
  - [AIO Process Manager (experimental)](#aio-process-manager-experimental)
  - [Netmail Unread Counts](#netmail-unread-counts)
- [AIO Process Manager (experimental)](#aio-process-manager-experimental-1)
- [Netmail Unread Counts](#netmail-unread-counts-1)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### AIO Process Manager (experimental)

- `binktermphp-pm` and `binktermphp-ctl` — the optional Go-based process manager and its companion CLI — are no longer built or bundled inside this repository. They now live in their own repository at [github.com/awehttam/binktermphp-pm](https://github.com/awehttam/binktermphp-pm).
- This release adds support in the web interface and the admin daemon for driving that external process manager, but only when `config/aio.json` is present. Without that file, the **Admin → BBS Settings → Services** menu item does not appear at all, and nothing about a normal install changes.
- There is currently no migration path or installer integration for `binktermphp-pm` — it is offered purely for sysops who want to experiment with it ahead of a future, fully supported release.

### Netmail Unread Counts

- Fixed a bug where the netmail unread count and the **Unread** tab could undercount messages that were correctly delivered to your account but addressed to a nickname or alias rather than your exact username or real name. If your unread netmail count jumps after upgrading, this is why — those messages were already in your inbox, they just weren't being flagged as unread.

---

## AIO Process Manager (experimental)

`binktermphp-pm` is an experimental, optional Go-based process supervisor for BinktermPHP's daemons (`admin_daemon`, `binkp_server`, `binkp_scheduler`, `realtime_daemon`, and optional services such as Telnet, SSH, MRC, Gemini, and FTP), restarting them automatically if they exit unexpectedly. `binktermphp-ctl` is its command-line companion for checking status, tailing logs, and starting, stopping, or restarting individual services.

Both binaries are built and distributed from a separate repository, [github.com/awehttam/binktermphp-pm](https://github.com/awehttam/binktermphp-pm) — they are not part of this repository and are not included in the installer or any release archive of BinktermPHP itself.

This release teaches BinktermPHP's web interface and admin daemon how to talk to `binktermphp-pm` when it is present, but the integration only activates if `config/aio.json` exists:

- If `config/aio.json` is absent (the default for every existing and new install), the **Admin → BBS Settings → Services** menu item is hidden and nothing else changes.
- If you create `config/aio.json` (following the instructions in the `binktermphp-pm` repository), the Services page appears and lets you toggle individual supervised services on or off. Changes are written back to `config/aio.json` through the admin daemon, so the web process never writes config files directly.

**This is an experimental preview, not a supported deployment path yet.** There is no migration tooling, no installer support, and no guidance yet for moving an existing cron- or systemd-based install onto `binktermphp-pm`. Sysops are welcome to build and run it from the external repository to try it out, but should expect rough edges and be prepared to fall back to `scripts/restart_daemons.sh`, which is completely unaffected by this change and remains the supported way to keep daemons running.

---

## Netmail Unread Counts

Netmail is delivered to your account based on your FidoNet address, but the unread count and the **Unread** tab were only counting a message as unread if the sender's `To:` field exactly matched your username or real name. In practice, senders often address netmail to a nickname, alias, or misspelled variant of your name — the message still lands in your inbox correctly because delivery is resolved by address, but it was silently excluded from the unread count and the Unread tab because the name comparison failed.

This release fixes the unread and received-message filters (in the web UI, the netmail stats used for notification badges, and the dashboard widget) to also count a message as yours whenever it was actually delivered to your account, regardless of whether the `To:` name matches exactly. If you have a backlog of netmail that was addressed to a nickname, expect your unread count to increase after upgrading to reflect messages that were already sitting in your inbox unread.

This delivery-based check only applies to netmail that genuinely arrived from elsewhere. Netmail you send to another local user or to the sysop is excluded from your own unread count, since that mail was never something you needed to "read" in the first place — only the recipient's unread count reflects it.

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
