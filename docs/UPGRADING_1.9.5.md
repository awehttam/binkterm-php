# Upgrading to 1.9.5

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Inline Media Player](#inline-media-player)
- [Networks Admin](#networks-admin)
- [Per-Network and Per-Area Media Controls](#per-network-and-per-area-media-controls)
- [Auto Feed](#auto-feed)
- [Message Reader](#message-reader)
- [Bulletin Manager](#bulletin-manager)
- [Terminal Message Editor](#terminal-message-editor)
- [Terminal Message Encoding](#terminal-message-encoding)
- [Terminal Echomail Display](#terminal-echomail-display)
- [User Settings](#user-settings)
- [Terminal Language Selection](#terminal-language-selection)
- [Localized Documentation](#localized-documentation)
- [Community Wireless Node Map](#community-wireless-node-map)
- [BBS Directory CLI](#bbs-directory-cli)
- [File Areas](#file-areas)
- [Terminal Registration](#terminal-registration)
- [Message Search](#message-search)
- [Echoarea Message Count](#echoarea-message-count)
- [Database Migration Fix](#database-migration-fix)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Inline Media Player

- Added an inline media player to the web message reader. URLs in echomail and netmail message bodies that point to supported video platforms, oEmbed-compatible services, or raw media files can be automatically embedded as playable inline players. Supported platforms include YouTube, Odysee, Rumble, BitChute, Brighteon, PeerTube, Bastyon, Twitter/X, SoundCloud, TikTok, and ReverbNation. Direct links to video, audio, image, tracker module, SID, and MIDI files are also embedded inline.
- The user preference formerly called "Image load mode" has been renamed "Inline media rendering" and controls whether embeds load automatically or require a user click to expand. All existing accounts are migrated to click-to-expand on upgrade. The default for new accounts is also click-to-expand.
- Sysops can globally enable or disable the media player and toggle individual providers from **Admin → Appearance → Message Reader**. The media player is **disabled by default** on fresh installations.
- Added a **Networks** admin page that stores network-level message policy settings separately from BinkP uplink connection details. The upgrade migrates existing uplink-level `allow_markup`, `allow_media`, `default_charset`, and `posting_name_policy` values into network rows and removes those migrated keys from `config/binkp.json`.
- Added per-network and per-area inline media controls. Sysops manage the network default in **Admin → Networks**. Each echo area in the echo area manager has an "Inline Media Rendering" setting that can be set to inherit from its network, or explicitly enabled or disabled.
- The user settings page now shows a notice when the sysop has disabled inline media globally, rather than presenting controls that have no effect.

### Echoarea Message Count

- Fixed the admin echomail bulk-delete endpoint so it recalculates `echoareas.message_count` after removing messages. Previously, bulk-deleting messages left the cached counter inflated, causing the echoarea list to report more messages than actually exist. Installations where bulk deletions were performed before this upgrade can correct the drift by running `php scripts/check_message_counts.php --fix`.
- Added a `--fix` flag to `scripts/check_message_counts.php`. With `--fix`, the script recalculates and corrects any drifted `message_count` values in addition to reporting them.

### BBS Directory CLI

- Added `scripts/dlimport_bbslist.php`, a cron-friendly wrapper that downloads the current monthly Telnet BBS Guide archive (`ibbsMMYY.zip`) from `https://www.telnetbbsguide.com/bbslist/` and runs `scripts/import_bbslist.php` against the downloaded ZIP.
- The script supports explicit month/year selection, explicit filenames, dry-run imports, and quiet mode for scheduled jobs.

### Auto Feed

- Added a source type field to Auto Feed so the existing cron-driven poster can support additional source adapters beyond RSS/Atom. Existing feed rows are migrated to `rss`.
- Added a Bluesky source adapter. Public Bluesky profile URLs can be configured in **Admin → Auto Feed** and are polled by `scripts/rss_poster.php`. Posts without media attachments are skipped; media URLs from Bluesky image embeds are included in the generated echomail body so the inline media renderer can process them.
- The inline media renderer now recognizes Bluesky CDN image URLs, including CDN paths that do not end with a traditional image file extension.
- Auto Feed messages now use `BinktermPHP Auto Feed` for the generated tagline and tearline component instead of the older RSS-specific text.

## Auto Feed

Auto Feed now stores a `source_type` for each configured source. The database migration adds this column with a default of `rss`, so existing RSS/Atom sources continue to run without manual changes.

To add a Bluesky source, open **Admin → Auto Feed**, create or edit a feed source, set **Source Type** to **Bluesky**, and use a public profile URL such as `https://bsky.app/profile/example.bsky.social`. The existing cron command remains the same:

```bash
php scripts/rss_poster.php
```

The Bluesky adapter uses the public author feed endpoint, skips reposts and text-only posts, and posts only source items that expose image/media URLs.

Messages posted by Auto Feed now identify the poster component as `BinktermPHP Auto Feed` in generated message metadata, including the visible tearline at the bottom of the message body.

### Database Migration Fix

- Fixed a regression from the set-based migration tracker change. Legacy semantic-version migrations (e.g. `v1.4.9`) are included in the base `postgres_schema.sql` and were never recorded individually in `database_migrations`, causing them to re-apply on upgrade. The tracker now skips any legacy migration at or below the highest already-recorded version, restoring the original behavior.

### Message Search

- Fixed echomail and netmail search results being replaced by the full message list when the background auto-refresh fired (on new-message events from BinkStream or when the browser tab was restored after being hidden). Search results now stay on screen until explicitly cleared.

### Message Reader

- Fixed the message reader modal so the `f` key shortcut for toggling full-screen no longer intercepts the browser's Ctrl+F (or Cmd+F on Mac) find shortcut. Ctrl+F and Cmd+F now open the browser's native find dialog as expected. Applies to both echomail and netmail message modals.

### Content and Display

- Added a Discord invite link option to the Messaging menu. Sysops can paste a Discord server invite URL in **Admin → Appearance → Message Reader**. When set, a Discord entry appears in the Messaging navigation dropdown for all users.
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
- Fixed URL metadata fetching for YouTube links. The Fetch Info button now uses the YouTube oEmbed API instead of scraping the page HTML, resolving an issue where production servers received generic placeholder metadata rather than the actual video title and thumbnail.

## Inline Media Player

The web message reader now detects URLs in echomail and netmail message bodies and embeds them as inline players. No changes to how messages are stored or sent are required — the embedding happens entirely in the browser when the message is rendered.

### Supported platforms

| Platform | Type |
|---|---|
| YouTube | Inline iframe |
| Odysee | Inline iframe |
| Rumble | Inline iframe |
| BitChute | Inline iframe |
| Brighteon | Inline iframe |
| PeerTube | Inline iframe |
| Bastyon video posts | Server-resolved PeerTube iframe |
| Twitter / X | oEmbed (client-side) |
| SoundCloud | oEmbed (client-side) |
| TikTok | oEmbed (client-side) |
| ReverbNation | oEmbed (client-side) |
| Video files (`mp4`, `webm`, `ogv`, `mov`) | Native HTML5 player |
| Audio files (`mp3`, `flac`, `ogg`, `opus`, `wav`, `m4a`, `aac`) | Native HTML5 player |
| Tracker module files (`xm`, `it`, `s3m`, `mod`, `stm`, `amf`, `669`, `mptm`) | Browser tracker player |
| SID files (`sid`) | Browser SID player |
| MIDI files (`mid`, `midi`) | Browser MIDI player |
| Image files (`png`, `webp`, `gif`, `jpg`, `jpeg`, `svg`) | Inline image |

oEmbed embeds are resolved client-side: the browser fetches the oEmbed endpoint directly. If the direct request fails due to CORS restrictions, the player falls back to the server-side `/api/media/embed` proxy.

Rumble public URLs are resolved through Rumble's oEmbed API because the public video ID is not always the same as the iframe embed ID. PeerTube watch URLs are converted to their `/videos/embed/` form directly. Bastyon video post URLs are resolved server-side through PocketNet RPC nodes; the on-chain `peertube://` video reference is converted to a PeerTube embed URL, because the public Bastyon page is a JavaScript application that does not work as a direct iframe.

Tracker module playback is provided in the browser through a bundled libopenmpt-based AudioWorklet player. SID playback uses the existing browser SID emulator. MIDI playback uses a lightweight browser synth fallback, which is suitable for previewing MIDI note data but is not a full General MIDI soundfont renderer. These retro audio formats are also supported in the file previewer, including previews of supported files inside archives.

### User setting: Inline media rendering

Each user can control how embeds appear through the **Inline media rendering** setting on their Settings page:

- **Automatically render rich media** — embeds load and appear immediately when a message is opened. This is the new default for all accounts.
- **Click to expand** — a "▶ Load player" button appears in place of each embed. The player loads only when the user clicks the button.

This setting was previously named "Image load mode" and controlled only whether Markdown inline images loaded automatically. The upgrade migrates all existing accounts to the click-to-expand mode. Users who prefer automatic rendering can switch on their Settings page.

The `image_load_mode` key in `users_meta` is renamed to `media_render_mode` and all values are set to `click` by the migration.

### Admin settings

The media player can be configured from **Admin → Appearance → Message Reader**:

- **Enable inline media player** — turns the feature on or off globally for all users. The media player is **disabled by default** on new installations. Enabling it activates inline rendering for all networks and areas that do not have the feature explicitly disabled. The feature can be toggled at any time without data loss.
- **Enabled providers** — individual toggles for each supported platform. Disabling a provider prevents the server from resolving embeds for that platform and hides any client-side embed injection for it.

These settings are stored in `data/appearance.json` and take effect immediately without restarting any daemons.

## Networks Admin

Version 1.9.5 adds **Admin → Networks** for FTN network metadata and network-level message policy settings. Built-in rows are created for common networks such as FidoNet, LovlyNet, ArakNet, FSXNet, RetroNet, DoveNet, MicroNet, BattleNet, SciNet, and WWIVNet. Sysops can also create custom network rows for private nets and local domains.

Each network row owns these settings:

- Display name, description, and website URL
- Markdown/StyleCodes allowance
- Inline media allowance
- Default message charset override
- Posting-name policy

Uplink connection details remain in **Admin → BinkP Configuration** and in `config/binkp.json`. Uplinks now select a configured network by domain instead of carrying their own copies of the network-level flags. Multiple uplinks can use the same domain, such as a primary and backup hub, and they will share the same network policy settings.

The upgrade migrates any existing uplink-level `allow_markup`, `allow_markdown`, `allow_media`, `default_charset`, and `posting_name_policy` values from `config/binkp.json` into the `networks` table. If multiple uplinks for the same domain have conflicting values, the first uplink in the file wins and a warning is written to `server.log` for sysop review. After migration, those keys are stripped from `config/binkp.json` and future BinkP config saves will not write them back.

The network editor includes a dedicated change-domain control beside the domain label. Changing a domain cascades to matching `echoareas.domain` values and matching BinkP uplink domains so the network row, echo areas, and uplink connection records stay aligned.

## Per-Network and Per-Area Media Controls

When the inline media player is enabled globally, sysops can restrict or allow it at the network and echo area level independently.

### Network-level control

Each network in **Admin → Networks** has an **Allow Inline Media** setting. When unchecked, inline media embeds are suppressed for all messages received from or associated with that network unless an echo area explicitly overrides it. The setting defaults to unchecked for new networks; inline media must be explicitly enabled per network.

The setting is stored as `allow_media` in the `networks` table. The old per-uplink `allow_media` key in `config/binkp.json` is migrated automatically and then removed from the file.

### Area-level control

Each echo area in the echo area manager has an **Inline Media Rendering** setting with three options:

- **Inherit from network** (default) — the area follows the allow/deny setting of its associated network. New areas default to this.
- **Enabled** — inline media is allowed for this area regardless of the network setting.
- **Disabled** — inline media is suppressed for this area regardless of the network setting.

The setting is stored as `allow_media` on the `echoareas` table (nullable boolean; `NULL` means inherit). Run `php scripts/setup.php` to apply the migration that adds this column.

### Resolution order

When a message is loaded, the media player decision follows this precedence from highest to lowest:

1. Global disabled (`Admin → Appearance`) → no media, regardless of network or area.
2. Area `allow_media` is explicitly set → use that value.
3. Area `allow_media` is inherit → use the network's `allow_media` value.

## Message Reader

The `f` key in the web message reader modal toggles between full-screen and normal view. Previously, pressing Ctrl+F or Cmd+F while the modal was open triggered this toggle instead of opening the browser's native find-in-page dialog. The key handler now checks for Ctrl and Meta modifier keys and passes them through to the browser. Plain `f` still toggles full-screen. This fix applies to both the echomail and netmail message modals.

No configuration changes or database migrations are required.

### Discord Invite Link

A new Discord field is available in **Admin → Appearance → Message Reader**. When a Discord server invite URL is entered and saved, a Discord link appears in the Messaging navigation dropdown for all users. Leaving the field blank hides the link entirely.

No database changes or configuration file updates are required.

## Bulletin Manager

Version 1.9.5 introduces a built-in Bulletin Manager for short sysop announcements, notices, and other BBS-facing updates. Bulletins are managed from **Admin -> Ads and Bulletins -> Bulletins**. Each bulletin has a title, body, display format, sort order, active flag, and optional active date range.

Bulletins support plain text and Markdown. Active bulletins are shown to users through the web bulletin viewer and through the terminal BBS experience over telnet and SSH. The dashboard also includes a bulletin card so users can see when unread bulletins are waiting.

By default, bulletins use the existing read-tracking behavior: a user sees each active bulletin until it has been marked read. Sysops can change this in **Admin -> BBS Settings** with the Bulletin Display Mode option:

- **Display once** shows unread bulletins until each user has read or skipped them.
- **Always display** shows active bulletins once at the start of each new login session. It does not repeatedly interrupt users every time they return to the dashboard during the same session.
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

The **Inline Media Rendering** preference on the Settings page now shows a notice instead of the render-mode radio buttons when the sysop has disabled the inline media player globally. This makes it clear to users that the setting has no effect in its current state rather than presenting controls that cannot do anything.

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

## BBS Directory CLI

The BBS Directory importer can now be run directly against the monthly Telnet BBS Guide download without manually fetching the ZIP first:

```bash
php scripts/dlimport_bbslist.php
```

The script builds the monthly filename as `ibbsMMYY.zip`, downloads it from `https://www.telnetbbsguide.com/bbslist/` into `data/bbslist/`, and then invokes `scripts/import_bbslist.php` with the downloaded archive.

For a scheduled dry run or a specific archive:

```bash
php scripts/dlimport_bbslist.php --dry-run
php scripts/dlimport_bbslist.php --file=ibbs0526.zip
php scripts/dlimport_bbslist.php --month=05 --year=2026 --quiet
```

No database schema changes or configuration updates are required. The underlying import behavior is unchanged: BBS entries are upserted by name, local entries are not modified, and no deletions are performed.

## File Areas

The Add Link form now includes a File Name field. Previously, when a user added an external URL link to a file area, the display name stored for that link was derived automatically from the URL path — which produced unhelpful results for URLs where the path is not meaningful, such as YouTube watch links (`/watch`).

The File Name field appears between the URL and Short Description fields. When a URL is entered and the field loses focus, it is pre-populated with the last meaningful path segment if that segment includes a file extension, or with the hostname otherwise. When the user clicks Fetch Info, if the field has not been manually edited, it is updated to a sanitized version of the page title returned by the metadata fetch, which is generally more descriptive than the raw URL path.

Users can edit the file name field freely before submitting. The field is required.

No database changes or configuration updates are required.

### YouTube Link Metadata

The Fetch Info button now uses the YouTube oEmbed API when the URL points to a YouTube video (`youtube.com` or `youtu.be`). Previously, metadata was retrieved by scraping the YouTube page HTML. This worked on development machines with residential internet connections but returned generic placeholder values on production servers, because YouTube restricts HTML scraping from datacenter IP addresses.

The oEmbed API is a public endpoint that returns the actual video title and thumbnail from any server. Non-YouTube URLs continue to use the existing Open Graph HTML scraper.

No configuration changes are required.

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

## Echoarea Message Count

The `echoareas.message_count` column is a cached counter incremented when messages are posted and decremented when they are individually deleted. The admin-only bulk message delete endpoint (`POST /api/messages/echomail/delete`) was deleting rows directly from the `echomail` table without updating this counter. Echoareas where messages had been bulk-deleted would show an inflated count in the echoarea list that did not match the actual number of stored messages.

The endpoint now recalculates `message_count` from an accurate `COUNT(*)` for each echoarea touched by the deletion. Using a recalculation rather than an incremental adjustment means the result is exact even when earlier drift had already accumulated.

To correct any counts that drifted before this upgrade, run:

```bash
php scripts/check_message_counts.php --fix
```

Without `--fix`, the script reports discrepancies without modifying any data. Adding `--fix` was new in this release.

## Database Migration Fix

The migration tracking system was changed in 1.9.4 from a version-comparison approach (skip any migration whose version number is less than or equal to the highest recorded version) to a set-based approach (skip any migration whose version string appears in the `database_migrations` table). This change was made to support the new timestamp-based migration IDs alongside legacy semantic version IDs.

All installations are initially populated from the base schema dump (`postgres_schema.sql`). That schema already includes the changes from many legacy semantic-version migrations, but those individual migration files are not necessarily recorded one-by-one in `database_migrations`. After the tracker changed to set-based checks, those already-baked-in legacy migrations could appear as pending and be re-executed.

The upgrade script now applies a backward-compatible rule: any legacy semantic-version migration file whose version is at or below the highest semantic version already recorded in `database_migrations` is considered already applied and is skipped, even if it does not appear in the table. Timestamp-based migrations are not affected by this rule and continue to be tracked only by explicit table entries.

No manual intervention is required. The fix takes effect automatically the next time `php scripts/setup.php` is run.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
