# ANSI Escape Sequence Support

BinktermPHP includes full support for ANSI escape sequences (also called ANSI codes or escape codes) used for text formatting, colors, and cursor control in messages.

## What are ANSI Escape Sequences?

ANSI escape sequences are special character sequences that control text formatting and cursor positioning in terminals. They begin with the ESC character (ASCII 27, `\x1b`) followed by control codes.

ANSI codes were standardized by ANSI X3.64 and are widely used in:
- Unix/Linux terminals
- BBS systems
- FidoNet message systems
- ASCII/ANSI art

## Format

```
ESC[<parameters>m
```

Where:
- `ESC` is the escape character (ASCII 27, `\x1b`)
- `[` begins a Control Sequence Introducer (CSI)
- `<parameters>` are semicolon-separated numeric codes
- `m` indicates SGR (Select Graphic Rendition)

Example: `\x1b[1;31m` = Bold + Red text

## Color Codes

### Standard Foreground Colors (30-37)
- `30` - Black
- `31` - Red
- `32` - Green
- `33` - Yellow
- `34` - Blue
- `35` - Magenta
- `36` - Cyan
- `37` - White

### Bright Foreground Colors (90-97)
- `90` - Bright Black (Gray)
- `91` - Bright Red
- `92` - Bright Green
- `93` - Bright Yellow
- `94` - Bright Blue
- `95` - Bright Magenta
- `96` - Bright Cyan
- `97` - Bright White

### Standard Background Colors (40-47)
- `40` - Black background
- `41` - Red background
- `42` - Green background
- `43` - Yellow background
- `44` - Blue background
- `45` - Magenta background
- `46` - Cyan background
- `47` - White background

### Bright Background Colors (100-107)
- `100` - Bright Black background
- `101` - Bright Red background
- `102` - Bright Green background
- `103` - Bright Yellow background
- `104` - Bright Blue background
- `105` - Bright Magenta background
- `106` - Bright Cyan background
- `107` - Bright White background

### Reset Colors
- `39` - Default foreground color
- `49` - Default background color

## Text Formatting Codes

### Styles
- `0` - Reset all attributes (default)
- `1` - Bold / Increased intensity
- `2` - Dim / Faint
- `3` - Italic
- `4` - Underline
- `5` - Slow blink
- `6` - Fast blink
- `7` - Reverse video (swap foreground/background)
- `8` - Hidden / Concealed
- `9` - Strikethrough / Crossed out

### Reset Specific Attributes
- `22` - Normal intensity (not bold, not dim)
- `23` - Not italic
- `24` - Not underlined
- `25` - Not blinking
- `27` - Not reversed
- `28` - Not hidden
- `29` - Not strikethrough

## Cursor Control Sequences

BinktermPHP supports cursor positioning for ASCII/ANSI art:

### Cursor Movement
- `ESC[<n>A` - Cursor up n lines
- `ESC[<n>B` - Cursor down n lines
- `ESC[<n>C` - Cursor forward n columns
- `ESC[<n>D` - Cursor back n columns
- `ESC[<n>E` - Cursor next line (beginning of line, n lines down)
- `ESC[<n>F` - Cursor previous line (beginning of line, n lines up)
- `ESC[<n>G` - Cursor horizontal absolute (column n)
- `ESC[<row>;<col>H` - Cursor position (row, col)
- `ESC[<row>;<col>f` - Cursor position (alternative)

### Screen Control
- `ESC[<n>J` - Clear screen
  - `0` - Clear from cursor to end of screen
  - `1` - Clear from cursor to beginning of screen
  - `2` - Clear entire screen
  - `3` - Clear entire screen and scrollback buffer
- `ESC[<n>K` - Clear line
  - `0` - Clear from cursor to end of line
  - `1` - Clear from cursor to beginning of line
  - `2` - Clear entire line
- `ESC[<n>S` - Scroll up n lines
- `ESC[<n>T` - Scroll down n lines

### Cursor Position Save/Restore
- `ESC[s` - Save cursor position
- `ESC[u` - Restore cursor position

## Implementation

### Terminal Emulation

BinktermPHP includes a full ANSI terminal emulator that:
- Maintains a virtual screen buffer (80 columns × variable rows)
- Processes cursor positioning sequences
- Handles screen clearing and scrolling
- Renders to HTML with CSS classes

### Automatic Detection

The system automatically detects message content type:

**ASCII/ANSI Art** (uses terminal emulation):
- Contains cursor positioning codes
- Dense ANSI formatting (4+ lines, 30+ chars/line)
- Leading space patterns (ASCII art detection)

**Regular Text** (line-by-line parsing):
- Simple color codes without positioning
- Normal message formatting
- Better performance for non-art content

### Rendering Pipeline

1. **Detection** - Check for ANSI codes and cursor positioning
2. **Processing**
   - ASCII/ANSI art → Terminal emulator → HTML
   - Regular text → Line-by-line parser → HTML
3. **Output** - HTML with CSS classes for styling

## JavaScript Functions

### Main Functions

**`renderAnsiTerminal(text, cols=80, rows=500)`**
Main rendering function that:
- Auto-detects ANSI art vs regular text
- Uses terminal emulation for cursor positioning
- Falls back to simple parsing for better performance
- Returns HTML with CSS classes

**`parseAnsi(text)`**
Fast line-by-line ANSI parser for regular messages:
- Processes SGR color/style codes
- Strips cursor control sequences
- Returns HTML with CSS classes

**`hasAnsiCodes(text)`**
Detects if text contains ANSI escape sequences.

### Terminal Emulator Class

**`AnsiTerminal(cols, rows)`**
Full terminal emulator with:
- Virtual screen buffer
- Cursor positioning
- Attribute tracking (colors, bold, etc.)
- Screen clearing and scrolling
- HTML rendering with proper formatting

## CSS Classes

ANSI codes are rendered as HTML spans with CSS classes:

### Colors
- `ansi-black`, `ansi-red`, `ansi-green`, `ansi-yellow`, `ansi-blue`, `ansi-magenta`, `ansi-cyan`, `ansi-white`
- `ansi-bright-black`, `ansi-bright-red`, etc.
- `ansi-bg-black`, `ansi-bg-red`, etc. (backgrounds)

### Styles
- `ansi-bold` - Bold text
- `ansi-dim` - Dim/faint text
- `ansi-italic` - Italic text
- `ansi-underline` - Underlined text
- `ansi-blink` - Blinking text
- `ansi-reverse` - Reversed colors
- `ansi-hidden` - Hidden text
- `ansi-strike` - Strikethrough text

## Usage Examples

### Simple Color Change
```
\x1b[31mThis is red\x1b[0m and this is normal
```
Output: <span style="color: red">This is red</span> and this is normal

### Bold + Color
```
\x1b[1;34mBold Blue Text\x1b[0m
```
Output: <span style="font-weight: bold; color: blue">Bold Blue Text</span>

### Background Color
```
\x1b[41;37mWhite text on red background\x1b[0m
```
Output: <span style="background: red; color: white">White text on red background</span>

### ASCII Art with Positioning
```
\x1b[2J\x1b[H\x1b[1;32m
  ╔════════════╗
  ║   MENU     ║
  ╚════════════╝
\x1b[5;5H\x1b[37m1) Option One
\x1b[6;5H2) Option Two
```
Uses cursor positioning to place text precisely on screen.

### Multiple Attributes
```
\x1b[1;4;31mBold, Underlined, Red\x1b[0m
```

## User Settings

ANSI parsing can be controlled via user settings:

```javascript
window.userSettings.ansi_parsing = false;  // Disable ANSI parsing
```

When disabled, ANSI codes are stripped and messages display as plain text.

## Compatibility

### Fully Supported
- ✅ SGR color and style codes (CSI m)
- ✅ Cursor positioning (CSI H, f, A-G)
- ✅ Screen clearing (CSI J, K)
- ✅ Scrolling (CSI S, T)
- ✅ Save/restore cursor (CSI s, u)
- ✅ 256-color palette (CSI 38;5;n / 48;5;n)

### Partially Supported
- ⚠️ Blink - Mapped to CSS but may not render in all browsers

### Not Supported (Stripped)
- ❌ Other escape sequences (OSC, etc.)
- ❌ Non-CSI escape codes

### Works With
- Traditional BBS ANSI art
- FidoNet message formatting
- ASCII art with cursor positioning
- Terminal-style colored output
- Mixed ANSI and pipe codes

## Performance Optimization

BinktermPHP uses intelligent detection to optimize performance:

1. **Simple messages** → Fast line-by-line parser
2. **ASCII art** → Full terminal emulation
3. **No codes** → Plain text rendering

This ensures fast rendering for most messages while still supporting complex ANSI art when needed.

## Related

- [Pipe Code Support](Pipe_Code_Support.md) - BBS pipe codes (|XX format)
- CSS styling in `/css/ansisys.css`
- JavaScript implementation in `/js/ansisys.js`

## References

- ANSI X3.64 (ECMA-48) standard
- VT100/VT220 terminal sequences
- FidoNet Technical Standards (FTS-0001)
