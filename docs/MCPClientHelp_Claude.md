# Connecting Claude Desktop to an MCP server with Bearer token authentication

Follow the steps below to configure Claude Desktop to connect to your MCP server using a Bearer token.

## Prerequisites

- [Claude Desktop](https://claude.ai/download) installed
- [Node.js](https://nodejs.org) installed (required for `npx mcp-remote`)
- A Bearer token from your account dashboard

---

## Step 1 — Locate the configuration file

Find the `claude_desktop_config.json` file for your operating system:

| Platform | Path |
|----------|------|
| macOS    | `~/Library/Application Support/claude/claude_desktop_config.json` |
| Windows  | `%APPDATA%\Claude\claude_desktop_config.json` |
| Linux    | `~/.config/claude/claude_desktop_config.json` |

The file is created automatically the first time Claude Desktop runs. If it does not exist yet, create it manually before continuing.

---

## Step 2 — Add the server configuration

Open the file in a text editor and add the configuration for your platform.

### macOS and Linux

```json
{
  "mcpServers": {
    "my-bbs": {
      "command": "npx",
      "args": [
        "mcp-remote",
        "https://yourbbs.domain.com/mcp",
        "--header",
        "Authorization: Bearer YOUR_TOKEN"
      ]
    }
  }
}
```

### Windows

Windows requires a `cmd /c` wrapper for `npx` to execute correctly.

```json
{
  "mcpServers": {
    "my-bbs": {
      "command": "cmd",
      "args": [
        "/c", "npx",
        "mcp-remote",
        "https://yourbbs.domain.com/mcp",
        "--header",
        "Authorization: Bearer YOUR_TOKEN"
      ]
    }
  }
}


```
 

Replace the following values:

- `https://yourbbs.domain.com/mcp` — the MCP endpoint URL for your MCP server
- `YOUR_TOKEN` — the Bearer token from your account dashboard

> **Note:** If `claude_desktop_config.json` already contains other server entries, add your new entry inside the existing `mcpServers` object rather than replacing the whole file.

> **Note:** Note that: /mcp is required

---

## Step 3 — Restart Claude Desktop

Fully quit and reopen Claude Desktop. Closing the window alone is not enough — use **Quit** from the menu or system tray.

---

## Step 4 — Verify the connection

Start a new conversation and look for the tools icon in the input bar. Click it to confirm your server's tools are listed.

If the server does not appear, check the following:

- The Bearer token is correct and has not expired
- The MCP server URL is reachable from your machine
- Node.js is installed and `npx` is available in your terminal
- The JSON in `claude_desktop_config.json` is valid (no trailing commas, balanced braces)
