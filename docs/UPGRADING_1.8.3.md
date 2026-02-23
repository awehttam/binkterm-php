# Upgrading to 1.8.3

Make sure you've made a backup of your database and files before upgrading.

## New Features

### Gemini Browser: Built-in Start Page

The Gemini Browser WebDoor now opens to a built-in start page (`about:home`) with curated links to popular Geminispace destinations (search engines, aggregators, community spaces, and software). Previously it opened directly to an external Gemini capsule.

The start page can be overridden per-installation: set `home_url` to any `gemini://` URL in Admin → WebDoors → Gemini Browser to use an external page instead.

---

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

```bash
wget https://raw.githubusercontent.com/awehttam/binkterm-php-installer/main/binkterm-installer.phar
php binkterm-installer.phar
scripts/restart_daemons.sh
```

> No database migrations are required for this release.
