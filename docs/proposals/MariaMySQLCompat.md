# MariaDB / MySQL Compatibility Proposal

## Summary

BinktermPHP is currently PostgreSQL-only in both code and operations. Adding MariaDB/MySQL support is possible, but it is not a small "swap the PDO driver" task. The project currently depends on PostgreSQL at four levels:

- connection/bootstrap code in `src/Database.php`
- install and upgrade tooling in `scripts/install.php` and `scripts/upgrade.php`
- schema and migrations in `database/postgresql_schema.sql` and `database/migrations/`
- application queries and realtime/eventing code spread across `src/`, `routes/`, and daemon scripts

The practical path is to introduce a database abstraction layer for SQL dialect differences, keep PostgreSQL as the first-class backend initially, and then port the schema, migrations, and high-risk query patterns in phases.

My recommendation is:

1. Treat MariaDB/MySQL support as a medium-to-large engineering project.
2. Target MariaDB 10.11+ and MySQL 8.0+ only.
3. Do not attempt to support older MySQL variants.
4. Keep PostgreSQL as the reference implementation until the full test suite passes against both engines.

## Recommended Direction

The default recommendation for BinktermPHP should be to remain PostgreSQL-only unless there is a concrete adoption or deployment reason to support MariaDB/MySQL.

Reasons:

- the current architecture already relies on PostgreSQL features in schema, queries, migrations, and realtime signaling
- MariaDB/MySQL support would be an ongoing maintenance cost, not a one-time port
- PostgreSQL is a better fit for the current eventing model, JSON-heavy state, timestamp handling, and query patterns already in the codebase
- keeping one supported database reduces correctness risk in user identity rules, search behavior, and migration safety

In practical terms, PostgreSQL-only is the cleaner engineering choice for this project.

MariaDB/MySQL support should only be pursued if one of these becomes true:

- there is meaningful user demand that is blocking adoption
- the project must run in a hosting environment where PostgreSQL is not realistic
- the project is willing to support a reduced-feature or explicitly experimental non-PostgreSQL backend

The rest of this document should be read as background analysis of what would be required if MariaDB/MySQL support is ever pursued, not as a recommendation that the project should add it now.

## Why PostgreSQL Fits Better

For BinktermPHP specifically, PostgreSQL is a better fit than MariaDB because the project already depends on database features and behaviors that PostgreSQL supports more naturally.

Key advantages in this codebase:

- better support for advanced SQL patterns already used by the project, including `RETURNING`, `DISTINCT ON`, rich upsert patterns, and complex query expressions
- stronger JSON support through `JSONB`, which is a better fit for event payloads, metadata, configuration-like state, and draft/session data
- better realtime/event integration through `LISTEN/NOTIFY`, which aligns with the current live-update and daemon signaling approach
- better support for functional and search-oriented indexing strategies used by the project
- cleaner handling of UTC-focused timestamp and timezone workflows
- fewer compatibility edge cases around case-insensitive identity rules and uniqueness enforcement

The practical benefit is not just performance. It is simpler code, fewer query rewrites, fewer migration branches, and lower long-term maintenance risk.

## Suggested Public Answer

If asked publicly whether MariaDB/MySQL support is coming soon, a reasonable answer is:

> I looked further into this and the answer is.. probably not.
>
> BinktermPHP was intentionally PostgreSQL-first, and that is likely to remain the default direction unless there is strong demand. The codebase already leans heavily on PostgreSQL features like JSONB, RETURNING, ILIKE, DISTINCT ON, functional indexes, and LISTEN/NOTIFY for some realtime behavior, so MariaDB/MySQL support would be a fairly large effort rather than a simple compatibility switch.
>
> It is not impossible, and I have documented what it would take, but at the moment the priority is improving BinktermPHP rather than carrying a second database backend. If enough users actually need MariaDB/MySQL, I can revisit it, but for now PostgreSQL is the supported path.

## Current PostgreSQL Lock-In

### Connection and bootstrap

The application currently hardcodes PostgreSQL connection behavior:

- `src/Database.php` builds a `pgsql:` DSN
- `src/Database.php` runs `SET TIME ZONE 'UTC'`
- `src/Database.php` runs `SET application_name = ...`
- `src/Database.php` reads `database/postgresql_schema.sql`

The installer is also PostgreSQL-specific:

- `scripts/install.php` loads `database/postgresql_schema.sql`
- `scripts/install.php` uses `lastInsertId()` in admin-user setup, which is already noted in `CLAUDE.md` as a PostgreSQL gotcha and should be fixed even before any portability work

The upgrader creates schema with PostgreSQL syntax:

- `scripts/upgrade.php` creates `database_migrations` using `SERIAL`

### Schema and migration assumptions

The schema and migrations rely heavily on PostgreSQL features:

- `SERIAL` and `BIGSERIAL`
- `TIMESTAMPTZ`
- `INET`
- `JSONB`
- `ON CONFLICT`
- functional indexes such as `LOWER(real_name)`
- partial indexes
- trigger functions written in PostgreSQL PL/pgSQL
- `pg_notify(...)`

There are also PostgreSQL-specific admin/metadata assumptions:

- `SET application_name`
- `pg_stat_activity`-style operational visibility
- migration logic and daemon code that assume PostgreSQL session and timezone behavior

### Query syntax in app code

The codebase currently uses PostgreSQL-specific SQL constructs in many places:

- `RETURNING id`
- `ILIKE`
- `DISTINCT ON`
- `AT TIME ZONE`
- `?::jsonb` and similar casts
- expressions that depend on PostgreSQL JSON operators/functions

Examples visible in the current tree include:

- `routes/api-routes.php`
- `routes/admin-routes.php`
- `routes/webdoor-routes.php`
- `src/MessageHandler.php`
- `src/Advertising.php`
- `src/BbsDirectory.php`
- `src/Chat/ChatMessageService.php`
- `src/Realtime/BinkStream.php`
- `scripts/activity_digest.php`

### Realtime and daemon/event model

Some subsystems are not just "query syntax" dependent; they rely on PostgreSQL behavior:

- `src/Realtime/BinkStream.php` uses `pg_notify`
- trigger migrations such as `database/migrations/v1.11.0.58_dashboard_stats_triggers.php` and `database/migrations/v1.11.0.67_targeted_dashboard_stats_triggers.php` use `pg_notify` from trigger functions
- `scripts/ai_bot_daemon.php` uses native `pg_*` functions and `LISTEN/NOTIFY`

This is one of the largest compatibility issues. MariaDB/MySQL do not provide a drop-in equivalent to PostgreSQL `LISTEN/NOTIFY`.

## Main Compatibility Problems

### 1. SQL dialect mismatch

The easiest problems are still numerous:

- `ILIKE` must become collation-aware `LIKE`, `LOWER(...) LIKE LOWER(...)`, or generated-column/index strategies
- `RETURNING` must be replaced with a cross-database insert-id strategy, or an abstraction that uses `RETURNING` only on PostgreSQL
- `DISTINCT ON` must be rewritten using window functions or grouped subqueries
- `AT TIME ZONE` expressions must be rewritten per engine
- cast syntax like `?::jsonb` must be replaced entirely

### 2. JSON storage differences

PostgreSQL `JSONB` is used widely for config/state/event payloads.

MariaDB and MySQL differ here:

- MySQL 8 has a native `JSON` type, but not PostgreSQL `JSONB`
- MariaDB's `JSON` is historically an alias layered on `LONGTEXT` with JSON validation semantics, depending on version

Implications:

- some columns can map to `JSON`
- some update expressions must change
- JSON indexing strategy will need per-engine design
- JSON containment and merge operations will need wrappers

Any code that currently relies on PostgreSQL-style casts or JSON mutation should be isolated behind helper methods first.

### 3. Case-insensitive uniqueness and lookup semantics

This project has important identity rules:

- usernames are unique
- real names are unique
- username/real-name collisions are prevented

Today that is enforced with PostgreSQL-specific techniques such as:

- `LOWER(...)` unique indexes
- trigger logic such as `database/migrations/v1.10.15_username_realname_collision_trigger.php`

MariaDB/MySQL introduce risk here because default collations are often already case-insensitive, but behavior varies by charset/collation and accent rules. That matters for:

- auth lookups
- duplicate-prevention rules
- cross-field collision checks
- predictable FTN/BBS naming behavior

This area needs explicit normalization policy, not just "let the database collation decide."

### 4. Timestamp and timezone behavior

The codebase makes heavy use of UTC and currently depends on PostgreSQL behavior around:

- `TIMESTAMPTZ`
- `NOW() AT TIME ZONE 'UTC'`
- session timezone changes

MariaDB/MySQL support timezones differently. The safest cross-database rule is likely:

- store UTC timestamps only
- write UTC from PHP where practical
- avoid database-specific timezone transformations in hot queries

That would reduce portability risk, but it would require query cleanup across the codebase.

### 5. Realtime event delivery

The current event bus uses PostgreSQL as a signaling mechanism, not just storage.

For MariaDB/MySQL support, this likely needs to change to one of:

- database table polling
- Redis pub/sub
- a local queue daemon
- filesystem/socket-based signaling for single-node installs

My preference would be:

- keep the existing PostgreSQL `LISTEN/NOTIFY` path for PostgreSQL
- add a backend-neutral event transport abstraction
- implement Redis pub/sub or table polling as the non-PostgreSQL fallback

### 6. Index and performance differences

Some current indexes are PostgreSQL-oriented, especially around search:

- `ILIKE`-oriented query patterns
- trigram-index migrations such as `database/migrations/v1.11.0.12_trigram_search_indexes.sql` and `database/migrations/v1.11.0.27_file_search_indexes.sql`
- functional indexes on `LOWER(...)`
- partial indexes in some migrations

MariaDB/MySQL support equivalent outcomes in some cases, but not the same DDL or planner behavior. Search-heavy features may regress unless re-tuned per engine.

## Suggested Architecture

### Database driver abstraction

Introduce a small internal database platform layer, not a full ORM rewrite.

Suggested pieces:

- `src/DatabasePlatform.php` interface or equivalent
- `src/DatabasePlatform/PostgresPlatform.php`
- `src/DatabasePlatform/MySqlPlatform.php`

Responsibilities:

- build DSN
- configure session settings
- expose engine name/version
- generate dialect-specific fragments for:
  - insert returning id
  - case-insensitive comparisons
  - UTC timestamp expressions
  - JSON parameter handling
  - upsert syntax where it differs semantically

This layer should stay thin. The goal is not to hide all SQL, only the unstable parts.

### Schema strategy

Maintain separate base schema files:

- `database/postgresql_schema.sql`
- `database/mysql_schema.sql`

Migrations should support one of two approaches:

1. Dual migration files where needed:
   - `...sql` plus engine-specific branching in the upgrader
2. Prefer PHP migrations for all future portability-sensitive changes

I would lean toward more PHP migrations going forward for any schema that must support both backends.

### Realtime/event abstraction

Create an event notifier interface:

- PostgreSQL implementation using `LISTEN/NOTIFY`
- generic implementation using polling or Redis

Likely touchpoints:

- `src/Realtime/BinkStream.php`
- `src/Realtime/WebSocketServer.php`
- `scripts/ai_bot_daemon.php`
- trigger-based migrations that currently call `pg_notify`

## Compatibility Mapping

### Type mapping

Rough initial mapping:

| PostgreSQL | MariaDB/MySQL candidate | Notes |
| --- | --- | --- |
| `SERIAL` | `INT AUTO_INCREMENT` | straightforward |
| `BIGSERIAL` | `BIGINT AUTO_INCREMENT` | straightforward |
| `BOOLEAN` | `TINYINT(1)` or `BOOLEAN` | treat consistently in PHP |
| `TIMESTAMPTZ` | `DATETIME` or `TIMESTAMP` | prefer explicit UTC rules |
| `INET` | `VARCHAR(45)` | easier than engine-specific IP types |
| `JSONB` | `JSON` | behavior differs by MariaDB version |
| `TEXT` | `TEXT` | straightforward |

### Query pattern mapping

| PostgreSQL pattern | MariaDB/MySQL rewrite |
| --- | --- |
| `ILIKE ?` | `LIKE ?` under controlled collation, or `LOWER(col) LIKE LOWER(?)` |
| `RETURNING id` | execute insert, then use engine-specific inserted-id retrieval |
| `DISTINCT ON (...)` | `ROW_NUMBER() OVER (...)` or grouped subquery |
| `NOW() AT TIME ZONE 'UTC'` | write UTC from PHP, or use engine-specific UTC function |
| `?::jsonb` | bind JSON text to `JSON` column without PostgreSQL casts |
| `ON CONFLICT ... DO UPDATE` | `ON DUPLICATE KEY UPDATE` with care |

The upsert row is especially important: PostgreSQL and MySQL/MariaDB upsert syntax look similar conceptually, but conflict-target behavior and returned-row behavior are not identical.

## Proposed Phases

### Phase 1: Inventory and abstraction

- add database platform detection/configuration
- remove hardcoded `pgsql:` assumptions from `src/Database.php`
- fix remaining `lastInsertId()` usage so insert-id logic is explicit and platform-aware
- identify every PostgreSQL-only query with a tracking list

Deliverable:

- app still runs only on PostgreSQL, but the codebase has clear seams for another backend

### Phase 2: Installer and schema portability

- add `database/mysql_schema.sql`
- update `scripts/install.php` to select schema by configured engine
- update `scripts/upgrade.php` to create `database_migrations` portably
- convert portability-sensitive future migrations to PHP where appropriate

Deliverable:

- fresh install works on MariaDB/MySQL for a reduced feature set

### Phase 3: Core CRUD and auth paths

Port the highest-value user flows first:

- login/session validation
- user registration/admin approval
- netmail/echomail read paths
- posting paths
- settings/profile flows

Deliverable:

- basic web BBS usage works on MariaDB/MySQL

### Phase 4: Search, reports, and admin features

- replace `ILIKE` patterns
- rewrite `DISTINCT ON` queries
- port date aggregation/reporting queries
- retune indexes per engine

Deliverable:

- admin and search-heavy features behave correctly and with acceptable performance

### Phase 5: Realtime and advanced features

- abstract `pg_notify` usage
- replace `LISTEN/NOTIFY` dependency for non-PostgreSQL installs
- port AI bot daemon/event delivery
- validate chat/dashboard/live updates

Deliverable:

- feature parity close to PostgreSQL

## Risks

### Behavioral drift

The biggest risk is not syntax failure. It is silent behavior drift:

- different case-insensitive matching behavior
- different timestamp coercion
- different JSON semantics
- different unique-index handling
- different ordering/planner behavior in "latest message" queries

### Migration burden

There are many historical migrations. Some are portable with minor edits; others are intrinsically PostgreSQL-specific. Supporting both engines may require:

- engine branching in the upgrader
- selective backfill migrations
- declaring some historical migrations PostgreSQL-only and rolling their effect into `database/mysql_schema.sql`

### Performance regressions

Message search, dashboards, and area lists may perform materially worse on MariaDB/MySQL until queries and indexes are redesigned for that backend.

### Operational complexity

Two supported databases means:

- more CI matrix jobs
- more setup docs
- more migration testing
- more bug surface

This is manageable, but it is ongoing maintenance cost, not one-time work.

## Recommended Scope Decisions

If this project moves forward with MariaDB/MySQL support, I would explicitly choose these boundaries:

- support MySQL 8.0+ only
- support MariaDB 10.11+ only
- no support for MySQL 5.7
- no support for older MariaDB branches
- PostgreSQL remains the best-supported backend until parity is proven

I would also document that some features may temporarily remain PostgreSQL-preferred during rollout:

- realtime signaling
- some search/reporting features
- admin analytics

## Minimal First Implementation

If the goal is to make progress without committing to full parity immediately, the smallest reasonable target is:

1. Add configurable driver support in `src/Database.php`.
2. Create `database/mysql_schema.sql`.
3. Port installer/upgrader.
4. Make auth, sessions, users, and basic message reads work.
5. Disable or degrade realtime/event features on MariaDB/MySQL initially.

That would give an honest "experimental MariaDB/MySQL support" milestone without pretending the backend is fully interchangeable yet.

## Conclusion

MariaDB/MySQL compatibility is feasible, but only if the project treats PostgreSQL-specific behavior as a first-class design concern rather than as scattered query cleanup. The main blockers are not just schema types; they are the event model, SQL dialect usage, case-insensitive identity rules, timezone handling, and index strategy.

The cleanest path is phased support:

- abstract engine-specific behavior
- introduce a second schema
- port core flows first
- then tackle search, analytics, and realtime

If done that way, BinktermPHP can likely support PostgreSQL and MariaDB/MySQL without gutting the current codebase.
