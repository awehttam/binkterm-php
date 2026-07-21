# Upgrading to 1.10.0

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
  - [Echomail Unread/Read Filter (Threaded View)](#echomail-unreadread-filter-threaded-view)
- [Echomail Unread/Read Filter (Threaded View)](#echomail-unreadread-filter-threaded-view-1)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Echomail Unread/Read Filter (Threaded View)

- The **Unread** and **Read** tabs on an echo area's message list now show a flat list of just the matching messages instead of trying to preserve conversation threading. Threading unread/read results could mix in already-read messages, hide genuinely unread ones inside a conversation, and slow page loads considerably in areas with deep reply chains.

---

## Echomail Unread/Read Filter (Threaded View)

When browsing an echo area in threaded (conversation) view, switching to the **Unread** or **Read** tab now shows a flat list of just the messages matching that filter, rather than the full conversation tree.

Previously, filtering by unread or read status in threaded view worked by matching whole conversations, which caused two problems: a thread whose first message had already been read could hide an unread reply buried further down, and conversely a conversation containing both read and unread messages would show every message in it — including ones you'd already read — under the Unread tab. Areas with long reply chains could also take a long time to load under this filter.

The Unread and Read tabs now filter and display individual messages directly, the same way the non-threaded message list already does. Threading is unaffected for the **All**, **To Me**, and **Saved** tabs.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
