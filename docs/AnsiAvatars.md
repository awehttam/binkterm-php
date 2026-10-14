# ANSI Avatars

BinktermPHP stores one optional Synchronet-compatible avatar per account. The record is a 10 by 6 CP437 BIN: inert glyph and attribute bytes, never a terminal program.

This is separate from ANSI escape sequences inside messages. Message art is documented in [ANSI Support](ANSI_Support.md).

## BIN contract

A saved avatar is exactly 120 bytes, row-major, sixty pairs of `[CP437 byte, IBM PC attribute]`.

- The low attribute nibble is the foreground color, 0 through 15.
- Attribute bits 4 through 6 are the background color, 0 through 7.
- Bit 7 is the blink flag. It is stored and returned to editors. Web and terminal rendering do not animate it.
- Byte `0x1B` is the CP437 left-arrow glyph. It is never executed as ESC.
- A record is blank, and is rejected, when every character is `0x00`, `0x20`, or `0xFF` and every background is black. A space or null with a non-black background is a colored blank and is allowed.
- The API transports the BIN as canonical Base64: exactly 160 characters from `A-Za-z0-9+/`, with no whitespace and no padding. Decoding must reproduce that same text.

Control-range CP437 bytes are drawn as one-column substitutes. The renderer synthesizes SGR only from the validated color nibbles, resets each row, and keeps every row at ten display columns.

## Storage

Migration `database/migrations/v20261014051503_user_ansi_avatars.sql` creates `user_ansi_avatars`:

| Column | Notes |
|--------|-------|
| `user_id` | Primary key and foreign key to `users.id`, deleted with the account |
| `avatar_bin` | `BYTEA`, exactly 120 bytes (`octet_length` check) |
| `updated_at` | `TIMESTAMPTZ`, set on insert and replace |

The avatar is not stored in `users_meta`. Replacing an avatar is last-write-wins for that account, same as the rest of the profile form.

## Web

Signed-in users paint or import an avatar on **Profile**. Import accepts a 120-byte `.bin` and refuses any other size, including a blank-on-black image. Save and remove call the account API. The editor sends only `{ "ansi": "<base64>" }`.

Other signed-in users see the plate on `/profile/{username}`. With no saved avatar, the page shows the first two letters of the username. The plate is HTML text and CSS color classes. It does not embed escape bytes, and the script builds cells with `textContent`.

## API

All four routes require a signed-in session. `POST` and `DELETE` also require the session CSRF token (`X-CSRF-Token`), enforced by `Auth::requireAuth()`. The write subject is that session user. A `user_id` or `id` field in the JSON body is ignored.

| Method | Path | Result |
|--------|------|--------|
| `GET` | `/api/user/ansi-avatar` | The caller's avatar, or `avatar: null` |
| `GET` | `/api/user/ansi-avatar/{id}` | An active user's avatar, or 404 |
| `POST` | `/api/user/ansi-avatar` | Replace the caller's avatar |
| `DELETE` | `/api/user/ansi-avatar` | Remove the caller's avatar |

`GET /api/user/public-profile/{id}` includes `profile.ansi_avatar` as the canonical Base64 string, or `null`.

Field details are in [API.md](API.md).

## Terminal

Who's Online and the settings action **View ANSI avatar** open the existing public profile viewer.

- A color-capable full-screen session draws six final lines. Those lines are already in the session character set (`utf8`, `cp437`, or `ascii`) and are not passed through `encodeForTerminal()` again.
- A session with ANSI color disabled shows the username initials.
- The line shell strips color, so it also shows initials rather than a color-dependent picture.

### CP437 bytes 0x80–0x9F

These bytes are CP437 graphics. `0x80` is Ç, `0x81` is ü, and the range continues through `0x9F` (ƒ). They are not C0 controls, and this feature does not replace them with substitutes.

Raw single bytes `0x80`–`0x9F` are written only when all of the following are true:

- The viewer is the full-screen public profile (`TelnetUtils::showPublicProfileViewer`). The line shell never draws the picture.
- ANSI color is enabled (`BbsSession::applyTerminalSettings()` copied `terminal_ansi_color` into `TelnetUtils::$ansiColorEnabled`). Color off returns no art lines, and the viewer shows initials.
- `BbsSession::getTerminalCharset()` is exactly `cp437`.

That charset is the saved user setting `terminal_charset = cp437` (`CP437 (DOS/ANSI)` in terminal settings). `applyTerminalSettings()` copies that value onto the session. Negotiation does not select it. With no saved charset, the session becomes `utf8` or `ascii` (`shouldUseAsciiFallback()`). A TTYPE containing `CP437`, `ANSI`, or `PCANSI` is an ASCII fallback, not a CP437 session. SSH with no saved charset stays UTF-8.

On that CP437 session the rest of the terminal already emits these same bytes: `encodeForTerminal()` converts UTF-8 text with `iconv` to CP437. Avatar lines are built as final CP437 bytes and are inserted after that conversion so they are not encoded twice. A client that actually speaks CP437 shows `0x80` as Ç. A client that saved CP437 but still parses ISO 6429 C1 controls could treat `0x90` as DCS, `0x9B` as CSI, or `0x9D` as OSC. That is the same client mismatch as every other CP437 write in the session, not a second avatar policy.

Other modes never receive those raw bytes:

- `utf8` sends the Unicode glyph from `Cp437::glyph()` when it is one column and contains no C0 control. Ç is the UTF-8 sequence `C3 87`, not byte `0x80`. A glyph that is wider than one column, or that contains a C0 byte, uses the ASCII stand-in instead.
- `ascii` sends a one-column printable stand-in (`0x20`–`0x7E`). A full block (`0xDB`) becomes a space whose background is the foreground color.
- Color disabled, the line shell, and an avatar that fails to parse show initials. An unsupported charset name throws, and the profile viewer catches that and shows initials.

Bytes `0x01`–`0x1F` and `0x7F` are the control range that is always substituted. `0x1B` is `<` on CP437 and ASCII, and the one-column arrow ← on UTF-8. It is never written as ESC. The only ESC bytes in an art line are the synthesized SGR sequences, one per cell plus a reset at the end of the row.

## Provenance

The parser, plate, and editor started as an unreviewed Cythu scratch and were adapted to this tree. New avatar source files are BSD-3-Clause, copyright 2026 Cythu contributors. The behavior above is the contract that was reconciled with this codebase, including the real CSRF check, the migration, and the terminal character-set path.
