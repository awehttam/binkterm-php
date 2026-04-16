# AI Providers and Usage

BinktermPHP has a provider-agnostic AI layer in `src/AI/` that lets features call either OpenAI or Anthropic through a shared interface. The system also records every AI request in a local accounting ledger so you can estimate usage and cost from the admin UI.

---

## What It Covers

The current AI system is used by:

- `src/InterestGenerator.php` for AI-assisted interest suggestion generation
- `scripts/create_translation_catalog.php` for AI-assisted i18n catalog translation
- `src/AI/MessageAiAssistant.php` for the message-reader assistant

The shared layer supports:

- provider selection per feature
- a common request and response model
- usage and error recording in `ai_requests`
- estimated cost calculation from `.env` pricing values
- a reporting dashboard at `/admin/ai-usage`

---

## Architecture

The AI abstraction lives in `src/AI/`.

### Core Classes

| File | Purpose |
|------|---------|
| `src/AI/AiService.php` | Main orchestration layer used by application features |
| `src/AI/AiRequest.php` | Provider-neutral request object |
| `src/AI/AiResponse.php` | Provider-neutral response object |
| `src/AI/AiUsage.php` | Normalized token and cost data |
| `src/AI/AiException.php` | Standardized API/network error wrapper |
| `src/AI/AiPricing.php` | Maps provider/model pricing env vars to estimated request cost |
| `src/AI/UsageRecorder.php` | Writes request outcomes to the `ai_requests` ledger |
| `src/AI/AiUsageReport.php` | Builds dashboard summaries from the ledger |
| `src/AI/Providers/OpenAIProvider.php` | OpenAI adapter |
| `src/AI/Providers/AnthropicProvider.php` | Anthropic adapter |
| `src/AI/HttpClient.php` | Shared JSON HTTP client used by providers |

### Flow

1. A feature creates an `AiRequest`.
2. `AiService` resolves the provider and model.
3. The chosen provider adapter translates the normalized request into the vendor API call.
4. The provider returns a normalized `AiResponse`.
5. `UsageRecorder` writes a success or failure row to `ai_requests`.
6. `AiUsageReport` aggregates those rows for the admin dashboard.

---

## Provider Configuration

Set one or both provider API keys in `.env`:

```ini
OPENAI_API_KEY=
OPENAI_API_BASE=https://api.openai.com/v1

ANTHROPIC_API_KEY=
ANTHROPIC_API_BASE=https://api.anthropic.com/v1
```

Only configured providers are registered. A provider is considered configured when its API key is non-empty.

### Default Selection

You can set a global default provider and model:

```ini
AI_DEFAULT_PROVIDER=openai
AI_DEFAULT_MODEL=gpt-4o-mini
```

If no explicit provider is set on a request and no feature-specific override exists, `AiService` uses:

1. `AI_<FEATURE>_PROVIDER`
2. `AI_DEFAULT_PROVIDER`
3. `openai` if configured
4. otherwise the first configured provider

Model resolution uses:

1. request-level model
2. `AI_<FEATURE>_MODEL`
3. `AI_DEFAULT_MODEL`
4. the provider adapter's built-in default

### Feature-Specific Overrides

The feature name from `AiRequest` is normalized to uppercase with non-alphanumeric characters changed to underscores.

Current feature-specific env vars:

```ini
AI_TRANSLATION_CATALOG_PROVIDER=openai
AI_TRANSLATION_CATALOG_MODEL=gpt-4o-mini

AI_INTEREST_GENERATION_PROVIDER=anthropic
AI_INTEREST_GENERATION_MODEL=claude-haiku-4-5-20251001
```

These match the current consumers:

- `message_ai_assistant`
- `translation_catalog`
- `interest_generation`

---

## Pricing and Cost Estimation

Estimated cost is not fetched from the provider. It is calculated locally from token usage and the pricing values you set in `.env`.

Prices are expressed in USD per 1,000,000 tokens.

### Provider-Wide Pricing

```ini
AI_PRICE_OPENAI_INPUT_PER_MILLION_USD=0
AI_PRICE_OPENAI_OUTPUT_PER_MILLION_USD=0

AI_PRICE_ANTHROPIC_INPUT_PER_MILLION_USD=0
AI_PRICE_ANTHROPIC_OUTPUT_PER_MILLION_USD=0
```

### Model-Specific Pricing

Model-specific overrides take precedence over provider-wide defaults:

```ini
AI_PRICE_ANTHROPIC_CLAUDE_SONNET_4_6_INPUT_PER_MILLION_USD=3
AI_PRICE_ANTHROPIC_CLAUDE_SONNET_4_6_OUTPUT_PER_MILLION_USD=15

AI_PRICE_OPENAI_GPT_4O_MINI_INPUT_PER_MILLION_USD=0.15
AI_PRICE_OPENAI_GPT_4O_MINI_OUTPUT_PER_MILLION_USD=0.60
```

Supported rate buckets:

- `INPUT`
- `OUTPUT`
- `CACHED_INPUT`
- `CACHE_WRITE`

Resolution order:

1. model-specific rate
2. provider-wide rate
3. `0`

If you do not set pricing values, requests are still recorded, but estimated cost will remain zero.

---

## The Accounting Ledger

Migration `database/migrations/v1.11.0.51_ai_request_accounting.sql` creates the `ai_requests` table.

Each row records:

- request time
- user id, when available
- provider and model
- feature and operation
- success or error status
- provider request id, when available
- token counts
- estimated cost in USD
- request duration
- HTTP status, error code, and error message for failures
- request metadata as JSON

This ledger is the source of truth for the admin usage dashboard.

### Operations

The current system records these operation types:

- `generate_text`
- `generate_json`

### Success and Failure Recording

Both successful and failed AI requests are written to the ledger. This makes the dashboard useful for:

- cost estimation
- spotting provider outages
- seeing which feature is making requests
- identifying misconfiguration such as missing keys or dead proxies

---

## Current Feature Integrations

### Interest Suggestions

`src/InterestGenerator.php` uses `AiService` for the AI-assisted pass of interest classification.

Relevant feature id:

```text
interest_generation
```

Keyword-based matching still exists. AI is only used when the feature asks for it, unmatched areas remain, and a provider is configured.

### Translation Catalog Generation

`scripts/create_translation_catalog.php` uses `AiService` for batch JSON translation.

Relevant feature id:

```text
translation_catalog
```

The script still supports explicit `--provider` and `--model` arguments, but requests now flow through the shared provider layer and accounting ledger.

### Message Reader Assistant

`src/AI/MessageAiAssistant.php` resolves its provider through `AiService` and then runs an MCP-backed tool loop.

Relevant feature id:

```text
message_ai_assistant
```

This feature requires a provider that supports tools, plus a reachable MCP server.

---

## Admin Dashboard

The AI usage dashboard is available at:

```text
/admin/ai-usage
```

It is linked from the admin dashboard and the main admin navigation.

### What It Shows

For the selected time window, the page shows:

- total request count
- total estimated cost
- total failure count
- total token count
- cost and request counts by feature
- cost and request counts by provider/model
- recent failures with error details
- recent requests with status, token count, and estimated cost

### Supported Periods

The dashboard currently supports:

- `1d`
- `7d`
- `30d`
- `all`

Dates are formatted in the current user's timezone.

### When the Table Is Missing

If `ai_requests` does not exist yet, the dashboard shows an unavailable warning instead of crashing. Run the normal upgrade path to create the table:

```bash
php scripts/setup.php
```

---

## Troubleshooting

### No AI Provider Appears to Be Available

Check that at least one API key is set:

- `OPENAI_API_KEY`
- `ANTHROPIC_API_KEY`

### Requests Fail With Network Errors

If failures mention a local address such as `127.0.0.1:9`, check process-level proxy variables:

- `HTTP_PROXY`
- `HTTPS_PROXY`
- `ALL_PROXY`

A broken local proxy can prevent requests from ever reaching OpenAI or Anthropic.

### The Dashboard Shows No Cost

The most common reasons are:

- pricing keys were not set in `.env`
- the provider returned no usage tokens
- the model name does not match the pricing key you configured

### Interest Generation Returns No Results

Check:

1. whether `ai_requests` exists
2. whether recent failures appear on `/admin/ai-usage`
3. whether proxy settings or provider credentials are blocking outbound requests
4. whether keyword mode and AI mode were both disabled in the generator UI

---

## Operational Notes

- The AI abstraction is intended to keep feature code out of vendor-specific HTTP details.
- Cost numbers are estimates, not billing statements.
- If you add new AI-powered features, use `AiService` rather than calling a provider directly.
- New AI features should choose a stable feature id so usage can be filtered and costed per feature.
