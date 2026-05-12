#!/usr/bin/env php
<?php

declare(strict_types=1);

chdir(__DIR__ . '/../');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\AI\AiRequest;
use BinktermPHP\AI\AiService;

const ROUTE_FILES = [
    'api'     => ['file' => 'routes/api-routes.php',     'label' => 'Public API'],
    'admin'   => ['file' => 'routes/admin-routes.php',   'label' => 'Admin API'],
    'door'    => ['file' => 'routes/door-routes.php',    'label' => 'Door / Terminal API'],
    'webdoor' => ['file' => 'routes/webdoor-routes.php', 'label' => 'WebDoor API'],
];

const DEFAULT_AI_BATCH_SIZE   = 8;
const DEFAULT_MODEL_ANTHROPIC = 'claude-haiku-4-5-20251001';
const DEFAULT_MODEL_OPENAI    = 'gpt-4o-mini';
const CLOSURE_SNIPPET_LINES   = 50;
const AI_MAX_RETRIES          = 4;
const AI_RETRY_BASE_DELAY_SEC = 5;

main($argv);

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

function main(array $argv): void
{
    [$options] = parseArgs($argv);

    if (isset($options['help'])) {
        printUsage();
        exit(0);
    }

    $format     = $options['format']   ?? 'markdown';
    $outputFile = $options['output']   ?? null;
    $useAi      = isset($options['ai']);
    $provider   = $options['provider'] ?? null;
    $model      = $options['model']    ?? null;
    $batchSize  = (int)($options['ai-batch-size'] ?? DEFAULT_AI_BATCH_SIZE);
    $routeSets  = isset($options['routes'])
        ? array_filter(array_map('trim', explode(',', $options['routes'])))
        : ['api'];

    if (!in_array($format, ['markdown', 'openapi'], true)) {
        fwrite(STDERR, "Error: --format must be 'markdown' or 'openapi'\n");
        exit(1);
    }

    if (in_array('all', $routeSets, true)) {
        $routeSets = array_keys(ROUTE_FILES);
    }

    foreach ($routeSets as $set) {
        if (!isset(ROUTE_FILES[$set])) {
            fwrite(STDERR, "Error: Unknown route set '$set'. Valid sets: " . implode(', ', array_keys(ROUTE_FILES)) . ", all\n");
            exit(1);
        }
    }

    $sections = [];
    foreach ($routeSets as $set) {
        $info = ROUTE_FILES[$set];
        stderr("Parsing {$info['file']}...");
        $routes = parseRouteFile($info['file']);
        stderr("  Found " . count($routes) . " endpoints");
        $sections[$set] = ['label' => $info['label'], 'routes' => $routes];
    }

    if ($useAi) {
        $aiService = AiService::create();
        $configured = $aiService->getConfiguredProviders();
        if (empty($configured)) {
            fwrite(STDERR, "Error: No AI providers are configured. Set ANTHROPIC_API_KEY or OPENAI_API_KEY in .env\n");
            exit(1);
        }
        stderr("Using AI provider(s): " . implode(', ', $configured));

        foreach ($sections as $set => &$section) {
            stderr("Enriching {$section['label']} routes with AI...");
            $section['routes'] = enrichRoutes($section['routes'], $aiService, $provider, $model, $batchSize);
        }
        unset($section);
    }

    $content = $format === 'openapi'
        ? generateOpenApi($sections)
        : generateMarkdown($sections);

    if ($outputFile !== null) {
        file_put_contents($outputFile, $content);
        stderr("Documentation written to $outputFile");
    } else {
        echo $content;
    }
}

// ---------------------------------------------------------------------------
// Route file parser
// ---------------------------------------------------------------------------

/**
 * Parse a SimpleRouter route file and return extracted endpoint metadata.
 *
 * @return array<int, array{method: string, path: string, comment: string, auth: bool, middleware: string[], snippet: string, line: int}>
 */
function parseRouteFile(string $filePath): array
{
    if (!file_exists($filePath)) {
        stderr("Warning: $filePath not found");
        return [];
    }

    $source = file_get_contents($filePath);
    $tokens = token_get_all($source);
    $n      = count($tokens);

    $routes      = [];
    $groupStack  = [];  // [['prefix' => string, 'middleware' => string[]]]
    $groupDepths = [];  // brace depth at which each group body opens
    $braceDepth  = 0;
    $interpDepth = 0;   // depth inside {$var} / ${var} string interpolation

    $httpMethods = ['get', 'post', 'put', 'delete', 'patch', 'options'];

    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];

        // === Brace / interpolation depth tracking ===
        if (is_array($tok)) {
            // T_CURLY_OPEN  = the { in "{$var}"
            // T_DOLLAR_OPEN_CURLY_BRACES = the ${ in "${var}"
            // These open a string-interpolation context; their matching } is a plain char.
            if ($tok[0] === T_CURLY_OPEN || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $interpDepth++;
            }
            // Array tokens cannot be SimpleRouter-style plain chars; fall through.
        } elseif ($tok === '{') {
            // Real code block opener (never inside a string with token_get_all)
            $braceDepth++;
            continue;
        } elseif ($tok === '}') {
            if ($interpDepth > 0) {
                // Closing a {$var} or ${var} interpolation, not a real block
                $interpDepth--;
            } else {
                $braceDepth--;
                while (!empty($groupDepths) && end($groupDepths) > $braceDepth) {
                    array_pop($groupDepths);
                    array_pop($groupStack);
                }
            }
            continue;
        }

        // === SimpleRouter:: detection ===
        if (!is_array($tok) || $tok[0] !== T_STRING || $tok[1] !== 'SimpleRouter') {
            continue;
        }

        $j = skipWhitespace($tokens, $i + 1, $n);
        if ($j >= $n || !is_array($tokens[$j]) || $tokens[$j][0] !== T_DOUBLE_COLON) {
            continue;
        }

        $k = skipWhitespace($tokens, $j + 1, $n);
        if ($k >= $n || !is_array($tokens[$k]) || $tokens[$k][0] !== T_STRING) {
            continue;
        }

        $methodName = strtolower($tokens[$k][1]);
        $lineNo     = $tokens[$i][2] ?? 0;

        if ($methodName === 'group') {
            $groupInfo    = parseGroupArgTokens($tokens, $k + 1, $n);
            $groupStack[] = $groupInfo;
            // The group closure { hasn't been seen yet; it will push $braceDepth to $braceDepth+1.
            // Record that depth so we pop when } brings us back below it.
            $groupDepths[] = $braceDepth + 1;
            $i = $k;
            continue;
        }

        if (!in_array($methodName, $httpMethods, true)) {
            $i = $k;
            continue;
        }

        // === Route found ===
        $routePath = parseFirstStringArg($tokens, $k + 1, $n);

        $prefix     = '';
        $middleware = [];
        foreach ($groupStack as $g) {
            $prefix    .= $g['prefix'];
            $middleware = array_merge($middleware, $g['middleware']);
        }

        $fullPath     = '/' . ltrim(rtrim($prefix, '/') . '/' . ltrim($routePath, '/'), '/');
        $comment      = extractPrecedingComment($tokens, $i);
        $authRequired = detectAuth($middleware, $tokens, $k + 1, $n);
        $snippet      = extractClosureBody($tokens, $k + 1, $n, CLOSURE_SNIPPET_LINES);

        $routes[] = [
            'method'     => strtoupper($methodName),
            'path'       => $fullPath,
            'comment'    => $comment,
            'auth'       => $authRequired,
            'middleware' => $middleware,
            'snippet'    => $snippet,
            'line'       => $lineNo,
        ];

        $i = $k;
    }

    return $routes;
}

// ---------------------------------------------------------------------------
// Token helper functions
// ---------------------------------------------------------------------------

/**
 * Advance $i past whitespace tokens.
 */
function skipWhitespace(array $tokens, int $i, int $n): int
{
    while ($i < $n && is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
        $i++;
    }
    return $i;
}

/**
 * Scan backwards from a route's token index to find any immediately preceding comment.
 * Returns empty string if a blank line intervenes or there is no comment.
 */
function extractPrecedingComment(array $tokens, int $routeIndex): string
{
    for ($i = $routeIndex - 1; $i >= 0; $i--) {
        $tok = $tokens[$i];

        if (is_array($tok) && $tok[0] === T_WHITESPACE) {
            // More than one newline = blank line → no comment for this route
            if (substr_count($tok[1], "\n") > 1) {
                return '';
            }
            continue;
        }

        if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            return normalizeComment($tok[1]);
        }

        // Hit any other token (code) → stop
        break;
    }

    return '';
}

/**
 * Parse the array argument passed to SimpleRouter::group() and extract prefix/middleware.
 *
 * @return array{prefix: string, middleware: string[]}
 */
function parseGroupArgTokens(array $tokens, int $start, int $n): array
{
    $i = skipWhitespace($tokens, $start, $n);
    if ($i >= $n || $tokens[$i] !== '(') {
        return ['prefix' => '', 'middleware' => []];
    }
    $i++;

    // Collect raw text of the array argument (up to the matching closing paren at depth 1)
    $depth = 1;
    $raw   = '';
    while ($i < $n && $depth > 0) {
        $tok = $tokens[$i];
        $val = is_array($tok) ? $tok[1] : $tok;
        if ($val === '(' || $val === '[') {
            $depth++;
        } elseif ($val === ')' || $val === ']') {
            $depth--;
            if ($depth === 0) {
                break;
            }
        }
        $raw .= $val;
        $i++;
    }

    $prefix     = '';
    $middleware = [];

    // Extract prefix value
    if (preg_match("/'prefix'\s*=>\s*'([^']*)'/", $raw, $m)
        || preg_match('/"prefix"\s*=>\s*"([^"]*)"/', $raw, $m)) {
        $prefix = $m[1];
    }

    // Extract middleware array entries
    if (preg_match("/'middleware'\s*=>\s*\[([^\]]*)\]/s", $raw, $m)
        || preg_match('/"middleware"\s*=>\s*\[([^\]]*)\]/s', $raw, $m)) {
        preg_match_all("/['\"]([^'\"]+)['\"]/", $m[1], $mm);
        $middleware = $mm[1] ?? [];
    }

    return ['prefix' => $prefix, 'middleware' => $middleware];
}

/**
 * Parse the first string literal argument after an opening paren.
 */
function parseFirstStringArg(array $tokens, int $start, int $n): string
{
    $i = skipWhitespace($tokens, $start, $n);
    if ($i >= $n || $tokens[$i] !== '(') {
        return '';
    }
    $i = skipWhitespace($tokens, $i + 1, $n);

    if ($i >= $n || !is_array($tokens[$i])) {
        return '';
    }

    $tok = $tokens[$i];
    if ($tok[0] === T_CONSTANT_ENCAPSED_STRING) {
        return trim($tok[1], "'\"");
    }

    return '';
}

/**
 * Extract the first N lines of a route closure body for AI context.
 */
function extractClosureBody(array $tokens, int $start, int $n, int $maxLines): string
{
    // Find the opening brace of the closure (last argument)
    $i = skipWhitespace($tokens, $start, $n);
    if ($i >= $n || $tokens[$i] !== '(') {
        return '';
    }

    // Scan to find the function keyword inside the argument list
    $depth = 1;
    $i++;
    $functionStart = -1;

    while ($i < $n && $depth > 0) {
        $tok = $tokens[$i];
        if ($tok === '(' || $tok === '[' || $tok === '{') {
            $depth++;
        } elseif ($tok === ')' || $tok === ']' || $tok === '}') {
            $depth--;
            if ($depth === 0) break;
        }
        if (is_array($tok) && $tok[0] === T_FUNCTION && $depth === 1) {
            $functionStart = $i;
        }
        $i++;
    }

    if ($functionStart === -1) {
        return '';
    }

    // Now collect from functionStart to the matching }
    $out   = '';
    $lines = 0;
    $bd    = 0;
    $started = false;

    for ($j = $functionStart; $j < $n; $j++) {
        $tok = $tokens[$j];
        $val = is_array($tok) ? $tok[1] : $tok;

        if ($val === '{') {
            $bd++;
            $started = true;
        } elseif ($val === '}') {
            $bd--;
            if ($bd === 0) {
                break;
            }
        }

        if ($started) {
            $out .= $val;
            $lines += substr_count($val, "\n");
            if ($lines >= $maxLines) {
                $out .= "\n    // ... (truncated)";
                break;
            }
        }
    }

    return trim($out);
}

/**
 * Detect whether a route requires authentication from middleware names or inline auth checks.
 *
 * @param string[] $middleware
 */
function detectAuth(array $middleware, array $tokens, int $start, int $n): bool
{
    foreach ($middleware as $m) {
        if (stripos($m, 'auth') !== false || stripos($m, 'login') !== false) {
            return true;
        }
    }

    // Also look for inline auth checks in the snippet
    $snippet = extractClosureBody($tokens, $start, $n, 30);
    if (preg_match('/getCurrentUser|requireAuth|requireAdmin|requireBinkpAdmin|isLoggedIn/i', $snippet)) {
        return true;
    }

    return false;
}

/**
 * Normalize a raw PHP comment into plain text.
 */
function normalizeComment(string $raw): string
{
    // Remove docblock wrapping
    $text = preg_replace('#^/\*+\s?#', '', $raw)   ?? $raw;
    $text = preg_replace('#\s*\*+/$#', '', $text)   ?? $text;
    $text = preg_replace('#^\s*\*\s?#m', '', $text) ?? $text;
    // Remove single-line comment markers
    $text = preg_replace('#^\s*//\s?#m', '', $text) ?? $text;
    return trim($text);
}

// ---------------------------------------------------------------------------
// AI enrichment
// ---------------------------------------------------------------------------

/**
 * @param array<int, array{method: string, path: string, comment: string, auth: bool, middleware: string[], snippet: string, line: int}> $routes
 * @return array<int, array{method: string, path: string, comment: string, auth: bool, middleware: string[], snippet: string, line: int, ai: array<string, mixed>}>
 */
function enrichRoutes(array $routes, AiService $aiService, ?string $provider, ?string $model, int $batchSize): array
{
    $batches = array_chunk($routes, $batchSize, true);
    $results = [];

    $resolvedModel = $model;
    if ($resolvedModel === null) {
        $providers     = $aiService->getConfiguredProviders();
        $resolvedModel = in_array('anthropic', $providers, true)
            ? DEFAULT_MODEL_ANTHROPIC
            : DEFAULT_MODEL_OPENAI;
    }

    $systemPrompt = <<<'SYSTEM'
You are an API documentation expert. Given PHP route handler code snippets for a FidoNet BBS web interface (binkterm-php), generate concise developer-facing documentation for each endpoint.

Respond with a raw JSON array only — no markdown fences, no commentary, no preamble. Each element corresponds to an input route (same order, same index) and must have:
- "index": the original route index (integer, unchanged from input)
- "summary": one-sentence description (max 120 chars)
- "description": 2-4 sentence developer description covering what it does, key behaviors, and important notes
- "auth": true/false — whether authentication is required (use the provided hint but confirm from the code)
- "path_params": array of {name, type, description} for URL path parameters such as {id}
- "query_params": array of {name, type, required, description} for query string parameters
- "request_body": null or {description, fields: [{name, type, required, description}]}
- "response": {description, fields: [{name, type, description}]}
- "errors": array of {status, description} for notable error responses

Be concise. Skip boilerplate. Focus on what a developer integrating this API needs to know.
SYSTEM;

    foreach ($batches as $batchIndex => $batch) {
        $batchNum = $batchIndex + 1;
        $total    = count($batches);
        stderr("  AI batch $batchNum/$total (" . count($batch) . " routes)");

        $routeSummaries = [];
        foreach ($batch as $idx => $route) {
            $routeSummaries[] = [
                'index'   => $idx,
                'method'  => $route['method'],
                'path'    => $route['path'],
                'comment' => $route['comment'],
                'auth'    => $route['auth'],
                'snippet' => $route['snippet'],
            ];
        }

        $userPrompt = "Document these API endpoints:\n\n"
            . json_encode($routeSummaries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $request = new AiRequest(
            feature: 'api_docs',
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            provider: $provider,
            model: $resolvedModel,
            temperature: 0.1,
            maxOutputTokens: 8192,
            timeoutSeconds: 120,
        );

        $decoded = aiCallWithRetry($aiService, $request, $batchNum);

        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                $idx = (int)($item['index'] ?? -1);
                if (isset($batch[$idx])) {
                    $batch[$idx]['ai'] = $item;
                }
            }
        } else {
            stderr("  Warning: AI returned unusable response for batch $batchNum — skipping");
        }

        $results = array_merge($results, array_values($batch));
    }

    return $results;
}

/**
 * Call the AI service with exponential-backoff retry for transient errors
 * (rate limits, overload). Returns decoded JSON array on success, null on failure.
 *
 * @return array<int, mixed>|null
 */
function aiCallWithRetry(AiService $aiService, AiRequest $request, int $batchNum): ?array
{
    $attempt = 0;
    while ($attempt <= AI_MAX_RETRIES) {
        try {
            // Use generateText so JSON array extraction is done here, not inside the
            // provider's decodeJsonContent() which only handles objects ({...}).
            $response = $aiService->generateText($request);
            $text     = trim($response->getContent());

            // Strip optional markdown code fence the model may add despite instructions
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```\s*$/', '', $text)        ?? $text;
            $text = trim($text);

            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            // Fallback: extract first [...] block from the response
            if (preg_match('/\[[\s\S]+\]/m', $text, $m)) {
                $decoded = json_decode($m[0], true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            stderr("  Warning: batch $batchNum AI response was not valid JSON (attempt " . ($attempt + 1) . ")");
            return null;

        } catch (\Throwable $e) {
            $isOverload = isTransientAiError($e);

            if (!$isOverload || $attempt >= AI_MAX_RETRIES) {
                stderr("  Warning: AI enrichment failed for batch $batchNum: " . $e->getMessage());
                return null;
            }

            $delay = AI_RETRY_BASE_DELAY_SEC * (2 ** $attempt);
            stderr("  Overloaded — retrying batch $batchNum in {$delay}s (attempt " . ($attempt + 1) . "/" . AI_MAX_RETRIES . ")");
            sleep($delay);
        }

        $attempt++;
    }

    return null;
}

/**
 * Return true for transient API errors worth retrying (rate limits, overload).
 */
function isTransientAiError(\Throwable $e): bool
{
    if ($e instanceof \BinktermPHP\AI\AiException) {
        $status = $e->getHttpStatus();
        if ($status === 429 || $status === 529) {
            return true;
        }
    }
    $msg = strtolower($e->getMessage());
    return str_contains($msg, 'overload') || str_contains($msg, 'rate limit') || str_contains($msg, 'too many requests');
}

// ---------------------------------------------------------------------------
// Markdown output
// ---------------------------------------------------------------------------

/**
 * @param array<string, array{label: string, routes: array<int, mixed>}> $sections
 */
function generateMarkdown(array $sections): string
{
    $out  = [];
    $out[] = "# BinktermPHP API Documentation";
    $out[] = "";
    $out[] = "> Generated by `scripts/generate_api_docs.php`. Do not edit manually.";
    $out[] = "";
    $out[] = "## Authentication";
    $out[] = "";
    $out[] = "Most endpoints require session authentication. Log in via `POST /api/auth/login` to receive a session cookie (`binktermphp_session`). Include this cookie in subsequent requests. Some endpoints also require a CSRF token returned at login; include it as `X-CSRF-Token` on state-changing requests.";
    $out[] = "";

    // Table of contents
    $out[] = "## Contents";
    $out[] = "";
    foreach ($sections as $set => $section) {
        $anchor = anchorSlug($section['label']);
        $out[]  = "- [{$section['label']}](#{$anchor})";

        $grouped = groupRoutesByTag($section['routes']);
        foreach ($grouped as $tag => $tagRoutes) {
            $tagAnchor = anchorSlug($section['label'] . '-' . $tag);
            $out[]     = "  - [{$tag}](#{$tagAnchor}) (" . count($tagRoutes) . ")";
        }
    }
    $out[] = "";

    // Sections
    foreach ($sections as $set => $section) {
        $out[] = "---";
        $out[] = "";
        $out[] = "## {$section['label']}";
        $out[] = "";

        $grouped = groupRoutesByTag($section['routes']);

        foreach ($grouped as $tag => $tagRoutes) {
            $tagAnchor = anchorSlug($section['label'] . '-' . $tag);
            $out[]     = "### {$tag} {#$tagAnchor}";
            $out[]     = "";

            // Quick-reference table
            $out[] = "| Method | Path | Auth | Summary |";
            $out[] = "|--------|------|------|---------|";
            foreach ($tagRoutes as $route) {
                $method  = $route['method'];
                $path    = $route['path'];
                $auth    = $route['auth'] ? 'Yes' : 'No';
                $summary = isset($route['ai']['summary'])
                    ? $route['ai']['summary']
                    : (strlen($route['comment']) > 0 ? firstLine($route['comment']) : '_—_');
                $pathAnchor = endpointAnchor($method, $path);
                $out[]  = "| `{$method}` | [`{$path}`](#{$pathAnchor}) | {$auth} | {$summary} |";
            }
            $out[] = "";

            // Full entries
            foreach ($tagRoutes as $route) {
                $out = array_merge($out, renderMarkdownRoute($route));
            }
        }
    }

    return implode("\n", $out) . "\n";
}

/**
 * @param array<string, mixed> $route
 * @return string[]
 */
function renderMarkdownRoute(array $route): array
{
    $out    = [];
    $method = $route['method'];
    $path   = $route['path'];
    $auth   = $route['auth'] ? '**Requires authentication**' : 'Public';
    $ai     = $route['ai'] ?? null;

    $out[]  = "#### `{$method} {$path}`";
    $out[]  = "";
    $out[]  = $auth;
    $out[]  = "";

    $description = '';
    if ($ai !== null && !empty($ai['description'])) {
        $description = $ai['description'];
    } elseif (!empty($route['comment'])) {
        $description = $route['comment'];
    }

    if ($description !== '') {
        $out[] = $description;
        $out[] = "";
    }

    // Path params
    $pathParams = $ai['path_params'] ?? extractPathParams($path);
    if (!empty($pathParams)) {
        $out[] = "**Path Parameters**";
        $out[] = "";
        $out[] = "| Name | Type | Description |";
        $out[] = "|------|------|-------------|";
        foreach ($pathParams as $p) {
            $name = is_array($p) ? ($p['name'] ?? '') : $p;
            $type = is_array($p) ? ($p['type'] ?? 'string') : 'string';
            $desc = is_array($p) ? ($p['description'] ?? '') : '';
            $out[] = "| `{$name}` | {$type} | {$desc} |";
        }
        $out[] = "";
    }

    // Query params
    $queryParams = $ai['query_params'] ?? [];
    if (!empty($queryParams)) {
        $out[] = "**Query Parameters**";
        $out[] = "";
        $out[] = "| Name | Type | Required | Description |";
        $out[] = "|------|------|----------|-------------|";
        foreach ($queryParams as $p) {
            $name     = $p['name']        ?? '';
            $type     = $p['type']        ?? 'string';
            $required = ($p['required']   ?? false) ? 'Yes' : 'No';
            $desc     = $p['description'] ?? '';
            $out[]    = "| `{$name}` | {$type} | {$required} | {$desc} |";
        }
        $out[] = "";
    }

    // Request body
    $body = $ai['request_body'] ?? null;
    if ($body !== null) {
        $out[] = "**Request Body** _(JSON)_";
        $out[] = "";
        if (!empty($body['description'])) {
            $out[] = $body['description'];
            $out[] = "";
        }
        if (!empty($body['fields'])) {
            $out[] = "| Field | Type | Required | Description |";
            $out[] = "|-------|------|----------|-------------|";
            foreach ($body['fields'] as $f) {
                $name     = $f['name']        ?? '';
                $type     = $f['type']        ?? 'mixed';
                $required = ($f['required']   ?? false) ? 'Yes' : 'No';
                $desc     = $f['description'] ?? '';
                $out[]    = "| `{$name}` | {$type} | {$required} | {$desc} |";
            }
            $out[] = "";
        }
    }

    // Response
    $response = $ai['response'] ?? null;
    if ($response !== null) {
        $out[] = "**Response** _(JSON)_";
        $out[] = "";
        if (!empty($response['description'])) {
            $out[] = $response['description'];
            $out[] = "";
        }
        if (!empty($response['fields'])) {
            $out[] = "| Field | Type | Description |";
            $out[] = "|-------|------|-------------|";
            foreach ($response['fields'] as $f) {
                $name = $f['name']        ?? '';
                $type = $f['type']        ?? 'mixed';
                $desc = $f['description'] ?? '';
                $out[] = "| `{$name}` | {$type} | {$desc} |";
            }
            $out[] = "";
        }
    }

    // Errors
    $errors = $ai['errors'] ?? [];
    if (!empty($errors)) {
        $out[] = "**Error Responses**";
        $out[] = "";
        $out[] = "| Status | Description |";
        $out[] = "|--------|-------------|";
        foreach ($errors as $e) {
            $status = $e['status']      ?? '';
            $desc   = $e['description'] ?? '';
            $out[]  = "| {$status} | {$desc} |";
        }
        $out[] = "";
    }

    $out[] = "---";
    $out[] = "";

    return $out;
}

// ---------------------------------------------------------------------------
// OpenAPI output
// ---------------------------------------------------------------------------

/**
 * @param array<string, array{label: string, routes: array<int, mixed>}> $sections
 */
function generateOpenApi(array $sections): string
{
    $paths = [];
    $tags  = [];

    foreach ($sections as $set => $section) {
        $grouped = groupRoutesByTag($section['routes']);
        foreach ($grouped as $tag => $tagRoutes) {
            $tags[] = ['name' => $tag, 'description' => "{$section['label']} — {$tag}"];
            foreach ($tagRoutes as $route) {
                $path   = convertPathToOpenApi($route['path']);
                $method = strtolower($route['method']);
                $ai     = $route['ai'] ?? null;

                $summary = $ai['summary'] ?? firstLine($route['comment']);
                $desc    = $ai['description'] ?? $route['comment'];

                $operation = [
                    'tags'        => [$tag],
                    'summary'     => $summary ?: "{$route['method']} {$route['path']}",
                    'description' => $desc,
                    'operationId' => operationId($route['method'], $route['path']),
                    'parameters'  => [],
                    'responses'   => ['200' => ['description' => 'Success']],
                ];

                if ($route['auth']) {
                    $operation['security'] = [['cookieAuth' => []]];
                }

                // Path params
                foreach (extractPathParams($route['path']) as $param) {
                    $name = is_array($param) ? $param['name'] : $param;
                    $desc = is_array($param) ? ($param['description'] ?? '') : '';
                    $operation['parameters'][] = [
                        'name'        => $name,
                        'in'          => 'path',
                        'required'    => true,
                        'description' => $desc,
                        'schema'      => ['type' => 'string'],
                    ];
                }

                // AI-provided query params
                foreach (($ai['query_params'] ?? []) as $qp) {
                    $operation['parameters'][] = [
                        'name'        => $qp['name'] ?? '',
                        'in'          => 'query',
                        'required'    => $qp['required'] ?? false,
                        'description' => $qp['description'] ?? '',
                        'schema'      => ['type' => $qp['type'] ?? 'string'],
                    ];
                }

                // AI-provided request body
                if (!empty($ai['request_body'])) {
                    $properties = [];
                    $required   = [];
                    foreach (($ai['request_body']['fields'] ?? []) as $f) {
                        $fname = $f['name'] ?? '';
                        $properties[$fname] = [
                            'type'        => mapType($f['type'] ?? 'string'),
                            'description' => $f['description'] ?? '',
                        ];
                        if ($f['required'] ?? false) {
                            $required[] = $fname;
                        }
                    }

                    $schema = ['type' => 'object', 'properties' => $properties];
                    if ($required) {
                        $schema['required'] = $required;
                    }

                    $operation['requestBody'] = [
                        'required' => true,
                        'content'  => ['application/json' => ['schema' => $schema]],
                    ];
                }

                // AI-provided response
                if (!empty($ai['response']['fields'])) {
                    $respProperties = [];
                    foreach (($ai['response']['fields'] ?? []) as $f) {
                        $fname = $f['name'] ?? '';
                        $respProperties[$fname] = [
                            'type'        => mapType($f['type'] ?? 'string'),
                            'description' => $f['description'] ?? '',
                        ];
                    }

                    $operation['responses']['200'] = [
                        'description' => $ai['response']['description'] ?? 'Success',
                        'content'     => [
                            'application/json' => [
                                'schema' => ['type' => 'object', 'properties' => $respProperties],
                            ],
                        ],
                    ];
                }

                // Error responses
                foreach (($ai['errors'] ?? []) as $err) {
                    $status = (string)($err['status'] ?? '400');
                    $operation['responses'][$status] = ['description' => $err['description'] ?? ''];
                }

                if (!isset($paths[$path])) {
                    $paths[$path] = [];
                }
                $paths[$path][$method] = $operation;
            }
        }
    }

    $spec = [
        'openapi' => '3.0.3',
        'info'    => [
            'title'       => 'BinktermPHP API',
            'description' => 'FidoNet BBS web interface and mailer API',
            'version'     => '1.0.0',
        ],
        'components' => [
            'securitySchemes' => [
                'cookieAuth' => [
                    'type' => 'apiKey',
                    'in'   => 'cookie',
                    'name' => 'binktermphp_session',
                ],
            ],
        ],
        'tags'  => $tags,
        'paths' => $paths,
    ];

    return yamlDump($spec);
}

// ---------------------------------------------------------------------------
// Utilities
// ---------------------------------------------------------------------------

/**
 * Group routes into logical sections based on the first path segment after the base prefix.
 *
 * @param array<int, array<string, mixed>> $routes
 * @return array<string, array<int, array<string, mixed>>>
 */
function groupRoutesByTag(array $routes): array
{
    $grouped = [];
    foreach ($routes as $route) {
        $tag       = routeTag($route['path']);
        $grouped[$tag][] = $route;
    }
    ksort($grouped);
    return $grouped;
}

function routeTag(string $path): string
{
    // Strip leading slash and take first two non-empty segments
    $parts = array_values(array_filter(explode('/', ltrim($path, '/'))));
    if (empty($parts)) {
        return 'General';
    }
    // Skip well-known top-level prefixes that are just namespaces
    $skip = ['api', 'admin', 'door', 'webdoor'];
    $segments = [];
    foreach ($parts as $p) {
        if (in_array(strtolower($p), $skip, true)) {
            continue;
        }
        $segments[] = $p;
        if (count($segments) === 1) {
            break;
        }
    }

    if (empty($segments)) {
        return ucfirst($parts[0] ?? 'General');
    }

    // Convert kebab-case/snake_case to Title Case
    $tag = implode(' ', array_map('ucfirst', preg_split('/[-_]/', $segments[0]) ?: [$segments[0]]));
    return $tag ?: 'General';
}

/** @return string[] */
function extractPathParams(string $path): array
{
    preg_match_all('/\{(\w+)\}/', $path, $m);
    return $m[1] ?? [];
}

function anchorSlug(string $text): string
{
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text) ?? $text;
    $text = preg_replace('/\s+/', '-', trim($text))    ?? $text;
    return $text;
}

function endpointAnchor(string $method, string $path): string
{
    return anchorSlug($method . ' ' . $path);
}

function operationId(string $method, string $path): string
{
    $parts  = array_filter(explode('/', $path));
    $parts  = array_map(fn($p) => preg_replace('/[^a-zA-Z0-9]/', '_', $p) ?? $p, $parts);
    return strtolower($method) . '_' . implode('_', $parts);
}

function convertPathToOpenApi(string $path): string
{
    // SimpleRouter uses {param}, OpenAPI also uses {param} — no change needed
    return $path;
}

function firstLine(string $text): string
{
    $line = strtok($text, "\n");
    return $line !== false ? trim($line) : '';
}

function mapType(string $type): string
{
    return match (strtolower($type)) {
        'int', 'integer'        => 'integer',
        'float', 'double'       => 'number',
        'bool', 'boolean'       => 'boolean',
        'array', 'object'       => 'object',
        'array<string>', 'list' => 'array',
        default                 => 'string',
    };
}

function stderr(string $msg): void
{
    fwrite(STDERR, $msg . "\n");
}

// ---------------------------------------------------------------------------
// Minimal YAML emitter (avoids requiring symfony/yaml in all environments)
// ---------------------------------------------------------------------------

function yamlDump(mixed $value, int $indent = 0): string
{
    $pad = str_repeat('  ', $indent);

    if ($value === null) {
        return "null\n";
    }
    if (is_bool($value)) {
        return ($value ? 'true' : 'false') . "\n";
    }
    if (is_int($value) || is_float($value)) {
        return $value . "\n";
    }
    if (is_string($value)) {
        // Use block scalar for multi-line, quoted for special chars, plain otherwise
        if (str_contains($value, "\n")) {
            $lines = explode("\n", rtrim($value));
            $block = "|\n";
            foreach ($lines as $line) {
                $block .= $pad . '  ' . $line . "\n";
            }
            return $block;
        }
        if (preg_match('/[:{}\[\],&*#?|<>=!%@`\'"\\\\]/', $value) || $value === '' || is_numeric($value)) {
            return '"' . addcslashes($value, '"\\') . '"' . "\n";
        }
        return $value . "\n";
    }

    if (is_array($value)) {
        if (empty($value)) {
            return "[]\n";
        }

        // Detect sequential (list) vs associative (map)
        $keys = array_keys($value);
        $isList = $keys === range(0, count($keys) - 1);

        $out = '';
        if ($isList) {
            foreach ($value as $item) {
                if (is_array($item)) {
                    $rendered = yamlDump($item, $indent + 1);
                    // Prefix first line with "- " and subsequent with "  "
                    $lines    = explode("\n", rtrim($rendered));
                    $out     .= $pad . '- ' . ltrim($lines[0]) . "\n";
                    for ($i = 1; $i < count($lines); $i++) {
                        if ($lines[$i] !== '') {
                            $out .= $pad . '  ' . ltrim($lines[$i]) . "\n";
                        }
                    }
                } else {
                    $rendered = yamlDump($item, $indent + 1);
                    $out     .= $pad . '- ' . ltrim($rendered);
                }
            }
        } else {
            foreach ($value as $k => $v) {
                $key = (string)$k;
                if (preg_match('/[:{}\[\],&*#?|<>=!%@`\'"\\\\]/', $key) || $key === '') {
                    $key = '"' . addcslashes($key, '"\\') . '"';
                }
                if (is_array($v) && !empty($v)) {
                    $out .= $pad . $key . ":\n" . yamlDump($v, $indent + 1);
                } else {
                    $out .= $pad . $key . ': ' . ltrim(yamlDump($v, $indent + 1));
                }
            }
        }

        return $out;
    }

    return "null\n";
}

// ---------------------------------------------------------------------------
// CLI argument parser
// ---------------------------------------------------------------------------

/**
 * @return array{0: array<string, string|bool>, 1: string[]}
 */
function parseArgs(array $argv): array
{
    $options    = [];
    $positional = [];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (str_starts_with($arg, '--')) {
            $arg = substr($arg, 2);
            if (str_contains($arg, '=')) {
                [$key, $val] = explode('=', $arg, 2);
                $options[$key] = $val;
            } else {
                $options[$arg] = true;
            }
        } else {
            $positional[] = $arg;
        }
    }

    return [$options, $positional];
}

function printUsage(): void
{
    echo <<<'USAGE'
Usage: php scripts/generate_api_docs.php [options]

Generates developer API documentation for BinktermPHP route files.

Options:
  --routes=SETS        Comma-separated route sets to document. Default: api
                       Valid: api, admin, door, webdoor, all
  --format=FORMAT      Output format: markdown (default) or openapi
  --output=FILE        Write output to FILE instead of stdout
  --ai                 Enrich documentation using a configured AI provider
  --provider=NAME      AI provider to use: anthropic or openai (default: auto)
  --model=MODEL        Override the AI model (default: claude-haiku or gpt-4o-mini)
  --ai-batch-size=N    Routes per AI request batch (default: 8)
  --help               Show this help

Examples:
  # Document the public API as Markdown
  php scripts/generate_api_docs.php --output=docs/API.md

  # Document all routes with AI enrichment, output OpenAPI YAML
  php scripts/generate_api_docs.php --routes=all --ai --format=openapi --output=docs/openapi.yaml

  # Admin API with AI enrichment using Anthropic
  php scripts/generate_api_docs.php --routes=admin --ai --provider=anthropic --output=docs/AdminAPI.md

USAGE;
}
