# Upgrading to 1.10.7

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Messaging](#messaging)
  - [Date Display Preferences](#date-display-preferences)
  - [Message Search Scoped by Network and Interest](#message-search-scoped-by-network-and-interest)
- [Administration](#administration)
  - [Fixed: user-manager.php create Command](#fixed-user-managerphp-create-command)
- [AreaFix / FileFix](#areafix--filefix)
  - [Automatic Area Sync on Reply Now Opt-In](#automatic-area-sync-on-reply-now-opt-in)
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

### Administration

- **Fixed `scripts/user-manager.php create`:** the operator CLI's `create` command failed on PostgreSQL with `column "is_active" is of type boolean but expression is of type integer`, because it inserted the literal `1` instead of a boolean. This is now fixed.

### AreaFix / FileFix

- **Automatic area sync on reply is now opt-in:** receiving an AreaFix/FileFix reply from a hub that looks like an area list no longer automatically creates or activates local echo areas / file areas by default. Set `AREAFIX_AUTOIMPORT_ENABLED=true` in `.env` to restore the previous automatic behavior.

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

## Administration

### Fixed: user-manager.php create Command

`scripts/user-manager.php create` previously failed on every PostgreSQL install with:

```
SQLSTATE[42804]: column "is_active" is of type boolean but expression is of type integer
```

This was left over from the project's earlier SQLite-based schema, where `is_active` accepted an integer. The command now inserts a proper boolean and reads back the new user's id via `RETURNING id` instead of `lastInsertId()`. If you were creating operator accounts by editing the database directly to work around this, you can now use `scripts/user-manager.php create` normally again.

## AreaFix / FileFix

### Automatic Area Sync on Reply Now Opt-In

When your BBS receives a netmail reply from a hub's AreaFix or FileFix robot that looks like an area list (for example, the response to a `%LIST` or `%QUERY` command), BinktermPHP can automatically create matching `echoareas` or `file_areas` rows and activate them, using the descriptions the hub reports.

Starting with this release, that automatic sync is **disabled by default**. Incoming AreaFix/FileFix replies are still stored and viewable as normal netmail; they simply no longer create or activate local areas on their own. Sysops who want to review and apply a hub's area list continue to do so from **Admin -> AreaFix / FileFix**, using the **Sync to Echo Areas** button on a parsed reply.

If you relied on the previous automatic behavior — for example, to pick up new areas from your hub without visiting the admin page — set the following in `.env` to restore it:

```
AREAFIX_AUTOIMPORT_ENABLED=true
```

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
