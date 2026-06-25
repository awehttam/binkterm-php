# Upgrading to 1.9.9

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Core Platform](#core-platform)
- [Auto Feed Full Article Content](#auto-feed-full-article-content)
- [Re-post Attribution Header](#re-post-attribution-header)
- [Security](#security)
- [Messaging Menu Unread Counts](#messaging-menu-unread-counts)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Core Platform

- **Admin -> Users** now includes an **Approved Registration History** lookup inside the registrations panel. Pending registrations and retained approved-registration history now share one tabbed panel, making it easier to review current signup requests and look up previously approved accounts without taking extra vertical space on the page.
- When **Require approval for new users** is disabled, successful self-registrations now create an authenticated session immediately. Web users are signed in right away, and Telnet/SSH users continue directly into the terminal session without reconnecting.
- **Admin -> Auto Feed** now supports arbitrary poster names instead of linked posting accounts, and each feed can target multiple echo areas. Existing feeds are migrated automatically from their old linked-user and single-area settings.
- **Admin -> Auto Feed -> Check now** now runs through the admin daemon instead of spawning `rss_poster.php` directly from the web request. The manual check result is shown in the UI, and a Windows-specific daemon re-entry hang during feed posting has been fixed.
- Echomail area links that use `?echoarea=AREA&domain=DOMAIN` now preserve the selected area correctly instead of internally resolving to `AREA@DOMAIN@DOMAIN`.
- The archive entry preview endpoint now rejects absolute paths in addition to `..` traversal sequences, closing a gap where a specially crafted archive with absolute-path entries could cause the extraction tool to write outside the designated temp directory.

### Auto Feed Full Article Content

- The Auto Feed RSS poster now reads the full article body from feeds that provide it. For RSS 2.0 and RSS 1.0 feeds, `<content:encoded>` (the Content Module field used by WordPress, Ghost, Substack, and most modern CMS platforms) is preferred over `<description>`, which typically contains only an excerpt. For Atom feeds, `<content>` is now correctly preferred over `<summary>` (these were previously swapped). Bodies longer than 16 000 characters are truncated to fit within practical FTN message size limits.

### Re-post Attribution Header

- When reposting a message, the compose editor now opens with a standardised attribution block prepended to the body, identifying the original area, sender, subject, and date. See the full section below for the header format.

### Messaging Menu Unread Counts

- The **Messaging** nav menu now shows unread message counts next to each item. The parent entry shows the combined total; **Netmail** shows the number of unread messages in your inbox; **Echomail** shows new messages using the same badge mode configured on your dashboard (new since last visit, or true unread). Counts clear automatically when you navigate to the relevant section.

### Security

- **PacketBBS bridge session binding**: Each radio-node PacketBBS session is now bound to the bridge that created it. A registered bridge can no longer submit commands or poll pending messages on behalf of a session established through a different bridge. A database migration adds the binding column to `packet_bbs_sessions`.

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

### Archive Preview Path Validation

The archive entry preview endpoint (`/api/files/{id}/archive-preview`) now rejects entry paths that are absolute in addition to the existing `..` traversal check.

Previously, a crafted archive containing entries stored with absolute paths (e.g. `/var/www/html/evil.php` or `C:\inetpub\wwwroot\evil.php`) could pass the `..` check and be forwarded to the `unzip` or `7z` extraction tool. On installations with older versions of these tools, the tool could write outside the designated temp directory rather than treating the absolute path as relative to the extraction root.

The fix validates entry paths in two places:

- the API route rejects any path that begins with `/`, `\`, or a Windows drive prefix (`C:\`, `D:/`, etc.) before invoking the reader
- `ArchiveReader::extractZipViaShell()` and `ArchiveReader::extract7z()` apply the same check internally as a defense-in-depth guard

### Echomail Query Link Area Selection

Echomail URLs that specify an area with query parameters now initialize the selected area consistently with path-style area URLs.

Previously, a URL such as `/echomail?echoarea=AREA&domain=DOMAIN` could be normalized as `AREA@DOMAIN` by the route and then have `@DOMAIN` appended again by the page template. The browser URL remained unchanged, but the in-page selected area became `AREA@DOMAIN@DOMAIN`, which did not match any echo area and could leave the page showing the all-areas view.

The query form now keeps the area tag and domain separate for template initialization, matching `/echomail/AREA@DOMAIN` behavior.

## Security

## Messaging Menu Unread Counts

The **Messaging** nav menu now displays unread message counts alongside each entry.

- The **Messaging** parent item shows the combined total of unread netmail and new echomail.
- The **Netmail** sub-item shows the number of messages in your inbox that you have not yet read.
- The **Echomail** sub-item shows new messages using the same badge mode you have selected on your dashboard — either messages that arrived since your last visit to the echomail section, or a true unread count across your subscribed areas (configurable in dashboard settings).

Counts appear in parentheses next to the label (e.g. `Messaging (41)`, `Netmail (36)`, `Echomail (5)`) and update in real time via BinkStream without requiring a page refresh. A count clears automatically when you navigate to the corresponding section.

## Security

## Re-post Attribution Header

When a message is reposted to another area or forwarded to netmail, the compose editor now opens with an attribution block at the top of the body:

```
--- Re-posted from: AREANAME@domain
From: SenderName@1:234/56
Subject: Original Subject
Date: June 25, 2026
---
```

- **Re-posted from** — the echomail area tag and domain the original message came from, or `Netmail` when reposting a netmail message.
- **From** — the original sender's name and FTN node address. If no node address is recorded the name alone is shown.
- **Subject** — the original subject line, unchanged.
- **Date** — the date the original message was written.

The original message body follows the header block. Recipients can immediately see the provenance of the reposted content without consulting kludge lines.

## Auto Feed Full Article Content

The Auto Feed RSS poster (`scripts/rss_poster.php`) now reads the full article body from feeds that carry it, rather than always falling back to the short excerpt.

**RSS 2.0 and RSS 1.0 (RDF) feeds** — body field priority:

1. `<content:encoded>` (RSS Content Module, namespace `http://purl.org/rss/1.0/modules/content/`) — the full HTML body, used by WordPress, Ghost, Substack, Feedburner, and most modern CMS platforms
2. `<description>` — short excerpt or first paragraph (previous behaviour)
3. `<dc:description>` (Dublin Core) — fallback for academic and library publishers

**Atom feeds** — the priority of `<content>` and `<summary>` was previously inverted. The correct order per the Atom spec is:

1. `<content>` — full article body
2. `<summary>` — short excerpt fallback

Bodies are truncated to 16 000 characters before posting to stay within practical FTN message size limits; a `[... truncated ...]` marker is appended when truncation occurs. The field actually used for each item is written to the Auto Feed log at info level to aid debugging.

## Security

### PacketBBS Bridge Session Binding

The PacketBBS API now enforces that a radio-node session can only be accessed by the bridge that originally established it.

Previously, the `/api/packetbbs/command` and `/api/packetbbs/pending` endpoints authenticated the request using the `bridge_node_id` field but loaded session state using the separately supplied `node_id`. Because these two values were not tied together, any registered bridge with a valid API key could supply a different `node_id` in the request body to access another node's active authenticated session — reading that user's netmail, posting echomail as that user, or intercepting queued outbound mail notifications.

Each `packet_bbs_sessions` row now stores the `bridge_node_id` that first created it. Subsequent requests for that node must present the same bridge identity. Requests from a different bridge are rejected with an error before any session state is read or modified. Sessions created before this upgrade have a null bridge binding and will accept the first bridge that contacts them, after which the binding is fixed.

A database migration adds the `bridge_node_id` column to `packet_bbs_sessions` and is applied automatically by `php scripts/setup.php`.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically.
