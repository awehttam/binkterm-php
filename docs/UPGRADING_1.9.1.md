# Upgrading to 1.9.1

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Echomail Moderation

- New users' echomail posts can be held for admin review before being distributed to the network. This feature is disabled by default and must be enabled by the sysop.
- An admin-configurable approval threshold automatically promotes users to unmoderated posting once they have accumulated a sufficient number of approved posts.
- A moderation queue page is available at **Admin → Area Management → Echomail Moderation** where pending posts can be previewed and approved or rejected.
- The Admin menu and the Echomail Moderation item highlight in yellow when posts are waiting for review.
- Existing users who have previously logged in are grandfathered in and bypass moderation automatically.
- Admins always bypass moderation regardless of post count.

## Echomail Moderation

New users' echomail posts can optionally be held in a moderation queue before being forwarded to the network. This feature is **disabled by default** — no action is required if you do not want to use it.

**How it works**

When moderation is enabled and a new user submits an echomail message, it is stored with `moderation_status = 'pending'` rather than being spooled for outbound delivery immediately. An admin reviews the post from the moderation queue and either approves or rejects it. Approved posts are spooled for network distribution and become visible to all subscribers. Rejected posts are removed from public view and are never forwarded.

Once a user accumulates enough approved posts to meet the configured threshold, they are automatically promoted to unmoderated status and their future posts are published immediately without requiring review.

**Configuration**

The approval threshold is set under **Admin → BBS Settings**. The default value is `0`, which disables moderation — all users post without moderation. Set it to `N` to require each new user to have `N` echomail posts approved on non-local echoareas before bypassing the queue.

**Upgrading**

The database migration adds `moderation_status` and `user_id` columns to the `echomail` table, and a `can_post_netecho_unmoderated` column to the `users` table. All users who have previously logged in are marked as unmoderated automatically so existing community members are not affected. Run `php scripts/setup.php` to apply the migration.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
```

### Using the Installer

Re-run the BinktermPHP installer to update the application files. When prompted to run `php scripts/setup.php`, allow it to complete.
