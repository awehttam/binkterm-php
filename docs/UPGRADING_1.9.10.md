# Upgrading to 1.9.10

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
  - [AIO Process Manager (experimental)](#aio-process-manager-experimental)
  - [Netmail Unread Counts](#netmail-unread-counts)
  - [Admin Menu Navigation](#admin-menu-navigation)
  - [Login Service Field Validation](#login-service-field-validation)
  - [Echo Area List Mobile Layout](#echo-area-list-mobile-layout)
  - [Bulk Mark Echo Areas as Read](#bulk-mark-echo-areas-as-read)
- [AIO Process Manager (experimental)](#aio-process-manager-experimental-1)
- [Netmail Unread Counts](#netmail-unread-counts-1)
- [Admin Menu Navigation](#admin-menu-navigation-1)
- [Login Service Field Validation](#login-service-field-validation-1)
- [Echo Area List Mobile Layout](#echo-area-list-mobile-layout-1)
- [Bulk Mark Echo Areas as Read](#bulk-mark-echo-areas-as-read-1)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### AIO Process Manager (experimental)

- `binktermphp-pm` and `binktermphp-ctl` — the optional Go-based process manager and its companion CLI — are available to download or build from their dedicated repository at [github.com/awehttam/binktermphp-pm](https://github.com/awehttam/binktermphp-pm).
- This release adds support in the web interface and the admin daemon for driving that external process manager, but only when `config/aio.json` is present. Without that file, the **Admin → BBS Settings → Services** menu item does not appear at all, and nothing about a normal install changes.
- There is currently no migration path or installer integration for `binktermphp-pm` — it is offered purely for sysops who want to experiment with it ahead of a future, fully supported release.

### Netmail Unread Counts

- Fixed a bug where the netmail unread count and the **Unread** tab could undercount messages that were correctly delivered to your account but addressed to a nickname or alias rather than your exact username or real name. If your unread netmail count jumps after upgrading, this is why — those messages were already in your inbox, they just weren't being flagged as unread.

### Admin Menu Navigation

- Nested items under **Admin** in the top navigation (for example **Area Management → AreaFix**) could become unreachable on narrow browser windows and touch devices. Clicking or tapping a nested submenu heading could silently fail to open it, and once open, the menu could get stuck without letting you scroll down to reach items further down the list. This has been fixed; nested Admin submenus now open reliably and scroll independently of the page.

### Login Service Field Validation

- The `POST /api/auth/login` endpoint accepts an optional `service` field used to label how a session was created (for example `web` or `telnet`) when displaying it in the **Who's Online** list. This field is now validated to only contain letters, digits, underscores, and hyphens, up to 20 characters. Requests with a `service` value outside this format are rejected with a `400` error instead of being accepted.

### Echo Area List Mobile Layout

- Fixed a layout bug on the Echo Areas page where an echo area's tag and description could render as a single word per line, stretching the full height of the screen and making the list unreadable. This was most noticeable on narrow viewports and with longer translated unread-count labels (for example Russian).

### Bulk Mark Echo Areas as Read

- The Echo Areas page (`/echolist`) now lets you mark an entire echo area as read directly from the list, without opening it first, and lets you select multiple echo areas at once (via a **Select** toggle and per-row checkboxes) and mark them all as read in one action.

---

## AIO Process Manager (experimental)

`binktermphp-pm` is an experimental, optional Go-based process supervisor for BinktermPHP's daemons (`admin_daemon`, `binkp_server`, `binkp_scheduler`, `realtime_daemon`, and optional services such as Telnet, SSH, MRC, Gemini, and FTP), restarting them automatically if they exit unexpectedly. `binktermphp-ctl` is its command-line companion for checking status, tailing logs, and starting, stopping, or restarting individual services.

Both binaries are available to download or build from their dedicated repository at [github.com/awehttam/binktermphp-pm](https://github.com/awehttam/binktermphp-pm); they are not included in the BinktermPHP installer or any release archive.

This release teaches BinktermPHP's web interface and admin daemon how to talk to `binktermphp-pm` when it is present, but the integration only activates if `config/aio.json` exists:

- If `config/aio.json` is absent (the default for every existing and new install), the **Admin → BBS Settings → Services** menu item is hidden and nothing else changes.
- If you create `config/aio.json` by copying `config/aio.json.example` and adjusting it for your setup (see the `binktermphp-pm` repository for full instructions), the Services page appears and lets you toggle individual supervised services on or off. Changes are written back to `config/aio.json` through the admin daemon, so the web process never writes config files directly.

**This is an experimental preview, not a supported deployment path yet.** There is no migration tooling, no installer support, and no guidance yet for moving an existing cron- or systemd-based install onto `binktermphp-pm`. Sysops are welcome to build and run it from the external repository to try it out, but should expect rough edges and be prepared to fall back to `scripts/restart_daemons.sh`, which is completely unaffected by this change and remains the supported way to keep daemons running.

---

## Netmail Unread Counts

Netmail is delivered to your account based on your FidoNet address, but the unread count and the **Unread** tab were only counting a message as unread if the sender's `To:` field exactly matched your username or real name. In practice, senders often address netmail to a nickname, alias, or misspelled variant of your name — the message still lands in your inbox correctly because delivery is resolved by address, but it was silently excluded from the unread count and the Unread tab because the name comparison failed.

This release fixes the unread and received-message filters (in the web UI, the netmail stats used for notification badges, and the dashboard widget) to also count a message as yours whenever it was actually delivered to your account, regardless of whether the `To:` name matches exactly. If you have a backlog of netmail that was addressed to a nickname, expect your unread count to increase after upgrading to reflect messages that were already sitting in your inbox unread.

This delivery-based check only applies to netmail that genuinely arrived from elsewhere. Netmail you send to another local user or to the sysop is excluded from your own unread count, since that mail was never something you needed to "read" in the first place — only the recipient's unread count reflects it.

---

## Admin Menu Navigation

On viewports narrower than the desktop breakpoint (roughly tablet width and below, including phones and narrowed desktop browser windows), the collapsed hamburger menu could make items nested under **Admin** — such as **Area Management → AreaFix**, **Community → Chat**, or **Help → Developer** — difficult or impossible to reach. Three separate issues combined to cause this:

- Clicking or tapping a nested submenu heading (e.g. **Area Management**) could appear to do nothing. A mouse hovering over the heading, or a touch tap's built-in hover simulation, would already visually open the submenu before the click was handled, so the click handler mistook it for already being open and immediately closed it again.
- Selecting a nested submenu heading could close the entire **Admin** menu outright, because the browser's dropdown behavior treated clicking any item inside the menu as "an item was selected."
- On narrow viewports, the expanded **Admin** menu could grow taller than the visible page area. Because the menu bar stays pinned to the top of the screen while you scroll, any items below the visible area were unreachable — scrolling moved the rest of the page instead of the menu.

All three issues are fixed. Submenus under **Admin** now open reliably on the first click or tap, stay open until you close them, and the menu now scrolls within itself so every nested item can be reached regardless of window size or device.

---

## Login Service Field Validation

Every logged-in session is tagged with a `service` label (`web`, `telnet`, `ssh`, `ftp`, `packetbbs`, and so on) that shows up in the **Who's Online** list to indicate how that user connected. For sessions created through the API, this label was previously accepted from the client with no restriction on its contents.

The `service` field is now validated on `POST /api/auth/login`: it must consist only of letters, digits, underscores, and hyphens, and be no longer than 20 characters. A request with a `service` value outside this format now receives a `400` response with the error code `errors.auth.invalid_service`, and no session is created.

If you have custom scripts, bots, or third-party clients that call `/api/auth/login` directly with a custom `service` value, confirm that value only uses letters, digits, underscores, and hyphens (20 characters or fewer) before upgrading, or the login call will start failing.

---

## Echo Area List Mobile Layout

On the Echo Areas page, each area's row lays out its tag and description in a flexible column next to a badge showing its unread and total post counts. That badge column was set to never shrink or wrap, so on narrow screens a long unread-count label — especially longer translated strings such as the Russian "непрочитанных из ... постов" — could claim most of the row's width. The remaining space left for the area's tag and description could collapse to almost nothing, causing the text to wrap one word (or even one character) per line and stretch the row across the entire screen height.

The badge column now wraps onto multiple lines instead of forcing the row wider, and the tag/description column breaks long words safely instead of collapsing. Echo area rows now stay readable on narrow screens regardless of translated string length.

---

## Bulk Mark Echo Areas as Read

Previously, the only way to clear an echo area's unread count was to open it and either read a message or use the message list's own selection tools. For echo areas that receive only occasional traffic (for example a rules-only echo posted a couple of times a month), this meant opening the area just to dismiss a badge.

The Echo Areas page (`/echolist`) now offers two ways to mark an area read without opening it:

- Each echo area row with unread messages shows a small mark-read icon next to its unread count. Clicking it marks every currently unread, visible message in that area as read for you.
- A **Select** button (next to **New Post** / **New Messages**) turns on selection mode, adding a checkbox to each row. With one or more areas checked, a floating action bar appears at the bottom of the screen — it stays visible while you scroll through the list — with **Select all**, **Mark as Read**, and a button to cancel selection mode.

Marking an area read this way respects the same visibility rules as the unread counts shown on the page: ignored senders and pending/rejected moderated messages are not marked read, and sysop-only areas are only affected for admin accounts.

---

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically.
