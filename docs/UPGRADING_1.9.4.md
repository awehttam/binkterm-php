# Upgrading to 1.9.4

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Bug Fixes](#bug-fixes)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Bug Fixes

- **Echo area management**: When deleting an echo area that still has messages, the API correctly rejected the request but the error message was never displayed to the user. The delete confirmation modal also stayed open after the failure. Both issues are now resolved — the modal closes and the error is shown.
- **Error and success alert display**: A long-standing bug caused all `showError` and `showSuccess` alerts throughout the application to be silently discarded rather than inserted into the page. Alerts now appear correctly at the top of the page content.

## Bug Fixes

### Echo Area Delete Error Not Displayed

Attempting to delete an echo area that contains messages is intentionally blocked — the correct action is to deactivate the area instead. The API returned a structured error response with the message "Cannot delete echo area with existing messages", but the error never appeared in the UI. The delete confirmation modal also remained open after the failed request, leaving the user with no feedback and no clear way to understand what went wrong.

Two issues caused this:

1. The delete error callback in `public_html/js/` (inlined in `templates/echoareas.twig`) did not close the delete confirmation modal before attempting to display the error. Any alert inserted into the page while a Bootstrap modal is open is hidden behind the modal overlay.
2. The shared `showError` function (see below) was silently discarding all alerts due to a broken DOM selector.

The error callback now closes the modal before displaying the error message.

### Error and Success Alert Display Broken Site-Wide

The `showError` and `showSuccess` functions in `public_html/js/app.js` insert dismissible Bootstrap alert banners at the top of the page. These functions used the jQuery selector `$('main .container')` to find the insertion point. All base templates in this application render the main content area as `<main class="container mt-4">` — the `main` element itself carries the `container` class, with no nested `.container` descendant inside it. The space-descendant selector therefore matched nothing, and every call to `showError` or `showSuccess` silently inserted the alert HTML into an empty jQuery set where it was immediately discarded.

The selector has been changed to `$('main')`, which correctly targets the main content element regardless of whether `container` is on `main` itself or on a child element.

This fix benefits every page that calls `showError` or `showSuccess`, not only the echo area management page.

## Upgrade Instructions

No database migrations are required for this release.

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
