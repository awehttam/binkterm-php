# Advertising

BinktermPHP includes a built-in ANSI advertising system that lets sysops promote services, events, and other BBSes directly within their node — both on the web dashboard and through scheduled echomail posts into active message areas.

The system is designed around the way FTN networks actually work. Ads are authored in classic ANSI art, stored in a central library, and delivered through two complementary channels: a rotating carousel on the dashboard for visitors browsing the web interface, and automated campaign posts that reach users reading echomail across the network. A single ad can appear in both places simultaneously, or be restricted to one channel.

Campaigns give sysops fine-grained control over timing and reach. Each campaign targets one or more echomail areas and fires on a configurable weekly schedule with per-timezone awareness. Weighted ad selection within a campaign means you can favor certain ads over others without manual intervention. Every post — whether triggered automatically by the scheduler or run manually — is recorded in a history log, making it easy to audit what was sent, when, and to which areas.

The ad library tracks content hashes so duplicate uploads are flagged before they crowd out your rotation. Existing flat-file ads from the legacy `bbs_ads/` directory are migrated into the database automatically on upgrade, so you don't lose content switching from the old system.

Ads are managed from **Admin -> Ads -> Advertisements**. Campaigns are managed from **Admin -> Ads -> Ad Campaigns**.

## Features

- Upload ANSI ads directly into the ad library
- Edit ad metadata and ANSI content in the browser
- Preview ANSI ads in a modal before saving
- Tag ads with freeform labels such as `general`, `door`, `network`, or `event`
- Choose which ads are eligible for the dashboard carousel
- Build auto-posting campaigns with multiple targets
- Schedule campaigns by day of week, time, and timezone
- Track post history for manual and automatic posts

## Ad Library

Each ad is stored in the database with:

- title
- slug
- description
- ANSI content
- content hash for duplicate warning
- enabled/disabled state
- dashboard eligibility
- auto-post eligibility
- tags

Duplicate uploads are allowed. If the ANSI payload matches an existing ad, the system warns but does not block the upload.

## Dashboard Ads

The dashboard advertising window pulls from ads marked for dashboard display.

- Rotation is per PHP session
- Left and right arrow controls move through eligible ads
- Keyboard left/right navigation is supported
- Duplicate ANSI payloads are de-duplicated in the displayed set

If only one eligible ad exists, the dashboard simply shows that ad without carousel controls.

## Campaigns

Campaigns let the sysop post ads automatically into echomail areas.

Each campaign can define:

- a posting user
- one or more active schedules
- one or more active targets
- one or more assigned ads with weights

Each target contains:

- echoarea tag
- domain
- subject template
- enabled/disabled state

Each schedule contains:

- selected days of the week
- time of day
- timezone
- enabled/disabled state

The campaign runner chooses an eligible ad using weighted random selection.

## Scheduler Integration

Campaigns are processed automatically by `scripts/binkp_scheduler.php`.

The scheduler checks due schedule slots and attempts posts for each active campaign target. Matching is based on the configured local schedule time with a grace window for slightly late runs.

For manual testing or one-off runs, you can also use:

```bash
php scripts/run_ad_campaigns.php
php scripts/run_ad_campaigns.php --campaign-id=3
php scripts/run_ad_campaigns.php --dry-run
```

## Post History

The **Ad Campaigns** page includes a post history table showing:

- post time
- campaign
- ad
- target
- status
- subject
- posting user
- error text when a post fails

Both manual and automatic campaign runs are recorded in the same history log.

## Echomail Posting Notes

- Outbound ad posts strip SAUCE before the message body is posted
- Subject templates are stored per target
- Local-only areas are posted locally without uplink distribution

