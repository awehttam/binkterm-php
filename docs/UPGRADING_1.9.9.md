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
- **Admin -> Auto Feed -> Check now** now runs through the admin daemon instead of spawning `rss_poster.php` directly from the web request. The manual check result is shown in the UI, and a Windows-specific daemon re-entry hang during feed posting has been fixed.

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

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically.
