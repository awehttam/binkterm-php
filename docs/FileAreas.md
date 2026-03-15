# File Areas

This document explains file area configuration, storage layout, and file area rules.

## Overview

File areas are categorized by:

- `tag` (e.g., `NODELIST`, `GENERAL_FILES`)
- `domain` (e.g., `fidonet`, `localnet`)
- Flags such as `is_local`, `is_active`, and `scan_virus`

File areas can be managed via `/fileareas` in the web UI.

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

New uploads and TIC imports will automatically create the directory if it does not exist.

## File Area Rules

File area rules are configured in `config/filearea_rules.json` and allow post-upload automation.

Rules are applied in this order:

1. All `global_rules`
2. Area-specific `area_rules[TAG]`

Rules are evaluated by regex against the filename. Each matching rule runs its script in order.

### Rule File Structure

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

The rule configuration editor is available at:

- `/admin/filearea-rules`

Changes are saved through the admin daemon.

## Notes

- Rules are applied after virus scanning.
- Infected files are rejected and will not run rules.
- Rule processing runs for both user uploads and TIC imports.

## FREQ (File REQuest)

BinktermPHP can serve files to remote FidoNet nodes that send FREQ requests. Two request mechanisms are supported:

- **Binkp `M_GET`** — the remote node sends a `M_GET` command during a binkp session. Files are delivered in the same session.
- **Netmail FILE_REQUEST** — the remote node sends a netmail with the `FILE_REQUEST` attribute (0x0800). The subject line contains the requested filename(s). The response is delivered as a FILE_ATTACH netmail (see below). The request netmail itself is not stored in the inbox.

### Enabling FREQ on a File Area

1. Go to **Admin → File Areas** and edit the area.
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
