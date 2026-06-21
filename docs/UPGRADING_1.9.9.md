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

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically.
