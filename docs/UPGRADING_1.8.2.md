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

## DOS Door Improvements

- Doorway launcher now passes user information via `DOOR.SYS` instead of command-line arguments.
- Registered version of Doorway receives the `/o` flag for correct operation.
- `DOORWAYU.EXE` (unregistered Doorway) is now bundled and used by default; installing `DOORWAY.EXE` alongside it will override automatically.
- Added a README for the built-in Admin door.
- Suppressed repetitive idle-status entries in the multiplexor daemon log.

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
