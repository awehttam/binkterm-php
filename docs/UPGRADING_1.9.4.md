# Upgrading to 1.9.4

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Account Security](#account-security)
- [PacketBBS Gateway](#packetbbs-gateway)
- [Bug Fixes](#bug-fixes)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

### Bug Fixes

- **Echo area management**: When deleting an echo area that still has messages, the API correctly rejected the request but the error message was never displayed to the user. The delete confirmation modal also stayed open after the failure. Both issues are now resolved — the modal closes and the error is shown.
- **Error and success alert display**: A long-standing bug caused all `showError` and `showSuccess` alerts throughout the application to be silently discarded rather than inserted into the page. Alerts now appear correctly at the top of the page content.

### Account Security

- **PacketBBS authenticator enrollment**: The Settings -> Account page now displays a QR code during PacketBBS authenticator setup. Users can scan the QR code with a TOTP authenticator app instead of copying the otpauth URI manually.

### PacketBBS Gateway

- **Mesh/radio text gateway**: PacketBBS provides a compact text command interface for MeshCore-style radio bridges. The gateway supports login, online-user lookup, netmail reading/replying/sending, echomail area browsing, echomail reading/replying/posting, paging, and quitting.
- **Compact radio UX**: PacketBBS responses are optimized for short radio text exchanges rather than full-screen BBS terminal use. Help is brief by default, message lists are compact, message reads use short headers, and compose mode accepts `/SEND` and `/CANCEL`.
- **Admin-managed nodes**: Sysops can manage registered PacketBBS bridge nodes from the admin Packet BBS page, generate per-node API keys, view active sessions, and inspect the outbound queue.

## Account Security

### PacketBBS Authenticator QR Code

PacketBBS authenticator setup now generates a QR code for the TOTP enrollment URI using `chillerlan/php-qrcode`. The QR code is displayed directly on Settings -> Account during enrollment, while the manual secret and otpauth URI remain available as fallback options.

This adds a new Composer dependency. Upgraders must run `composer install` before `php scripts/setup.php` so the QR code library is available when the web routes are loaded.

## PacketBBS Gateway

### Bridge API and Admin Management

PacketBBS adds server-side routes under `/api/packetbbs/` for radio bridge software. Bridge requests authenticate with a per-node bearer token generated in the admin Packet BBS page. The bridge protocol remains plain HTTP with text responses for commands and JSON responses for queued outbound messages.

Sysops must register each bridge node before it can use the gateway. The node record controls the allowed bridge identity, interface type, and API key. Unknown bridge nodes are rejected before any BBS command is processed.

### User Authentication

PacketBBS user login uses the PacketBBS authenticator configured from Settings -> Account. Users enroll a TOTP authenticator in the web UI, then log in over radio with:

```text
LOGIN <username> <6-digit-code>
```

The login flow does not use the normal web password over radio.

### Compact Command Interface

The gateway is intentionally terse for mesh/radio use. The primary commands are:

```text
HELP
LOGIN <user> <code>
WHO
MAIL
R <id>
RP <id>
SEND <user> <subject>
AREAS
AREA <tag>
POST <tag> <subject>
M
Q
```

Legacy aliases remain available where useful, including `N`, `NR`, `NRP`, `NS`, `E`, `ER`, `EM`, `EMR`, `EP`, `MORE`, and `QUIT`.

Compose mode accepts one body line per radio message. Send `/SEND` or `.` to finish, and `/CANCEL` or `CANCEL` to abort.

### Echoarea Domains

Networked echoareas may be shown as `TAG@domain`, for example `LVLY_TEST@lovlynet`. PacketBBS preserves that domain when listing, paging, replying, and posting so messages are posted to the correct networked area.

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

Run `php scripts/setup.php` after upgrading so PacketBBS database migrations, admin routing, and configuration defaults are applied.

### From Git

```bash
git pull
composer install
php scripts/setup.php
```

### Using the Installer

Replace your files with the new release archive, then run:

```bash
composer install
php scripts/setup.php
```
