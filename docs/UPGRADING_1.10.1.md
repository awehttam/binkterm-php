# Upgrading to 1.10.1

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Docker: Translation Catalogs Never Updated After the First Container Start](#docker-translation-catalogs-never-updated-after-the-first-container-start)
- [Docker: docs/ Was Never Included in the Image](#docker-docs-was-never-included-in-the-image)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Docker

- Docker installs kept serving stale translation text (or raw translation keys instead of text) after upgrading, because the persistent `config` volume shadowed the updated translation catalogs shipped in the new image. The container now re-syncs translation catalogs from the image into that volume on every start, so upgrades pick up new and changed translation text automatically. Sysop-customized translation overrides are never touched by this sync.
- The `docs/` directory was excluded from every Docker image build, so several in-app features that read Markdown files at runtime — the admin documentation browser, the user guide pages, the admin dashboard's upgrade notes, and the MCP client setup help — never worked on Docker installs. `docs/` (aside from the internal `docs/proposals/` drafts) is now included in the image.

---

## Docker: Translation Catalogs Never Updated After the First Container Start

BinktermPHP's Docker setup keeps sysop-editable settings (`binkp.json`, `bbs.json`, and similar files under `config/`) in a persistent named volume, so they survive image rebuilds across upgrades. The translation catalogs used for the interface's multi-language support (`config/i18n/`) happened to live inside that same `config/` directory, even though they are application code, not sysop data, and change with nearly every release.

Docker only copies the image's files into a named volume the first time that volume is created. On every later `docker-compose up`, the existing volume content is used as-is and the newer files baked into the image are never copied in. In practice this meant a Docker install's translation catalogs were permanently frozen at whatever version was running when the volume was first created — later upgrades that added or changed translation text would render the raw translation key (e.g. `ui.settings.title`) in the interface instead of the actual text, even though the underlying image had the correct catalog files all along.

The container's startup script now re-syncs the translation catalogs from the image into the persistent volume on every container start, so this class of staleness can no longer happen on future upgrades. Any translation phrases you have customized yourself under `config/i18n/overrides/` are excluded from this sync and are never overwritten.

Rebuild your image and recreate the container to pick up the fix:

```bash
docker-compose build --no-cache
docker-compose up -d
```

## Docker: docs/ Was Never Included in the Image

The project's `.dockerignore` excluded the entire `docs/` directory (and all `*.md` files generally) from every Docker image build. Several features in the app read Markdown files from `docs/` at request time rather than bundling their content into PHP or Twig, and all of them silently failed to load their content on Docker installs:

- The admin documentation browser (`/admin/docs`)
- The user guide pages shown to regular users
- The admin dashboard's "what's new" upgrade notes, which read `docs/UPGRADING_<version>.md` for the running version
- The MCP client setup help page

This was not a regression in this release — it affected every prior Docker build. `docs/` is now included in the image (`docs/proposals/`, which holds internal draft design documents, is still excluded). Rebuild your image to pick up the fix:

```bash
docker-compose build --no-cache
docker-compose up -d
```

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
