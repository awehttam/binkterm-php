# Dashboard

The dashboard is the first page users see after logging in. It provides a summary of unread mail, new activity, and quick access to common features. It is divided into a wide main column and a narrower sidebar, each containing a set of cards that can be reordered, hidden, or moved between columns.

## Table of Contents

- [Cards](#cards)
- [Echomail Badge Mode](#echomail-badge-mode)
- [Customizing the Layout](#customizing-the-layout)
- [Sysop Configuration](#sysop-configuration)

---

## Cards

The dashboard is composed of cards. Which cards appear depends on which features are enabled and whether the user is an admin.

### Mail & Areas *(always visible)*

Shows the count of unread netmail and new (or unread) echomail in subscribed areas. Click either counter to go directly to your inbox or echo area list.

Below the counters, the **New Echo Areas** section lists areas added in the past 30 days (up to 8), with their tag, network, and date. The section can be collapsed; the count is still shown when collapsed. Buttons at the bottom link to subscription management and, if enabled, the Interests page.

The dashboard stats refresh automatically every 30 seconds.

### System News

Displays the sysop-written MOTD (message of the day), formatted as Markdown. Set it from **Admin → Appearance & Content → Content → System News**.

### Shoutbox

An inline version of the Shoutbox — shows the most recent shouts, lets users post a new shout, and loads older shouts on demand. Appears only when the Shoutbox feature is enabled. See [Shoutbox](Shoutbox.md).

### Advertisement

Displays one or more ANSI-art ads from the ad system. When multiple ads are configured, they rotate automatically and can be navigated with previous/next buttons. Appears only when the Advertising feature is enabled and at least one ad is assigned to the dashboard position. See [Advertising](Advertising.md).

### Bulletins *(sidebar)*

Shows the count of unread bulletins with a link to the Bulletins page. Displays "No new bulletins" when all are read. See [Bulletins](Bulletins.md).

### System Information *(sidebar)*

Shows the sysop name, the logged-in user's username, and the FTN network addresses the BBS is registered on.

### Today's Callers *(sidebar, admin only)*

A table of users who have logged in today, with the time of their last activity and an online indicator for users currently active.

### Voting Booth *(sidebar)*

Shows an active poll inline. Users can vote directly from the dashboard; results appear immediately after voting. If multiple polls are active, prev/next buttons cycle between them. Appears only when the Voting Booth feature is enabled. See [Voting Booth](VotingBooth.md).

### Echo Areas *(sidebar)*

Lists all subscribed echo areas with their unread and total message counts. Click an area name to go to its message list.

### Referral *(sidebar)*

Shows the user's personal referral link with a one-click copy button, plus stats (total referrals and credits earned) and a short list of recent referrals. Appears only when the credit system's referral feature is enabled.

---

## Echomail Badge Mode

The echomail counter on the Mail & Areas card can operate in two modes, selectable in **Account Settings**:

- **New since last visit** (default) — counts messages that arrived after you last opened the echo area list. Fast query; resets automatically when you visit `/echomail`.
- **Unread** — counts messages in your subscribed areas that you have never opened, using the full read-tracking table. More accurate but a heavier database query on large installs.

---

## Customizing the Layout

Click the **Customize** button (top right of the dashboard) to open the layout editor. Each card appears as a draggable chip in either the Main Column or Sidebar Column list.

- **Drag** a chip to reorder it within a zone or move it to the other zone.
- Click the **eye icon** on a chip to hide or show that card.
- Click **Save** to apply the new layout; it is stored in your account settings and persists across sessions.
- Click **Reset** to restore the sysop's default layout (or the built-in defaults if no sysop default is configured).

The **Mail & Areas** card is required and cannot be hidden.

---

## Sysop Configuration

**Default layout** — Sysops can configure the layout new users start with from **Admin → Appearance & Content → Dashboard**. Drag cards between the Main Column, Sidebar Column, and Hidden lists and save. Individual users who have already customized their layout are unaffected; they can reset to the sysop default using the Reset button on their own Customize modal.

**System News** — Edit the MOTD displayed in the System News card from **Admin → Appearance & Content → Content → System News**. The field accepts Markdown.
