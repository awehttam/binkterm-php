# Upgrading to 1.9.3

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Interest Area Management](#interest-area-management)
- [Localization](#localization)
- [Bug Fixes](#bug-fixes)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Interest Area Management

- The interest subscription widget now lets users manage individual echo areas within an interest via a new **Manage Areas** dialog, replacing the previous unsubscribe-only flow.

### Localization

- Italian translation updated with new interest area management strings. Thanks to Freddy Krueger for providing the Italian translation.

### Bug Fixes

- `scripts/echomail_maintenance.php`: Fixed a PostgreSQL foreign key violation that caused the maintenance script to abort when deleting messages that other messages referenced via `reply_to_id`. The script now NULLs those references before deleting the parent messages.

## Interest Area Management

The interests feature groups related echo areas under a topic label. Users subscribe to an interest to automatically follow its member areas. Before this release, the subscription widget offered two states: **Subscribe** (for interests not yet followed) and **Subscribed** (for interests already followed). Clicking the **Subscribed** button opened a dialog that only allowed unsubscribing from individual areas — there was no way to add areas from within the interest widget.

Starting in 1.9.3, clicking the button on an already-subscribed interest opens a **Manage Areas** dialog. All echo areas belonging to the interest are listed in a table with checkboxes. Areas the user is currently subscribed to are pre-checked; areas the user is not subscribed to are unchecked. The user can check or uncheck any area and save the result.

- If at least one area is checked, the save button reads **Save N area(s)** and updates the subscription set to match the selection.
- If all areas are unchecked, the save button changes to **Unsubscribe** (shown in red) and saving removes the interest subscription entirely.
- Selecting an area that was previously explicitly unsubscribed through the subscription manager page will re-activate that subscription — the management dialog is treated as an explicit user choice and overrides earlier opt-outs for the selected areas.

No database migrations are required for this release. The change is limited to the interest picker JavaScript widget, a new API endpoint, and an update to `InterestManager` in `src/InterestManager.php`.

## Localization

The Italian translation catalog (`config/i18n/it/`) has been updated with strings for the new interest area management feature. Thanks to Freddy Krueger for providing the Italian translation.

## Bug Fixes

### Echomail Maintenance Foreign Key Violation

`scripts/echomail_maintenance.php` would abort with a PostgreSQL foreign key violation (`echomail_reply_to_id_fkey`) when attempting to delete messages that other messages referenced as a reply parent via the `reply_to_id` column. The deletion failed because the referenced parent row could not be removed while child rows still pointed to it.

The script now NULLs out any `reply_to_id` references to the messages being deleted before issuing the `DELETE`. This severs the self-referential FK on thread replies without destroying threading information for messages that are not being deleted.

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
```

### Using the Installer

Replace your files with the new release archive, then run:

```bash
php scripts/setup.php
```
