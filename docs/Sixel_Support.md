# Sixel Support

BinktermPHP renders DEC Sixel graphics embedded in messages and uploaded as files.

## Overview

Sixel is a bitmap graphics format developed by Digital Equipment Corporation (DEC) for VT240/VT340 terminals. It encodes raster images as streams of six-pixel-tall columns using printable ASCII characters, and is still widely supported by modern terminals (xterm, iTerm2, mlterm, etc.). BinktermPHP decodes and renders sixel data entirely in the browser using an HTML5 `<canvas>` element.

## Where Sixel Renders

### In Messages (Echomail & Netmail)

When viewing an echomail or netmail message, the message reader scans the body for embedded sixel data (sequences beginning with `ESC P` / `ESC P ... q`). If found, the sixel segments are rendered to canvas inline with any surrounding plain text.

The `renderSixelChunks()` function handles mixed content — a message may contain both plain text sections and one or more sixel image blocks, all rendered in order.

### In File Areas

Files with `.six` or `.sixel` extensions are automatically previewed as sixel images in the file browser. The preview renders the file content to a canvas element in the file detail panel.

## Supported Features

The sixel decoder in `public_html/js/sixel.js` supports:

- **256-color palette** — default VT340 palette for registers 0–15, remainder default to black until defined by the stream
- **HLS and RGB color definition** (`#n;2;r;g;b` and `#n;1;h;l;s`)
- **Repeat introducer** (`!count char`) for run-length encoded rows
- **Carriage return** (`$`) and next-row (`-`) control characters
- **Raster attributes** (`"Pan;Pad;Ph;Pv`) for aspect ratio and canvas size hints
- **Transparent background** — background pixels default to transparent

## Default Palette

The first 16 color registers use the VT340 palette:

| Register | Color |
|----------|-------|
| 0 | Black |
| 1 | Blue |
| 2 | Red |
| 3 | Green |
| 4 | Magenta |
| 5 | Cyan |
| 6 | Yellow |
| 7 | Gray 50% |
| 8 | Gray 33% |
| 9 | Light Blue |
| 10 | Light Red |
| 11 | Light Green |
| 12 | Light Magenta |
| 13 | Light Cyan |
| 14 | Light Yellow |
| 15 | White |

Registers 16–255 default to opaque black until redefined by the stream.

## Public JS API

The sixel renderer exposes these functions globally:

```javascript
// Returns true if the string contains a sixel DCS sequence
looksLikeSixel(text)

// Decodes and renders sixel data, returns an HTMLCanvasElement or null
renderSixelToCanvas(sixelData)

// Renders mixed text+sixel content into a container element
// textChunks are rendered using the provided renderTextFn callback
renderSixelChunks(container, rawText, renderTextFn)

// Renders a sixel file preview into a jQuery container
renderSixelFilePreview($container, text)
```

## Notes

- Sixel rendering is entirely client-side — no server processing is required.
- Very large sixel images may take a moment to decode depending on resolution and color depth.
- The canvas element scales with the container; actual pixel dimensions are determined by the sixel stream's raster attributes or by the decoded content size.
