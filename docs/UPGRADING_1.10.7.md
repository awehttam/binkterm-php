# Upgrading to 1.10.7

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Messaging](#messaging)
  - [Date Display Preferences](#date-display-preferences)
  - [Message Search Scoped by Network and Interest](#message-search-scoped-by-network-and-interest)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Messaging

- **Date display preferences:** users and sysops can now choose between relative timestamps ("4d ago") and exact date/time for message lists and headers, and choose whether echomail is ordered and displayed by received date or written date.
- **Message search scoped by network and interest:** searching for messages from the Echo Areas page now respects the network and interest filters selected there, and searching while browsing a single interest on the Echomail page now stays within that interest's echo areas, instead of always searching every echo area.

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
