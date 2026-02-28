# Pipe Code Support

BinktermPHP now supports pipe codes (also called MCI codes or color codes) used by various BBS software including Synchronet, Renegade, and Mystic BBS.

## What are Pipe Codes?

Pipe codes are text formatting codes that use the pipe character (`|`) followed by a two-digit hexadecimal code to change colors and styles. They were popularized by BBS software as a simpler alternative to ANSI escape sequences.

## Format

```
|XX
```

Where `XX` is a two-digit hexadecimal code (00-FF).

## Color Mapping

### Foreground Colors (|00 to |0F)
- `|00` - Black
- `|01` - Blue
- `|02` - Green
- `|03` - Cyan
- `|04` - Red
- `|05` - Magenta
- `|06` - Yellow
- `|07` - White
- `|08` - Gray (Bright Black)
- `|09` - Bright Blue
- `|0A` - Bright Green
- `|0B` - Bright Cyan
- `|0C` - Bright Red
- `|0D` - Bright Magenta
- `|0E` - Bright Yellow
- `|0F` - Bright White

### Background + Foreground (|10 to |FF)

For codes above `|0F`, the format is `|XY` where:
- `X` (upper nibble) = Background color (0-F)
- `Y` (lower nibble) = Foreground color (0-F)

Examples:
- `|1F` - Blue background, white foreground
- `|4E` - Red background, yellow foreground
- `|2C` - Green background, bright red foreground

## Usage

Pipe codes are automatically detected and rendered when displaying messages. No special configuration is needed.

### In Message Bodies

```
|0FWelcome to the BBS!

|0EYou have |0F5 |0Enew messages.

|0CMenu: |0F1|07) Messages  |0F2|07) Files
```

### Mixed with ANSI Codes

Pipe codes can be mixed with ANSI escape sequences in the same message:

```
|0CThis is pipe red [33mThis is ANSI yellow[0m Back to normal
```

## Implementation

The pipe code parser works by:

1. Detecting pipe codes in the format `|XX` where XX is a hex digit
2. Converting them to ANSI escape sequences
3. Processing through the existing ANSI parser

This approach ensures:
- Backward compatibility with existing ANSI rendering
- Support for mixing both formats
- Consistent color rendering across the application

## JavaScript Functions

### `hasPipeCodes(text)`
Detects if text contains pipe codes.

### `convertPipeCodesToAnsi(text)`
Converts pipe codes to ANSI escape sequences.

### `renderAnsiTerminal(text)`
Main function that handles both ANSI and pipe codes. Automatically detects and processes both formats.

### `parseColorCodes(text)`
Alias for `renderAnsiTerminal()` that handles auto-detection.

## Testing

A test page is available at `/pipecode-test.html` showing various examples of pipe code usage including:
- Basic foreground colors
- Bright colors
- Background + foreground combinations
- Mixed ANSI and pipe codes
- Realistic BBS message examples

## User Settings

Pipe code parsing can be disabled in user settings:

```javascript
window.userSettings.pipe_parsing = false;  // Disable pipe code parsing
```

This uses the same setting as ANSI parsing. If ANSI parsing is disabled, pipe codes will also be disabled.

## Compatibility

Pipe codes are compatible with:
- **Synchronet BBS** - Standard `|XX` format
- **Mystic BBS** - Standard `|XX` format with hex codes
- **Renegade BBS** - Standard `|XX` format
- **Other FTN-compatible BBS software** using similar pipe code standards

## Special Pipe Codes

BBS software uses letter-based pipe codes for terminal control, dynamic content substitution, and display-file behaviour. These fall into three categories:

### Rendered
A small number of letter codes have a meaningful equivalent in the web viewer:
- `|PI` - Literal pipe character (`|`) — Mystic BBS escape for a pipe symbol
- `|CD` - Reset colour to default — converted to ANSI reset (`\x1b[0m`)

### Stripped (control codes)
These are interactive terminal control codes that have no meaning when viewing archived messages:
- `|AA` / `|AO` - Abort on / Abort off (Mystic display-file control)
- `|BE` - Beep / bell character
- `|BS` - Destructive backspace
- `|CL` - Clear screen
- `|CN` / `|CY` - iCE colours off / on
- `|CR` - Carriage return + line feed
- `|DE` - Half-second delay
- `|LC` - Load last colour mode
- `|LF` - Load last font
- `|PA` - Pause prompt
- `|PB` - Purge input buffer
- `|PE` / `|PN` - Pause for Enter / wait for key (no prompt)
- `|PO` - Pause off (Mystic display-file control)
- `|RA` / `|RS` / `|SA` / `|SS` - Save/restore attribute and screen
- `|RD` - Read
- Cursor/screen codes: `|[0`, `|[1`, `|[K`, `|[A##`, `|[X##`, etc.

### Stripped (information codes)
These codes are substituted by the BBS at display time. In archived messages there is no live BBS context to resolve them, so they are removed:
- `|BN` - BBS name, `|SN` - Sysop name, `|DN` - Domain name
- `|UN` - User real name, `|UH` - User handle
- `|TI` / `|TM` / `|TS` - Current time variants
- `|DA` - Current date
- `|CS` / `|CT` - Total calls / calls today
- `|SL` - Security level
- All other unrecognised two-letter codes

Mystic BBS theme colour codes (`|T0`–`|T9`) are also stripped as they are theme-dependent.

## Notes

- Pipe codes are case-insensitive (`|0F` = `|0f`, `|CL` = `|cl`)
- Mystic-style hex colour codes (`|0A`–`|FF`) are parsed as two hex nibbles: upper = background, lower = foreground
- Renegade-style decimal colour codes (`|00`–`|23`) use decimal values: 00–15 = foreground, 16–23 = background
- All unrecognised two-letter codes are stripped (rather than passed through as literal text)
- The implementation covers Renegade, Mystic, Synchronet, and other FTN-compatible BBS software
