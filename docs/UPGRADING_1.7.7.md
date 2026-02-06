# UPGRADING_1.7.7

This upgrade note covers changes introduced in version 1.7.7.

## Summary of Changes Since 1.7.6

- File area rules system with macro-driven scripts, action handling, and logging.
- File area storage directories now use `TAGNAME-DATABASEID` naming.
- Virus scanner improvements.
- Nodelist importer fixes (Zone/Net parsing) and archive cleanup.
- Telnet subsystem refactor and updates (including screenshots and goodbye screen).
- Telnet UX updates: hotkey-driven menus, list navigation/status bars, new Who's Online screen, and shoutbox display changes.
- WebDoors documentation and configuration cleanup.
- README/FAQ updates and documentation reorganization.
- User signatures (per-user, compose-only) and sysop-managed taglines for outbound messages.
- User-selectable default tagline preference for message composition (web + telnet).

## Post upgrade steps

If upgrading from git, be sure to run setup.php after the upgrade.  This is to ensure all migrations have been applied.  If you upgraded through the installer, this step should have been performed for you.

## File Area Storage Directory Naming Change

File area storage directories now use the convention:

`TAGNAME-DATABASEID`

For example:

`NODELIST-6`

### What You Need To Do

- New uploads and TIC imports will create the new `TAGNAME-DATABASEID` directories automatically.
- Existing files stored under the old `TAGNAME` directories are **not** moved automatically.
- You must manually move existing files to the new directory and update any external references if needed.

### Example Manual Move

If your file area tag is `NODELIST` and its database ID is `6`:

- Old directory: `data/files/NODELIST`
- New directory: `data/files/NODELIST-6`

Move the files, then verify permissions remain correct for your web server user.

### Ownership Note

File area directories should be owned by the web server user (often `www-data` on Linux).
Ensure ownership/permissions match your web server so uploads and downloads continue to work.

## File Area Rules: Domain-Scoped Keys

File area rules can now be scoped by domain using `TAG@DOMAIN` keys inside `area_rules`.
If a domain-scoped entry exists, it takes precedence over the plain `TAG` key.

Example:

- `area_rules.NEWS` applies to all `NEWS` areas by default.
- `area_rules.NEWS@FIDONET` applies only to the `FIDONET` domain and overrides `NEWS` for that domain.

## Signatures and Taglines

This release adds per-user signatures and sysop-managed taglines for outbound netmail and echomail.

### Database Migration

Run the standard upgrade process (`scripts/setup.php`) to apply database changes.

### User Signatures (Compose-Only)

- Users configure their signature in **Settings**.
- Limit: 4 lines.
- Signatures are inserted into the compose window (web + telnet). They are **not** injected at send time.
- If users compose via external tools, the signature must be included in the message body explicitly.

### Taglines (Sysop-Managed)

- Taglines are stored in `config/taglines.txt`, one per line.
- Edit taglines from **Admin → BBS Settings → Taglines** (uses the admin daemon).
- Taglines are selectable in the web compose screen; users can optionally set a default tagline preference.
- Outbound packets place the tagline between the tearline and origin line (FidoNet-style).

### Default Tagline Preference

- Users can pick a default tagline in **Settings** (defaults to none).
- Compose screens (web and telnet) will preselect the user's default tagline when available.

### Admin Daemon Note

The taglines editor uses new admin daemon commands. Restart the admin daemon after deploying.

## Telnet UX Updates

The telnet interface received several usability improvements:

- Main menu now uses single-key hotkeys (`N/E/W/S/P/Q`) and includes a top status bar with system name and local time.
- New `W) Who's Online` screen that calls `/api/whosonline` and shows online users.
- Netmail/Echomail list screens use a bottom status bar and smoother row-only redraw when moving the selection.
- Full-screen message reader uses `Q` to quit.
- Shoutbox display now uses alternating colors (no border) and pauses with a “Press any key” prompt.
