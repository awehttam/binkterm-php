# New FTN Network

Use this skill to register a new FTN network in the database via a timestamped PHP migration.

## Step 1: Gather information

Ask the developer for all six values (collect them in one go if they weren't provided with the invocation):

| Field | Required | Default | Description |
|-------|----------|---------|-------------|
| **Domain** | Yes | — | Unique lowercase slug — letters, numbers, hyphens, underscores |
| **Name** | Yes | — | Human-readable display name |
| **Description** | Yes | — | Short description of the network |
| **Website** | No | (none) | Network's website URL |
| **Posting name policy** | No | `real_name` | How senders appear: `username` or `real_name` |
| **Default code page** | No | `CP437` | Default charset for messages (any iconv-compatible name) |

Domain rules (enforced by `NetworkManager::isValidDomain()`):
- Must match `/^[a-z0-9][a-z0-9_-]{0,49}$/`
- Lowercase only; no spaces

Posting name policy must be one of: `username`, `real_name`.

Common code page values: `CP437`, `UTF-8`, `CP850`, `CP866`.

## Step 2: Confirm

Show a confirmation block before doing anything:

```
Ready to create migration:
  Domain:      <domain>
  Name:        <name>
  Description: <description>
  Website:     <website or (none)>
  Name policy: <username|real_name>
  Code page:   <charset>
```

Ask: **"Proceed? (y/n)"** — do not continue until the developer confirms.

## Step 3: Create the migration file

Run the migration generator:

```bash
php scripts/migration.php create "upsert_<domain>_ftn_network" php
```

This produces a file such as `database/migrations/vYYYYMMDDHHMMSS_upsert_<domain>_ftn_network.php`.

## Step 4: Write the migration body

Replace the generated stub with:

```php
<?php
// Migration: <timestamp> - upsert <domain> ftn network
// Created: <date> UTC

return function (\PDO $db): bool {
    $nm = new \BinktermPHP\NetworkManager($db);
    $existing = $nm->getByDomain('<domain>');
    $data = [
        'domain'              => '<domain>',
        'name'                => '<name>',
        'description'         => '<description>',
        'website'             => <'website' or null>,
        'network_type'        => \BinktermPHP\NetworkManager::NETWORK_TYPE_FIDONET,
        'posting_name_policy' => '<username|real_name>',
        'default_charset'     => '<charset>',
    ];
    if ($existing) {
        $nm->update((int)$existing['id'], $data);
        echo "Updated existing network: <name> (<domain>)\n";
    } else {
        $nm->create($data);
        echo "Created new network: <name> (<domain>)\n";
    }
    return true;
};
```

Substitute all `<placeholders>` with the confirmed values.
For `website`: use a PHP string `'https://...'` when provided, or `null` when omitted.

## Step 5: Remind the developer

After writing the migration file, output:

```
Migration created: database/migrations/<filename>

To apply it:
  php scripts/setup.php
```
