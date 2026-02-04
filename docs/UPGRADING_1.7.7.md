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
