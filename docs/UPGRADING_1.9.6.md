# Upgrading to 1.9.6

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Messaging](#messaging)
- [Shared Pages](#shared-pages)
- [Documentation](#documentation)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Messaging

- Added a new web-reader **Re-Post** action for echomail and netmail. Re-post opens the composer with the original message body, preserves the original message charset and markup format, prefixes the subject with `FWD:`, and leaves the recipient or target area unset so the user must choose where to send it.
- Adjusted echomail re-post flow so the compose screen does not preselect the original or current echo area. Users now explicitly choose the destination area for the new post.
- After sending an echomail re-post, the web UI now returns the user to the area they launched the re-post from rather than redirecting to the newly selected destination area.
- Bumped the web service worker cache version so browsers refresh the updated compose and reader JavaScript immediately after deployment.

### Shared Pages

- Fixed shared message pages so they no longer emit two `og:description` tags. Social previews now use the shared message subject/body excerpt instead of also including the site-wide description from the global appearance settings.
- Applied the same metadata override pattern to shared file pages so file shares also emit a single page-specific `og:description` value.

### Documentation

- Expanded the user guide with a dedicated message reader section that explains the web reader interface and lists the supported keyboard shortcuts for both echomail and netmail readers. The translated user guide variants were updated to include the same section.

---

## Messaging

The web message readers for echomail and netmail now include a **Re-Post** action alongside the existing reply tools. Re-post is intended for taking an existing message and sending it again as a new message rather than as a threaded reply.

When a user chooses **Re-Post**, the composer opens with the original message text already inserted, the original message charset preselected, and the original markup mode restored when the source message used Markdown or StyleCodes. The subject is copied with an added `FWD:` prefix. Netmail re-posts leave the recipient fields blank, and echomail re-posts leave the area selector blank, so the user must deliberately choose the new destination before sending.

For echomail, the send flow now keeps track of which area the user started from. After the repost is sent, the browser returns to that original area view instead of navigating into the newly selected target area. This keeps the user in their previous reading context after cross-posting or moving content to another echo area.

The service worker cache version was also incremented for this change set so browsers download the updated reader and compose assets immediately after upgrade. No manual cache-clearing steps are required beyond the normal upgrade process.

## Shared Pages

Shared message pages now override the default description metadata provided by the site shell templates. Previously, a shared message page could output both the global site description from **Admin -> Appearance** and a second message-specific `og:description` tag based on the shared post content. Link preview crawlers that saw both tags could pick the wrong one, causing the preview text to describe the BBS in general rather than the shared message itself.

The page now emits only the message-specific description metadata when a shared message is being viewed. This keeps the Open Graph preview aligned with the shared message subject and excerpt.

The same override structure is also applied to shared file pages. Shared files continue to use their own file description or fallback text, but they no longer risk combining that description with a second site-wide Open Graph description tag.

No database changes, migration steps, or manual configuration updates are required for this fix.

## Documentation

The user guide now includes a dedicated message reader section. It explains that the web message reader is shared by echomail and netmail and documents the supported keyboard shortcuts for navigation, viewer mode changes, downloads, full-screen mode, shortcut help, and closing the reader.

The localized user guide files were updated alongside the main English guide so the same message reader guidance is available across the translated variants.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
