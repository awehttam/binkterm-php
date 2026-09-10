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

- **ANSI-art message viewer:** echomail and netmail whose body is ANSI art (it
  positions the cursor to place its pieces) can now be viewed as art. The inline
  reader still shows the escape-filtered, reflowed body; pressing `A` opens a
  dedicated full-screen view that renders the art with cursor positioning
  intact. That view still strips window-title/clipboard writes (OSC),
  answerback/device-status queries and other input-injection sequences — only
  in-screen drawing is restored. The `TERM_ANSI_ART_MODE` setting controls this:
  `viewer` (default) is the press-`A` behaviour above; `inline` opens the
  full-screen art view automatically whenever an art message is opened, and any
  key drops through to the normal reader; `raw` passes cursor-positioning and
  erase sequences straight through to the normal reader for art messages (and
  does not word-wrap them), so the art renders in place during normal scrolling.
  `raw` reintroduces in-screen display spoofing inside the message reader — the
  sysop opts into that tradeoff; the OSC/DCS/answerback vectors stay closed in
  every mode.

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
