# File Areas

This document explains file area configuration, storage layout, and file area rules.

## Overview

File areas are categorized by:

- `tag` (e.g., `NODELIST`, `GENERAL_FILES`)
- `domain` (e.g., `fidonet`, `localnet`)
- Flags such as `is_local`, `is_active`, and `scan_virus`

File areas can be managed via `/fileareas` in the web UI.

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
        "domain": "fidonet",
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

Area rule keys should use the domain syntax to ensure rules target the correct file area:

**Format:** `TAG@DOMAIN`

Examples:
- `"NODELIST@fidonet"` - FidoNet NODELIST area
- `"FILES@lovlynet"` - LOVLYNET FILES area
- `"INBOUND@fsxnet"` - fsxNet INBOUND area

For local file areas without a network domain, use the tag alone:
- `"LOCAL_FILES"` - Local area

```json
"area_rules": {
  "NODELIST@fidonet": [
    {
      "name": "Import FidoNet Nodelist",
      "pattern": "/^NODELIST\\.(Z|A)[0-9]{2}$/i",
      "script": "php scripts/import_nodelist.php %filepath% fidonet"
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
- `domain` (string, optional): If provided, rule only applies to file areas in that domain.

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
