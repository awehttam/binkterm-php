# Upgrading to 1.9.5

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Message Reader](#message-reader)
- [Bulletin Manager](#bulletin-manager)
- [Terminal Message Editor](#terminal-message-editor)
- [Terminal Message Encoding](#terminal-message-encoding)
- [Terminal Echomail Display](#terminal-echomail-display)
- [User Settings](#user-settings)
- [Terminal Language Selection](#terminal-language-selection)
- [Localized Documentation](#localized-documentation)
- [Community Wireless Node Map](#community-wireless-node-map)
- [File Areas](#file-areas)
- [Terminal Registration](#terminal-registration)
- [Message Search](#message-search)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Message Search

- Fixed echomail and netmail search results being replaced by the full message list when the background auto-refresh fired (on new-message events from BinkStream or when the browser tab was restored after being hidden). Search results now stay on screen until explicitly cleared.

### Message Reader

- Fixed the message reader modal so the `f` key shortcut for toggling full-screen no longer intercepts the browser's Ctrl+F (or Cmd+F on Mac) find shortcut. Ctrl+F and Cmd+F now open the browser's native find dialog as expected. Applies to both echomail and netmail message modals.

### Content and Display

- Added a Bulletin Manager for sysop-authored BBS bulletins. Bulletins can be managed from the admin web interface and displayed to users in the web, telnet, and SSH BBS flows.
- Added a BBS setting for bulletin display mode. Sysops can choose whether bulletins are shown once until read, or shown once at the start of each login session.
- Renamed the Community Wireless Node List WebDoor to Community Wireless Node Map.
- Fixed the Community Wireless Node Map network count so it reports the full active map total instead of stopping at the first 500 returned rows. The map now loads markers for the current viewport instead of loading every CWN row on page load.
- Fixed the terminal full-screen message editor so typed text is hard-wrapped to the detected terminal body width. Messages composed in telnet and SSH now display with the same line breaks when viewed in the terminal message reader.
- Fixed terminal message display so UTF-8 message text, subjects, sender names, and kludge/header lines are converted to the user's active terminal character set before being written to telnet or SSH sessions.
- Updated the terminal echomail empty-state text to tell users when they are not subscribed to any echo areas, instead of implying that no areas exist.
- Fixed terminal echomail area labels for local echo groups that have no domain. Telnet and SSH now show plain area names such as `LOCAL.TEST` instead of appending an empty `@` suffix.
- Fixed the terminal language selector so it shows all installed languages rather than a fixed list of three.
- Added support for locale-specific documentation files. The user guide and admin help browser now serve translated Markdown files when available, falling back to the English source when no translation exists. Any locale directory added under `config/i18n/` now appears automatically in the telnet and SSH settings language list.
- Added translated user guides for French (`index.fr.md`), Spanish (`index.es.md`), Italian (`index.it.md`), and German (`index.de.md`). These are served automatically when the user's active language matches the locale.
- Fixed the web settings page so saving one changed preference no longer resubmits every setting from every tab. The page now shows a loading overlay until the user's saved settings are loaded, then saves only changed preferences so unrelated settings are not overwritten by stale or unloaded form values.

### Terminal Registration

- The admin pending users list now shows whether each registration was submitted through the web, telnet, or SSH, using a color-coded badge.
- Email address and reason for joining are now required fields when registering via telnet or SSH. The registration flow re-prompts until both are provided.

### File Areas

- Added a File Name field to the Add Link form. When adding an external URL link to a file area, users can now set a specific display name for the link. The field is pre-populated from the URL path when a URL is entered, and updated to the page title when Fetch Info is used — both values can be freely edited before submitting.

## Message Reader

The `f` key in the web message reader modal toggles between full-screen and normal view. Previously, pressing Ctrl+F or Cmd+F while the modal was open triggered this toggle instead of opening the browser's native find-in-page dialog. The key handler now checks for Ctrl and Meta modifier keys and passes them through to the browser. Plain `f` still toggles full-screen. This fix applies to both the echomail and netmail message modals.

No configuration changes or database migrations are required.

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

## Terminal Language Selection

The language selector in the telnet and SSH settings screen previously showed a fixed list of three languages (English, French, Spanish). Languages added after that list was written, such as German, did not appear even when their catalog files were present under `config/i18n/`.

The language list is now built dynamically from the installed locale directories, matching the behavior of the web interface. Any locale added by dropping a `config/i18n/<code>/` directory into the installation will appear automatically in both the web and terminal language selectors without a code change.

No database changes or configuration updates are required.

## User Settings

The web settings page now shows a loading overlay while the user's current settings are being loaded. The Save button stays disabled until loading finishes, so users are not shown an interactive form containing temporary default values.

After loading, the page records the initial values and only sends preferences that were changed in the current edit session. This prevents a change to one setting, such as language or theme, from overwriting unrelated settings that live on other tabs. No database changes are required.

## Localized Documentation

The user guide (`/user-guide`) and the admin documentation browser (`/admin/docs`) now serve locale-specific Markdown files when available. Previously, both always loaded the English source regardless of the user's language setting.

The resolution order for any documentation file is:

1. `FILENAME.<locale>.md` — a file matching the user's active locale (e.g. `index.de.md` for German)
2. `FILENAME.md` — the generic English source with no locale suffix
3. `FILENAME.en.md` — an explicit English file as an alternative to the unsuffixed form

To provide a translated version of a documentation file, place the translated file alongside the original using the locale code before the `.md` extension — for example, `docs/userguide/index.de.md` for a German user guide. No configuration changes are required.

This release ships translated user guides for French (`docs/userguide/index.fr.md`), Spanish (`docs/userguide/index.es.md`), Italian (`docs/userguide/index.it.md`), and German (`docs/userguide/index.de.md`). Users with one of those languages set will see the translated guide automatically. All other locales continue to fall back to the English source.

> **Note:** The translated user guides were produced with AI assistance and have not been reviewed by a native speaker. They may contain inaccurate or unnatural phrasing. Corrections from native speakers are welcome.

## Community Wireless Node Map

The Community Wireless Node Map now separates the overall active network count from the marker query used to populate the current map view. Previously, the frontend requested a single `limit=500` result page and displayed the number of returned rows, which made the network count appear capped at 500 even when additional manual entries or MeshCore repeater adverts were present.

The CWN list API now returns `total_all` for the full active, non-expired map total. The frontend displays that value in the statistics panel while loading marker data only for the current Leaflet map bounds. Panning or zooming the map refreshes the visible markers for the new viewport. This keeps the displayed total accurate without forcing the browser to load every CWN row as the database grows.

No manual configuration changes are required. Run `php scripts/setup.php` as part of the normal upgrade process so any pending CWN schema migrations from earlier 1.9.x changes are applied.

## File Areas

The Add Link form now includes a File Name field. Previously, when a user added an external URL link to a file area, the display name stored for that link was derived automatically from the URL path — which produced unhelpful results for URLs where the path is not meaningful, such as YouTube watch links (`/watch`).

The File Name field appears between the URL and Short Description fields. When a URL is entered and the field loses focus, it is pre-populated with the last meaningful path segment if that segment includes a file extension, or with the hostname otherwise. When the user clicks Fetch Info, if the field has not been manually edited, it is updated to a sanitized version of the page title returned by the metadata fetch, which is generally more descriptive than the raw URL path.

Users can edit the file name field freely before submitting. The field is required.

No database changes or configuration updates are required.

## Terminal Registration

### Registration Source

The admin **Manage Users** page now records and displays where each registration request originated. A color-coded badge appears in both the pending registrations table and the registration detail view:

- **web** — submitted through the web registration form
- **telnet** — submitted through the telnet BBS registration flow
- **ssh** — submitted through the SSH BBS registration flow

The upgrade adds a `registration_source` column to the `pending_users` table with a default value of `web`. Existing pending registrations will show as `web` after upgrading. Run `php scripts/setup.php` to apply the migration.

### Required Email and Reason for Terminal Registration

When a user registers through telnet or SSH, email address and reason for joining are now required fields. If either is left blank or the email address fails format validation, the terminal registration flow re-prompts the user until a valid value is entered.

Web registration is not affected — email and reason remain optional for web-based sign-ups.

No configuration changes are required.

## Message Search

The web echomail and netmail message lists auto-refresh in two situations: when a BinkStream event signals that new messages have arrived, and when the browser tab returns to the foreground after being hidden for more than 30 seconds. Previously, both of these refreshes unconditionally reloaded the message list, which replaced any active search results with the full unfiltered list.

The auto-refresh now skips the message list reload when a search is active. Unread counts, echoarea stats, and other sidebar data continue to update in the background so new arrivals are reflected without disturbing the search view. To dismiss search results and return to the live message list, use the Clear Search button.

No database changes or configuration updates are required.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
