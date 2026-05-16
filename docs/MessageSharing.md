# Message Sharing

Users can generate public share links for individual echomail messages. Shared links work for anyone on the web — no BBS account required for public shares. Links can include a custom preview image and AI-generated description for social media embeds (Open Graph).

Echomail sharing supports both token URLs and human-readable friendly URLs. Netmail cannot be shared.

---

## Sharing a Message

Open a message in the reader and click the **Share** button (or right-click the message and choose **Share**). The share dialog lets you:

- Choose **Public** (anyone with the link can read it) or **Private** (link works only for logged-in users).
- Set an expiry — e.g. 48 hours — or leave it open-ended (never expires).
- Optionally write or generate an AI summary for the Open Graph description.
- Upload a preview image for social media embeds.
- Generate a friendly URL (echomail only).

Each user gets at most one active share per message. Creating a share when one already exists returns the existing share URL unchanged.

---

## Share URL Formats

**Token URL:**
```
https://your-bbs.example.com/shared/a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4
```

**Friendly URL** — generated on request:
```
https://your-bbs.example.com/shared/ECHOAREA/message-subject-slug
https://your-bbs.example.com/shared/ECHOAREA@network/message-subject-slug
```

The friendly URL is generated from the message subject and the echo area tag. If two messages in the same area produce the same slug, a `-2`, `-3`, … suffix is appended automatically. Friendly URLs redirect through the same access-control and expiry checks as token URLs.

---

## Open Graph Preview

When a share link is pasted into social media (Facebook, Mastodon, Discord, etc.), the platform fetches the page and reads the Open Graph meta tags to build a card preview.

**Description** — The `og:description` tag is populated from, in priority order:
1. The custom `ai_og_summary` field if set.
2. The first 160 characters of the message body.

**Preview image** — Upload a JPEG, PNG, GIF, or WebP image (max 5 MB) through the share dialog. The image is served via `/shared-image/{slug}` with a 24-hour public cache. If no image is uploaded the card appears without a thumbnail.

The AI summary button (if AI is configured on the system) sends the message text to the configured AI provider and returns a one- or two-sentence description suitable for an OG tag.

---

## Access Control

| Share type | Who can view |
|---|---|
| Public | Anyone with the link — no login required |
| Private | Only logged-in BBS users |

A share stops working if:
- It has been revoked by the user who created it.
- Its expiry time has passed.
- The original message was deleted.

Revoked shares are soft-deleted (marked inactive). The share key and record are kept for audit purposes but the URL returns 404.

---

## Share Expiry

Set `expires_hours` when creating a share to have it expire automatically. A value of 0 or leaving the field blank means the share never expires. Expired shares are cleaned up automatically when the share URL is accessed.

Default expiry for new shares is 168 hours (one week). Users can change their personal default in account settings.

---

## Managing Your Shares

Open the share dialog on any message you have previously shared. It shows:

- Your active share for the message with its URL, expiry, access count, and last-accessed time.
- Other users' active public shares for the same message (read-only, copy-link only).

Click **Revoke** to deactivate your share. Revoking does not delete the OG image from storage; remove it explicitly before revoking if you want the file gone.

---

## Per-User Limits

| Setting | Default | Description |
|---|---|---|
| `allow_sharing` | enabled | Sharing can be disabled per user by an admin |
| `default_share_expiry` | 168 hours | Default expiry when creating a share |
| `max_shares_per_user` | 50 | Maximum simultaneous active shares |

Admins can change these values in **Admin → Users → Edit user**.

---

## Referrer Tracking

Each time a shared link is accessed from an external site, the referrer domain is recorded. The share dialog shows a **Top Referrers** list for each share. Internal referrers (same-site requests) are excluded. Analytics for shares are also visible in **Admin → Analytics**.

---

## Admin Controls

**AI summary generation** is enabled or disabled system-wide in **Admin → AI Settings** (`share_summary_enabled`). When enabled, a custom system prompt can be configured (`share_summary_prompt`); the default prompt instructs the model to write one or two plain-text sentences with no Markdown or HTML.

There are no admin controls to revoke another user's share. Users manage their own shares.

---

## API Reference

All share endpoints require an authenticated session except where noted.

### Create or retrieve a share
```
POST /api/messages/echomail/{id}/share

{
  "public": true,
  "expires_hours": 168,
  "ai_og_summary": "Optional custom description"
}
```

Returns `share_key`, `share_url`, `is_public`, and `existing` (true if a share already existed).

### List shares for a message
```
GET /api/messages/echomail/{id}/shares
```

Returns `my_shares` (full detail) and `other_shares` (partial, read-only).

### Revoke a share
```
DELETE /api/messages/echomail/{id}/share
```

### Generate a friendly URL
```
POST /api/messages/echomail/{id}/share/friendly-url
```

### Upload an OG preview image
```
POST /api/messages/echomail/{id}/share/image
Content-Type: multipart/form-data

image: <file>   (image/*, max 5 MB)
```

### Remove an OG preview image
```
DELETE /api/messages/echomail/{id}/share/image
```

### Serve an OG image (public, no auth)
```
GET /shared-image/{share_key}.{ext}
```

### View a shared message (HTML, public if share is public)
```
GET /shared/{share_key}
GET /shared/{area}/{slug}
GET /shared/{area@network}/{slug}
```

### Retrieve shared message data (JSON)
```
GET /api/messages/shared/{share_key}
GET /api/messages/shared/{area}/{slug}
```

Returns 401 for private shares when unauthenticated, 404 for expired or revoked shares.

### Generate an AI summary
```
POST /api/messages/echomail/{id}/share-summary
```

Only available when AI summary generation is enabled in admin settings.

---

## Key Files

| Path | Purpose |
|---|---|
| `src/MessageHandler.php` | Share creation, retrieval, revocation, slug generation, expiry cleanup |
| `src/FileAreaManager.php` | OG image upload and deletion (`storeSharedMessageImage`, `deleteSharedMessageImage`) |
| `src/AI/ShareSummaryGenerator.php` | AI summary generation |
| `src/ShareReferralTracker.php` | External referrer recording and aggregation |
| `routes/api-routes.php` | Share API endpoints |
| `routes/web-routes.php` | HTML share page routes (`/shared/…`, `/shared-image/…`) |
| `public_html/js/echomail.js` | Share dialog UI, upload handling, clipboard copy |
| `templates/shared_message.twig` | Publicly rendered shared message page with OG meta tags |
| `database/migrations/v1.4.0_add_message_sharing_fixed.sql` | Initial schema |
