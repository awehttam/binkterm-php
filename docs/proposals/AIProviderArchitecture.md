> **Draft** - This proposal was generated with AI assistance and may not have been reviewed for accuracy. It is intended as a starting point for discussion, not a finalized specification.

# AI Provider Architecture and Cost Accounting

## Recommendation

Implement AI support as a small internal service layer with:

1. A provider-agnostic interface for common operations such as text generation and JSON generation.
2. Provider adapters for Anthropic and OpenAI.
3. A normalized usage ledger in PostgreSQL that stores token/request counts and estimated cost for every call.
4. Feature-level services that depend on the abstraction rather than calling vendor APIs directly.

This is the right fit for BinktermPHP because the codebase already has a successful precedent for this pattern in `src/Antivirus/`:

- `ScannerInterface`
- provider-specific implementations
- `AntivirusManager` as the orchestration layer

The current AI usage in the repo is still ad hoc:

- `scripts/create_translation_catalog.php` has provider branching inline.
- `src/InterestGenerator.php` calls Anthropic directly and has no usage accounting.

Those should become the first two consumers of the shared AI layer.

## Why This Approach

Anthropic and OpenAI APIs are similar at the product level but not identical at the transport level. The commonality is high enough to share:

- prompt assembly
- model selection
- timeout/retry policy
- JSON-output workflows
- usage accounting
- error handling

The provider-specific parts should stay isolated:

- HTTP endpoint shape
- auth headers
- response parsing
- token field names
- provider-specific features

The important design choice is to normalize at the application boundary, not to pretend the vendor APIs are identical.

## Proposed Structure

Add a new `src/AI/` namespace:

```text
src/AI/
  AiProviderInterface.php
  AiService.php
  AiRequest.php
  AiResponse.php
  AiUsage.php
  AiFeature.php
  AiPricing.php
  UsageRecorder.php
  Providers/
    AnthropicProvider.php
    OpenAIProvider.php
```

Suggested responsibilities:

- `AiProviderInterface`
  - `generateText(AiRequest $request): AiResponse`
  - `generateJson(AiRequest $request): AiResponse`

- `AiRequest`
  - provider
  - model
  - feature name
  - system prompt
  - user/content payload
  - temperature
  - max tokens
  - timeout
  - user id or actor id when relevant
  - correlation metadata

- `AiResponse`
  - text
  - parsed JSON when applicable
  - raw provider response summary
  - normalized usage object
  - request id if the provider returns one
  - finish reason

- `AiUsage`
  - input tokens
  - output tokens
  - cache/write/read tokens if available
  - total tokens
  - estimated cost
  - currency

- `AiService`
  - provider selection
  - retries
  - request execution
  - usage recording
  - feature policy checks

- `UsageRecorder`
  - writes the normalized ledger row to the database

- `AiPricing`
  - calculates estimated cost from provider/model/rates

## Common API Surface

Do not start with a very broad abstraction. Start with the two operations the repo already needs:

1. `generateText`
2. `generateJson`

That covers:

- translation catalog generation
- interest classification
- future summarization
- future reply drafting

If later features need embeddings, images, or streaming, add new interfaces then. Do not bake them into v1.

## Provider Selection

Use explicit provider configuration per feature, with environment defaults.

Example env shape:

```text
AI_DEFAULT_PROVIDER=openai
AI_DEFAULT_MODEL=gpt-4o-mini

OPENAI_API_KEY=...
OPENAI_API_BASE=https://api.openai.com/v1

ANTHROPIC_API_KEY=...
ANTHROPIC_API_BASE=https://api.anthropic.com/v1
```

Then optionally feature-specific overrides:

```text
AI_INTERESTS_PROVIDER=anthropic
AI_INTERESTS_MODEL=claude-haiku-4-5-20251001

AI_TRANSLATION_PROVIDER=openai
AI_TRANSLATION_MODEL=gpt-4o-mini
```

This lets the sysop choose the cheaper or better provider per task.

## Accounting and Cost Estimation

This should be database-backed, not log-only.

### Recommended tables

Add a migration creating:

### `ai_requests`

One row per outbound AI API call.

Suggested columns:

- `id`
- `created_at`
- `user_id` nullable
- `provider`
- `model`
- `feature`
- `operation`
- `status` (`success`, `error`)
- `request_id` nullable
- `input_tokens`
- `output_tokens`
- `cached_input_tokens` nullable
- `cache_write_tokens` nullable
- `total_tokens`
- `estimated_cost_usd` numeric
- `duration_ms`
- `http_status` nullable
- `error_code` nullable
- `error_message` nullable
- `metadata_json` jsonb

### Optional `ai_daily_usage`

This is optional. You can derive reporting from `ai_requests` first and add rollups later if reporting becomes slow.

I recommend starting with only `ai_requests`.

## Cost Calculation Model

Do not hardcode vendor pricing directly in feature code.

Use a centralized pricing map in `AiPricing`, with values loaded from config defaults and optionally overridden by env or admin config later.

Suggested model:

- provider returns usage counts
- system normalizes them into `AiUsage`
- `AiPricing` multiplies counts by configured rates
- result is stored as `estimated_cost_usd`

This gives you:

- cost per request
- cost per model
- cost per feature
- cost per user
- cost per day/month

It also keeps historical rows stable even if pricing changes later.

## Feature Tagging

Every request should include a stable internal feature name, for example:

- `translation_catalog`
- `interest_generation`
- `thread_summary`
- `reply_draft`
- `tagline_suggestions`

This matters more than it first appears. Without feature tags, cost reports become much less useful.

## Failure Handling

Recommended rules:

- If a provider call fails, record the failed request row with status and error metadata.
- Do not record prompt bodies or raw completions by default in the database.
- Keep prompt/result content out of persistent accounting tables unless a feature explicitly requires auditing.
- Log enough metadata to debug without storing sensitive user content.

That is especially important if future features summarize private netmail or user drafts.

## Migration Path in This Repo

### Phase 1: Introduce shared AI layer

Create `src/AI/` and database migration for `ai_requests`.

### Phase 2: Move existing call sites

Refactor:

- `scripts/create_translation_catalog.php`
- `src/InterestGenerator.php`

Both should call `AiService` instead of building HTTP requests inline.

### Phase 3: Add admin visibility

Add an admin page or widget showing:

- requests today
- estimated cost today
- estimated cost by feature
- estimated cost by provider/model
- recent failures

### Phase 4: Add feature flags and limits

Support env-configured safety controls such as:

- per-feature enable/disable
- daily request cap
- daily cost cap
- per-user request cap for interactive features

## Concrete Interface Example

```php
$request = new AiRequest(
    provider: 'anthropic',
    model: 'claude-haiku-4-5-20251001',
    feature: 'interest_generation',
    operation: 'generate_json',
    systemPrompt: $systemPrompt,
    userContent: $payload,
    temperature: 0.2,
    maxTokens: 4096,
    userId: null,
    metadata: ['batch_size' => count($batch)]
);

$response = $aiService->generateJson($request);
$result = $response->getParsedJson();
```

The caller should not need to know whether the provider uses `/messages` or `/chat/completions`.

## What Should Not Be Abstracted Yet

Avoid these in the first version:

- streaming
- tool calling
- multi-turn conversation state
- embeddings
- image generation
- prompt template storage in the database

Those can be added later, but they are not needed to solve the current problem.

## Recommended First Deliverable

The first implementation should ship these three things together:

1. `src/AI/` abstraction with OpenAI and Anthropic providers.
2. `ai_requests` usage ledger and cost estimation.
3. Refactors of `scripts/create_translation_catalog.php` and `src/InterestGenerator.php` to use it.

That is enough to prove the design, eliminate duplicated provider code, and start producing real cost data immediately.

## Final Recommendation

Yes, there are common functions applicable to both providers, but the common layer should be narrow and normalized:

- one internal request model
- one internal response/usage model
- small provider adapters
- central usage ledger

Do not let feature code talk directly to Anthropic or OpenAI anymore. Put all vendor-specific code behind `src/AI/`, record every request in `ai_requests`, and treat cost accounting as a first-class part of the AI integration rather than a later add-on.
