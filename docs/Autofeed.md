# Auto Feed

Auto Feed is an auto feeder that monitors external sources and automatically posts new items as echomail messages to configured echo areas on a recurring schedule. Supported source types are RSS/Atom feeds and Bluesky accounts.

Each feed source is configured with a target echo area and a "post as" user. When the script runs, it fetches new items from each active source, formats them as FTN echomail messages, and posts them using that user's identity.

## Supported Source Types

| Type | Description |
|---|---|
| **RSS / Atom** | RSS 2.0, RSS 1.0 (RDF), and Atom feeds. Any standard feed URL works. |
| **Bluesky** | A Bluesky account's public posts via the Bluesky public API. Accepts a profile URL, handle (`@user.bsky.social`), or DID. Reply posts are excluded. |

Bluesky source URLs are detected automatically in the admin UI — entering a `bsky.app` profile URL switches the source type to Bluesky without requiring manual selection.

## How It Works

1. `scripts/rss_poster.php` is run periodically via cron.
2. All active feed sources are fetched from the `auto_feed_sources` database table.
3. For each feed, the script fetches recent items from the source URL.
4. Items are deduplicated against the `last_article_guid` stored for that feed.
5. New items (up to `max_articles_per_check`) are posted to the configured echo area.
6. The `last_article_guid`, `last_check`, `articles_posted`, and `last_error` fields are updated for each feed.

To prevent overloading a source, feeds checked within the last 5 minutes are skipped unless `--force` is passed.

## Admin Configuration

Go to **Admin → Auto Feed** to manage feed sources.

### Feed Fields

| Field | Description |
|---|---|
| **Feed Name** | Display label for the feed in the admin list. |
| **Source URL** | The RSS/Atom feed URL or Bluesky profile URL. Must be unique. |
| **Source Type** | `RSS` or `Bluesky`. Auto-detected for Bluesky URLs. |
| **Echo Area** | The echo area where new posts will appear. |
| **Post As** | The user whose identity is used when posting the echomail message. |
| **Max Articles Per Check** | Maximum number of new items to post per script run. Default 10, range 1–50. |
| **Include Feed Name in Subject** | When enabled, the posted subject becomes `[feed_name] article title` before FTN truncation is applied. |
| **Active** | Whether this feed is checked during script runs. |

### Manual Check

The admin UI includes a **Check Now** button for each feed that triggers `rss_poster.php --feed-id=N --verbose` immediately and displays the output. This is useful for testing a newly added feed before the cron job runs.

### Statistics

The admin list shows per-feed article counts and last-check timestamps. The statistics sidebar shows totals across all feeds: total sources configured, active count, and total articles posted.

## Cron Setup

Run the script on a regular interval. Every 30 minutes is a reasonable default; the per-feed rate limiting inside the script prevents any single feed from being hammered if the cron interval is shorter.

```cron
*/30 * * * * www-data php /path/to/binkterm-php/scripts/rss_poster.php >> /path/to/binkterm-php/data/logs/auto_feed.log 2>&1
```

Adjust the path and user (`www-data`) to match your installation.

## Message Format

Each posted item becomes an echomail message addressed to `All`. The body contains:

1. The item title (word-wrapped to 79 characters).
2. For Bluesky posts: the poster's display name and handle.
3. The item's text or description (HTML stripped).
4. Any attached media URLs (images or video), if present.
5. A link to the original item (`View post` for Bluesky, `Read more` for RSS).
6. The item's published date and time.

The subject line is normally the item title, truncated to 72 characters to respect the FTN message header limit. If **Include Feed Name in Subject** is enabled for a feed and that feed has a name, the subject becomes `[feed_name] item title` before the same 72-character FTN truncation is applied.

## CLI Script

See also [Command Line Interface](CLI.md#auto-feed-poster).

```bash
# Check all active feeds
php scripts/rss_poster.php

# Check a specific feed by ID
php scripts/rss_poster.php --feed-id=3

# Force a check even if the feed was recently checked
php scripts/rss_poster.php --force

# Show detailed output (item titles, post results, errors)
php scripts/rss_poster.php --verbose

# Combine flags
php scripts/rss_poster.php --feed-id=3 --force --verbose
```

### Options

| Flag | Description |
|---|---|
| `--feed-id=N` | Check only the feed with this database ID. Must be active. |
| `--force` | Skip the 5-minute rate-limit check and process the feed regardless of when it was last checked. |
| `--verbose` | Print item titles and post results to stdout. |
| `--help` | Show usage information. |

## Database

Feed sources are stored in the `auto_feed_sources` table.

| Column | Type | Description |
|---|---|---|
| `id` | `SERIAL` | Primary key. |
| `feed_url` | `TEXT` | Source URL. Unique. |
| `feed_name` | `VARCHAR(100)` | Display name. |
| `source_type` | `VARCHAR(20)` | `rss` or `bluesky`. |
| `echoarea_id` | `INTEGER` | FK to `echoareas(id)`. |
| `post_as_user_id` | `INTEGER` | FK to `users(id)`. |
| `active` | `BOOLEAN` | Whether the feed is checked on each run. |
| `max_articles_per_check` | `INTEGER` | Article cap per run. Default 10. |
| `include_feed_name_in_subject` | `BOOLEAN` | Whether subjects should be prefixed with `[feed_name]` before posting. |
| `last_article_guid` | `TEXT` | GUID/URI of the most recently posted item; used for deduplication. |
| `last_check` | `TIMESTAMP` | When the feed was last successfully checked. |
| `articles_posted` | `INTEGER` | Running total of articles posted via this feed. |
| `last_error` | `TEXT` | Most recent error message, if any. |
| `created_at` | `TIMESTAMP` | Row creation time. |
| `updated_at` | `TIMESTAMP` | Row last-modified time. |

Added by migrations `v1.9.2.2_auto_feed.sql` and `v20260508180000_add_auto_feed_source_type.sql`.

## Troubleshooting

**Feed shows an error in the admin list**
The `last_error` column is displayed in the feed table when non-empty. Common causes: unreachable URL, malformed XML, Bluesky API error, or echo area/user deleted. Fix the underlying issue and use **Check Now** to confirm.

**No new articles appear after a manual check**
All items may already have been seen. The deduplication is based on `last_article_guid` — if that GUID no longer appears in the feed (e.g., the feed was reset), all current items will be treated as new on the next check. Use `--force` to bypass the rate-limit window and re-run immediately.

**Bluesky feed posts nothing**
Confirm the handle or URL resolves to a public account with recent posts that are not replies. The script fetches `posts_no_replies` — accounts whose only recent activity is replies to others will appear empty.

**Duplicate messages posted**
This can happen if `last_article_guid` was cleared or if the same item appears in the feed with different GUIDs across fetches. Check the feed source for inconsistent GUID generation.
