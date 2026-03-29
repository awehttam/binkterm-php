# Interests

## Overview

Users can follow cross-network topic feeds without individually subscribing to dozens of echo areas. Admins define "Interests" (e.g. "Cooking", "Retro Gaming") by grouping echo areas and file areas under a topic. Users browse a list of interests and subscribe to the ones that match their tastes. Once subscribed, a user sees a unified message feed from all the interest's echo areas — with file uploads from linked file areas interleaved by date — directly within the echomail reader.

Feature is gated behind `ENABLE_INTERESTS=true` in `.env` (default: `true`).

---

## Implementation Progress

| Feature | Status |
|---------|--------|
| Database schema (interests, junction tables, subscription tracking) | ✅ Done |
| `InterestManager` — admin CRUD, subscription logic, slug generation | ✅ Done |
| `InterestGenerator` — keyword + AI classification pipeline | ✅ Done |
| Admin: manage interests page (create/edit/delete, assign areas) | ✅ Done |
| Admin: generate suggestions UI | ✅ Done |
| Admin: echo areas not assigned to any interest report | ✅ Done |
| Admin: Popular Interests tab on activity statistics page | ✅ Done |
| User: `/interests` browse and subscribe page | ✅ Done |
| User: per-interest subscribe modal with per-area checkboxes | ✅ Done |
| User: Interests sidebar tab in echomail reader (mobile + desktop) | ✅ Done |
| User: unified message feed via `/api/interests/{id}/messages` | ✅ Done |
| User: message count stats via `/api/interests/{id}/stats` | ✅ Done |
| File uploads interleaved in interest feed (UNION ALL by date) | ✅ Done |
| File preview modal for file items in the feed | ✅ Done |
| Uploader name shown below file area badge | ✅ Done |
| First-time onboarding redirect to `/interests` | ✅ Done |
| Feature flag + `has_active_interests` Twig global | ✅ Done |
| Nav link in both base templates | ✅ Done |
| i18n keys (en, es, fr) | ✅ Done |
| Compose new message while in interest mode (area list filtered to interest) | ✅ Done |
| Cross-post cooldown enforcement | ❌ Not implemented |

---

## Table of Contents

1. [Implementation Progress](#implementation-progress)
2. [Database Schema](#database-schema)
3. [InterestManager class](#interestmanager-class)
4. [MessageHandler extension](#messagehandler-extension)
5. [API Routes](#api-routes)
6. [Web Routes](#web-routes)
7. [Admin UI](#admin-ui)
8. [Interest Generation Tool](#interest-generation-tool)
9. [User-Facing Templates](#user-facing-templates)
10. [Feature Flag Integration](#feature-flag-integration)
11. [i18n Keys](#i18n-keys)

---

## Database Schema

Migrations:
- `database/migrations/v1.11.0.49_interests.sql`
- `database/migrations/v1.11.0.50_interest_echo_sources.sql`

```sql
-- Admin-defined topic interests
CREATE TABLE IF NOT EXISTS interests (
    id          SERIAL PRIMARY KEY,
    slug        VARCHAR(100) NOT NULL UNIQUE,   -- URL-friendly, e.g. "retro-gaming"
    name        VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    icon        VARCHAR(50)  NOT NULL DEFAULT 'fa-layer-group',
    color       VARCHAR(7)   NOT NULL DEFAULT '#6c757d',
    sort_order  INTEGER      NOT NULL DEFAULT 0,
    is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- Echo areas belonging to an interest
CREATE TABLE IF NOT EXISTS interest_echoareas (
    interest_id INTEGER NOT NULL REFERENCES interests(id) ON DELETE CASCADE,
    echoarea_id INTEGER NOT NULL REFERENCES echoareas(id) ON DELETE CASCADE,
    PRIMARY KEY (interest_id, echoarea_id)
);

-- File areas belonging to an interest
CREATE TABLE IF NOT EXISTS interest_fileareas (
    interest_id INTEGER NOT NULL REFERENCES interests(id) ON DELETE CASCADE,
    filearea_id INTEGER NOT NULL REFERENCES file_areas(id) ON DELETE CASCADE,
    PRIMARY KEY (interest_id, filearea_id)
);

-- User subscriptions to interests
CREATE TABLE IF NOT EXISTS user_interest_subscriptions (
    id            SERIAL PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES users(id)      ON DELETE CASCADE,
    interest_id   INTEGER NOT NULL REFERENCES interests(id)  ON DELETE CASCADE,
    subscribed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (user_id, interest_id)
);

CREATE INDEX IF NOT EXISTS idx_user_interest_subs_user ON user_interest_subscriptions(user_id);

-- Tracks which echo area subscriptions came from which interests.
-- Allows clean multi-interest unsubscription: an echo area subscription
-- is only removed when the user has no other subscribed interest covering it.
CREATE TABLE IF NOT EXISTS user_echoarea_interest_sources (
    user_id     INTEGER NOT NULL REFERENCES users(id)      ON DELETE CASCADE,
    echoarea_id INTEGER NOT NULL REFERENCES echoareas(id)  ON DELETE CASCADE,
    interest_id INTEGER NOT NULL REFERENCES interests(id)  ON DELETE CASCADE,
    PRIMARY KEY (user_id, echoarea_id, interest_id)
);

CREATE INDEX IF NOT EXISTS idx_ueis_user_interest ON user_echoarea_interest_sources(user_id, interest_id);

-- Interest-sourced echo area subscriptions are also tracked here for backcompat
ALTER TABLE user_echoarea_subscriptions
    ADD COLUMN IF NOT EXISTS interest_id INTEGER REFERENCES interests(id) ON DELETE SET NULL;
```

Notes:
- `PRIMARY KEY` on junction tables creates the unique index automatically — no separate `CREATE INDEX` needed.
- `user_echoarea_interest_sources` supersedes the `interest_id` column on `user_echoarea_subscriptions` for multi-interest correctness. When a user unsubscribes from an interest, only the rows in `user_echoarea_interest_sources` for that interest are removed; if another subscribed interest also covers the same echo area, the echo area subscription is preserved.

---

## InterestManager class

File: `src/InterestManager.php`

### Public (user-facing) methods

| Method | Description |
|--------|-------------|
| `getInterests(bool $activeOnly = true): array` | All interests with echoarea count, filearea count, and subscriber count |
| `getInterest(int $id): ?array` | Single interest with its echoarea and filearea ID lists |
| `getInterestBySlug(string $slug): ?array` | Lookup by slug |
| `getUserSubscribedInterestIds(int $userId): array` | IDs of interests the user is subscribed to |
| `subscribeUser(int $userId, int $interestId): bool` | Subscribe a user (idempotent); creates echo area subscriptions via `user_echoarea_interest_sources` |
| `unsubscribeUser(int $userId, int $interestId): bool` | Unsubscribe; only removes echo area subs not covered by another subscribed interest |
| `getInterestEchoareaIds(int $interestId): array` | Echo area IDs (used by MessageHandler) |
| `getInterestFileareas(int $interestId): array` | File area rows |
| `getInterestFileareaIds(int $interestId): array` | File area IDs |

### Admin-only methods

| Method | Description |
|--------|-------------|
| `createInterest(array $data): int` | Create; generates slug from name if omitted; enforces uniqueness |
| `updateInterest(int $id, array $data): bool` | Update metadata |
| `deleteInterest(int $id): bool` | Delete; cascades to junction tables |
| `setEchoareas(int $interestId, array $echoareaIds): void` | Transactional replace of echo area list |
| `setFileareas(int $interestId, array $fileareaIds): void` | Transactional replace of file area list |

Slug generation: lowercase, spaces→hyphens, strip non-alphanumeric except hyphens; appends `-2`, `-3` etc. on collision.

---

## MessageHandler extension

File: `src/MessageHandler.php`

`getEchomailFromInterest(int $userId, int $interestId, ...)` fetches messages for an interest feed. When the interest has associated file areas, it uses a `UNION ALL` query to interleave file uploads with echomail messages, sorted by date:

```sql
SELECT ... FROM (
    SELECT 'message' AS item_type, em.*, ea.tag AS area_tag, ...
    FROM echomail em JOIN echoareas ea ON ...
    WHERE ea.id IN (...)

    UNION ALL

    SELECT 'file' AS item_type, f.id, f.filename, f.filesize,
           f.short_description, f.created_at AS sort_date,
           fa.tag AS area_tag,
           COALESCE(u.real_name, f.uploaded_from_address) AS uploader_name, ...
    FROM files f
    JOIN file_areas fa ON f.file_area_id = fa.id
    LEFT JOIN users u ON u.id = f.owner_id
    WHERE f.file_area_id IN (...)
      AND f.status = 'approved'
) combined
ORDER BY sort_date DESC
```

File items in the response have `type: 'file'` and include `filename`, `filesize`, `short_description`, `file_area_tag`, `uploader_name`, and `date_received`. Message items have `type: 'message'`. The JS in `echomail.js` renders each type differently and skips file items when navigating message-to-message.

---

## API Routes

All routes check the feature flag and return 404 if `ENABLE_INTERESTS` is not `true`.

### User-facing endpoints (`routes/api-routes.php`)

| Method | Path | Description |
|--------|------|-------------|
| `GET`  | `/api/interests` | Active interests; includes `subscribed` boolean per item when authenticated |
| `POST` | `/api/interests/{id}/subscribe` | Subscribe current user to an interest |
| `POST` | `/api/interests/{id}/unsubscribe` | Unsubscribe current user from an interest |
| `GET`  | `/api/interests/{id}/echoareas` | Echo areas belonging to an interest with user's subscription status |
| `GET`  | `/api/interests/{id}/messages` | Paginated feed (`?page&filter&sort&threaded`) — messages + file uploads interleaved |
| `GET`  | `/api/interests/{id}/stats` | Message counts (total, unread, recent, areas, filter_counts) — same shape as echomail stats |

### Admin-only endpoints (`routes/admin-routes.php`)

All require `$user['is_admin']`; return 403 otherwise.

| Method   | Path | Description |
|----------|------|-------------|
| `GET`    | `/api/admin/interests` | All interests including inactive |
| `POST`   | `/api/admin/interests` | Create interest |
| `GET`    | `/api/admin/interests/unassigned-echoareas` | Echo areas not assigned to any interest |
| `POST`   | `/api/admin/interests/generate` | Run classification pipeline; returns suggestions (does not save) |
| `GET`    | `/api/admin/interests/{id}` | Single interest with area lists |
| `PUT`    | `/api/admin/interests/{id}` | Update metadata |
| `DELETE` | `/api/admin/interests/{id}` | Delete (cascades) |
| `POST`   | `/api/admin/interests/{id}/echoareas` | Set echo area list — body: `{"ids":[...]}` |
| `POST`   | `/api/admin/interests/{id}/fileareas` | Set file area list — body: `{"ids":[...]}` |

Note: `/api/admin/interests/unassigned-echoareas` and `/api/admin/interests/generate` must be registered **before** the `/{id}` routes to prevent the literal strings from being matched as an ID parameter.

---

## Web Routes

Added to `routes/web-routes.php`.

| Method | Path | Description |
|--------|------|-------------|
| `GET`  | `/interests` | User browse and subscribe page |
| `GET`  | `/echomail` | Echomail reader — interests appear as a sidebar tab and are read within this page |

### First-time onboarding

When a logged-in user visits `/echomail` or `/echomail/{tag}` for the first time and has no interest subscriptions, they are redirected to `/interests` once. This is tracked via the `interests_onboarded` user metadata key.

---

## Admin UI

### Manage Interests page

Route: `GET /admin/interests`
Template: `templates/admin/interests.twig`

Features:
- Create / edit / delete interests (name, slug, description, FontAwesome icon class, color, sort order, active toggle)
- Assign echo areas via searchable checkbox list
- Assign file areas via searchable checkbox list
- View subscriber count per interest
- **Generate Suggestions** button — runs the `InterestGenerator` pipeline and presents suggested groupings for review before saving
- **Echo Areas Not Assigned to Any Interest** — collapsible report at the bottom of the page; pre-loads a count badge on page load and renders the full list on expand; refreshes after saves and deletes

Link added to the admin sidebar navigation.

### Activity Statistics page

A **Popular Interests** tab has been added to `templates/admin/activity_stats.twig` alongside the Popular Areas tab. It shows each active interest ranked by subscriber count, with the icon, color accent, and a proportional bar.

---

## Interest Generation Tool

Manually building interests from scratch is tedious on a large system with hundreds of echo areas. The generation tool analyses existing echo areas and suggests a set of interests pre-populated with the areas that belong to them. Admins review and edit suggestions before saving.

### Classification pipeline

**Pass 1 — Keyword heuristics (free, instant)**

Each echo area tag is normalised:
1. Known network prefixes are stripped (`LVLY_`, `HNET_`, `DOVE-`, `FSX_`, `RTN_`, `NIX_`, `MIN_`, etc.)
2. Known suffixes are stripped (`_ECHO`, `_AREA`, `_NET`, `_BASE`, etc.)
3. The cleaned tag and description are concatenated, uppercased, and tested against a keyword table. The first matching category wins.

**Pass 2 — AI classification (optional, requires `ANTHROPIC_API_KEY`)**

Areas not matched in pass 1 are sent to the Anthropic API in batches of 50. Each batch includes:
- The current list of category names (to prefer established names)
- Each area's tag and description
- An instruction to return a JSON object mapping each tag to a category name

Model: `claude-haiku-4-5-20251001` for speed and cost efficiency. Falls back gracefully to keyword-only mode if `ANTHROPIC_API_KEY` is not set.

Unclassified areas are placed in a catch-all "Uncategorised" group for manual assignment.

### Description quality

Tag names alone are often ambiguous. Well-written descriptions dramatically improve classification accuracy. A pre-flight warning in the admin UI counts echo areas with no description and prompts the admin to fill them in first.

### Backend class

File: `src/InterestGenerator.php`

| Method | Description |
|--------|-------------|
| `generate(bool $useAi = true): array` | Full pipeline; returns `[['name', 'echoarea_ids', 'source'], ...]` |
| `classifyByKeyword(array $echoareas): array` | Pass 1 |
| `classifyByAi(array $unmatched): array` | Pass 2, batched |
| `classifyBatch(array $batch, string $categoryList): array` | Single API batch call |
| `cleanTag(string $tag): string` | Strip prefixes/suffixes |

### API endpoint

`POST /api/admin/interests/generate`
- Requires admin.
- Optional body: `{"use_ai": true}` (default true)
- Returns suggestion array; does **not** create any interests.
- Existing interests are excluded from suggestions to prevent duplicates on re-run.

---

## User-Facing Templates

### `templates/interests.twig`

Browse and subscribe page at `/interests`.

- Grid of interest cards: icon, color accent, name, description, echo area count, subscriber count
- Subscribe / Unsubscribe toggle on each card opens a modal showing the echo areas that will be (un)subscribed, with per-area checkboxes so the user can customise which areas to include
- Visual distinction between subscribed (filled button) and unsubscribed (outline button)
- "Go to Echo Areas" button navigates to the echomail reader
- Empty state if no active interests are defined

### `templates/echomail.twig` (Interests sidebar tab)

Interests are surfaced as a sidebar tab within the echomail reader — no separate page is needed.

- Desktop: collapsible sidebar section labelled "Interests" alongside the echo area list
- Mobile: tab in the area/interests accordion
- Clicking an interest loads its unified feed (`/api/interests/{id}/messages`) into the message pane; the URL stays at `/echomail`
- File uploads from linked file areas are interleaved in the feed by date
- Clicking a file item opens a file info modal via `openFileInfoModal()` from `file-preview.js`, which includes full preview support (images, video, audio, ANSI art, archives, etc.)
- The uploader's name (or FidoNet address for TIC-sourced files) is shown below the file area badge
- Message counts (total, unread, recent) use `/api/interests/{id}/stats` when an interest is active

Preview dependencies (`ansisys.js`, `sixel.js`, `pcboard.js`, `file-preview.js`) are only loaded when `interests_enabled` is true.

---

## Feature Flag Integration

| Location | Change |
|----------|--------|
| `.env.example` | `ENABLE_INTERESTS=true` |
| `src/Template.php` | `interests_enabled` global (`Config::env('ENABLE_INTERESTS', 'true') === 'true'`); `has_active_interests` global (true when at least one active interest exists) |
| `templates/base.twig` | Nav link under messaging dropdown, gated on `interests_enabled` |
| `templates/shells/web/base.twig` | Identical nav addition (both base templates must stay in sync) |
| API and web routes | Return 404 / redirect when feature is disabled |

The `has_active_interests` global controls whether the Interests sidebar tab is shown at all in the echomail reader, preventing the tab from appearing on systems that have the feature enabled but no interests defined yet.

---

## i18n Keys

All keys exist in `config/i18n/en/`, `es/`, and `fr/` locales.

### `common.php`

```
ui.interests.nav_label
ui.interests.page_title
ui.interests.heading
ui.interests.page_intro
ui.interests.page_intro_unsub
ui.interests.page_intro_unsub_link
ui.interests.go_to_echo_areas
ui.interests.subscribe
ui.interests.unsubscribe
ui.interests.subscribed
ui.interests.no_interests
ui.interests.load_failed
ui.interests.area_count
ui.interests.areas_modal_title
ui.interests.areas_modal_subscribe_title
ui.interests.areas_modal_unsubscribe_title
ui.interests.areas_modal_empty
ui.interests.areas_modal_col_tag
ui.interests.areas_modal_col_net
ui.interests.areas_modal_col_desc
ui.interests.areas_modal_select_all
ui.interests.areas_modal_deselect_all
ui.interests.areas_modal_confirm_subscribe
ui.interests.areas_modal_confirm_unsubscribe
ui.interests.subscriber_count
ui.interests.browse_areas
ui.interests.browse_page_title
ui.interests.browse_search_placeholder
ui.interests.tab_messages
ui.interests.tab_files
ui.interests.viewer_title
ui.interests.crosspost_button
ui.interests.crosspost_cooldown_warning
ui.interests.admin.page_title
ui.interests.admin.create
ui.interests.admin.edit
ui.interests.admin.delete
ui.interests.admin.delete_confirm
ui.interests.admin.name_label
ui.interests.admin.slug_label
ui.interests.admin.description_label
ui.interests.admin.icon_label
ui.interests.admin.color_label
ui.interests.admin.sort_order_label
ui.interests.admin.is_active_label
ui.interests.admin.echo_areas
ui.interests.admin.file_areas
ui.interests.admin.crosspost_cooldown_enabled
ui.interests.admin.crosspost_cooldown_minutes
ui.interests.admin.crosspost_cooldown_threshold
ui.interests.admin.saved_success
ui.interests.admin.deleted_success
ui.interests.admin.areas_saved_success
ui.admin.activity_stats.tab_interests
ui.admin.activity_stats.popular_interests
ui.admin.activity_stats.interest
ui.admin.activity_stats.subscribers
```

### `errors.php`

```
errors.interests.not_found
errors.interests.name_required
errors.interests.name_taken
errors.interests.slug_taken
errors.interests.feature_disabled
errors.interests.crosspost_cooldown
```

After any key changes, run:
```
php scripts/check_i18n_hardcoded_strings.php
php scripts/check_i18n_error_keys.php
```
