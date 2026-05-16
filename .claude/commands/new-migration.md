# New Migration

## Creating the file

Use the migration script to generate the file with a correct timestamp ID:

```bash
php scripts/migration.php create "description"        # SQL migration
php scripts/migration.php create "description" php    # PHP migration
```

## Migration ID format (authoritative)

New migrations must use timestamp IDs in UTC: `vYYYYMMDDHHMMSS_<description>.sql` or `.php`, e.g. `v20260503143000_add_user_preferences.sql`. Legacy `vX.Y.Z_*` migrations are supported for existing files only. Do not create new sequential versioned migrations; timestamp IDs reduce collisions when multiple developers work in parallel.

## PHP migration patterns

See `docs/DEVELOPER_GUIDE.md` for PHP migration patterns (direct execution vs callable).

## Index and constraint rules

Do NOT create an explicit `CREATE INDEX` on a column that already has a `UNIQUE` constraint — PostgreSQL automatically creates a unique index for every `UNIQUE` constraint, which serves lookups identically to a plain index.

## After creating a migration

Always write out schema changes — a database must be creatable from scratch, so migrations are the only authoritative record of schema state.

Run `php scripts/setup.php` when upgrading — this executes both migrations and other upgrade-related tasks (file permissions, etc.).
