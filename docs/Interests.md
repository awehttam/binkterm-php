# Interests

Interests are topic groups — think "Retro Gaming", "Amateur Radio", or "Arts & Crafts" — that bundle related echo areas together under a single named card. Subscribe to an interest and you're automatically subscribed to all its member echo areas. You can also read all messages from an interest as a unified feed without switching between individual areas.

## Table of Contents

**For sysops and users**
- [For Users: Finding and Following Interests](#for-users-finding-and-following-interests)
  - [Why Use Interests?](#why-use-interests)
  - [The Interest Picker](#the-interest-picker)
  - [Reading Messages from an Interest](#reading-messages-from-an-interest)
  - [Subscribing and Unsubscribing](#subscribing-and-unsubscribing)
  - [How Unsubscription Works](#how-unsubscription-works)
- [For Sysops: Setting Up Interests](#for-sysops-setting-up-interests)
  - [Why Set Up Interests?](#why-set-up-interests)
  - [Creating an Interest](#creating-an-interest)
  - [Assigning Echo Areas and File Areas](#assigning-echo-areas-and-file-areas)
  - [Keeping Interests Up to Date](#keeping-interests-up-to-date)
  - [AI-Assisted Generation](#ai-assisted-generation)
  - [Enabling and Disabling the Feature](#enabling-and-disabling-the-feature)

**Developer reference**
- [Subscription Mechanics](#subscription-mechanics)
- [Multi-Interest Source Tracking](#multi-interest-source-tracking)
- [Navigation and Visibility Globals](#navigation-and-visibility-globals)
- [Key Classes and Files](#key-classes-and-files)
- [Database Schema](#database-schema)
- [API Reference](#api-reference)

---

## For Users: Finding and Following Interests

### Why Use Interests?

A BBS can carry hundreds of echo areas covering an enormous range of subjects. Finding the ones worth following — and then subscribing to them one by one — can be a chore, especially for new users who don't yet know what's available.

Interests solve this by letting the sysop curate topic bundles. Instead of browsing a raw list of area tags, you see named, color-coded cards like "Retro Gaming" or "Linux & Open Source". One click subscribes you to everything in that bundle. You can also read all the messages from an interest as a single unified feed, which is useful when a topic spans several areas — you get the full conversation without switching between them.

The key benefits:

- **Faster onboarding** — new users can start reading relevant content immediately, without needing to know which specific area tags to look for.
- **Curated discovery** — the sysop has already done the work of grouping related areas; you benefit from that knowledge.
- **Unified reading** — messages from all areas in an interest appear together in one feed, so you never miss a thread that spilled across areas.
- **Flexible control** — you can subscribe to a whole interest at once or pick individual areas from it. Unsubscribing from an interest removes only the areas it brought in, leaving anything you subscribed to on your own untouched.
- **Safe overlap** — if two interests share an echo area and you unsubscribe from one, the shared area stays because the other interest still covers it.

### The Interest Picker

Visit **Interests** from the user menu (or go to `/interests`) to see the full list of active interests. Each card shows the interest's icon, color, name, description, how many echo areas it covers, and how many subscribers it has.

If there are no active interests, the menu link is hidden.

### Reading Messages from an Interest

On the echomail reader, the sidebar (desktop) and the **Viewing** accordion (mobile) show an **Interests** tab. It lists every active interest with its icon and color. Click or tap an interest to load a unified message feed — all the messages from every echo area in that interest, sorted and paginated the same way as any individual area.

You can filter by unread, read, messages to you, or saved messages; change the sort order; and page through results exactly as you would in a single area.

While the Interests tab is active, the **Manage Interests** button links to `/interests` so you can adjust your subscriptions without leaving the reader.

### Subscribing and Unsubscribing

On the interest card, click **Subscribe** to join an interest. You'll have two options:

- **Subscribe to all** — subscribes you to every echo area in the interest at once.
- **Choose areas** — opens a list so you can pick only the areas you want.

Once subscribed, those echo areas appear in your normal area list just like any other subscription. The interest card shows a filled button and a subscriber count that includes you.

Click **Unsubscribe** to leave. Only echo areas that came from this interest are removed — areas you subscribed to independently, or that are also part of another interest you're still subscribed to, are left alone.

### How Unsubscription Works

If you explicitly unsubscribed from an individual echo area at some point, subscribing to an interest that includes that area will **not** re-subscribe you to it. Your explicit opt-out is always respected.

If two interests share an echo area and you unsubscribe from one of them, the shared area stays — it's only removed when no remaining interest covers it for you.

---

## For Sysops: Setting Up Interests

### Why Set Up Interests?

A well-run BBS often accumulates a large echo area catalog over time. That breadth is a strength, but it can work against you when new users arrive and face an undifferentiated wall of area tags. Many will subscribe to nothing, read nothing, and leave.

Interests let you apply your knowledge of your own network to guide users toward the content that will hook them. You know which areas belong together, which ones are active, and which topics your community cares about. Packaging that knowledge into named, curated bundles does the discovery work on the user's behalf.

The key benefits for sysops:

- **Better first-run experience** — a new user can subscribe to "Retro Gaming" or "Linux Talk" in a single click and immediately have a populated reading list, rather than staring at a blank subscriptions page.
- **Higher engagement** — users who find relevant content quickly are more likely to stick around, read regularly, and eventually post.
- **Low maintenance** — once an interest is set up, it takes care of itself. Adding a new area to an interest automatically propagates the subscription to everyone already following it.
- **Network promotion** — if your uplink carries specialized areas your users don't know about, an interest is an easy way to surface them. A well-described "Amateur Radio" bundle is more inviting than asking users to find `HAMRADIO`, `HAMTECH`, and `DXNEWS` on their own.
- **Flexible curation** — interests can be activated and deactivated without deleting them, so you can run seasonal or event-specific bundles and bring them back later. Sort order controls which interests appear most prominently.
- **No disruption to existing subscriptions** — interests layer on top of the existing subscription system. Users who already subscribed to areas manually are unaffected. Everything is additive.

### Creating an Interest

Go to **Admin → Interests** (`/admin/interests`) and click **Create**. Fill in:

| Field | Notes |
|-------|-------|
| **Name** | Shown to users on the interest card. Must be unique. |
| **Slug** | Auto-generated from the name. Used in internal tracking. Edit it if you want a specific URL-friendly string. |
| **Description** | Optional. Shown on the card to help users decide whether to subscribe. |
| **Icon** | Any FontAwesome class, e.g. `fa-gamepad` or `fa-music`. Displayed on the card and in the echomail sidebar. |
| **Color** | Hex color, e.g. `#e74c3c`. Used as an accent on the card and as a left border stripe in the area list. |
| **Sort order** | Lower numbers appear first. Use this to put your most popular interests at the top. |
| **Active** | Inactive interests are hidden from users but preserved in the database. Useful for seasonal or temporarily suspended topics. |

### Assigning Echo Areas and File Areas

After saving the interest, use the **Echo Areas** and **File Areas** tabs to assign content. Both use a searchable checklist. Saving a new selection replaces the previous one entirely — there is no append mode, so include everything you want in each save.

You can assign as many or as few areas as you like. An interest with no echo areas is valid (it will appear to users as having 0 areas) but won't produce any messages in the reader.

### Keeping Interests Up to Date

When you add a new echo area to an existing interest, all users currently subscribed to that interest are automatically subscribed to the new area — unless they previously explicitly unsubscribed from it, in which case it is skipped.

When you remove an echo area from an interest, existing user subscriptions to that area are not automatically removed. Users keep their subscriptions; they just won't be re-added if they unsubscribe and re-subscribe to the interest later.

Deleting an interest removes it and all interest-sourced echo area subscriptions for all subscribers. Echo areas that users subscribed to independently remain.

### AI-Assisted Generation

The admin interests page includes a **Generate Suggestions** wizard. It analyzes your echo area catalog and proposes interest groupings with suggested names, descriptions, icons, and area assignments. Suggestions are presented for review before anything is saved — nothing is created automatically.

The wizard uses keyword matching to classify areas into topics. If `ANTHROPIC_API_KEY` is set in `.env`, it offers an additional AI-assisted mode that produces higher-quality groupings for areas with ambiguous or abbreviated tags.

### Enabling and Disabling the Feature

Interests are enabled by default. To turn the feature off, add this to `.env`:

```
ENABLE_INTERESTS=false
```

When disabled, all Interests routes return 404 and no Interests UI appears anywhere. No data is deleted.

---

## Subscription Mechanics

`InterestManager::subscribeUser(userId, interestId)` handles subscribing:

1. Inserts a row into `user_interest_subscriptions` (idempotent — `ON CONFLICT DO NOTHING`).
2. For each echo area in the interest, calls `subscribeUserToEchoarea()`.

Per-area logic in `subscribeUserToEchoarea()`:

- If `user_echoarea_subscriptions` has a row for this area with `is_active = false` (explicit unsubscribe) → **skip**.
- If there is already an active subscription → record the interest as a source in `user_echoarea_interest_sources` only; do not overwrite the existing subscription.
- If no row exists → insert a new subscription with `subscription_type = 'interest'` and `interest_id` set; record the source.

`InterestManager::unsubscribeUser(userId, interestId)`:

1. Deletes the row from `user_interest_subscriptions`.
2. Deletes all source-tracking rows for this interest from `user_echoarea_interest_sources`.
3. Deletes echo area subscriptions where `interest_id` matches **and** no remaining rows exist in `user_echoarea_interest_sources` for that `(user_id, echoarea_id)` pair.

Partial subscribe/unsubscribe (`subscribeUserToSelectedEchoareas` / `unsubscribeUserFromSelectedEchoareas`) follows the same per-area logic but operates only on the provided IDs. The interest-level subscription row is removed when no sourced areas remain.

---

## Multi-Interest Source Tracking

A user may subscribe to two interests that share echo areas. Without source tracking, unsubscribing from one interest could remove areas still covered by the other.

The `user_echoarea_interest_sources` table records `(user_id, echoarea_id, interest_id)` — one row per interest that is a source for a given user's subscription to a given area. An echo area subscription is only deleted during unsubscription when no rows remain for that `(user_id, echoarea_id)` pair.

This table is maintained automatically by `InterestManager` and requires no manual intervention.

---

## Navigation and Visibility Globals

Two Twig globals are set by `src/Template.php` and available in every template:

| Global | Source | Meaning |
|--------|--------|---------|
| `interests_enabled` | `ENABLE_INTERESTS` env var | Whether the feature is on at all |
| `has_active_interests` | `InterestManager::getInterests(true)` | Whether any active interests exist |

The user menu link and echomail tabs are both gated on `interests_enabled and has_active_interests`.

---

## Key Classes and Files

| File | Purpose |
|------|---------|
| `src/InterestManager.php` | All interest CRUD, subscription logic, and slug generation |
| `src/MessageHandler.php` | `getEchomailFromInterest()` — unified message feed for an interest |
| `src/Template.php` | Sets `interests_enabled` and `has_active_interests` globals |
| `routes/admin-routes.php` | Admin web page and CRUD API endpoints |
| `routes/api-routes.php` | User-facing API endpoints |
| `routes/web-routes.php` | `/interests` user page route |
| `templates/admin/interests.twig` | Admin management UI |
| `templates/interests.twig` | User interest picker page |
| `templates/echomail.twig` | Interests tab in sidebar and mobile accordion |
| `database/migrations/v1.11.0.49_interests.sql` | Core schema |
| `database/migrations/v1.11.0.50_interest_echo_sources.sql` | Source-tracking table |

---

## Database Schema

Migrations `v1.11.0.49_interests.sql` and `v1.11.0.50_interest_echo_sources.sql`.

### `interests`

| Column | Type | Description |
|--------|------|-------------|
| `id` | SERIAL PK | |
| `slug` | VARCHAR(100) | URL-friendly identifier, e.g. `retro-gaming` |
| `name` | VARCHAR(100) | Display name, unique |
| `description` | TEXT | Optional description shown to users |
| `icon` | VARCHAR(50) | FontAwesome class, e.g. `fa-gamepad` |
| `color` | VARCHAR(7) | Hex accent color, e.g. `#e74c3c` |
| `sort_order` | INTEGER | Controls display order (lower = first) |
| `is_active` | BOOLEAN | Inactive interests are hidden from users |
| `created_at` | TIMESTAMPTZ | |
| `updated_at` | TIMESTAMPTZ | |

### `interest_echoareas`

Junction table. `(interest_id, echoarea_id)` PK. Both columns cascade on delete.

### `interest_fileareas`

Junction table. `(interest_id, filearea_id)` PK. Both columns cascade on delete.

### `user_interest_subscriptions`

`(user_id, interest_id)` PK. Records that a user is subscribed to an interest.

### `user_echoarea_interest_sources`

`(user_id, echoarea_id, interest_id)` PK. Records which interest(s) are sources for a user's echo area subscription. Used to protect shared areas during unsubscription.

### `user_echoarea_subscriptions` — added column

| Column | Type | Description |
|--------|------|-------------|
| `interest_id` | INTEGER FK → `interests.id` SET NULL | Interest that created the subscription, or NULL for manual subscriptions |

---

## API Reference

### User endpoints

All require authentication. All return 404 if `ENABLE_INTERESTS` is false.

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/interests` | Active interests with the authenticated user's subscription status |
| `POST` | `/api/interests/{id}/subscribe` | Subscribe to an interest. Optional `echo_area_ids` body param for partial subscription |
| `POST` | `/api/interests/{id}/unsubscribe` | Unsubscribe from an interest. Optional `echo_area_ids` for partial unsubscription |
| `GET` | `/api/interests/{id}/echoareas` | Echo areas in an interest with the user's per-area subscription status |
| `GET` | `/api/interests/{id}/messages` | Paginated echomail feed. Supports `page`, `sort`, `filter` query params |

### Admin endpoints

All require admin privileges. All return 404 if `ENABLE_INTERESTS` is false.

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/admin/interests` | List all interests including inactive, with area and subscriber counts |
| `GET` | `/api/admin/interests/{id}` | Single interest with full echo area and file area lists |
| `POST` | `/api/admin/interests` | Create a new interest |
| `PUT` | `/api/admin/interests/{id}` | Update interest metadata |
| `DELETE` | `/api/admin/interests/{id}` | Delete interest (cascades) |
| `POST` | `/api/admin/interests/{id}/echoareas` | Replace echo area list `{"ids":[...]}` |
| `POST` | `/api/admin/interests/{id}/fileareas` | Replace file area list `{"ids":[...]}` |
| `POST` | `/api/admin/interests/generate` | Generate interest suggestions from the echo area catalog (requires `ANTHROPIC_API_KEY`) |
