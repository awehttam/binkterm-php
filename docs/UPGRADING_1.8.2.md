# Upgrading to 1.8.2

Make sure you've made a backup of your database and files before upgrading.

## New Features

- **CSRF Protection** — All state-changing API requests (POST/PUT/PATCH/DELETE) are now protected by a synchronizer token. The token is stored per-user in the database and is automatically attached to AJAX requests by the web client. The telnet daemon receives the token in the login response and sends it with outgoing requests.
- **Telnet Bind Configuration** — The telnet daemon's bind host and port can now be set via `.env` variables (`TELNET_HOST`, `TELNET_PORT`), removing the need to edit the script directly.
- **Telnet Anti-Bot ESC Challenge** — New connections receive an ESC-key challenge before the login prompt, blocking automated scanners. Failed login attempts are now logged.
- **Activity Stats Timezone** — Dates and times on the activity statistics page are now displayed in the logged-in user's preferred timezone.
- **Persistent Echolist Filter** — The unread-only filter on the forum-style echo list is now persisted across sessions alongside the subscribed-only preference.

## Bug Fixes

### FTN / Messaging
- **Echomail MSGID `addr@domain` Format** — Incoming echomail with a MSGID in `address@domain serial` format (e.g. `618:618/1@micronet 6695bee3`) logged a warning and failed to record the originating address. The parser now handles this format alongside the existing `address serial` and `opaque@address serial` forms.
- **Origin Line Restricted to Echomail** — Outgoing netmail packets no longer include a `* Origin:` line. Per FTS-0004, origin lines are an echomail convention; netmail routing is conveyed via kludge lines (`^AINTL`, `^AMSGID`, etc.).
- **Pipe Code Decimal Parsing** — Pipe colour codes are now parsed as decimal values, correcting a blink rendering bug introduced by treating them as octal/hex.

### Telnet Daemon
- **Message Reader Flash** — Opening a message from the list no longer immediately exits. The bug was caused by terminals sending CR+LF for Enter; the trailing LF was being read as a second Enter by the message reader.
- **Door List Display** — The door list now shows the door name instead of its internal ID.
- **Multiplexor Log Timestamps** — All multiplexor log output now includes timestamps using local server time (previously UTC or missing entirely).

### Message Display
- **Signature Block Detection** — Signature styling is now only applied to separators (`--` or `---`) found in the bottom third of a message. Previously any bare dash separator triggered dimmed styling for the rest of the message, causing mid-message dividers to be incorrectly styled as signatures. Long decorative dash lines are also no longer mistaken for signature separators.

### Admin / Daemon
- **`reload_binkp_config` Response** — The admin daemon's `reload_binkp_config` command now correctly returns an array response, fixing a parsing error in the web interface.
- **Fresh Install Migrations** — Database migrations now run correctly during a fresh installation (previously only ran on upgrades).
- **Auto Feed User Selector** — The "Post As User" dropdown in the auto feed configuration now lists all users instead of being capped at 25.

## Security Fixes

- **Binkp M_GOT Path Traversal** — A malicious authenticated peer could send a crafted `M_GOT` filename containing `../` sequences to delete arbitrary files on the server. The filename is now sanitised with `basename()` before use, matching the protection already applied to inbound `M_FILE` handling.
- **File Area Rule Command Injection** — Filenames received via Binkp are substituted into admin-configured rule scripts as shell macros. These values are now wrapped with `escapeshellarg()` before substitution, preventing a peer from achieving remote code execution via a crafted filename.
- **Gateway Token Debug Logging** — A leftover debug block in `verifyGatewayToken()` was logging raw token values to the PHP error log and issuing a redundant database query on every call. Both have been removed.
- **XSS in `<script>` Data Islands** — `window.currentUser` and `userTimezone` were serialised with plain `json_encode`, which does not encode `<` or `>`. These now use `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` to prevent `</script>` injection regardless of PHP version or flag combinations.
- **Password Hash in Client-Side Object** — `window.currentUser` included the user's `password_hash` field. The hash is now stripped from the `current_user` Twig global before it reaches any template.
- **TIC File Path Traversal** — A malicious peer could supply a crafted `File:` field in a `.tic` file containing `../` sequences to write a received file to an arbitrary location on the server. The filename is now sanitised with `basename()` before the storage path is constructed.
- **Binkp Plaintext Password Timing** — The plaintext password fallback path in the Binkp session used a non-constant-time `===` comparison. This is now done with `hash_equals()` to prevent timing oracle attacks.
- **Case-Insensitive Username Matching** — Registration and login now compare usernames case-insensitively, preventing two accounts from coexisting with names that differ only by case (e.g. `Admin` and `admin`).
- **Expanded Reserved Username List** — The list of usernames and real names blocked at registration has been extended to cover common authority-implying names (`admin`, `administrator`, `sysadmin`, `sysadm`, `moderator`, `staff`, `support`, and others) to prevent impersonation.

### File Areas
- **`%basedir%` Macro Shell Quoting** — The Feb 18 security fix wrapped all file area rule macro values in `escapeshellarg()`, which was correct for network-derived values (`%filepath%`, `%filename%`, `%domain%`, etc.) but broke `%basedir%`. Because `%basedir%` is used as a path component in rule templates (e.g. `php %basedir%/scripts/foo.php`), pre-quoting it produced a split path like `'/home/user/app'/scripts/foo.php`. The basedir value (derived from `realpath()` on the application directory) is now substituted raw; all network-derived macros retain their `escapeshellarg()` protection.

### API / Internal
- **`getEchomail()` / `getThreadedEchomail()` `$domain` Parameter** — The `$domain` parameter is now optional (defaults to `null`). Callers that previously relied on positional argument ordering without a default value will continue to work.

### Web Interface
- **Netmail Sent Count on Profile** — The "Netmail Sent" statistic on user profiles was incorrectly counting received messages. The `netmail` table stores `user_id` as the recipient for inbound messages and as the sender for outbound messages; the count now filters by `is_sent = TRUE` so only dispatched messages are counted.
- **Echomail Sidebar Selected Item Contrast** — The network name, description, and message count badges in the echo area list were unreadable when an area was selected (theme-specific colours such as blue or amber persisted on the blue active background). Selected items now render all text and badges in high-contrast white/light colours.
- **Who's Online Idle Timer** — An Idle column (admin-only) has been added to the Who's Online page showing time elapsed since each user's last activity. The timer updates every 10 seconds in the browser without additional server requests.
- **Echomail Sort Order Dropdown** — The sort order dropdown (Newest First, Oldest First, By Subject, By Author) on the echomail list page was non-functional. The API routes were not reading the `sort` query parameter and `MessageHandler` always used a hardcoded `ORDER BY date DESC`. Sorting now works correctly in both standard and threaded views.
- **Random Tagline** — Users can now select "Random tagline" as their default tagline in user settings. Each time the compose window is opened, a tagline is picked at random from the system tagline list and pre-selected in the dropdown (the user can still change it before sending).
- **"Back to Doors" Label** — The back button on the DOS door and WebDoor play pages now reads "Back to Doors" instead of "Back to Games".

## New WebDoors

### Gemini Browser

A built-in WebDoor that lets users browse [Geminispace](https://geminiprotocol.net/) from within the BBS. The Gemini protocol is a lightweight, privacy-focused alternative to the web that uses a plain-text format called Gemtext.

**Features:**
- Full Gemtext rendering (headings, links, lists, blockquotes, preformatted blocks)
- Back/forward navigation history
- Per-user bookmarks (stored server-side)
- Input prompt support for Gemini 1x interactive pages
- Dark terminal-green theme

**Security:** The PHP proxy enforces port 1965 exclusively, blocks connections to private/reserved IP ranges, and limits response size. Gemtext content is rendered as escaped HTML — external link text and server error messages cannot inject scripts.

**Configuration** (Admin → WebDoors → Gemini Browser):

| Setting | Default | Description |
|---|---|---|
| `home_url` | `gemini://geminiprotocol.net/` | Page loaded on open and when Home is clicked |
| `max_redirects` | `5` | Maximum redirects to follow |
| `timeout` | `15` | Connection timeout in seconds |
| `max_response_bytes` | `10485760` | Maximum response body size (10 MB) |
| `block_private_ranges` | `true` | Block connections to RFC-1918 / loopback addresses |

## Docker Improvements

- **DOSBox-X** — The Docker image now uses `dosbox-x` (headless-capable) instead of vanilla `dosbox`. This enables correct headless DOS door operation inside the container.
- **Telnet Daemon in Container** — The telnet daemon is now managed by supervisord inside the Docker container. Port `2323` is exposed by default (configurable via `TELNET_PORT` in `.env`).
- **`ADMIN_DAEMON_SECRET` Auto-Generation** — The Docker entrypoint now auto-generates a random `ADMIN_DAEMON_SECRET` on first start if the variable is not set in `.env`. Set it explicitly if you need a stable value across container restarts.
- **`postgresql-client` Included** — The `postgresql-client` package is now installed in the Docker image for easier database maintenance from within the container.
- **`pcntl` / `posix` PHP Extensions** — These extensions are now compiled into the image, required by the telnet daemon process management.

> **Breaking change for Docker users upgrading to 1.8.2:**
> The database password environment variable has been renamed from `DB_PASSWORD` to `DB_PASS` in `.env.docker.example`, `docker-compose.yml`, and `docker/entrypoint.sh`. Update your `.env` file and any deployment scripts to use `DB_PASS` before restarting the container.

## DOS Door Improvements

- Doorway launcher now passes user information via `DOOR.SYS` instead of command-line arguments.
- Registered version of Doorway receives the `/o` flag for correct operation.
- `DOORWAYU.EXE` (unregistered Doorway) is now bundled and used by default; installing `DOORWAY.EXE` alongside it will override automatically.
- Added a README for the built-in Admin door.
- Suppressed repetitive idle-status entries in the multiplexor daemon log.

## Developer Tooling

- **`test_filearea_rules.php` `--from-filebase`** — The file area rule test script now accepts a `--from-filebase` flag that resolves the file's actual storage path from the database rather than assuming `/tmp/<filename>`. This allows `--execute` mode to run against a real file that has already been received into the file base. A clear error is reported if the filename is not found in the specified area.
- **`test_filearea_rules.php` `--execute` skip reporting** — When `--execute` is used but the file does not exist on disk, the script now reports a per-rule skip warning and an accurate summary instead of silently showing "0 succeeded, 0 failed".

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
