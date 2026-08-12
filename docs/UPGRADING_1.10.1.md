# Upgrading to 1.10.1

Make sure you have a current backup of your database and files before upgrading.

> **Docker sysops:** how environment configuration is handled has changed in this release — `.env.docker.example` is gone, and Docker-only settings now live in a separate `docker-compose.override.yml` instead of being mixed into `.env`. This is not a drop-in upgrade for existing Docker deployments. Read the [Docker: Configurable Optional Daemons and Scheduled Jobs](#docker-configurable-optional-daemons-and-scheduled-jobs) section below, then follow [Upgrading from 1.10 or Earlier](DOCKER.md#upgrading-from-110-or-earlier) in `docs/DOCKER.md` step by step before upgrading a running Docker install.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Docker: Translation Catalogs Never Updated After the First Container Start](#docker-translation-catalogs-never-updated-after-the-first-container-start)
- [Docker: docs/ Was Never Included in the Image](#docker-docs-was-never-included-in-the-image)
- [Docker: Configurable Optional Daemons and Scheduled Jobs](#docker-configurable-optional-daemons-and-scheduled-jobs)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### FTN Netmail Routing

- Fixed a netmail routing loop: mail from a registered point/downlink addressed to an *unregistered* point under your own boss address (e.g. `227:1/200.1` sending to `227:1/200.21`, with `.21` never added under Admin -> Downlinks) was relayed upstream to your uplink instead of being dropped. Since standard FTN netmail routing strips the point suffix and delivers by node number, the uplink would just route it straight back down to you, and the same relay logic would fire again on receipt -- an unbounded ping-pong between your system and your uplink for any mail addressed to a nonexistent point of your own. `HubNetmailRouter::relayIfFromHubNode()` now checks whether the destination is one of your own AKAs before relaying and drops it as undeliverable instead if so, matching how it already behaves for a destination that belongs to neither you nor a registered downlink.

### Docker

- Docker installs kept serving stale translation text (or raw translation keys instead of text) after upgrading, because the persistent `config` volume shadowed the updated translation catalogs shipped in the new image. The container now re-syncs translation catalogs from the image into that volume on every start, so upgrades pick up new and changed translation text automatically. Sysop-customized translation overrides are never touched by this sync.
- The `docs/` directory was excluded from every Docker image build, so several in-app features that read Markdown files at runtime — the admin documentation browser, the user guide pages, the admin dashboard's upgrade notes, and the MCP client setup help — never worked on Docker installs. `docs/` (aside from the internal `docs/proposals/` drafts) is now included in the image.
- Docker installs previously ran a fixed set of daemons with no way to enable the SSH, Gemini, FTP, MRC, AI bot, Matterbridge, or MCP servers, and no way to run the `rss_poster`, `echomail_robots`, or `logrotate` maintenance scripts that bare-metal installs typically schedule via crontab. Both are now configurable, and toggling either only needs `docker-compose up -d`, not an image rebuild.
- `scripts/ftp_daemon.php` no longer refuses to start when `FTPD_ENABLED=false` -- it serves FTP as soon as it's run, matching how every other standalone daemon in the project works. `FTPD_ENABLED` still exists, but now only controls whether the web UI advertises FTP access to users. This affects bare-metal installs too, not just Docker.

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

## Docker: Configurable Optional Daemons and Scheduled Jobs

Two previously fixed parts of the Docker image are now configurable, and Docker deployments now use two separate config files instead of one.

**`.env.docker.example` is gone.** Docker configuration is now split into two files that are never mixed:

- **`.env`** — copy it from `.env.example`, the *same file bare-metal installs use*. It contains only real BinktermPHP settings (`SITE_URL`, per-daemon internal ports like `SSH_PORT`/`GEMINI_PORT`, everything in [CONFIGURATION.md](CONFIGURATION.md)) and is bind-mounted live into the container, so editing it and running `docker-compose restart binkterm` is enough to pick up a change -- no recreate or rebuild needed. It has no Docker-specific content at all, and it must exist (`cp .env.example .env`) before the container is first started.
- **`docker-compose.override.yml`** — copy it from `docker-compose.override.yml.example`. It contains everything Docker-only: which optional daemons run, which host ports are published, and scheduled-job timing. It's gitignored and merges automatically with `docker-compose.yml`. Changes here need `docker-compose up -d` (a recreate), since Compose only re-resolves those values at container creation.

**Optional daemons.** `docker/supervisord.conf` used to define a fixed list of services, with no way to run the SSH, Gemini, FTP, MRC, AI bot, Matterbridge, or MCP servers under Docker at all (some of these had no Docker wiring whatsoever; Gemini required editing `supervisord.conf` and rebuilding). Each now ships as a disabled-by-default template that `docker/entrypoint.sh` activates when its `ENABLE_*` variable is `true`, set in `docker-compose.override.yml`: `ENABLE_SSH`, `ENABLE_GEMINI`, `ENABLE_FTP`, `ENABLE_MRC`, `ENABLE_AI_BOT`, `ENABLE_MATTERBRIDGE`, `ENABLE_MCP_SERVER`. The internal port each one binds to comes from `.env` (`SSH_PORT`, `GEMINI_PORT`, `FTPD_PORT`, `MCP_SERVER_PORT`) exactly as on bare metal; the host-facing port it's published on is a separate setting in `docker-compose.override.yml`. See [Included Services](DOCKER.md#included-services) in `docs/DOCKER.md` for the full table.

**Scheduled maintenance jobs.** `rss_poster.php`, `echomail_robots.php`, and `logrotate.php` previously had no way to run inside a Docker container at all — bare-metal installs schedule them via crontab, but Docker had no cron process. A `cron` process now runs under supervisor by default, with `docker/entrypoint.sh` generating `/etc/cron.d/binkterm` from `ENABLE_RSS_POSTER`, `ENABLE_ECHOMAIL_ROBOTS`, and `ENABLE_LOGROTATE` (all `true` by default) plus `RSS_POSTER_SCHEDULE`, `ECHOMAIL_ROBOTS_SCHEDULE`, and `LOGROTATE_SCHEDULE` (defaulting to hourly, every 5 minutes, and Sundays at midnight respectively) and `LOGROTATE_KEEP` (default `52`) -- all set in `docker-compose.override.yml`. See [Scheduled maintenance jobs (cron)](DOCKER.md#scheduled-maintenance-jobs-cron) in `docs/DOCKER.md`.

**For step-by-step migration commands, see [Upgrading from 1.10 or Earlier](DOCKER.md#upgrading-from-110-or-earlier) in `docs/DOCKER.md`.**
