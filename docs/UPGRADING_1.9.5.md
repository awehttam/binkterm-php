# Upgrading to 1.9.5

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Bulletin Manager](#bulletin-manager)
- [Terminal Message Editor](#terminal-message-editor)
- [Terminal Message Encoding](#terminal-message-encoding)
- [Terminal Echomail Display](#terminal-echomail-display)
- [User Settings](#user-settings)
- [Community Wireless Node Map](#community-wireless-node-map)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Content and Display

- Added a Bulletin Manager for sysop-authored BBS bulletins. Bulletins can be managed from the admin web interface and displayed to users in the web, telnet, and SSH BBS flows.
- Added a BBS setting for bulletin display mode. Sysops can choose whether bulletins are shown once until read, or shown once at the start of each login session.
- Renamed the Community Wireless Node List WebDoor to Community Wireless Node Map.
- Fixed the Community Wireless Node Map network count so it reports the full active map total instead of stopping at the first 500 returned rows. The map now loads markers for the current viewport instead of loading every CWN row on page load.
- Fixed the terminal full-screen message editor so typed text is hard-wrapped to the detected terminal body width. Messages composed in telnet and SSH now display with the same line breaks when viewed in the terminal message reader.
- Fixed terminal message display so UTF-8 message text, subjects, sender names, and kludge/header lines are converted to the user's active terminal character set before being written to telnet or SSH sessions.
- Updated the terminal echomail empty-state text to tell users when they are not subscribed to any echo areas, instead of implying that no areas exist.
- Fixed terminal echomail area labels for local echo groups that have no domain. Telnet and SSH now show plain area names such as `LOCAL.TEST` instead of appending an empty `@` suffix.
- Fixed the web settings page so saving one changed preference no longer resubmits every setting from every tab. The page now shows a loading overlay until the user's saved settings are loaded, then saves only changed preferences so unrelated settings are not overwritten by stale or unloaded form values.

## Bulletin Manager

Version 1.9.5 introduces a built-in Bulletin Manager for short sysop announcements, notices, and other BBS-facing updates. Bulletins are managed from **Admin -> Ads and Bulletins -> Bulletins**. Each bulletin has a title, body, display format, sort order, active flag, and optional active date range.

Bulletins support plain text and Markdown. Active bulletins are shown to users through the web bulletin viewer and through the terminal BBS experience over telnet and SSH. The dashboard also includes a bulletin card so users can see when unread bulletins are waiting.

By default, bulletins use the existing read-tracking behavior: a user sees each active bulletin until it has been marked read. Sysops can change this in **Admin -> BBS Settings** with the Bulletin Display Mode option:

- **Display once** shows unread bulletins until each user has read or skipped them.
- **Always display** shows active bulletins once at the start of each new login session. It does not repeatedly interrupt users every time they return to the dashboard during the same session.

The upgrade creates the bulletin storage tables and read-tracking table through `php scripts/setup.php`. No manual database changes are required.

## Terminal Message Editor

The telnet and SSH full-screen message editor now hard-wraps typed text to the detected terminal message body width. The editor uses the same width as the terminal message reader, so newly composed netmail and echomail keep consistent line breaks when the saved message is viewed in-terminal.

Long words are still split only when they exceed the available terminal width. Existing manual line breaks are preserved. No database changes or message reprocessing are required.

## Terminal Message Encoding

Terminal message views now convert UTF-8 message content to the active terminal character set before writing it to the socket. This fixes mojibake when messages contain accented characters or other non-ASCII text, such as German umlauts, and the user is connected with a CP437 or ASCII terminal profile.

The fix applies to echomail and netmail message bodies, message list rows, message header boxes, and the raw header/kludge viewer. Web message display continues to use UTF-8 directly.

No database changes or manual reprocessing of existing messages are required.

The terminal echomail browser also now distinguishes the subscription empty state more clearly. When a user has no subscribed echo areas, telnet and SSH show "You are not subscribed to any areas." rather than a generic "No echo areas available" message.

## Terminal Echomail Display

Terminal echomail area labels now omit the domain separator when an echo area has no domain. Local-only echo groups such as `LOCAL.TEST` are displayed as `LOCAL.TEST` in telnet and SSH message lists, message headers, compose screens, and related terminal logs. Networked echo areas with a domain continue to display as `TAG@domain`.

No database changes or manual configuration updates are required.

## User Settings

The web settings page now shows a loading overlay while the user's current settings are being loaded. The Save button stays disabled until loading finishes, so users are not shown an interactive form containing temporary default values.

After loading, the page records the initial values and only sends preferences that were changed in the current edit session. This prevents a change to one setting, such as language or theme, from overwriting unrelated settings that live on other tabs. No database changes are required.

## Community Wireless Node Map

The Community Wireless Node Map now separates the overall active network count from the marker query used to populate the current map view. Previously, the frontend requested a single `limit=500` result page and displayed the number of returned rows, which made the network count appear capped at 500 even when additional manual entries or MeshCore repeater adverts were present.

The CWN list API now returns `total_all` for the full active, non-expired map total. The frontend displays that value in the statistics panel while loading marker data only for the current Leaflet map bounds. Panning or zooming the map refreshes the visible markers for the new viewport. This keeps the displayed total accurate without forcing the browser to load every CWN row as the database grows.

No manual configuration changes are required. Run `php scripts/setup.php` as part of the normal upgrade process so any pending CWN schema migrations from earlier 1.9.x changes are applied.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Replace your files with the new release archive, then run:

```bash
php scripts/setup.php
scripts/restart_daemons.sh
```
