This directory contains "screens" shown to clients through the Telnet and SSH terminal interfaces.

The terminal server now supports simple rotating screen families. A base file like
`login.ans` or `login.sixel` can be used by itself, or you can add numbered variants
such as `login1.ans`, `login2.ans`, `mainmenu1.sixel`, and so on. One matching file
from the family is chosen at random each time that screen is shown.

Supported families:

 * `login*.ans` / `login*.sixel` - shown to the user on first connect
 * `mainmenu*.ans` / `mainmenu*.sixel` - shown as the main menu backdrop
 * `bye*.ans` / `bye*.sixel` - shown when the user disconnects
