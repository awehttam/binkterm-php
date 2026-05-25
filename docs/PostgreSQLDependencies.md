# PostgreSQL Dependency Inventory

Developer-facing inventory of PostgreSQL-specific features, schema choices, and code paths in BinktermPHP.

This is a living document. BinktermPHP is PostgreSQL-only today, so PostgreSQL-specific code is allowed. The purpose of this file is not to ban PostgreSQL features; it is to keep a running list of deliberate dependencies so future compatibility work is scoping a known surface area rather than rediscovering it from scratch.

`CLAUDE.md` includes guidance for AI agents to update this document when they add a new PostgreSQL-specific dependency that future compatibility work would need to account for.

For the higher-level direction and rationale, see:

- `docs/proposals/MariaMySQLCompat.md`

## How To Use This Document

- Update this file when adding a new PostgreSQL-specific dependency that future MariaDB/MySQL compatibility work would need to understand.
- Do not list every ordinary SQL query here. Only track dependencies that are meaningfully PostgreSQL-specific.
- If a dependency is intentional, say so plainly.
- If a future-compatible abstraction exists, note it.

Suggested fields for new entries:

- dependency or pattern
- why it is PostgreSQL-specific
- current locations
- likely future compatibility strategy
- migration difficulty: low, medium, or high

## Current Support Position

- PostgreSQL is the only supported database backend.
- MariaDB/MySQL support is not an active roadmap item.
- This document exists to preserve optionality for the future, not to require present-day portability work on every change.

## Connection And Bootstrap

### Hardcoded PostgreSQL DSN and session setup

- Why PostgreSQL-specific:
  - uses `pgsql:` DSN construction
  - uses PostgreSQL session commands such as `SET TIME ZONE` and `SET application_name`
- Current locations:
  - `src/Database.php`
- Likely future strategy:
  - introduce a small database platform layer for DSN construction and session initialization
- Difficulty:
  - medium

### PostgreSQL-only base schema install path

- Why PostgreSQL-specific:
  - installer loads `database/postgresql_schema.sql`
- Current locations:
  - `src/Database.php`
  - `scripts/install.php`
- Likely future strategy:
  - select base schema by configured engine
  - add `database/mysql_schema.sql` only if compatibility work is ever pursued
- Difficulty:
  - medium

## Realtime And Event Signaling

### `LISTEN/NOTIFY` and native `pg_*` usage

- Why PostgreSQL-specific:
  - depends on PostgreSQL pub/sub behavior
  - uses native PostgreSQL client functions, not generic PDO
- Current locations:
  - `scripts/ai_bot_daemon.php`
- Likely future strategy:
  - replace with an event transport abstraction
  - possible backends: Redis pub/sub, polling, queue daemon
- Difficulty:
  - high

### `pg_notify(...)` from application code

- Why PostgreSQL-specific:
  - directly calls PostgreSQL notification functions
- Current locations:
  - `src/Realtime/BinkStream.php`
- Likely future strategy:
  - notifier interface with PostgreSQL implementation now and alternative backend later
- Difficulty:
  - high

### Trigger-based realtime signaling

- Why PostgreSQL-specific:
  - uses PL/pgSQL trigger functions and `pg_notify`
- Current locations:
  - `database/migrations/v1.11.0.58_dashboard_stats_triggers.php`
  - `database/migrations/v1.11.0.67_targeted_dashboard_stats_triggers.php`
- Likely future strategy:
  - move event signaling behind a service abstraction
  - possibly replace trigger wakeups with app-side event publishing or Redis
- Difficulty:
  - high

## SQL Dialect Dependencies

### `RETURNING id`

- Why PostgreSQL-specific:
  - PostgreSQL supports row-returning insert/update syntax heavily used in the codebase
- Current locations:
  - multiple files under `src/`, `routes/`, and `database/migrations/`
- Likely future strategy:
  - isolate insert-id retrieval behind repository/service helpers
- Difficulty:
  - medium

Representative examples:

- `routes/api-routes.php`
- `routes/admin-routes.php`
- `src/Advertising.php`
- `src/BbsDirectory.php`
- `src/Chat/ChatMessageService.php`
- `src/MessageHandler.php`

### `ILIKE`

- Why PostgreSQL-specific:
  - case-insensitive text matching syntax is PostgreSQL-specific
- Current locations:
  - many search and filtering queries across `src/`, `routes/`, and scripts
- Likely future strategy:
  - controlled collation rules or `LOWER(...) LIKE LOWER(...)`
  - centralize identity/search comparison helpers where practical
- Difficulty:
  - medium

Representative examples:

- `src/AddressBookController.php`
- `src/AdminController.php`
- `src/BbsDirectory.php`
- `src/Nodelist/NodelistManager.php`
- `routes/api-routes.php`

### `DISTINCT ON`

- Why PostgreSQL-specific:
  - PostgreSQL-only syntax for "first row per group" queries
- Current locations:
  - `routes/webdoor-routes.php`
  - `scripts/activity_digest.php`
  - `src/Auth.php`
  - `database/migrations/v1.11.0.82_echomail_perf.sql`
- Likely future strategy:
  - rewrite with window functions or grouped subqueries
- Difficulty:
  - medium

### PostgreSQL casts such as `?::jsonb`

- Why PostgreSQL-specific:
  - PostgreSQL cast syntax is used directly in application SQL
- Current locations:
  - `routes/webdoor-routes.php`
  - `routes/admin-routes.php`
  - `src/AI/UsageRecorder.php`
  - `src/AiBot/AiBotRepository.php`
  - `src/MessageHandler.php`
  - `src/Qwk/QwkBuilder.php`
- Likely future strategy:
  - centralize JSON binding and mutation logic
- Difficulty:
  - medium

### `AT TIME ZONE`

- Why PostgreSQL-specific:
  - uses PostgreSQL timezone conversion syntax and semantics in queries and migrations
- Current locations:
  - `routes/admin-routes.php`
  - `routes/api-routes.php`
  - `src/Auth.php`
  - `src/DashboardStatsService.php`
  - `src/MessageHandler.php`
  - many timestamp migrations
- Note:
  - BinktermPHP already aims to store timestamps in UTC; this dependency is about PostgreSQL-specific conversion, legacy migration, and reporting syntax rather than a non-UTC storage model
- Likely future strategy:
  - prefer UTC storage and UTC comparison rules
  - move display-timezone conversion out of hot SQL paths where practical
- Difficulty:
  - medium

## Schema And Type Dependencies

### `SERIAL` and `BIGSERIAL`

- Why PostgreSQL-specific:
  - PostgreSQL sequence-backed shorthand types
- Current locations:
  - `database/postgresql_schema.sql`
  - many files in `database/migrations/`
- Likely future strategy:
  - map to engine-specific auto-increment behavior if ever needed
- Difficulty:
  - low to medium

### `TIMESTAMPTZ`

- Why PostgreSQL-specific:
  - timezone-aware timestamp type used widely in schema and migrations
- Current locations:
  - `database/postgresql_schema.sql`
  - many files in `database/migrations/`
- Likely future strategy:
  - define explicit UTC storage policy and per-engine mapping
- Difficulty:
  - medium

### `INET`

- Why PostgreSQL-specific:
  - PostgreSQL-native IP address type
- Current locations:
  - `database/postgresql_schema.sql`
  - related session/login tables derived from base schema
- Likely future strategy:
  - likely map to `VARCHAR(45)` if compatibility is ever pursued
- Difficulty:
  - low

### `JSONB`

- Why PostgreSQL-specific:
  - used for mutable metadata, event payloads, settings-like state, and draft/session structures
- Current locations:
  - many files in `database/migrations/`
  - related queries under `src/` and `routes/`
- Likely future strategy:
  - map to `JSON` with backend-specific helper behavior
  - avoid scattering direct PostgreSQL JSON assumptions
- Difficulty:
  - medium to high

Representative schema examples:

- `database/migrations/v1.11.0.55_sse_events_table.php`
- `database/migrations/v1.11.0.70_drafts_meta_column.sql`
- `database/migrations/v1.11.0.73_dashboard_layout.sql`
- `database/migrations/v20260517123000_add_packet_bbs_session_state.sql`

## Indexing, Search, And Constraints

### Functional indexes on `LOWER(...)`

- Why PostgreSQL-specific:
  - relies on functional-index strategy for case-insensitive lookups and uniqueness
- Current locations:
  - `database/postgresql_schema.sql`
  - `database/migrations/v1.11.0.15_users_lower_username_index.sql`
  - `database/migrations/v1.11.0_bbs_directory.sql`
- Likely future strategy:
  - explicit normalization policy plus per-engine index design
- Difficulty:
  - medium

### Trigram search indexes

- Why PostgreSQL-specific:
  - uses PostgreSQL `pg_trgm` strategy for fast substring/`ILIKE` search
- Current locations:
  - `database/migrations/v1.11.0.12_trigram_search_indexes.sql`
  - `database/migrations/v1.11.0.27_file_search_indexes.sql`
- Likely future strategy:
  - per-engine search/index redesign
  - possibly accept degraded search behavior if compatibility is ever introduced experimentally
- Difficulty:
  - high

### Trigger-enforced username/real-name collision rules

- Why PostgreSQL-specific:
  - implemented with PostgreSQL trigger/function logic
- Current locations:
  - `database/migrations/v1.10.15_username_realname_collision_trigger.php`
- Likely future strategy:
  - preserve the rule at application level and redesign DB-level enforcement per engine if needed
- Difficulty:
  - medium

## Migration Hotspots

### PostgreSQL-specific migration language and behavior

- Why PostgreSQL-specific:
  - many historical migrations use PostgreSQL DDL, PostgreSQL functions, or timezone semantics
- Current locations:
  - `database/migrations/`
  - `scripts/upgrade.php`
- Likely future strategy:
  - if compatibility is ever pursued, prefer new PHP migrations for portability-sensitive changes
  - some historical migrations may remain PostgreSQL-only and be rolled into a separate base schema instead of replayed verbatim
- Difficulty:
  - high

## Guidance For New Entries

When adding a new entry, keep it brief and concrete. A good note answers:

1. What is the PostgreSQL-specific dependency?
2. Where is it?
3. Was it chosen intentionally?
4. What would a future compatibility effort probably replace it with?

If the answer is "this is PostgreSQL-specific on purpose because it is clearly better here," that is fine. Write that down and move on.
