# Upgrading to 1.10.3

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [FREQ](#freq)
- [Activity Log](#activity-log)
- [Dashboard](#dashboard)
- [Doors](#doors)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### FREQ

- `scripts/freq_getfile.php` and `scripts/freq_pickup.php` now connect anonymously by default, even when the target address matches one of your configured uplinks. A new `--authenticated` flag opts back into using that uplink's real session password/CRAM-MD5 for a single run, and a new `FREQ_AUTHENTICATE_UPLINKS` `.env` setting lets you make that the default everywhere, including the web/terminal File Requests UI.

### Activity Log

- Outbound FREQ, Viewing the public BBS Directory list and individual BBS detail page, entering local chat room, uploading/generating a PGP key or changing a primary key are now recorded events.
- **Admin → Activity Stats → Top Users** now has a "Returning Users" list showing which users were active on more than one day within the currently selected period, with a count at the top.

### Dashboard

- The admin-only **Today's Callers** widget now lists callers in chronological order by their actual last-call time, and no longer shows a stale last-call time or incorrect online status from a session left over from a previous day.

### Doors

- DOS Doors and Native Doors now support a `hide_from_web` config option that hides a door from the web games list and blocks its web player page, while leaving it fully playable over telnet/SSH.
- Fixed the Native Door manifest AI autofill failing with "Missing assistant content in OpenRouter API response" when the `openrouter/auto` route picked a reasoning model that spent its whole token budget on hidden reasoning before producing an answer.

## FREQ

FREQ requests made with `scripts/freq_getfile.php` or `scripts/freq_pickup.php` now default to an anonymous binkp session, matching standard FTN convention for file requests. Previously, if the address you were FREQing happened to match one of your configured uplinks, the script would automatically use that uplink's real session password and CRAM-MD5 credentials for the session — not what most sysops expect from a simple file request. This is separate from the FREQ area password (`--password`), which is still carried inside the `.req`/`M_GET` request itself and unaffected by this change.

If you specifically want a FREQ run to use your real uplink session credentials, pass the new `--authenticated` flag. To change the default for every FREQ (including the web/terminal File Requests UI, which shells out to `freq_getfile.php`), set `FREQ_AUTHENTICATE_UPLINKS=true` in `.env`; the new `--anonymous` flag forces an anonymous session for a single run even when that setting is enabled. See [docs/FREQ.md](FREQ.md#configuration-reference) for the full reference and the tradeoff to consider before enabling it.

## Activity Log

Several gaps in `user_activity_log` coverage are fixed in this release:

| Event |
| --- |
| Outbound FREQ |
| Viewing the public BBS Directory list |
| Viewing an individual BBS detail page |
| Entering local chat room |
| Uploading/generating a PGP key |
| Changing a primary PGP key |

None of these change any user-facing behavior; they only affect what shows up in a user's activity history and in **Admin → Activity Stats**.

### Returning Users (Admin → Activity Stats)

The **Top Users** tab now has a **Returning Users** card above the existing "Most Active Users" list. It shows a count and a list of users who were active on more than one distinct day within whatever period is currently selected on the page (7 days, 30 days, 90 days, or all time) — not a count of login events specifically. This app authenticates via a long-lived cookie, so a user can return many times without ever generating a fresh login event; the metric instead counts distinct calendar days with any tracked activity (echomail, chat, files, doors, etc.), which reflects real return visits regardless of how login/cookie auth behaves. This is purely a read of existing `user_activity_log` data — no new activity types or schema changes are involved.

## Dashboard

The **Today's Callers** widget on the admin dashboard had two bugs that are fixed in this release:

- Callers were listed in the order they first logged in that day, rather than by their most recent activity — a caller who logged in early but then remained active later in the day could appear above someone who called in after them.
- The last-call time and "online now" indicator were pulled from any session belonging to the user, including a still-valid session left over from a previous day (for example a long-lived "remember me" cookie). This could show a stale last-call time, or mark a user as online based on a session that wasn't actually active today.

Both are now scoped correctly to today's activity, and the list is sorted by actual last-call time.

## Doors

DOS Doors and Native Doors can now be restricted to the terminal server only. Each door's runtime config (`config/dosdoors.json` or `config/nativedoors.json`) supports a new `hide_from_web` boolean, defaulting to `false`. When set to `true` on a door:

- The door is omitted from the web games list at `/games`.
- Its web player page (`/games/dosdoors/{doorid}` or `/games/nativedoors/{doorid}`) returns a 404.
- It remains fully playable over telnet/SSH via **[Files] → Door Games**, unaffected by this setting.

This is useful for doors that only make sense in a real terminal session, or that a sysop wants to keep off the web games page for any other reason. Set it through **Admin → DOS Doors** or **Admin → Native Doors** — each door's entry in the config editor now has a globe toggle button, and a "Telnet/SSH only" badge appears on doors with it enabled.

### Native Door AI Autofill Fix

The AI-assisted "autofill" button when adding a Native Door could fail with a 500 error and `Missing assistant content in OpenRouter API response` logged to `server.log`. This happened when the OpenRouter provider is configured with the `openrouter/auto` model: OpenRouter would sometimes route the request to a hybrid reasoning model that spent its entire token budget on hidden reasoning tokens, leaving no room for the actual answer.

The OpenRouter provider now asks routed models to skip reasoning where supported, falls back to any reasoning-field text if the model still returns one, and gives the autofill request a larger token budget. Failures that do still occur are now logged with the response's `finish_reason` and message body for easier diagnosis.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
