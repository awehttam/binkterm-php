# File Areas

This document explains file area configuration, storage layout, and file area rules.

## Table of Contents

- [Overview](#overview)
  - [Tags](#tags)
- [File Permissions](#file-permissions)
- [Storage Layout](#storage-layout)
- [File Area Rules](#file-area-rules)
  - [Rules Editor](#rules-editor)
    - [Visual Editor Tab](#visual-editor-tab)
    - [JSON Editor Tab](#json-editor-tab)
    - [Pattern Tester](#pattern-tester)
  - [Configuration File Format](#configuration-file-format)
  - [Area Tag Syntax](#area-tag-syntax)
  - [Rule Fields](#rule-fields)
  - [Actions](#actions)
  - [Macros](#macros)
- [Logging](#logging)
- [Admin UI](#admin-ui)
- [Notes](#notes)
  - [Example: Automatic Nodelist Importing](#example-automatic-nodelist-importing)
- [ISO-Backed File Areas](#iso-backed-file-areas)
  - [How It Works](#how-it-works)
  - [Linux Setup](#linux-setup)
  - [Windows Setup](#windows-setup)
  - [Subfolder Navigation](#subfolder-navigation)
  - [Indexing: Preview-Based Import](#indexing-preview-based-import)
  - [Importing Files (CLI)](#importing-files-cli)
  - [Description Catalogue Support](#description-catalogue-support)
  - [Database Records](#database-records)
  - [FILE_ID.DIZ Preview](#file_iddiz-preview)
  - [Limitations](#limitations)
- [Content Preview](#content-preview)
  - [Supported Formats](#supported-formats)
  - [ZIP Browser](#zip-browser)
  - [Auto-Detection in Messages](#auto-detection-in-messages)
- [Public File Areas](#public-file-areas)
  - [Enabling Public Access](#enabling-public-access)
  - [Public File Area Index](#public-file-area-index)
  - [What Guests Can and Cannot Do](#what-guests-can-and-cannot-do)
  - [Security Notes](#security-notes)
- [FREQ (File REQuest)](#freq-file-request)
  - [Enabling FREQ on a File Area](#enabling-freq-on-a-file-area)
  - [Enabling FREQ on Individual Files](#enabling-freq-on-individual-files)
  - [Access Control Summary](#access-control-summary)
  - [Magic Names](#magic-names)
  - [FREQ Response Delivery](#freq-response-delivery)
  - [FREQ Log](#freq-log)
- [Re-Hatching Existing Files](#re-hatching-existing-files)

## Overview

File areas are BinktermPHP's file distribution system — the equivalent of
echomail areas but for files. They hold files that can be uploaded by users,
delivered automatically by FTN TIC processors, or imported from external
sources such as ISO disc images. Each area has its own access controls,
automation rules, and storage directory.

File areas serve several purposes within the BBS:

- **FTN file distribution** — areas receive files from uplinks via TIC
  attachments and forward them to downlinks. A typical installation has a
  `NODELIST` area that automatically receives and imports the network nodelist
  on every update cycle.
- **User uploads** — users can upload files directly through the web interface.
  Each user also has a private file area used for netmail attachments and FREQ
  responses.
- **Sysop file libraries** — areas can be created to host curated collections
  of files for download, including large shareware archives exposed via
  ISO-backed areas without copying files to local storage.
- **Post-upload automation** — file area rules can trigger scripts automatically
  when files arrive, enabling workflows like nodelist importing, virus scanning,
  file routing, and custom notifications.

Each file area is identified by:

- `tag` (e.g., `NODELIST`, `GENERAL_FILES`) — a short uppercase identifier,
  permanent after creation
- `domain` (e.g., `fidonet`, `localnet`) — the FTN network the area belongs to,
  or blank for local areas
- Flags such as `is_local`, `is_active`, `is_public`, `freq_enabled`, `gemini_public`, and
  `scan_virus`

**Key features at a glance:**

| Feature | Description |
|---|---|
| Upload permissions | Control whether uploads are open to all users, registered users only, or sysops only |
| Extension filters | Allowlists and blocklists for file extensions |
| Virus scanning | Optional ClamAV integration on upload and TIC import |
| File preview | Images, video, audio, and text files preview inline in the browser; ZIP files show `FILE_ID.DIZ` |
| Subfolder navigation | Virtual folder hierarchy within a single area |
| Automation rules | Pattern-matched scripts that fire on file arrival |
| ISO-backed areas | Mount a CD/DVD ISO image and expose its directory tree as a browsable area |
| FREQ support | Serve files to remote FTN nodes that send file requests via BinkP or netmail |
| Gemini support | Expose area contents to Gemini protocol clients |
| Public access | Allow unauthenticated visitors to browse and download files without an account (registered feature) |

File areas can be managed via **Admin → Area Management → File Areas** in the web UI.

### Tags

The tag is a short uppercase identifier for the area (e.g. `NODELIST`,
`GENERAL_FILES`). Tags are **permanent** — once a file area is created its tag
cannot be changed. This is because the tag is baked into the storage directory
name (`TAGNAME-ID`) and into file area rule keys. Changing a tag would orphan
the storage directory on disk and break any automation rules keyed to the old
tag name.

## File Permissions

File areas are typically written to by two different OS users:

- **Web server** (e.g. `www-data`) — handles user uploads through the web interface
- **BinkP daemon** (e.g. `binktermphp`) — handles inbound TIC file imports

Both users must be able to read and write each other's files. The recommended approach is to add the BinkP daemon user to the `www-data` group and ensure `data/files/` is group-owned by `www-data`:

```bash
sudo usermod -aG www-data binktermphp
sudo chgrp -R www-data data/files/
```

Substitute `binktermphp` with whatever user the BinkP daemon runs as on your system.

`setup.php` sets `data/files/` and its subdirectories to mode `02775` (setgid + group-writable). The setgid bit causes all new files and subdirectories to inherit the `www-data` group automatically, so no manual `chgrp` is needed after new file areas are created.

All stored files are set to mode `0664` (group-writable) by both the web upload and TIC import code paths.

> **Note:** Group membership changes do not take effect in already-running processes. After adding the daemon user to `www-data`, restart the BinkP daemons with `scripts/restart_daemons.sh` and log out/in on any shell sessions running as that user.

## Storage Layout

File area storage directories use the naming convention:

`TAGNAME-DATABASEID`

Example:

`NODELIST-6`

The database ID suffix ensures uniqueness even if two areas share the same tag
across different domains. The tag portion is included purely for human
readability when browsing the filesystem.

Because the storage path is derived from the tag at upload time and then stored
as an absolute path in the `files` table, existing files are always found
correctly regardless of what happens to the area configuration. New uploads
always go to the directory matching the current tag, so renaming a tag (which is
not permitted via the UI or API) would split files across two directories.

New uploads and TIC imports will automatically create the directory if it does not exist.

## Re-Hatching Existing Files

If you need to re-send a file that is already stored in a file area, use the
CLI rehatch helper instead of uploading it again:

```bash
php scripts/file_hatch.php --file-id=123
php scripts/file_hatch.php CHEVY.RIP LVLY_RIPSCRIP --domain=lovlynet
```

The script:

- looks up the existing file record
- resolves the current stored file on disk
- copies the file back into `data/outbound/`
- regenerates TIC file(s) for the file area's configured uplinks

Limitations:

- local-only file areas cannot be rehatch targets
- private file areas cannot be rehatch targets
- by default the file must be in `approved` status unless you pass
  `--allow-nonapproved`

## File Area Rules

File area rules are configured in `config/filearea_rules.json` and provide
**post-upload automation** — they trigger whenever a file arrives in a file
area, whether via web upload, TIC import, or BinkP inbound delivery.

Rules are the primary mechanism for integrating BinktermPHP with external tools.
Common uses include:

- **Automatic nodelist importing** — when a new nodelist file arrives in the
  `NODELIST` area, a rule fires a script that parses and imports it into the
  database immediately, with no manual intervention required.
- **Virus scanning** — run an external scanner on every uploaded file and
  quarantine or delete infections.
- **File routing** — move specific file types to a different area after arrival
  (e.g. send all `.ZIP` files to a separate archive area).
- **Custom notifications** — send a sysop netmail when a particular filename
  pattern arrives.
- **Archive management** — automatically archive superseded files when new
  versions arrive.

Rules are evaluated in this order:

1. All `global_rules` — applied to every file area
2. Area-specific `area_rules[TAG]` — applied only when the file arrived in a
   matching area

Within each group, rules are checked in order. All matching rules run (unless a
`stop` action halts processing). Rules are evaluated against the filename using
a PHP regex pattern.

### Rules Editor

Rules are managed via **Admin → Area Management → File Area Rules**. The editor
has two tabs that stay in sync — switch freely between them without losing work.

#### Visual Editor Tab

The default view shows two collapsible sections:

- **Global Rules** — rules that run against every file area. Use these for
  system-wide policies (e.g. blocking dangerous extensions or running a virus
  scanner on all uploads).
- **Area Rules** — one expandable group per area tag. Click **Add Area** to
  create a new group, then add rules inside it.

Each rule row shows the rule name, filename pattern, success/fail actions, and
an enabled toggle. The action buttons on each row are:

| Button | Action |
|--------|--------|
| Edit (pencil) | Open the rule modal pre-filled with the current values |
| Clone (copy) | Duplicate the rule — inserts a copy immediately below with " (copy)" appended to the name |
| Delete (trash) | Remove the rule after confirmation |

**Add/Edit Rule modal fields:**

| Field | Description |
|-------|-------------|
| Name | Human-readable label for the rule |
| Enabled | Toggle — disabled rules are stored but skipped at runtime |
| Filename Pattern | PHP-style regex (see [Rule Fields](#rule-fields)) |
| Script | Command to execute on a match, with macro substitution |
| Timeout (s) | Script execution time limit in seconds |
| On Success | Action(s) when the script exits 0 |
| On Failure | Action(s) when the script exits non-zero or times out |
| Move to area | Target area tag for the `move:` action (Success or Failure) |

#### JSON Editor Tab

The raw `config/filearea_rules.json` content is available as a second tab for
bulk edits, copy/paste, or version-controlled updates. Switching to the Visual
tab parses and validates the JSON first — a parse error is shown inline and the
switch is blocked until the JSON is valid. Switching to the JSON tab serialises
the current GUI state.

#### Pattern Tester

Click the flask icon (⚗) next to the Filename Pattern field to expand an
inline tester panel without leaving the modal:

- **Free-text input** — type any filename to test it live against the current
  pattern. A green **match** or grey **no match** badge updates as you type.
- **Load files from area** — when editing a rule inside an area group, the
  tester loads the actual filenames currently stored in that area and shows a
  match/no-match badge next to each one. Useful for verifying a pattern before
  deploying it against real data.

The tester understands PHP-style delimited patterns (e.g. `/^NODELIST\.Z\d+$/i`)
including flags. An unparseable pattern shows an **invalid regex** warning badge.

### Configuration File Format

Rules are stored in `config/filearea_rules.json`. The file is written
automatically when you save from the admin editor, but you can also edit it
directly on disk — changes take effect immediately on the next file arrival (no
daemon restart required).

```json
{
  "global_rules": [
    {
      "name": "Block Dangerous Extensions",
      "pattern": "/\\.(exe|com|bat|cmd|scr|vbs|js)$/i",
      "script": "echo 'Blocked dangerous file: %filename%'",
      "success_action": "delete+notify",
      "fail_action": "keep",
      "enabled": false,
      "timeout": 10
    }
  ],
  "area_rules": {
    "NODELIST@fidonet": [
      {
        "name": "Import FidoNet Nodelist",
        "pattern": "/^NODELIST\\.(Z|A|L|R|J)[0-9]{2}$/i",
        "script": "php %basedir%/scripts/import_nodelist.php %filepath% %domain% --force",
        "success_action": "delete",
        "fail_action": "keep+notify",
        "enabled": true,
        "timeout": 300
      }
    ]
  }
}
```

The top-level object has exactly two keys:

| Key | Type | Description |
|-----|------|-------------|
| `global_rules` | array | Rules applied to every file area, in order |
| `area_rules` | object | Keys are area tags (`TAG` or `TAG@DOMAIN`); values are arrays of rules |

Both arrays may be empty (`[]`). The `area_rules` object may be empty (`{}`). The
file must be valid JSON — comments are not supported.

### Area Tag Syntax

The rule key format depends on whether the file area belongs to a network domain.

#### Network areas (recommended): `TAG@DOMAIN`

> **Always use `TAG@DOMAIN` for file areas linked to a network domain.** The processor looks up rules by constructing `TAG@DOMAIN` from the file's actual domain at runtime, so the match is exact. Two networks can share the same area tag (e.g. `NODELIST@fidonet` and `NODELIST@fsxnet`) and each will only trigger its own rules.

```json
"area_rules": {
  "NODELIST@fidonet": [
    {
      "name": "Import FidoNet Nodelist",
      "pattern": "/^NODELIST\\.(Z|A|L|R|J)[0-9]{2}$/i",
      "script": "php %basedir%/scripts/import_nodelist.php %filepath% %domain% --force"
    }
  ],
  "NODELIST@fsxnet": [
    {
      "name": "Import fsxNet Nodelist",
      "pattern": "/^NODELIST\\.[0-9]{3}$/i",
      "script": "php %basedir%/scripts/import_nodelist.php %filepath% %domain% --force"
    }
  ]
}
```

Individual rules within a `TAG@DOMAIN` group do not need a `domain` field — it is redundant.

#### Local areas: `TAG`

For file areas that are not linked to any network domain, use the plain tag:

```json
"area_rules": {
  "LOCAL_FILES": [
    {
      "name": "Scan uploaded files",
      "pattern": "/.*$/",
      "script": "php %basedir%/scripts/scan_file.php %filepath%"
    }
  ]
}
```

### Rule Fields

- `name` (string): Human-friendly description.
- `pattern` (string): PHP regex used to match `filename`.
- `script` (string): Command to execute when the rule matches.
- `success_action` (string): Action(s) when script exits with code 0.
- `fail_action` (string): Action(s) when script exits non-zero or times out.
- `enabled` (bool): Toggle rule execution.
- `timeout` (int): Script timeout in seconds.
- `domain` (string, optional): Restricts the rule to a specific domain. Only useful under a plain `TAG` key — when using `TAG@DOMAIN` keys this field is redundant and can be omitted.

### Actions

Actions can be chained with `+` and are executed in order:

- `delete` - Delete file from disk and database.
- `keep` - No change (useful for explicit “do nothing”).
- `notify` - Send a sysop netmail notification.
- `move:TAG` - Move file to another file area.
- `archive` - Move file to `data/archive/<AREATAG>/` and mark as `archived`.
- `stop` - Stop processing any remaining rules.

### Macros

Macros are replaced before executing the script:

- `%basedir%` - Application base directory
- `%filepath%` - Full path to file
- `%filename%` - Filename only
- `%filesize%` - File size in bytes
- `%domain%` - File area domain
- `%areatag%` - File area tag
- `%uploader%` - Uploader address or username
- `%ticfile%` - Path to associated TIC file (if available)
- `%tempdir%` - System temp directory

## Logging

Rule execution logs are written to:

- `data/logs/filearea_rules.log` (configurable via `FILEAREA_RULE_ACTION_LOG`)

Debug logging can be enabled with:

- `FILE_ACTION_DEBUG=true`

Debug logs are written to:

- `data/logs/file_action_debug.log`

## Admin UI

All file area management is accessible via **Admin → Area Management** in the
navigation menu:

- **Admin → Area Management → File Areas** — create, edit, and delete file areas
- **Admin → Area Management → File Area Rules** — visual and JSON editor for automation rules (see [Rules Editor](#rules-editor))

Rule changes are saved through the admin daemon and take effect immediately for
subsequent file arrivals.

## Notes

- Rules are applied after virus scanning.
- Infected files are rejected and will not run rules.
- Rule processing runs for both user uploads and TIC imports.

### Example: Automatic Nodelist Importing

The most common real-world use of file area rules is automatic nodelist
processing. FidoNet nodelists arrive as TIC file attachments from your uplink,
land in the `NODELIST` file area, and should be imported into the database
immediately without manual action.

#### Setup

1. Create a file area with tag `NODELIST` linked to your network domain
   (e.g. `fidonet`).
2. Configure your uplink to TIC files to this area.
3. Add the following rule to `config/filearea_rules.json`:

```json
{
  "global_rules": [],
  "area_rules": {
    "NODELIST@fidonet": [
      {
        "name": "Import FidoNet Nodelist",
        "pattern": "/^NODELIST\\.(Z|A|L|R|J)[0-9]{2}$/i",
        "script": "php %basedir%/scripts/import_nodelist.php %filepath% %domain% --force",
        "success_action": "delete",
        "fail_action": "keep+notify",
        "enabled": true,
        "timeout": 300
      }
    ]
  }
}
```

#### How it works

1. Your uplink sends a nodelist file (e.g. `NODELIST.Z53`) via BinkP with a
   matching TIC file.
2. The TIC processor receives the file and places it in the `NODELIST` file
   area.
3. The rule engine checks the filename against the pattern
   `/^NODELIST\.(Z|A|L|R|J)[0-9]{2}$/i`. It matches.
4. `import_nodelist.php` is invoked with the file path and domain. It parses
   the nodelist and updates the database.
5. On success (`exit 0`), the `delete` action removes the nodelist file from
   disk and the database — you can opt to keep them or delete them depending
   on your preference.
6. On failure (non-zero exit or timeout), `keep+notify` leaves the file in
   place for inspection and sends a sysop netmail alert.

The `%domain%` macro passes the domain string (`fidonet`) to the import script
so it knows which network's nodelist it is processing. This allows the same
script to handle nodelists from multiple networks using separate rules keyed by
`NODELIST@fsxnet`, `NODELIST@amiganet`, etc.

#### Multiple networks

If you carry nodelists for more than one network, add a rule group per network:

```json
"area_rules": {
  "NODELIST@fidonet": [
    {
      "name": "Import FidoNet Nodelist",
      "pattern": "/^NODELIST\\.(Z|A|L|R|J)[0-9]{2}$/i",
      "script": "php %basedir%/scripts/import_nodelist.php %filepath% %domain% --force",
      "success_action": "delete",
      "fail_action": "keep+notify",
      "enabled": true,
      "timeout": 300
    }
  ],
  "NODELIST@fsxnet": [
    {
      "name": "Import fsxNet Nodelist",
      "pattern": "/^NODELIST\\.[0-9]{3}$/i",
      "script": "php %basedir%/scripts/import_nodelist.php %filepath% %domain% --force",
      "success_action": "delete",
      "fail_action": "keep+notify",
      "enabled": true,
      "timeout": 300
    }
  ]
}
```

Each network has its own file area and its own rule group. Even though both
areas use the tag `NODELIST`, the `@domain` suffix in the rule key ensures each
rule only fires for files from the correct network.

## ISO-Backed File Areas

An ISO-backed file area uses a CD/DVD ISO image as its file store instead of a
local upload directory. This is ideal for exposing large shareware CD collections
(Simtel, Walnut Creek, InfoMagic, etc.) whose directory trees already contain
`FILES.BBS` or `DESCRIPT.ION` description catalogues — importing thousands of
files takes seconds with no manual description entry required.

ISO areas are **read-only**: uploads, deletes, and renames are blocked. Description
edits (short/long description fields) are stored in the database and always
permitted.

### How It Works

1. The sysop mounts the ISO on the server using any suitable method and notes
   the resulting mount point path.
2. The sysop creates an ISO-backed file area and enters the mount point path
   in the **Mount Point** field. The admin UI shows a green **Accessible**
   badge when the path exists and is readable.
3. The sysop triggers a re-index from the admin UI or CLI. The importer walks
   the ISO directory tree, reads any description catalogues it finds, and writes
   file records to the database. Each record stores a path relative to the mount
   point (`iso_rel_path`).
4. At download or preview time the server reconstructs the absolute path from
   the current mount point and the stored relative path. If the path is not
   accessible the server returns HTTP 503.

---

### Linux Setup

#### Mounting the ISO

Mount the ISO to a directory of your choice. Using a loop device requires root
(or a sudoers rule):

```bash
sudo mkdir -p /srv/iso_mounts/simtel
sudo mount -o loop,ro /srv/isos/simtel.iso /srv/iso_mounts/simtel
```

To remount automatically on reboot, add an entry to `/etc/fstab`:

```
/srv/isos/simtel.iso  /srv/iso_mounts/simtel  iso9660  loop,ro  0  0
```

Alternatively, any method that produces a readable directory — FUSE tools,
udisksctl, or a loop device managed by your init system — works equally well.
The BBS only requires a readable directory path; it does not care how it was
mounted.

#### Creating the area

1. Mount the ISO as described above.
2. Go to **Admin → Area Management → File Areas** and click **Add File Area**.
3. Set **Area Type** to **ISO-backed**.
4. Enter the mount point path in **Mount Point** (e.g. `/srv/iso_mounts/simtel`).
5. Save. The status badge should show **Accessible**.
6. Click **Re-index ISO** to import the file catalogue.

---

### Windows Setup

Windows 10 and 11 can mount ISO files natively without third-party software.

#### Creating the area

1. Mount the ISO in Windows. The simplest way is to right-click the `.iso` file
   and select **Mount**. Windows assigns a drive letter (e.g. `D:`). You can also
   use PowerShell:

   ```powershell
   Mount-DiskImage -ImagePath "C:\isos\simtel.iso"
   # See which drive letter was assigned:
   Get-DiskImage -ImagePath "C:\isos\simtel.iso" | Get-Volume
   ```

2. Go to **Admin → Area Management → File Areas** and click **Add File Area**.
3. Set **Area Type** to **ISO-backed**.
4. Enter the mounted drive letter or path in **Mount Point** (e.g. `D:\`).
5. Save. The status badge should show **Accessible**.
6. Click **Re-index ISO** to import the file catalogue.

#### Re-mounting after a reboot

Windows drive letters can change between reboots when other removable media is
present. After remounting:

1. Right-click the `.iso` → **Mount** (or use `Mount-DiskImage`).
2. Edit the file area in the admin UI and update the **Mount Point** field if the
   drive letter changed. Save.

No re-index is needed unless the ISO itself changed — the existing file records
remain valid and path resolution uses the updated mount point immediately.

#### Dismounting

Clear the **Mount Point** field in the admin UI before dismounting, then:

```powershell
Dismount-DiskImage -ImagePath "C:\isos\simtel.iso"
```

Clearing the mount point prevents the system from attempting to serve files
while the ISO is not accessible.

---

### Subfolder Navigation

ISO images often contain a directory tree of files organised by category or
platform. BinktermPHP exposes this structure to users as browsable subfolders.

#### How subfolders work

- Each distinct subdirectory found on the ISO is represented in the database by a
  special **`iso_subdir`** record (`source_type = 'iso_subdir'`). This is a row
  in the `files` table with no physical file; it exists solely to carry a
  human-readable label and long description for the folder.
- Regular imported files carry the ISO-relative path in `subfolder`
  (e.g. `UTIL/DISK`) and `iso_rel_path` (e.g. `UTIL/DISK/PKUNZIP.ZIP`).
- The file listing page shows `iso_subdir` entries as clickable folder rows. The
  folder label is the record's `short_description` when set, otherwise the
  directory name taken from the ISO path.

#### Editing subfolder descriptions

Admins can set a human-readable label and longer description on any subfolder
from the file listing page:

1. Browse to the file area and navigate to (or stay at) the root listing where
   subfolder rows appear.
2. Click the pencil icon on the subfolder row.
3. Edit the **Short description** (used as the folder label in the UI) and
   **Long description** (shown in the description column).
4. Save.

Changes are stored in the `iso_subdir` record and take effect immediately. They
are preserved across re-indexes because `scanIsoDirectory` only overwrites the
description if it is still blank or matches the bare directory name — manually
set descriptions are never clobbered.

#### Deleting a subfolder

Admins can delete an entire subfolder (including all files and nested
sub-subfolders) from the file listing page by clicking the trash icon on a
subfolder row. This removes all database records for that path. Because ISO files
are read-only, no files are deleted from disk.

---

### Indexing: Preview-Based Import

Clicking **Re-index ISO** opens a preview modal rather than immediately writing
to the database. This lets the sysop review and customise the import before
committing.

#### Preview modal workflow

1. The UI fetches a dry-run scan from `GET /api/fileareas/{id}/preview-iso`.
2. A table is displayed with one row per directory found on the ISO. Each row
   shows:
   - **Include/exclude checkbox** — uncheck to skip that directory entirely.
   - **Directory path** — relative path from the ISO mount root.
   - **Description** — pre-filled from the catalogue entry for the directory
     name (e.g. the `FILES.BBS` entry for `UTIL`), or the existing database
     description if the subfolder has already been indexed. The sysop can edit
     this before importing.
   - **Files** — count of files that would be imported from that directory
     (respects the current import options).
   - **Status badge** — **New** (not yet in the database) or **Existing**
     (already indexed).
3. Adjust descriptions, uncheck directories to skip, set import options (see
   below), then click **Apply Import**.
4. The UI posts to `POST /api/fileareas/{id}/reindex-iso` with the overrides
   and options. The importer runs synchronously and returns counters.

Changing an import option checkbox while the modal is open automatically
re-fetches the preview so file counts and status stay accurate.

#### Import options

| Option | Description |
|---|---|
| **Flat import** | Strip all subdirectory structure — every file is stored at the root of the area with no subfolder. Useful for single-directory ISOs or when subdirectory grouping is not desired. |
| **Catalogue only** | Only import files that appear in a `FILES.BBS` / `DESCRIPT.ION` catalogue. Files present in the directory but absent from the catalogue are skipped. If a directory has no catalogue at all, all files in it are imported regardless. |

These options can also be combined (e.g. flat + catalogue-only).

---

### Importing Files (CLI)

The importer can also be run directly from the command line, which is useful for
large ISOs or scripted re-imports:

```
php scripts/import_iso.php --area=<area_id> [options]
```

| Option | Description |
|---|---|
| `--area=ID` | File area ID to import into **(required)** |
| `--dry-run` | Show what would be imported without writing to the database |
| `--update` | Re-import and update descriptions for files that already exist |
| `--no-descriptions` | Import using filename as description (skip catalogue files) |
| `--dir=PATH` | Only scan this subdirectory of the mount point |
| `--verbose` | Print each file as it is processed |

The importer prints a summary at the end: imported, updated, skipped,
no-description, and error counts.

---

### Description Catalogue Support

The importer looks for a description catalogue in each directory it visits, in
this order:

1. `FILES.BBS` (standard BBS multi-line format)
2. `DESCRIPT.ION` (4DOS/JPSOFT single-line format)
3. `FILE_LIST.BBS`
4. `00INDEX.TXT` / `INDEX.TXT` (common on FTP mirrors burned to CD)

Matching is case-insensitive. Only the first catalogue found in a directory is
used. If no catalogue is found, files are imported with the filename as the
description (unless **Catalogue only** mode is active, in which case a missing
catalogue means all files in that directory are imported regardless).

**`FILES.BBS` format:**

```
FILENAME.EXT  Short description here
ANOTHER.ZIP   Another description that
              continues on the next line
; this is a comment line
```

Continuation lines start with leading whitespace. Lines starting with `;` or
consisting only of `-` characters are treated as comments and ignored.

**`DESCRIPT.ION` format:**

```
filename.ext Description text here
another.zip "Quoted description"
```

One line per file. The first whitespace-delimited token is the filename; the
remainder is the description (surrounding double-quotes are stripped).

---

### Database Records

| `source_type` | Purpose |
|---|---|
| `iso_import` | An actual file imported from the ISO. `iso_rel_path` holds the full relative path from the mount root. `subfolder` holds the parent directory path (NULL for root-level files). |
| `iso_subdir` | A virtual record representing one ISO subdirectory. Carries `short_description` (folder label) and `long_description`. Not shown as a file; rendered as a folder row in the listing UI. |

Both record types live in the `files` table. Queries that list downloadable files
exclude `iso_subdir` records with `source_type IS DISTINCT FROM 'iso_subdir'`.

---

### FILE_ID.DIZ Preview

When a user opens the preview modal for a `.zip` file, the server extracts
`FILE_ID.DIZ` from inside the archive (case-insensitive) and displays it in the
preview panel with CP437→UTF-8 conversion applied. No extraction to disk occurs.

---

### Limitations

| Item | Notes |
|---|---|
| Mounting | The sysop is responsible for mounting the ISO and keeping it mounted. BinktermPHP does not manage mount lifecycle. |
| ARJ/LZH `FILE_ID.DIZ` | Not extracted — PHP has no built-in ARJ reader. Shown as download prompt instead. |
| Uploads | Blocked. ISO areas are permanently read-only. |
| File deletion | Admin-only. Removes the database record; no disk change. Re-index with `--update` to refresh descriptions if the ISO changes. |
| Move / rename | Filename and area moves are blocked. Description edits are allowed. |
| ISO format | ISO 9660, Joliet, and Rock Ridge extensions are supported by the Linux kernel ISO driver. UDF discs can be mounted with `mount -t udf`. |

---

## Content Preview

BinktermPHP renders many file types inline in the browser without requiring a
download. Preview is triggered when a user clicks a file in any file area listing
or follows a shared-file link. The preview modal also appears inside the ZIP
browser when an individual archive entry is selected.

### Supported Formats

| Format | Extensions | Notes |
|---|---|---|
| **Image** | `jpg`, `jpeg`, `png`, `gif`, `webp`, `svg`, `bmp`, `ico`, `tiff`, `tif`, `avif` | Displayed inline; click to open full size in a new tab. |
| **Video** | `mp4`, `webm`, `mov`, `ogv`, `m4v` | HTML5 `<video>` player. Browser codec support applies. |
| **Audio** | `mp3`, `wav`, `ogg`, `flac`, `aac`, `m4a`, `opus` | HTML5 `<audio>` player. |
| **HTML** | `htm`, `html` | Rendered in a sandboxed `<iframe>`. |
| **Plain text** | `txt`, `log`, `nfo`, `diz`, `asc`, `cfg`, `ini`, `conf`, `lsm`, `json`, `xml`, `bat`, `sh` | Displayed in a scrollable `<pre>` block. `nfo` and `diz` use a retro dark theme. |
| **Markdown** | `md` | Server-rendered to HTML and displayed in a styled panel. |
| **ANSI art** | `ans` | Decoded client-side by `ansisys.js` using the full CP437 character set and CGA/VGA colour palette. |
| **PCBoard BBS** | `bbs` | Decoded client-side by `pcboard.js`. Supports `@XY@` CGA colour codes, `@CLS@`, and common PCBoard control macros. CP437 characters are converted server-side before delivery. |
| **Sixel** | `six`, `sixel` | Decoded client-side by `sixel.js` and rendered to a `<canvas>`. |
| **PETSCII stream** | `seq` | Decoded client-side by `ansisys.js` using the Pet Me 64 font. A **Run on C64** button launches the built-in C64 emulator. |
| **C64 PRG** | `prg` | Rendered as a PETSCII gallery via the `/api/files/{id}/prgs` endpoint. Each screen found in the file is shown as a canvas panel. |
| **C64 D64 disk image** | `d64` | Disk directory and all PRG files within are listed and rendered as a PETSCII gallery. |
| **RIPscrip** | `rip` | Decoded and rendered by `ansisys.js`. |
| **MOD music** | `mod` | Played back client-side with an embedded MOD player. |
| **ZIP archive** | `zip` | Opens the ZIP browser (see below). |
| **Heuristic text** | `doc`, `msg` | Server probes the file; if it is ≥90% printable ASCII it is displayed as plain text, otherwise a download prompt is shown. |

Files whose extension is not in any of the above lists show a download prompt.

### ZIP Browser

When a `.zip` file is opened, the browser fetches the archive's table of
contents from `GET /api/files/{id}/zip-contents` and presents a file listing.
Clicking an entry fetches that entry's raw bytes via
`GET /api/files/{id}/zip-entry?path=…` and previews them inline using the same
format detection rules as standalone files.

`FILE_ID.DIZ` (case-insensitive) is automatically highlighted at the top of the
listing when present.

Text and art entries (`txt`, `nfo`, `diz`, `ans`, `bbs`, etc.) are served with
CP437→UTF-8 conversion applied server-side so the client always receives a valid
UTF-8 string. Unknown extensions that pass the ≥90% printable heuristic are also
converted. Binary entries are served as raw bytes.

> **Legacy compression note:** Entries compressed with older algorithms (e.g.
> implode, shrink, reduce) that PHP's ZipArchive cannot decompress show a
> *Legacy compression* notice with a link to download the full archive instead.

### Auto-Detection in Messages

When echomail or netmail message bodies are displayed, the renderer in `app.js`
checks the text for known art-format signatures before falling back to plain
text:

1. **PCBoard** — detected if the body contains `@XY@` colour codes (where XY is
   a two-digit hex CGA attribute), `@CLS@`, or `@NOSTOP@`.
2. **ANSI** — detected if the body contains ESC sequences (`\x1b[`).
3. **Plain text** — everything else.

Detection runs only when `pcboard.js` / `ansisys.js` are loaded on the page
(echomail and netmail templates include both scripts).

---

## Public File Areas

> **Registered feature.** Requires a valid BinktermPHP license. The checkbox is
> hidden and the setting is ignored on unlicensed installations.

An individual file area can be marked **Public**, allowing unauthenticated
visitors to browse its file listing and download files without a BBS account.
All other interactive features — comments, uploads, and area navigation — remain
login-gated.

This is intended for sysops running shareware libraries, FTN file echo mirrors,
or community software archives where open access to the files themselves is
desirable but full account-based interaction is not.

### Enabling Public Access

1. Go to **Admin → Area Management → File Areas** and edit the area.
2. Check **Public File Area** (visible only on registered installations).
3. Save.

The area is now accessible at `/files/AREATAG` without login. Guests see the
file listing and can download files. The sidebar showing other areas, the upload
button, and the comment form are all suppressed for unauthenticated visitors.

Private areas (`is_private = true`) cannot be made public — the access check
always rejects unauthenticated requests for private areas regardless of the
`is_public` flag.

### Public File Area Index

When the **Enable Public Files Index** toggle is turned on in **Admin → BBS
Settings → BBS Features**, a discoverable index page is available at
`/public-files`. This page lists all active public areas with their tag,
description, and file count. A **Public Files** navigation link is shown to
unauthenticated visitors in the site header.

This setting is off by default. Enable it only if you want guests to be able to
discover all your public areas from a single landing page. Individual public
areas are always reachable via their direct URL (`/files/AREATAG`) whether or
not the index is enabled.

### What Guests Can and Cannot Do

| Action | Guest (public area) |
|---|---|
| Browse file listing | ✅ |
| Download files | ✅ |
| Preview files (images, text, ANSI, ZIP, etc.) | ✅ |
| View file comments | ✗ (login required) |
| Post file comments | ✗ (login required) |
| Upload files | ✗ (login required) |
| Navigate to other areas via sidebar | ✗ (sidebar hidden) |

Credits are not charged or awarded for guest downloads.

### Security Notes

- Only non-private areas can be made public. `is_private = true` is always
  enforced regardless of `is_public`.
- Private user areas (tag format `PRIVATE_USER_{id}`) always have
  `is_private = true` and are never reachable by guests.
- The `is_public` flag is enforced server-side on every API request. There is no
  client-side bypass.
- On unlicensed installations the `is_public` flag is silently forced to `false`
  on every save, even if set directly via the API.
- Once an area is marked public, it remains accessible even if the license
  subsequently lapses. This is intentional — it avoids breaking links shared
  with visitors due to a lapsed license.

---

## FREQ (File REQuest)

BinktermPHP can serve files to remote FidoNet nodes that send FREQ requests. Two request mechanisms are supported:

- **Binkp `M_GET`** — the remote node sends a `M_GET` command during a binkp session. Files are delivered in the same session.
- **Netmail FILE_REQUEST** — the remote node sends a netmail with the `FILE_REQUEST` attribute (0x0800). The subject line contains the requested filename(s). The response is delivered as a FILE_ATTACH netmail (see below). The request netmail itself is not stored in the inbox.

### Enabling FREQ on a File Area

1. Go to **Admin → Area Management → File Areas** and edit the area.
2. Check **Allow FREQ** to make all approved files in the area requestable.
3. Optionally set a **FREQ Password**. Remote nodes must supply this password in their `M_GET` command to receive files. Leave blank for open access.
4. Save.

Only files with an `approved` status are served. Files in private areas are never served regardless of this setting.

### Enabling FREQ on Individual Files

A specific file can be made FREQable without opening its entire area:

1. Go to **Files** and share the file using the share button.
2. The **FREQ Accessible** checkbox (checked by default) makes the file requestable via FREQ.
3. Uncheck it if you want a web-only share link.

Shared file FREQ access respects expiration dates — an expired share is not served even if the file itself is still active.

### Access Control Summary

A file is served if **either** condition is true:

| Condition | Required |
|---|---|
| File is in an area with **Allow FREQ** enabled | Area password must match if set; area must not be private |
| File has an active, non-expired share with **FREQ Accessible** checked | Area must not be private |

Files with a status other than `approved` (pending, quarantined, rejected) are never served.

### Magic Names

Requesting a magic name returns a generated file listing rather than a literal file:

| Requested filename | Response |
|---|---|
| `ALLFILES` or `FILES` | Combined listing of all FREQ-enabled areas in `FILES.BBS` format |
| `<AREA_TAG>` | Listing for that specific area (if FREQ is enabled on it) |

Magic name responses are generated at request time and staged for delivery like any other FREQ response.

### FREQ Response Delivery

When a FREQ request is resolved, the response file is delivered as a
FILE_ATTACH netmail using one of two methods:

1. **Crashmail (direct)** — if the requesting node is resolvable in the nodelist
   with a hostname (IBN/INA flag), BinktermPHP connects directly and delivers
   the attachment. No action is needed from the requesting node.

2. **Hold directory (reverse crash)** — if the requesting node cannot be reached
   directly, the FILE_ATTACH netmail packet and the attached file are written to a
   per-node hold directory (`data/outbound/hold/<address>/`). The files are
   delivered during the next binkp session with that node, regardless of which
   side initiates the connection.

   BinktermPHP also sends the requesting node a plain notification netmail (via
   normal hub routing) informing them that their files are ready to collect. To
   pick up queued files, the requesting node can run:

   ```bash
   php scripts/freq_pickup.php <your-address>
   ```

   See [CLI.md](CLI.md#freq-file-pickup) for full usage.

> **Note:** Routed FILE_ATTACH netmail is intentionally not used because FTN
> hubs typically strip file attachments from forwarded messages.

### FREQ Log

All FREQ requests — served and denied — are recorded. View them at:

- **Admin → FREQ Log** (`/admin/freq-log`)

The log shows the requesting node address, filename, whether it was served, the deny reason if applicable, and the request source (`m_get` for binkp sessions, `netmail` for FILE_REQUEST netmails). You can filter by node, filename, served/denied status, and source.
