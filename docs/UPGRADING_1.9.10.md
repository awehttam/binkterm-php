# Upgrading to 1.9.10

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
  - [AIO Process Manager](#aio-process-manager)
- [AIO Process Manager](#aio-process-manager-1)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### AIO Process Manager

- `binktermphp-pm` is a new optional Go-based process manager that supervises all BinktermPHP daemons and restarts them automatically on failure.
- `binktermphp-ctl` is a companion CLI for checking service status, tailing logs, and starting, stopping, or restarting individual services without attaching to the console.
- A new **Admin → BBS Settings → Services** page lets sysops enable or disable supervised services through the web UI without editing config files directly.
- Pre-built binaries for Windows (amd64), Linux (amd64), macOS Intel (amd64), and macOS Apple Silicon (arm64) are included in `dist/`. Wrapper scripts `binktermphp-pm.sh` / `binktermphp-pm.cmd` and `binktermphp-ctl.sh` / `binktermphp-ctl.cmd` in the project root select the correct binary for the current platform automatically.
- The existing `scripts/restart_daemons.sh` workflow is unchanged for sysops who prefer it.

---

## AIO Process Manager

`binktermphp-pm` is an optional Go-based process supervisor for all BinktermPHP daemons. It starts `admin_daemon`, `binkp_server`, `binkp_scheduler`, `realtime_daemon`, and any optional services (Telnet, SSH, MRC, Gemini, FTP, and others) as supervised child processes, restarting them automatically if they exit unexpectedly.

`binktermphp-ctl` is a command-line companion that communicates with a running `binktermphp-pm` instance over a Unix socket. It supports `status`, `start`, `stop`, `restart`, `logs`, and `stop-all` commands. Running `binktermphp-pm` without arguments in a terminal opens an interactive dashboard showing live service state, health check results, and log output.

Health checks are built in. The `postgres` check opens a real authenticated database connection using the credentials in your `.env` file. The `caddy` check performs an HTTP GET against your configured `SITE_URL/api/verify` and expects a 2xx response.

A new **Admin → BBS Settings → Services** page lets sysops toggle individual services on or off. Changes write back to `config/aio.json` through the admin daemon, so the web process never writes config files directly.

Pre-built binaries for Windows (amd64), Linux (amd64), macOS Intel (amd64), and macOS Apple Silicon (arm64) live in `dist/<os>-<arch>/`. The wrapper scripts `binktermphp-pm.sh` and `binktermphp-pm.cmd` (and equivalents for `ctl`) in the project root pick the correct binary for the current platform at runtime.

**To use binktermphp-pm:**

1. Copy `config/aio.json.example` to `config/aio.json`.
2. Review the services list and set `"enabled": true` for the daemons you run.
3. Run `./binktermphp-pm.sh` (Linux/macOS) or `binktermphp-pm.cmd` (Windows) from the project root.

To run as a background daemon: `./binktermphp-pm.sh --daemon`. Use `./binktermphp-ctl.sh stop-all` (or press `Q` in the interactive console) to shut it down.

The existing `scripts/restart_daemons.sh` workflow continues to work for sysops who do not adopt the process manager.

---

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
