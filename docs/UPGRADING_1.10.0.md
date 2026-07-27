# Upgrading to 1.10.0

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
  - [Echomail Unread/Read Filter (Threaded View)](#echomail-unreadread-filter-threaded-view)
  - [Auto Feed (RSS/Bluesky) Watermark Fix](#auto-feed-rssbluesky-watermark-fix)
- [Echomail Unread/Read Filter (Threaded View)](#echomail-unreadread-filter-threaded-view-1)
- [Auto Feed (RSS/Bluesky) Watermark Fix](#auto-feed-rssbluesky-watermark-fix-1)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Echomail Unread/Read Filter (Threaded View)

- The **Unread** and **Read** tabs on an echo area's message list now show a flat list of just the matching messages instead of trying to preserve conversation threading. Threading unread/read results could mix in already-read messages, hide genuinely unread ones inside a conversation, and slow page loads considerably in areas with deep reply chains.

### Auto Feed (RSS/Bluesky) Watermark Fix

- Auto feed sources (`scripts/rss_poster.php`) now track a second watermark, the publish date of the last posted article, alongside the existing GUID watermark. This prevents an entire feed from being reposted when a single article disappears from the source feed (for example, an editor unpublishing it) even though the feed itself was never actually reset.

---

## Echomail Unread/Read Filter (Threaded View)

When browsing an echo area in threaded (conversation) view, switching to the **Unread** or **Read** tab now shows a flat list of just the messages matching that filter, rather than the full conversation tree.

Previously, filtering by unread or read status in threaded view worked by matching whole conversations, which caused two problems: a thread whose first message had already been read could hide an unread reply buried further down, and conversely a conversation containing both read and unread messages would show every message in it — including ones you'd already read — under the Unread tab. Areas with long reply chains could also take a long time to load under this filter.

The Unread and Read tabs now filter and display individual messages directly, the same way the non-threaded message list already does. Threading is unaffected for the **All**, **To Me**, and **Saved** tabs.

## Auto Feed (RSS/Bluesky) Watermark Fix

Auto feed sources deduplicate articles by remembering the GUID of the last article they posted. On each poll, the source feed is scanned for that GUID; anything appearing before it in the feed is treated as new. If the GUID can no longer be found anywhere in the feed, the script previously assumed the whole feed had been reset or cleared, and reposted every article currently in the feed to every configured echo area.

This assumption broke when a single article dropped out of a source feed without the feed actually being reset — for example, when a site editor unpublished the most recent article after it was already picked up, causing it to vanish from that site's RSS output on the next poll. Because the stored GUID no longer matched anything in the feed, the script incorrectly treated this as a full feed reset and reposted every remaining article as if it were new.

Auto feed sources now also store the publish date of the last posted article in a new `last_article_pubdate` column. When the stored GUID can't be located in the current feed, the script falls back to this publish-date watermark and only treats articles newer than it as new, instead of reposting the entire feed. Feeds that have never posted anything yet, or that were tracked before this column existed, keep the original "treat everything as new" behavior for their very first check after upgrading.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
