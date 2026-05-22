# Markdown and Markup Formatting

BinktermPHP supports rich text formatting in echomail and netmail messages. When a network permits it, users can compose messages using **Markdown** or **StyleCodes** and have them rendered with full formatting in the message reader.

## Table of Contents

- [How It Works](#how-it-works)
- [Enabling Markup for an Uplink](#enabling-markup-for-an-uplink)
- [The Compose Editor](#the-compose-editor)
  - [Markup Format Selector](#markup-format-selector)
  - [Markdown Editor](#markdown-editor)
  - [StyleCodes Editor](#stylecodes-editor)
- [Supported Markdown Syntax](#supported-markdown-syntax)
- [Supported StyleCodes Syntax](#supported-stylecodes-syntax)
- [Rendering Incoming Messages](#rendering-incoming-messages)
- [The MARKUP Kludge and LSC-001](#the-markup-kludge-and-lsc-001)
- [Security](#security)

---

## How It Works

Markup support is opt-in per network via **Admin BBS Settings → Networks**. When a user sends a message with a markup format selected, BinktermPHP adds a `^AMARKUP: Markdown 1.0` or `^AMARKUP: StyleCodes 1.0` kludge line to the outbound packet per the LSC-001 specification. Readers that recognize the kludge render the message body with formatting; readers that do not see the raw source text, which remains readable as plain text.

Messages without a `^AMARKUP` kludge are always displayed as plain text regardless of what they contain.

---

## Enabling Markup for an Uplink

Enable markup for a network from **Admin → Networks**. Edit the network and check the **Allow Markup** checkbox, then save. The Markup Format selector in the compose form is only shown to users when the destination network has markup enabled. It is hidden for plain networks and local-only areas.

---

## The Compose Editor

### Markup Format Selector

When composing a message to a markup-enabled network, a **Markup Format** dropdown appears below the message body with three options:

| Option | Kludge emitted | Editor mode |
|--------|---------------|-------------|
| Plain text | _(none)_ | Standard textarea |
| Markdown | `^AMARKUP: Markdown 1.0` | Split-pane WYSIWYG editor |
| StyleCodes | `^AMARKUP: StyleCodes 1.0` | Plain textarea + formatting toolbar |

The selector is also restored when replying to a marked-up message or loading a saved draft, pre-selecting the format that was originally used.

### Markdown Editor

Selecting **Markdown** activates the Toast UI WYSIWYG editor (`toastui-editor`), which replaces the plain textarea with a split-pane interface.

**Toolbar actions:**

| Button | Action | Keyboard shortcut |
|--------|--------|--------------------|
| B | Bold | Ctrl+B |
| I | Italic | Ctrl+I |
| H1 / H2 / H3 | Headings | — |
| `code` | Inline code | — |
| ` ``` ` | Code block | — |
| Link | Insert hyperlink | Ctrl+K |
| Bullet list | Unordered list | — |
| Numbered list | Ordered list | — |
| Blockquote | Block quote | — |
| HR | Horizontal rule | — |

**Edit / Preview tabs:** Switch between raw Markdown editing and a rendered preview at any time. The preview uses the same server-side `MarkdownRenderer` as the message reader, so what you see in preview is what recipients see.

**Image upload:** The editor's **Insert Image** button opens a multi-tab picker with direct URL, file upload, existing uploaded-image selection, and clipboard paste support. In Markdown mode, BinktermPHP hosts the image and inserts the Markdown reference (`![alt](url)`) automatically.

**URL paste:** Pasting a bare URL into the editor inserts it as a Markdown link.

The editor writes clean Markdown back to the hidden textarea on every change. When the form is submitted, the raw Markdown source is what gets stored and sent in the packet — not HTML.

### StyleCodes Editor

Selecting **StyleCodes** shows a compact formatting toolbar above the standard textarea. Click a button to wrap the selected text with the corresponding StyleCodes delimiters:

| Button | Delimiter | Effect |
|--------|-----------|--------|
| B | `*text*` | Bold |
| I | `/text/` | Italic |
| U | `_text_` | Underline |
| # | `#text#` | Inverse video |

StyleCodes compose in a plain textarea — the formatting is character-delimited inline markup applied directly in the message body. No split-pane view is used.

The same **Insert Image** picker used by the Markdown editor is also available from the Plain text and StyleCodes toolbars. In those modes, selecting or uploading an image inserts the hosted image URL directly into the textarea instead of Markdown image syntax.

---

## Supported Markdown Syntax

BinktermPHP's renderer (`src/MarkdownRenderer.php`) supports a CommonMark-compatible subset:

| Syntax | Result |
|--------|--------|
| `**bold**` or `__bold__` | **bold** |
| `*italic*` or `_italic_` | *italic* |
| `~~strikethrough~~` | ~~strikethrough~~ |
| `` `code` `` | inline code |
| `# Heading` through `###### Heading` | H1–H6 headings |
| `[text](url)` | hyperlink |
| `![alt](url)` | image (rendered as click-to-load placeholder for remote URLs) |
| `- item` or `* item` | unordered list (nested indented sub-lists supported) |
| `1. item` | ordered list |
| `> text` | blockquote (up to 8 levels deep) |
| ` ``` ` | fenced code block (optional language tag for syntax highlighting class) |
| `---` | horizontal rule |
| `\| col \| col \|` with separator row | GFM table |
| bare `https://` URL | auto-linked |

Heading slugs are generated using GitHub-style rules. Explicit anchors in the form `## Heading {#my-anchor}` are supported.

---

## Supported StyleCodes Syntax

StyleCodes (`src/StyleCodesRenderer.php`) use single-character delimiters around words or phrases. Codes must open and close on the same line.

| Syntax | Result |
|--------|--------|
| `*bold*` | **bold** |
| `/italics/` | *italics* |
| `_underlined_` | underlined |
| `#inverse#` | inverse video (rendered as a highlighted span) |

URL slashes are not mistaken for italic delimiters — the renderer excludes slashes adjacent to colons (`://`) from italic matching.

---

## Rendering Incoming Messages

Incoming messages are rendered based on the `^AMARKUP` kludge line:

| Kludge | Renderer |
|--------|----------|
| `^AMARKUP: Markdown 1.0` | `MarkdownRenderer::toHtml()` |
| `^AMARKUP: StyleCodes 1.0` | `StyleCodesRenderer::toHtml()` |
| `^AMARKDOWN:` _(legacy Draft 1)_ | `MarkdownRenderer::toHtml()` (backwards compatibility) |
| _(none)_ | Plain text |

Remote images in Markdown messages are rendered as click-to-load placeholders by default — the image is not fetched automatically. The user clicks the placeholder to load it. Inline `data:image/` payloads for raster formats (PNG, JPEG, GIF, WebP, BMP) are rendered directly.

---

## The MARKUP Kludge and LSC-001

The `^AMARKUP` kludge is defined by **LSC-001 — MARKUP Kludge for FidoNet-Compatible Echomail and Netmail**, a LovlyNet Standards Council specification submitted to the FTSC for consideration as a FidoNet Technical Standard.

The full specification is in `docs/LSC/LSC1 - Markup Kludge.txt`. Key points relevant to BinktermPHP:

- The kludge format is `^AMARKUP: <format> <version>` where `^A` is ASCII SOH (0x01).
- A message body MUST NOT contain more than one `^AMARKUP` kludge.
- The declared format applies to the **entire** visible message body.
- Tossers and packers MUST NOT strip or alter the kludge during routing.
- Software that does not recognize a format MUST display the body as plain text.
- When replying to a Markdown message, the recommended quoting style is a Markdown blockquote block (`> `) rather than traditional `> ` line prefixes, to preserve the structure of the original.

LSC-001 also defines a format registry for additional identifiers (BBCode, Gemtext, StyleCodes, etc.). BinktermPHP currently implements `Markdown 1.0` and `StyleCodes 1.0`.

---

## Security

Whether remote images in incoming Markdown are displayed inline or shown as click-to-load placeholders is controlled by the user's inline media setting in their account settings.

The renderer HTML-escapes all content before applying Markdown transformations. Raw HTML in message bodies is never passed through to output; the `allowHtml` flag in `MarkdownRenderer` is only enabled for trusted internal content such as `README.md` display.

Links are restricted to `https://`, `http://`, site-relative (`/`), and anchor (`#`) URLs. Arbitrary scheme URLs (e.g. `javascript:`) are not linked.
