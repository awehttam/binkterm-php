# Upgrading to 1.9.9

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Core Platform](#core-platform)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Core Platform

- **Admin -> Users** now includes an **Approved Registration History** lookup inside the registrations panel. Pending registrations and retained approved-registration history now share one tabbed panel, making it easier to review current signup requests and look up previously approved accounts without taking extra vertical space on the page.
- When **Require approval for new users** is disabled, successful self-registrations now create an authenticated session immediately. Web users are signed in right away, and Telnet/SSH users continue directly into the terminal session without reconnecting.
- **Admin -> Auto Feed** now supports arbitrary poster names instead of linked posting accounts, and each feed can target multiple echo areas. Existing feeds are migrated automatically from their old linked-user and single-area settings.
- **Admin -> Auto Feed -> Check now** now runs through the admin daemon instead of spawning `rss_poster.php` directly from the web request. The manual check result is shown in the UI, and a Windows-specific daemon re-entry hang during feed posting has been fixed.
- All four door admin pages (DOS Doors, Native Doors, JS-DOS Doors, WebDoors) now include a **Door Manifest Editor** — a form-based UI for creating and editing door manifests without touching JSON files directly. An **Add New Door** panel lists door directories that do not yet have a manifest so new games can be set up from the admin interface. An **Auto fill with AI** button reads the door's README and NFO files and populates game metadata automatically when an AI provider is configured.
- Echomail area links that use `?echoarea=AREA&domain=DOMAIN` now preserve the selected area correctly instead of internally resolving to `AREA@DOMAIN@DOMAIN`.

---

## Core Platform

### Registration Lookup in Admin Users

The **Admin -> Users** page now combines registration review into a single tabbed panel:

- **Pending Registrations** remains the place to approve or reject new signups
- **Approved Registration History** is a new searchable lookup for retained approved-registration records

The approved-history lookup searches existing retained registration records by:

- requested username
- real name
- email address
- created account username

When you open a registration record, the detail view now also shows review metadata already stored on the record, including:

- review timestamp
- reviewing admin
- admin notes

This change builds on the retained registration-history behavior introduced in the 1.9.x series. It does not add a schema change or require any extra upgrade step beyond the normal file update.

### Automatic Login After Auto-Approved Registration

If **Admin -> BBS Settings -> Features -> Require approval for new users** is disabled, successful self-registration now logs the new account in immediately instead of leaving the user at a manual login step.

- On the web interface, the registration flow now creates the normal authenticated session and redirects the new user into the site.
- On the terminal services, the Telnet/SSH registration flow now continues directly into the authenticated BBS session instead of disconnecting after registration.

This is a behavior change only. It does not add a schema change or require any extra upgrade step beyond the normal file update.

### Auto Feed Check Now Uses the Admin Daemon

The **Check now** action in **Admin -> Auto Feed** now delegates the manual feed run to the admin daemon instead of launching `scripts/rss_poster.php` directly from the web request.

This change fixes two problems with the old/manual-check path:

- web requests were responsible for spawning the CLI feed checker directly
- on Windows, a manual check could hang after posting messages because the daemon-run feed checker could re-enter the admin daemon while it was already busy handling the original command

The manual check flow now:

- runs through the admin daemon
- captures and returns the CLI output to the browser
- avoids the Windows-specific re-entry deadlock during feed posting

This is a behavior fix only. It does not add a schema change or require any extra upgrade step beyond the normal file update.

### Auto Feed Poster Names and Multi-Area Posting

**Admin -> Auto Feed** no longer stores a linked local user account as the visible author for generated posts. Each feed now stores a freeform **Poster Name** string instead.

Existing feeds are migrated automatically:

- the new `poster_name` field is populated from the currently linked account's real name or username
- the old single `echoarea_id` value is moved into the new `auto_feed_source_echoareas` join table

Each feed can now post to more than one echo area. The admin editor uses a searchable grouped checklist for selecting target areas, similar to the Interests editor, and the Auto Feed source list shows local areas as `@ Local`.

The posting path also changed internally:

- Auto Feed now fans each new source article out to every configured target area
- the visible sender name comes from the feed's stored `poster_name`
- the old linked posting-account field is no longer used by Auto Feed configuration

### Door Manifest Editor

All four door admin pages now include a form-based manifest editor that replaces manual JSON file editing for day-to-day door setup.

**Add New Door panel**

Each door admin page has an **Add New Door** panel that scans the door root directory and lists subdirectories that do not yet have a manifest file. Clicking **Create Manifest** on any entry opens the manifest editor pre-targeted at that directory.

**Manifest editor**

The manifest editor provides labelled form fields for every supported manifest field, a file picker for selecting executables and asset paths, and a collapsible **Advanced JSON Preview** panel that reflects the current form state in real time. Manifests saved through the editor are tagged `"managed": "web"`. Manifests that lack this field, or where it is set to another value, are displayed as read-only — the field must be changed to `"web"` before the editor will write to them.

**Auto fill with AI**

When an AI provider is configured, an **Auto fill with AI** button appears alongside the Save button. Clicking it instructs the admin daemon to read the door's text files (README, NFO, install docs, and similar) and send their content to the configured AI provider. The AI extracts game metadata — name, short name, description, author, version, release year, and genre — and pre-populates the matching form fields. The sysop reviews and adjusts the results before saving.

The AI fill feature requires at least one AI provider to be configured via environment variables (`AI_DOOR_MANIFEST_AI_FILL_PROVIDER` / `AI_DOOR_MANIFEST_AI_FILL_MODEL`, or the system default provider). If no provider is available the button returns a service-unavailable error.
### Echomail Query Link Area Selection

Echomail URLs that specify an area with query parameters now initialize the selected area consistently with path-style area URLs.

Previously, a URL such as `/echomail?echoarea=AREA&domain=DOMAIN` could be normalized as `AREA@DOMAIN` by the route and then have `@DOMAIN` appended again by the page template. The browser URL remained unchanged, but the in-page selected area became `AREA@DOMAIN@DOMAIN`, which did not match any echo area and could leave the page showing the all-areas view.

The query form now keeps the area tag and domain separate for template initialization, matching `/echomail/AREA@DOMAIN` behavior.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically.
