# Upgrading to 1.9.6

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Shared Pages](#shared-pages)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Shared Pages

- Fixed shared message pages so they no longer emit two `og:description` tags. Social previews now use the shared message subject/body excerpt instead of also including the site-wide description from the global appearance settings.
- Applied the same metadata override pattern to shared file pages so file shares also emit a single page-specific `og:description` value.

---

## Shared Pages

Shared message pages now override the default description metadata provided by the site shell templates. Previously, a shared message page could output both the global site description from **Admin -> Appearance** and a second message-specific `og:description` tag based on the shared post content. Link preview crawlers that saw both tags could pick the wrong one, causing the preview text to describe the BBS in general rather than the shared message itself.

The page now emits only the message-specific description metadata when a shared message is being viewed. This keeps the Open Graph preview aligned with the shared message subject and excerpt.

The same override structure is also applied to shared file pages. Shared files continue to use their own file description or fallback text, but they no longer risk combining that description with a second site-wide Open Graph description tag.

No database changes, migration steps, or manual configuration updates are required for this fix.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
