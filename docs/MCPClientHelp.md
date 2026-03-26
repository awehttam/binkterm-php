# Connecting MCP-compatible desktop clients

BinktermPHP exposes an MCP endpoint (`/mcp`) that desktop assistants (Claude, Anything LLM, OpenAI, etc.) can call directly. This document shows how to configure a generic client so it connects using your Bearer token and the `mcp-remote` helper. Once connected, the client sees the same tools and commands you expose to the web UI.

## Prerequisites

- An MCP-compatible desktop client installed (Claude Desktop, Anything LLM, OpenAI Desktop, etc.)
- [Node.js](https://nodejs.org) installed for `npx mcp-remote`
- A valid Bearer token obtained from your admin dashboard

## Step 1 — Locating the client configuration

Each client stores its configuration in a JSON file. Update the file that matches your OS:

| Platform | Configuration path |
|---|---|
| macOS | `~/Library/Application Support/<client>/config.json` (replace `<client>` with your product folder) |
| Windows | `%APPDATA%\<Client>\<client>_config.json` |
| Linux | `~/.config/<client>/<client>_config.json` |

Replace `<client>` with the actual folder name (`Claude`, `OpenAI`, `anything-llm`, etc.).

## Step 2 — Adding your MCP entry

Every client defines an `mcpServers` map. Add an entry with `npx mcp-remote` that proxies to your BinktermPHP MCP endpoint.

### Example template (macOS/Linux)

```json
{
  "mcpServers": {
    "my-binktermphp": {
      "command": "npx",
      "args": [
        "mcp-remote",
        "https://bbs.example.com/mcp",
        "--header",
        "Authorization: Bearer YOUR_TOKEN"
      ]
    }
  }
}
```

### Windows variant

Windows shells need `cmd /c` around `npx`:

```json
{
  "mcpServers": {
    "my-binktermphp": {
      "command": "cmd",
      "args": [
        "/c", "npx",
        "mcp-remote",
        "https://bbs.example.com/mcp",
        "--header",
        "Authorization: Bearer YOUR_TOKEN"
      ]
    }
  }
}
```

### Agent-specific tips

- **Claude Desktop** uses `claude_desktop_config.json` under `%APPDATA%\Claude`.
- **OpenAI Desktop** looks for `openai_desktop_config.json` in `%APPDATA%\OpenAI`. The JSON structure is identical to the template above.
- **Anything LLM** clients store `anything.json` in `~/.config/anything`. Drop the same `mcpServers` block into that file and keep the rest of the JSON valid.
- You can add multiple entries for different BBS nodes. The label (`my-binktermphp`) is arbitrary.

Always append `/mcp` to your MCP endpoint URL.

## Step 3 — Restart the client

Fully quit the client (use the menu’s Quit command or the system tray) and relaunch it so it reads the new configuration.

## Step 4 — Testing

- Open a new chat/agent session and confirm your MCP server appears in the tools menu.
- If it does not show up:
  - verify the Bearer token is valid and has MCP scope.
  - run `npx mcp-remote https://bbs.example.com/mcp --header "Authorization: Bearer YOUR_TOKEN"` from a terminal; the command should list your tools.
  - ensure TLS/DNS routes to your BinktermPHP hostname.
  - double-check the JSON file has no trailing commas and balanced braces.

## Optional: customizing for other agents

- Some clients support `commandTemplate` or environment wrappers. You can set `command`/`args` to any executable as long as it ultimately runs `npx mcp-remote`.
- If you operate more than one MCP server, add each URL+token pair to `mcpServers` and let the client pick between them.
- Capture `mcp-remote` output (Node logs to stdout) to diagnose TLS, token, or redirect issues.

Once configured, any client using this file will join the same realtime tool set as your web users and can reuse the MCP commands you expose.
