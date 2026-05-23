# Upgrading to 1.9.8

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Web Interface](#web-interface)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Web Interface

- The user-facing echoarea subscription manager at `/subscriptions` now uses a more compact filter layout modeled after `/echolist`, with network filtering and an option to show only interest groups that currently have message traffic.
- Subscribing or unsubscribing from an echoarea in `/subscriptions` now updates in place instead of reloading the page, preserving the current scroll position and active search/filter state.

---

## Web Interface

### Subscription Manager

The `/subscriptions` page now presents its filtering tools in a compact filter panel instead of a long row of controls and per-interest buttons.

The updated page adds:

- a network filter for narrowing the visible echoareas by network
- a compact interest picker instead of a button wall
- an `Only show groups with messages` filter that limits the visible results to interest-grouped areas with message activity
- the search and sort controls inside the same filter panel for a tighter layout
- in-place subscribe/unsubscribe updates that do not reset the current search, filters, or scroll position

This change is user-facing only. It does not alter subscriptions, interest membership, or message access rules.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically.
