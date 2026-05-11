# Upgrading to 1.9.6

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Shared Pages](#shared-pages)
- [Documentation](#documentation)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Shared Pages

- Fixed shared message pages so they no longer emit two `og:description` tags. Social previews now use the shared message subject/body excerpt instead of also including the site-wide description from the global appearance settings.
- Applied the same metadata override pattern to shared file pages so file shares also emit a single page-specific `og:description` value.

### Documentation

- Expanded the user guide with a dedicated message reader section that explains the web reader interface and lists the supported keyboard shortcuts for both echomail and netmail readers. The translated user guide variants were updated to include the same section.

---

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
