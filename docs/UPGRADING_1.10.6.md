# Upgrading to 1.10.6

Make sure you have a current backup of your database and files before upgrading.

## Table of Contents

- [Summary of Changes](#summary-of-changes)
- [Upgrade Instructions](#upgrade-instructions)
  - [From Git](#from-git)
  - [Using the Installer](#using-the-installer)

## Summary of Changes

<!--
Group bullet points by major feature area as changes land during this release
cycle. Each bullet should be self-contained: state what changed, why it matters,
and what (if anything) the upgrader must do.
-->

This release is in development. Feature-area details will be added here as
changes are made.

### Terminal Server

- **ANSI message wrapping fix:** the Telnet/SSH message reader now wraps message
  bodies with an ANSI- and UTF-8-aware word-wrapper. Previously, colour codes and
  multi-byte box-drawing characters were counted as literal bytes toward the line
  width, so coloured or ANSI-art messages could be hard-cut in the middle of an
  escape sequence (showing stray text such as `[35m`) or a multi-byte character
  (showing mojibake), and lines could overflow the terminal width. This was most
  visible on ANSI-art posts after the 1.10.5 escape-sequence filtering removed
  their absolute cursor positioning. Escape sequences are now treated as
  zero-width and are never split; wrapping only breaks on character boundaries.

- **ANSI-art messages render on a virtual canvas:** echomail and netmail whose
  body is ANSI art (it positions the cursor to place its pieces) are now drawn
  onto an off-screen character grid and the resulting coloured lines are shown
  inline in the normal reader. The 1.10.5 security fix strips absolute cursor
  positioning from message bodies, which left ANSI art reflowing into unreadable
  text; the canvas resolves every cursor move against the grid first, so the art
  keeps its layout and the reader still scrolls, repaints and resizes it like
  any other message. No cursor-control code reaches the terminal — only colour.
  Pressing `A` in the reader opens a full-screen view that renders the art with
  real cursor positioning for maximum fidelity; that view (like the inline
  canvas) still strips window-title/clipboard writes (OSC),
  answerback/device-status queries and other input-injection sequences.

  The `TERM_ANSI_ART_MODE` setting selects the behaviour:

  | Value | Behaviour |
  |-------|-----------|
  | `canvas` (default) | Art rendered on the virtual canvas inline; `A` for the full-screen view. |
  | `viewer` | Inline reader shows the escape-filtered, reflowed body; `A` for the full-screen view. |
  | `inline` | The full-screen view opens automatically when an art message is opened; any key returns to the reader. |
  | `raw` | Cursor-positioning and erase codes pass straight through to the normal reader for art messages (not word-wrapped). Reintroduces in-screen display spoofing within the reader — the sysop opts in. |

  The OSC/DCS/answerback protections apply in every mode.

---

## Upgrade Instructions

### From Git

```bash
git pull
php scripts/setup.php
scripts/restart_daemons.sh
```

### Using the Installer

Download the latest installer from the [BinktermPHP website](https://lovelybits.org/binktermphp) and run it. The installer handles file replacement, runs setup, and restarts all daemons automatically — no manual steps required.
