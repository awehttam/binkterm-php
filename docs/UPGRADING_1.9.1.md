# Upgrading to 1.9.1

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
  - [Echomail Moderation](#summary-echomail-moderation)
  - [Polls](#summary-polls)
  - [File Area URL Links](#summary-file-area-url-links)
- [Echomail Moderation](#echomail-moderation)
- [Polls](#polls)
- [File Area URL Links](#file-area-url-links)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Echomail Moderation {#summary-echomail-moderation}

- New users' echomail posts can be held for admin review before being distributed to the network. This feature is disabled by default and must be enabled by the sysop.
- An admin-configurable approval threshold automatically promotes users to unmoderated posting once they have accumulated a sufficient number of approved posts.
- A moderation queue page is available at **Admin → Area Management → Echomail Moderation** where pending posts can be previewed and approved or rejected.
- The Admin menu and the Echomail Moderation item highlight in yellow when posts are waiting for review.
- Existing users who have previously logged in are grandfathered in and bypass moderation automatically.
- Admins always bypass moderation regardless of post count.

### Polls {#summary-polls}

- The poll list now shows unvoted polls before polls the user has already voted on, so newly created polls always appear at the top.
- Within each group, polls are ordered newest first.

### File Area URL Links {#summary-file-area-url-links}

- File areas now support external URL links in addition to uploaded files.
- The upload modal has a new **Add Link** tab where a URL can be submitted with a short and long description.
- A **Fetch Info** button retrieves the page title and description from the URL automatically to pre-fill the description fields.
- URL links appear in file listings alongside regular files, marked with a link icon. Clicking the filename opens a preview card showing the descriptions and a Visit button.
- URL links go through the same approval workflow and credit system as file uploads.
- Admins can edit the URL of a link record from the file edit dialog.
- The database migration relaxes the `NOT NULL` constraints on `file_hash` and `storage_path` in the `files` table, as URL records have no physical file. Run `php scripts/setup.php` to apply.

## Echomail Moderation

New users' echomail posts can optionally be held in a moderation queue before being forwarded to the network. This feature is **disabled by default** — no action is required if you do not want to use it.

**How it works**

When moderation is enabled and a new user submits an echomail message, it is stored with `moderation_status = 'pending'` rather than being spooled for outbound delivery immediately. An admin reviews the post from the moderation queue and either approves or rejects it. Approved posts are spooled for network distribution and become visible to all subscribers. Rejected posts are removed from public view and are never forwarded.

Once a user accumulates enough approved posts to meet the configured threshold, they are automatically promoted to unmoderated status and their future posts are published immediately without requiring review.

**Configuration**

The approval threshold is set under **Admin → BBS Settings**. The default value is `0`, which disables moderation — all users post without moderation. Set it to `N` to require each new user to have `N` echomail posts approved on non-local echoareas before bypassing the queue.

**Upgrading**

The database migration adds `moderation_status` and `user_id` columns to the `echomail` table, and a `can_post_netecho_unmoderated` column to the `users` table. All users who have previously logged in are marked as unmoderated automatically so existing community members are not affected. Run `php scripts/setup.php` to apply the migration.

## Polls

The order in which polls appear has changed. Previously all polls were returned oldest-first regardless of voting status. Now:

- Polls the user has **not yet voted on** appear first, ordered newest first, so freshly created polls are always visible at the top.
- Polls the user **has already voted on** appear afterward, also ordered newest first.

No configuration or database changes are required for this behaviour.

## File Area URL Links

File areas can now contain entries that point to external URLs rather than stored files. This allows sysops and users to catalogue links to GitHub repositories, project pages, documentation, or any other web resource alongside regular file uploads.

**Adding a link**

The upload modal on the File Areas page has a new **Add Link** tab. Enter a full URL (e.g. `https://github.com/owner/repo`) and optionally click **Fetch Info** to have the server retrieve the page title and Open Graph description automatically. The short and long description fields are pre-filled from the fetched metadata and can be edited before submitting.

**How links appear**

URL entries appear in file listings with a link icon instead of a file icon. Clicking the filename opens the preview modal, which displays the short description, long description, and a **Visit** button that opens the URL in a new tab. The download button in the preview modal header also navigates to the URL. The file size column shows a dash for URL entries since there is no file to measure.

**Approval and credits**

URL links use the same pending/approved workflow as file uploads. When a user submits a link in an area that requires approval, it enters the admin approval queue at **Admin → File Approvals**. The queue displays the URL alongside the description so admins can review it before approving or rejecting. The credit system treats link submissions identically to file uploads — the same upload cost and upload reward apply.

**Admin editing**

Admins can edit the URL of a link record at any time through the standard file edit dialog (the pencil button in the file details modal). The URL field is only shown for link-type records.

**Database changes**

Migration `v1.11.0.72` makes the following schema changes to the `files` table:

- Adds a `url TEXT` column (nullable) to store the external URL for link records.
- Drops the `NOT NULL` constraint from `file_hash`, since link records have no file to hash.
- Drops the `NOT NULL` constraint from `storage_path`, since link records have no physical storage path.

The existing unique constraint on `(file_area_id, file_hash)` continues to work correctly — PostgreSQL treats `NULL` values as distinct in unique indexes, so multiple link records in the same area do not conflict with each other.

Run `php scripts/setup.php` to apply the migration.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
```

### Using the Installer

Re-run the BinktermPHP installer to update the application files. When prompted to run `php scripts/setup.php`, allow it to complete.
