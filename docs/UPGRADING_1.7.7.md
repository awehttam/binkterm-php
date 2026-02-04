# UPGRADING_1.7.7

This upgrade note covers changes introduced in version 1.7.7.

## Summary of Changes Since 1.7.6

- File area rules system with macro-driven scripts, action handling, and logging.
- File area storage directories now use `TAGNAME-DATABASEID` naming.
- Virus scanner improvements.
- Nodelist importer fixes (Zone/Net parsing) and archive cleanup.
- Telnet subsystem refactor and updates (including screenshots and goodbye screen).
- WebDoors documentation and configuration cleanup.
- README/FAQ updates and documentation reorganization.
- User signatures (per-user, compose-only) and sysop-managed taglines for outbound messages.

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

## Signatures and Taglines

This release adds per-user signatures and sysop-managed taglines for outbound netmail and echomail.

### Database Migration

Run the migration to add the signature field to user settings:

- `database/migrations/v1.9.2_add_user_signature_tagline.sql`

### User Signatures (Compose-Only)

- Users configure their signature in **Settings**.
- Limit: 4 lines.
- Signatures are inserted into the compose window (web + telnet). They are **not** injected at send time.
- If users compose via external tools, the signature must be included in the message body explicitly.

### Taglines (Sysop-Managed)

- Taglines are stored in `config/taglines.txt`, one per line.
- Edit taglines from **Admin → BBS Settings → Taglines** (uses the admin daemon).
- Taglines are selectable in the web compose screen; a random tagline is preselected by default.
- Outbound packets place the tagline between the tearline and origin line (FidoNet-style).

### Admin Daemon Note

The taglines editor uses new admin daemon commands. Restart the admin daemon after deploying.
