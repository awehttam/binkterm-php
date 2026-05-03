# Upgrading to 1.9.5

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Bulletin Manager](#bulletin-manager)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Content and Display

- Added a Bulletin Manager for sysop-authored BBS bulletins. Bulletins can be managed from the admin web interface and displayed to users in the web, telnet, and SSH BBS flows.
- Added a BBS setting for bulletin display mode. Sysops can choose whether bulletins are shown once until read, or shown once at the start of each login session.

## Bulletin Manager

Version 1.9.5 introduces a built-in Bulletin Manager for short sysop announcements, notices, and other BBS-facing updates. Bulletins are managed from **Admin -> Ads and Bulletins -> Bulletins**. Each bulletin has a title, body, display format, sort order, active flag, and optional active date range.

Bulletins support plain text and Markdown. Active bulletins are shown to users through the web bulletin viewer and through the terminal BBS experience over telnet and SSH. The dashboard also includes a bulletin card so users can see when unread bulletins are waiting.

By default, bulletins use the existing read-tracking behavior: a user sees each active bulletin until it has been marked read. Sysops can change this in **Admin -> BBS Settings** with the Bulletin Display Mode option:

- **Display once** shows unread bulletins until each user has read or skipped them.
- **Always display** shows active bulletins once at the start of each new login session. It does not repeatedly interrupt users every time they return to the dashboard during the same session.

The upgrade creates the bulletin storage tables and read-tracking table through `php scripts/setup.php`. No manual database changes are required.

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
