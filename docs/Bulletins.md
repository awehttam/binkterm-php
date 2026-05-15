# Bulletins

Bulletins are sysop-managed notices shown to users at login and available on demand from the main menu. They support plain text, ANSI art, pipe color codes, and Markdown, and can be scheduled to appear only during a specific date range.

## Table of Contents

- [Managing Bulletins](#managing-bulletins)
- [Bulletin Fields](#bulletin-fields)
- [Display Modes](#display-modes)
- [Web Interface](#web-interface)
- [Terminal Access](#terminal-access)

---

## Managing Bulletins

Bulletins are created and managed from **Admin → Ads and Bulletins → Bulletins**. The admin page shows all bulletins in order with a live preview. Bulletins can be reordered by adjusting their sort order.

---

## Bulletin Fields

| Field | Description |
|-------|-------------|
| Title | Displayed as a heading in the bulletin viewer |
| Body | The bulletin content — plain text, ANSI art with pipe codes, or Markdown |
| Format | `Plain` or `Markdown` — controls how the body is rendered |
| Sort order | Lower numbers appear first |
| Active | Whether the bulletin is currently live |
| Active from | Optional start date/time; bulletin is hidden before this time |
| Active until | Optional expiry date/time; bulletin is hidden after this time |

Leave **Active from** and **Active until** blank for a bulletin that is always active while enabled.

---

## Display Modes

The `bulletin_display_mode` setting in **Admin → BBS Settings** controls how often bulletins are shown at login:

- `once` (default) — a bulletin is shown once per user; it is not shown again after the user has seen it
- `always` — bulletins are shown every time the user logs in, regardless of read state

---

## Web Interface

Users can view all active bulletins at `/bulletins`. Unread bulletins are highlighted. Reading a bulletin marks it as read.

The dashboard does not automatically surface unread bulletins inline, but users can navigate to the bulletins page directly from the navigation menu.

---

## Terminal Access

In Telnet and SSH sessions, unread bulletins are displayed automatically during the post-login sequence, before the main menu appears. Each bulletin is shown in a paged box sized to the terminal width. The user can:

- Press **Enter** to advance to the next bulletin
- Press **S** to skip all remaining unread bulletins

After the post-login sequence, bulletins can be revisited at any time from the main menu by pressing **U**.

Plain text bulletin bodies are rendered with ANSI color and pipe code support. Cursor movement, erase sequences, and other non-display control codes are stripped so they cannot disturb the pager frame. Markdown bulletins are rendered using the terminal Markdown renderer. ANSI color can be suppressed for terminals that do not support it.
