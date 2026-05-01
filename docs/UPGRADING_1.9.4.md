# Upgrading to 1.9.4

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Echomail Performance Improvements](#echomail-performance-improvements)
- [Configurable Echomail Badge Mode](#configurable-echomail-badge-mode)
- [PacketBBS Gateway](#packetbbs-gateway)
- [CWN MeshCore Node Mapping](#cwn-meshcore-node-mapping)
- [Telnet Daemon](#telnet-daemon)
- [Shoutbox](#shoutbox)
- [Bug Fixes](#bug-fixes)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Echomail Performance Improvements

- **Echolist page**: Loading the echo area list on systems with large message bases (80K+ messages) previously required two full-table scans of the `echomail` table per request to compute total message counts and last-post metadata. The query now reads cached values from new columns on the `echoareas` table, eliminating those scans. Two database migrations apply the schema change and backfill the cache from existing data.
- **Dashboard unread badge**: The dashboard unread echomail count previously scanned every echomail message and joined against every per-message read record to count unread items. On large installs this query took 2–6 seconds. The badge now counts messages using an indexed watermark, reducing the query to a fast range scan.
- **Configurable echomail badge mode**: The dashboard echomail badge behavior is now a user setting under Settings → Messaging. The default mode counts messages that arrived since your last visit to an echomail page; an alternative mode counts total messages above the per-area read watermark. The dashboard card label has been updated from "Unread Echomail" to "New Echomail." Per-message bold/unread state inside the message list is unchanged.

### Shoutbox

- **ANSI and URL rendering**: Shoutbox messages now render ANSI color codes and clickable URLs using the same rendering pipeline as echomail messages. Previously, message text was displayed as plain escaped text.

### Telnet Daemon

- **Connection rate limiting**: The telnet daemon now rejects repeated rapid connections from the same IP address before forking a new process for each one. The limit is configurable via two `.env` variables (`TELNET_RATE_LIMIT_MAX`, `TELNET_RATE_LIMIT_WINDOW`) and is enabled by default. Set `TELNET_RATE_LIMIT_MAX=0` to disable it.

### Bug Fixes

- **Echo area management**: When deleting an echo area that still has messages, the API correctly rejected the request but the error message was never displayed to the user. The delete confirmation modal also stayed open after the failure. Both issues are now resolved — the modal closes and the error is shown.
- **Error and success alert display**: A long-standing bug caused all `showError` and `showSuccess` alerts throughout the application to be silently discarded rather than inserted into the page. Alerts now appear correctly at the top of the page content.

### PacketBBS Gateway

- **Mesh/radio text gateway**: PacketBBS provides a compact text command interface for MeshCore-style radio bridges. The gateway supports login, online-user lookup, netmail reading/replying/sending, echomail area browsing, echomail reading/replying/posting, paging, and quitting.
- **Compact radio UX**: PacketBBS responses are optimized for short radio text exchanges rather than full-screen BBS terminal use. Help is brief by default, message lists are compact, message reads use short headers, and compose mode accepts `/SEND` and `/CANCEL`.
- **Admin-managed nodes**: Sysops can manage registered PacketBBS bridge nodes from the admin Packet BBS page, generate per-node API keys, view active sessions, and inspect the outbound queue.
- **TOTP authentication**: PacketBBS radio login uses a TOTP authenticator code rather than the user's web password. Users must enroll under Settings -> Account by scanning the PacketBBS QR code into an authenticator app.

### CWN MeshCore Node Mapping

- **Automatic repeater mapping**: The Community Wireless Node List WebDoor can now receive MeshCore repeater advertisements from the binkterm-php MeshCore bridge and display them on the CWN map without manual user submission.
- **2-day rolling visibility**: MeshCore-sourced CWN entries remain in the database but are hidden from map and search results when they have not been heard for more than 2 days.
- **Bridge update required**: Sites using `binktermphp-meshcorebridge` must update that separate bridge repository so it forwards `new_advert` packets and startup contact-list records to the new BBS endpoint.

## Echomail Performance Improvements

### Echolist Cached Columns (migration v1.11.0.82)

Loading the echo area list (`/echolist`) on systems with large message bases required two aggregate subqueries against the full `echomail` table on every page load: one to count total messages per area, and one to retrieve the subject, author, and date of the most recent post per area. On a database with 80,000+ messages, each subquery performed a full sequential scan, making the page take several seconds to load.

Three columns have been added to the `echoareas` table to cache this information:

- `last_post_subject VARCHAR(255)`
- `last_post_author VARCHAR(100)`
- `last_post_date TIMESTAMP`

These columns are updated in-place whenever a new message is stored — either inbound via the binkp processor or posted via the web or terminal interface. Moderated messages that are pending approval do not update `last_post_*` until they are approved.

Migration `v1.11.0.82` adds the new columns, backfills them from existing `echomail` rows, and recalibrates all `message_count` values to match the actual row count. The echolist query no longer contains subqueries against `echomail`.

### Dashboard Unread Badge High-Watermark (migration v1.11.0.83)

The dashboard previously counted unread echomail by joining `user_echoarea_subscriptions`, `echomail`, and `message_read_status` and counting every message that had no matching read record for the current user. On a database with 80,000+ messages and a user subscribed to hundreds of areas, this query materialized tens of millions of row comparisons and regularly took 2–6 seconds.

A `last_read_id` column has been added to `user_echoarea_subscriptions`. It stores the highest `echomail.id` the user has read in each area. The dashboard badge query is now a fast index range scan per subscribed area using a new composite index `(echoarea_id, id)` on the `echomail` table.

The watermark is advanced whenever a user reads a message (individually or in bulk). Migration `v1.11.0.83` backfills the watermark from the existing `message_read_status` table so users do not see a large backlog of "new" messages after upgrading.

#### Changed behavior

The `last_read_id` watermark is used by the "Total unread" badge mode (see [Configurable Echomail Badge Mode](#configurable-echomail-badge-mode) below). The dashboard card label has been updated from "Unread Echomail" to "New Echomail." Detailed bold/unread state inside the message list continues to use per-message read tracking and is not affected by this change.

## Configurable Echomail Badge Mode

### New User Setting (migration v1.11.0.86)

The dashboard echomail badge can now be set to one of two counting modes under **Settings → Messaging → New Echomail Badge**. The same setting is available in the terminal server settings under the Messaging tab.

| Mode | Behavior |
|---|---|
| **New since last visit** (default) | Counts messages that arrived in your subscribed areas after the last time you opened `/echomail` or `/echolist`. The count resets to zero each time you visit an echomail page, regardless of whether you opened any individual messages. |
| **Total unread** | Counts every message in your subscribed areas that you have never opened. This is an exact count but requires a full scan of the echomail table on each dashboard refresh — on systems with large message bases it may take several seconds. |

The default is "New since last visit." Existing users receive this default automatically when the migration runs — no manual configuration is required.

Migration `v1.11.0.86` adds an `echomail_badge_mode` column to the `user_settings` table with a default value of `new`.

## PacketBBS Gateway

### Bridge API and Admin Management

PacketBBS adds server-side routes under `/api/packetbbs/` for radio bridge software. Bridge requests authenticate with a per-node bearer token generated in the admin Packet BBS page. The bridge protocol remains plain HTTP with text responses for commands and JSON responses for queued outbound messages.

Sysops must register each bridge node before it can use the gateway. The node record controls the allowed bridge identity, interface type, and API key. Unknown bridge nodes are rejected before any BBS command is processed.

### User Authentication

PacketBBS user login uses the PacketBBS authenticator configured from Settings -> Account. Users enroll a TOTP authenticator in the web UI, then log in over radio with:

```text
LOGIN <username> <6-digit-code>
```

The login flow does not use the normal web password over radio.

Enrollment displays a QR code generated with `chillerlan/php-qrcode`. Users scan that QR code into an authenticator app from Settings -> Account, then verify a 6-digit code to enable PacketBBS login. This adds a new Composer dependency, so upgraders must run `composer update` before `php scripts/setup.php`.

### Compact Command Interface

The gateway is intentionally terse for mesh/radio use. The primary commands are:

```text
HELP
LOGIN <user> <code>
WHO
MAIL
R <id>
RP <id>
SEND <user> <subject>
AREAS
AREA <tag>
POST <tag> <subject>
M
Q
```

Legacy aliases remain available where useful, including `N`, `NR`, `NRP`, `NS`, `E`, `ER`, `EM`, `EMR`, `EP`, `MORE`, and `QUIT`.

Compose mode accepts one body line per radio message. Send `/SEND` or `.` to finish, and `/CANCEL` or `CANCEL` to abort.

### Echoarea Domains

Networked echoareas may be shown as `TAG@domain`, for example `LVLY_TEST@lovlynet`. PacketBBS preserves that domain when listing, paging, replying, and posting so messages are posted to the correct networked area.

## CWN MeshCore Node Mapping

### MeshCore Advert Ingest (migration v1.11.0.87)

The Community Wireless Node List WebDoor now supports machine-ingested MeshCore repeater nodes. A MeshCore bridge can post heard repeater advertisements to:

```text
POST /api/meshcore/advert
Authorization: Bearer <packet-bbs-node-api-key>
```

The endpoint uses the same PacketBBS bridge-node bearer-token authentication as `/api/packetbbs/command`. The request identifies the bridge with `bridge_node_id`, and the server validates the advertised node public key and coordinates before writing anything to the CWN table.

Migration `v1.11.0.87` updates `cwn_networks` for MeshCore ingest:

- `submitted_by`, `submitted_by_username`, and `description` are now nullable so machine-ingested rows do not need a human submitter or human-written description.
- `public_key` stores the full 32-byte MeshCore public key as 64 lowercase hex characters.
- `source_type` distinguishes manual rows from MeshCore rows.
- `last_seen_at` records when the bridge most recently reported the node.
- `hop_count` stores the bridge-reported outbound path length.
- A partial unique index on `public_key` keeps one CWN row per MeshCore node while still allowing manual rows with no public key.

Manual CWN submissions are unchanged. They still require the existing user fields and continue to award credits through the WebDoor submission flow.

### Map and Search Visibility

MeshCore-sourced rows use a 2-day rolling visibility window. The rows are not deleted, but CWN list and search queries hide MeshCore entries when:

```sql
last_seen_at <= NOW() - INTERVAL '2 days'
```

When a bridge reports the same MeshCore public key again, the existing row is updated with the latest name, coordinates, hop count, network type, and `last_seen_at` value.

The CWN WebDoor now marks MeshCore entries with a `mesh` badge, uses a different map icon, and shows MeshCore-specific details such as public key, hop count, and last-seen time.

### MeshCore Bridge Deployment

This BBS release adds the receiving endpoint, but the transmitting code lives in the separate `binktermphp-meshcorebridge` repository. If you run that bridge, update it alongside the BBS release and restart the bridge process.

The updated bridge forwards only repeater advertisements. Chat nodes, room servers, sensors, nodes without valid coordinates, and nodes reporting `0,0` coordinates are skipped before any HTTP request is sent.

## Shoutbox

### ANSI and URL Rendering

Shoutbox messages on both the dashboard card and the dedicated shoutbox page (`/shoutbox`) were previously rendered as plain escaped text. Any ANSI color sequences or URLs in a message appeared as literal characters rather than rendered output.

Shoutbox message bodies are now passed through the same `formatMessageText` rendering pipeline used by echomail and netmail. ANSI color codes and pipe codes are interpreted and displayed with color, and any `http://`, `https://`, or `ftp://` URLs in the message text are converted to clickable links that open in a new tab.

No database migration or configuration change is required.

## Telnet Daemon

### Connection Rate Limiting

Systems that expose the telnet or TLS telnet port publicly can receive floods of rapid connection attempts from a single IP — either from automated scanners or deliberate denial-of-service attempts. Each accepted connection previously triggered a `pcntl_fork()` call with no guard on how many times a single IP could do so in a short period, meaning a single host could exhaust process table slots or file descriptors.

The main accept loop in the telnet daemon now checks a per-IP connection counter before forking. If a remote IP exceeds the configured limit within the configured time window, the connection is refused with a short text message and the socket is closed — no child process is created. The check happens in the parent process so the cost of a rejected connection is limited to accepting the TCP socket and writing one line of text.

Two `.env` variables control the behavior:

| Variable | Default | Description |
|---|---|---|
| `TELNET_RATE_LIMIT_MAX` | `5` | Maximum connections allowed from one IP within the window. Set to `0` to disable rate limiting entirely. |
| `TELNET_RATE_LIMIT_WINDOW` | `60` | Rolling window size in seconds. The counter for an IP resets after this many seconds have elapsed since the first connection in the current window. |

The defaults allow 5 connections per minute per IP, which is sufficient for any legitimate user — including those who connect, disconnect, and reconnect quickly. Rejections are logged to `telnetd.log` with the offending IP address.

No database migration or `composer update` is required for this change. The feature activates automatically on daemon restart.

## Bug Fixes

### Echo Area Delete Error Not Displayed

Attempting to delete an echo area that contains messages is intentionally blocked — the correct action is to deactivate the area instead. The API returned a structured error response with the message "Cannot delete echo area with existing messages", but the error never appeared in the UI. The delete confirmation modal also remained open after the failed request, leaving the user with no feedback and no clear way to understand what went wrong.

Two issues caused this:

1. The delete error callback in `public_html/js/` (inlined in `templates/echoareas.twig`) did not close the delete confirmation modal before attempting to display the error. Any alert inserted into the page while a Bootstrap modal is open is hidden behind the modal overlay.
2. The shared `showError` function (see below) was silently discarding all alerts due to a broken DOM selector.

The error callback now closes the modal before displaying the error message.

### Error and Success Alert Display Broken Site-Wide

The `showError` and `showSuccess` functions in `public_html/js/app.js` insert dismissible Bootstrap alert banners at the top of the page. These functions used the jQuery selector `$('main .container')` to find the insertion point. All base templates in this application render the main content area as `<main class="container mt-4">` — the `main` element itself carries the `container` class, with no nested `.container` descendant inside it. The space-descendant selector therefore matched nothing, and every call to `showError` or `showSuccess` silently inserted the alert HTML into an empty jQuery set where it was immediately discarded.

The selector has been changed to `$('main')`, which correctly targets the main content element regardless of whether `container` is on `main` itself or on a child element.

This fix benefits every page that calls `showError` or `showSuccess`, not only the echo area management page.

## Upgrade Instructions

Run `php scripts/setup.php` after upgrading so PacketBBS and CWN database migrations, admin routing, and configuration defaults are applied. Restart all daemons afterward so the new code takes effect. If you run `binktermphp-meshcorebridge`, update and restart that separate bridge process after updating the BBS.

### From Git

```bash
git pull
composer update
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Replace your files with the new release archive, then run:

```bash
composer update
php scripts/setup.php
scripts/restart_daemons.sh
```
