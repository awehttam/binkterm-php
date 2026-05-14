# Shoutbox

The Shoutbox is a simple public message wall where logged-in users can post short text messages visible to everyone on the BBS. It appears as a card on the dashboard and has its own dedicated page at `/shoutbox`.

## Table of Contents

- [For Users](#for-users)
- [Enabling and Disabling](#enabling-and-disabling)
- [Dashboard Card](#dashboard-card)
- [Terminal Access](#terminal-access)
- [Moderation](#moderation)

---

## For Users

Any logged-in user can post a shout. Messages are limited to 280 characters. The most recent shouts are shown first; older messages can be loaded by clicking **Load older**.

Shouts support ANSI art and pipe color codes, rendered inline using the same renderer as the message reader.

---

## Enabling and Disabling

The shoutbox is enabled by default. It can be toggled from **Admin → BBS Settings → Features**. When disabled, the `/shoutbox` page returns a 404 and the dashboard card is hidden automatically.

---

## Dashboard Card

The shoutbox also appears as a card on the dashboard. Users can show or hide the card and reposition it between the main column and sidebar via the dashboard layout customization controls. Sysops can configure its default position from **Admin → Appearance & Content → Dashboard**.

---

## Terminal Access

The shoutbox is also available in Telnet and SSH sessions. After login, the 5 most recent shouts are displayed inline on the post-login screen with a prompt to post (`S`) or continue. From the main menu, pressing **S** opens the full interactive shoutbox view.

The interactive terminal shoutbox shows messages in alternating green and cyan, wrapped to the terminal width. From the prompt:

- **P** — post a new shout
- **R** — refresh the list
- **Q** — return to the main menu

The shoutbox menu option is only shown when the feature is enabled.

---

## Moderation

Admins can review, hide, unhide, and delete individual shouts from **Admin → Shoutbox**.

- **Hide** — the shout is removed from all user-facing views but remains in the database and can be restored
- **Unhide** — restores a previously hidden shout to public visibility
- **Delete** — permanently removes the shout from the database
