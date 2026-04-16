# AI Assistant

BinktermPHP includes an optional AI assistant for the web message readers. It appears in the echomail reader UI and can answer questions about the message currently being viewed, summarize threads, explain jargon, and suggest replies.

The assistant is designed as a reader-side helper, not an unattended poster. It works by giving the model controlled read-only tool access to your message data through the built-in MCP server layer, then returning the result inside the web UI.

---

## What It Does

The current user-facing implementation is available in the web echomail reader:

- **Echomail**: toolbar button in the area view and in the message reader modal

The backend request flow also accepts `message_type` values of `echomail` and `netmail`, so the assistant implementation is reader-oriented rather than hard-wired to a single message type.

When the user opens the assistant while viewing a message, that message ID is passed as context. The assistant then receives a prompt such as:

- summarize this message
- explain technical terms in this message
- suggest a reply
- summarize the full thread this message belongs to

If no message is selected, the assistant still opens, but only general prompts make sense.

---

## How It Works

The reader assistant is implemented in:

- `public_html/js/ai-assistant.js`
- `routes/api-routes.php` at `POST /api/messages/ai-assist`
- `src/AI/MessageAiAssistant.php`

The request flow is:

1. A logged-in user opens the AI assistant modal from the message reader.
2. The browser sends the prompt, optional `message_id`, and `message_type` to `POST /api/messages/ai-assist`.
3. The API checks that the feature is enabled and that the system is configured.
4. `src/AI/MessageAiAssistant.php` ensures the user has an MCP bearer key. If they do not, one is generated automatically and stored in user meta.
5. The assistant connects to the configured MCP server URL and runs an agentic tool-use loop.
6. The model uses MCP tools to fetch message content, thread data, or related echomail information as needed.
7. The final answer is returned to the browser and rendered in the assistant modal.
8. If AI credit charging is configured, the request cost is converted into BBS credits and debited from the user's balance.

The system prompt explicitly tells the model to fetch actual message data before answering and not invent message content.

---

## Requirements

The AI assistant depends on four pieces being in place:

1. The `.env` feature flag must enable the reader assistant.
2. The BBS configuration must enable the assistant in `bbs.json`.
3. Anthropic must be configured.
4. The MCP server must be reachable.

### Current provider requirement

Although BinktermPHP has a broader AI provider abstraction layer, the current reader assistant implementation in `src/AI/MessageAiAssistant.php` directly builds an `AnthropicProvider`. That means the message-reader assistant currently requires:

- `ANTHROPIC_API_KEY`
- optionally `ANTHROPIC_API_BASE`

If `ANTHROPIC_API_KEY` is missing, the API returns a configuration error and the assistant cannot run.

### MCP server requirement

The assistant uses MCP tools to retrieve message and echomail context. Configure:

```ini
MCP_SERVER_URL=http://localhost:3740
```

If you already use the MCP server for external AI clients, this is the same server component. See `docs/MCPServer.md` for MCP server setup.

---

## Enabling The Assistant

### 1. Configure Anthropic

Set at minimum:

```ini
ANTHROPIC_API_KEY=your-key-here
```

Optional:

```ini
ANTHROPIC_API_BASE=https://api.anthropic.com/v1
```

### 2. Make sure the MCP server is running

Set the URL if needed:

```ini
MCP_SERVER_URL=http://localhost:3740
```

Then start or restart the MCP server process you use for BinktermPHP.

### 3. Enable the BBS-side feature toggle

The assistant must also be enabled in BBS configuration:

```json
{
  "ai_assistant": {
    "enabled": true
  }
}
```

You can manage this from:

- **Admin → BBS Settings → Features**

The default BBS setting is disabled until you turn it on.

If the BBS config flag is off, the assistant is disabled.

---

## Credit Charging

The AI assistant can optionally charge BBS credits based on actual estimated AI usage.

The conversion setting lives in BBS credits configuration:

```json
{
  "credits": {
    "ai_credits_per_milli_usd": 0
  }
}
```

This value means:

- how many BBS credits to charge per `$0.001` USD of estimated AI cost

Examples:

- `0`: do not charge users for AI usage
- `1`: charge 1 credit per $0.001 estimated cost
- `5`: charge 5 credits per $0.001 estimated cost

The estimate is based on token usage and the AI pricing values configured in `.env`. If the computed charge is greater than zero, the assistant debits the user's balance before completing successfully. If the user cannot afford the request, the API returns an insufficient credits error.

For the broader credits system, see `docs/CreditSystem.md`. For AI pricing inputs, see `docs/AIProviders.md`.

---

## UI Behavior

On the echomail page:

- the area toolbar can show an **AI Assistant** button
- the message reader modal can show an AI button in the header

Opening the assistant from the message reader modal pre-selects the current message as context. The modal then offers context-aware quick prompts such as summarizing the message or summarizing the thread.

When the assistant is disabled in BBS settings, the message reader UI hides those controls entirely.

---

## API Behavior

Endpoint:

```text
POST /api/messages/ai-assist
```

Expected JSON payload:

```json
{
  "prompt": "Summarize this thread",
  "message_id": 1234,
  "message_type": "echomail"
}
```

Rules enforced by the API:

- authenticated user required
- prompt required
- prompt length limited to 500 characters
- message type must be `echomail` or `netmail`
- feature must be enabled in both `.env` and BBS config
- Anthropic must be configured

Typical failure cases:

- feature disabled: HTTP `403`
- missing provider configuration: HTTP `503`
- insufficient credits: HTTP `402`
- request or provider failure: HTTP `500`

---

## Operational Notes

- The assistant is a read helper. It does not automatically post to echomail.
- User MCP keys are generated lazily the first time the assistant is used.
- Responses are intentionally brief and scoped to FTN/BBS use.
- The assistant relies on the MCP server's read permissions and user context rather than direct unrestricted database access.

---

## Related Documentation

- `docs/AIProviders.md` — provider keys, pricing, and usage accounting
- `docs/MCPServer.md` — MCP server setup and authentication model
- `docs/CreditSystem.md` — BBS credit economy and charging model
- `docs/UPGRADING_1.9.2.md` — release notes for the reader assistant introduction
