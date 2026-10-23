# ANSI avatar contribution handoff

Publication note for the Synchronet-compatible ANSI avatar contribution. The feature contract is [ANSI Avatars](AnsiAvatars.md). The documentation addendum `cf351d66` is not included in this branch.

## Known limitations

- The blink attribute bit is stored and returned to the editor. Web and terminal rendering leave it unanimated.
- A color-disabled terminal session and the line shell show the username initials. The 10 by 6 picture is drawn on a color-capable full-screen profile viewer.
- CP437 art bytes are emitted when the saved setting `terminal_charset` is exactly `cp437` and ANSI color is enabled. Terminal-type negotiation leaves the charset unchanged. A TTYPE containing `CP437`, `ANSI`, or `PCANSI` follows the ASCII fallback.
- Bytes `0x01` through `0x1F` and `0x7F` are drawn as one-column substitutes. Byte `0x1B` stays a glyph and is never written as ESC.
- An all-blank avatar on a black background is rejected. A space or null with a non-black background is a colored blank and is kept.
- Replacing an avatar is last-write-wins for that account. The value lives in `user_ansi_avatars`, not in `users_meta`.
- The avatar routes require a signed-in session. `POST` and `DELETE` also require the session CSRF token. A `user_id` field in the JSON body is ignored.
- This picture is account profile art. Message ANSI art remains in [ANSI Support](ANSI_Support.md).
