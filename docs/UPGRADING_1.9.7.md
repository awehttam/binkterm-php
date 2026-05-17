# Upgrading to 1.9.7

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
  - [Terminal Server](#terminal-server)
  - [Developer Tooling](#developer-tooling)
- [Terminal Server](#terminal-server-1)
- [Developer Tooling](#developer-tooling-1)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Terminal Server

- The shared terminal server now includes **Local Chat** from the main menu. Telnet and SSH users can open room chat, switch rooms and DMs, view online users from the left navigation pane, read Markdown-rendered messages, and send new messages without leaving the terminal session.
- The terminal chat client uses the existing local chat API with polling, so no additional daemon or sysop configuration is required when upgrading.
- The terminal main menu now reacts to live terminal resize events. On Telnet NAWS updates and SSH window-change events, the menu redraws to the new dimensions without requiring an extra keypress, and dashboard widgets are re-laid out using cached stats rather than triggering another API call.

### Developer Tooling

- The root `CLAUDE.md` contributor guide has been split into subdirectory-scoped files and on-demand skill scripts, reducing context load when working in specific parts of the codebase. No action required for sysops.
- A `session-start.php` script has been added to `.claude/` to print available project skills at the start of each Claude Code session.

---

## Terminal Server

Terminal users can now access Local Chat directly from the shared BBS main menu by pressing `C`.

The shared terminal main menu also now responds to terminal window resizing while waiting for input. If the user resizes the terminal, the menu redraws immediately for the new width and height, and the dashboard widgets switch between the wide sidebar and narrow bottom-bar layouts as needed without making another `/api/dashboard/stats` request.

The terminal client currently provides:

- room and DM selection from the left navigation pane
- online-user summary in that same pane
- a larger message pane with Markdown rendering
- a bottom compose box with `Enter` to send and `Ctrl+E` for multiline compose
- API-backed polling for live updates while the chat screen is open

No migration or post-upgrade admin action is required for this feature. If Local Chat is already enabled in **Admin -> BBS Settings**, it becomes available automatically to terminal users after the upgraded daemons are restarted.

---

## Developer Tooling

The root `CLAUDE.md` file previously contained all project guidance in a single document. It has been refactored so that sections relevant only to a specific subdirectory now live in a `CLAUDE.md` file within that directory (auto-loaded by Claude Code when working there). Subdirectory files were added for `scripts/`, `telnet/`, `ssh/`, `templates/`, and `public_html/webdoors/`.

Procedural checklists that contributors invoke on demand have been extracted into skill files under `.claude/commands/`:

- `/bump-version` — version bump steps, UPGRADING doc format, and composer dependency notes
- `/new-migration` — migration ID format, SQL vs PHP choice, no-duplicate-index rule, and setup.php reminder
- `/usercredits-workflow` — five-step checklist for adding new `UserCredit` types
- `/logging-guide` — log file table, per-context code patterns, log levels, and adding a new log file
- `/new-webdoor` — manifest requirement, SDK require path, and API independence rule

A `Doc Maintenance Checklist` section was also added to `docs/DEVELOPER_GUIDE.md` mapping subsystems to their corresponding documentation files, making it easier to identify which docs need updating when a subsystem changes.

`.claude/session-start.php` has been added and is now tracked in git. It prints a welcome message and the list of available project skills at the start of each new Claude Code session. The `CLAUDE.md` instructions were updated to require that any new skill file be registered in both the Skills list in `CLAUDE.md` and in `session-start.php`.

No sysop action is required for any of these changes.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
