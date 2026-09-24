# Upgrading to 1.10.7

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Messaging](#messaging)
  - [Date Display Preferences](#date-display-preferences)
  - [Message Search Scoped by Network and Interest](#message-search-scoped-by-network-and-interest)
- [AreaFix / FileFix](#areafix-filefix)
  - [Structural Reply Parsing Across More Hub Mailers](#structural-reply-parsing-across-more-hub-mailers)
  - [Mandatory Preview Before Syncing Areas](#mandatory-preview-before-syncing-areas)
- [Administration](#administration)
  - [Fixed: user-manager.php create Command](#fixed-user-managerphp-create-command)
- [Security](#security)
  - [Secure Flag on Session Cookies](#secure-flag-on-session-cookies)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)
- [Thanks](#thanks)

## Summary of Changes

### Messaging

- **Date display preferences:** users and sysops can now choose between relative timestamps ("4d ago") and exact date/time for message lists and headers, and choose whether echomail is ordered and displayed by received date or written date.
- **Message search scoped by network and interest:** searching for messages from the Echo Areas page now respects the network and interest filters selected there, and searching while browsing a single interest on the Echomail page now stays within that interest's echo areas, instead of always searching every echo area.

### AreaFix / FileFix

- **Structural reply parsing across more hub mailers:** AreaFix and FileFix replies are now parsed by recognizing the concrete layout each hub mailer actually sends — Mystic BBS/MBSE command blocks, delimited and columnar tables, BBBS/Li6-style quoted address lists, and HPT-style flag-prefixed quoted lists — instead of scanning for keywords. Real echo areas with common names such as `LINUX`, `WINDOWS`, or `BASE` are no longer mistaken for header text or help output.
- **Mandatory preview before syncing areas:** clicking "Sync Areas to Local BBS" (from the latest reply, or from any individual incoming message in the Message History table) now shows a preview of exactly which areas will be created, reactivated, deactivated, or left unchanged. Nothing is written to the database until this preview is explicitly confirmed.

### Administration

- **Fixed `scripts/user-manager.php create`:** the operator CLI's `create` command failed on PostgreSQL with `column "is_active" is of type boolean but expression is of type integer`, because it inserted the literal `1` instead of a boolean. This is now fixed.

### Security

- **Secure flag on session cookies:** the `binktermphp_session` cookie now sets the `Secure` flag whenever the site is served over HTTPS, so the cookie is no longer sent over a plain HTTP connection even if one is reachable.

## Messaging

### Date Display Preferences

Two new preferences control how dates are shown across echo area listings, echomail message headers, and netmail lists:

- **Date display style** — choose relative time (e.g. "4d ago", "2h ago") or exact date and time, formatted using the user's selected date format locale and timezone.
- **Echomail date field** — choose whether echomail lists and ordering use the received date (when the message arrived on this system) or the written date (when the original author composed it).

Both preferences default to "System Default," which follows a BBS-wide default that sysops can set in **Admin -> BBS Settings -> Features**. Users can override the system default for either preference individually in **Settings -> Preferences**.

Previously, only admin users could choose to order echomail by written date; this option is now available to all users. If your installation currently sets `ECHOMAIL_ORDER_DATE=written`, non-admin users will now see that ordering apply to them as well, following the same fallback chain (user preference, then BBS default, then this environment variable).

### Message Search Scoped by Network and Interest

The Echo Areas page lets you filter the area list down to one or more networks and interests using the **Network** and **Interests** dropdowns. The "Search Messages" box on that same page now carries those selections into the search, so results are limited to matching echo areas instead of every echo area on the system. Leaving both dropdowns on their "All" default still searches everything.

On the Echomail page, searching while browsing a single interest under the Interests tab is likewise scoped to that interest's echo areas. Searching from a specific echo area continues to scope to that single area, as before, taking priority over any network or interest scope.

## AreaFix / FileFix

### Structural Reply Parsing Across More Hub Mailers

AreaFix and FileFix replies from a hub are parsed by matching the actual layout the hub's mailer software produces, rather than by scanning line-by-line for known words and phrases. The parser recognizes:

- Mystic BBS and MBSE `Command:`/`Result:` blocks, including stacked multi-command replies and `%LIST`/`%QUERY`/`%LINKED`/`%UNLINKED` result listings.
- Colon- and pipe-delimited tables (Husky, Clearing Houz, FastEcho, FrontDoor, InterMail).
- Columnar and dotted-leader tables (HPT, Husky), including table headers that name the tag column something other than the literal word "Area" (for example "Message area").
- BBBS/Li6-style quoted address lists (`+TAG (address) "description"`), including descriptions that wrap onto a continuation line and a single reply that lists both echo areas and file areas.
- HPT-style flag-prefixed dotted-leader lists with quoted descriptions (`*S   TAG ....... "description"`).
- As a last resort, a conservative bare `TAG   Description` line matcher for hub replies that don't match any of the above, which never marks a matched area as subscribed on its own.

Because this approach recognizes real structure instead of matching words, an echo area named the same as an ordinary English word or a common piece of software (`LINUX`, `WINDOWS`, `BASE`, and similar) is preserved correctly instead of being mistaken for a header, a help topic, or unrelated prose.

A Mystic BBS/MBSE `%QUERY` reply that lists both linked and unlinked areas in a single block, with individual rows explicitly annotated `(linked)`, `(unlinked)`, or `(not linked)`, now honors each row's own annotation instead of marking every row in the block the same way.

### Mandatory Preview Before Syncing Areas

Previously, clicking "Sync Areas to Local BBS" on the AreaFix / FileFix Manager page applied the parsed area list to your local echo areas or file areas immediately, with no chance to review it first. It now opens a preview dialog instead, and nothing is written to your database until you explicitly confirm it there. This preview is available in two places: from the "Latest Reply" panel's sync button, and per-message from a sync button next to each incoming reply in the Message History table, so you can also review and apply an older reply without it needing to still be the most recent one.

The preview lists every area found in the reply as a row with a checkbox, its tag, its description, and a status badge:

- **New** — the area doesn't exist locally yet and will be created.
- **Reactivate** — the area exists but is currently inactive and will be turned on.
- **Deactivate** — the area is currently active and the reply says to unsubscribe from it.
- **Updated** — the area's activation state isn't changing, but its description will be filled in or updated to match the hub's reply.
- **Unchanged** — nothing about the area differs from what the reply says; selecting it has no effect.

For an "Updated" row, the description cell shows your current description struck through above the incoming one when it will actually be replaced. A description is only ever replaced when your current one is empty, an auto-generated placeholder, or you've explicitly selected that row for sync (see below) — a real, sysop-set description is never silently overwritten. If the hub's reply lists a different description for an area whose own real description would otherwise be left alone, the preview still shows what the hub sent underneath it, so the mismatch doesn't go unnoticed just because it's not required to be applied.

Every row starts checked except a genuine no-op "Unchanged" row — including every "New", "Reactivate", "Deactivate", and "Updated" row, so the normal case (review, then confirm) still applies everything in one click. Use the checkboxes, or the "Select All" / "Select None" buttons above the list, to apply only a subset instead. Confirming a checked "Updated" row is what actually lets a hub's description win over your own where it otherwise wouldn't — uncheck that specific row first if you'd rather keep your own description for that one area.

## Administration

### Fixed: user-manager.php create Command

`scripts/user-manager.php create` previously failed on every PostgreSQL install with:

```
SQLSTATE[42804]: column "is_active" is of type boolean but expression is of type integer
```

This was left over from the project's earlier SQLite-based schema, where `is_active` accepted an integer. The command now inserts a proper boolean and reads back the new user's id via `RETURNING id` instead of `lastInsertId()`. If you were creating operator accounts by editing the database directly to work around this, you can now use `scripts/user-manager.php create` normally again.

## Security

### Secure Flag on Session Cookies

The `binktermphp_session` cookie is now marked `Secure` whenever the site's effective URL uses HTTPS, determined from the `SITE_URL` environment variable (or, if that isn't set, from the request's own HTTPS signal). This prevents the browser from sending the session cookie over a plain HTTP connection, closing off a path where the session id could otherwise be exposed on the wire. Installations that serve BinktermPHP over HTTPS behind a reverse proxy should ensure `SITE_URL` in `.env` is set to the `https://` URL so this detection works correctly.

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

---

## Thanks

Thanks to **TheWebExpert** and **Skrawl** for their contributions to this release.
