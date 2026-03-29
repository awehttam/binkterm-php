#!/usr/bin/env php
<?php

declare(strict_types=1);

chdir(__DIR__ . '/../');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\AI\AiRequest;
use BinktermPHP\AI\AiService;
use BinktermPHP\Config;

const SOURCE_LOCALE          = 'en';
const DEFAULT_OPENAI_MODEL   = 'gpt-4o-mini';
const DEFAULT_CLAUDE_MODEL   = 'claude-sonnet-4-6';
const DEFAULT_BATCH_SIZE     = 150;
const DEFAULT_TIMEOUT_SECONDS = 120;
const DEFAULT_RETRIES        = 3;

main($argv);

function main(array $argv): void
{
    [$options, $positional] = parseArgs($argv);
    if (isset($options['help'])) {
        printUsage();
        exit(0);
    }

    $locale   = trim((string)($options['locale']   ?? ($positional[0] ?? '')));
    $language = trim((string)($options['language'] ?? ($positional[1] ?? '')));
    $batchSize = (int)($options['batch-size'] ?? DEFAULT_BATCH_SIZE);
    $timeout   = (int)($options['timeout']    ?? DEFAULT_TIMEOUT_SECONDS);
    $retries   = (int)($options['retries']    ?? DEFAULT_RETRIES);
    $overwrite = isset($options['overwrite']);
    $dryRun    = isset($options['dry-run']);
    $pauseMs   = (int)($options['pause-ms']   ?? 0);

    if ($locale === '' || $language === '') {
        fwrite(STDERR, "Error: --locale and --language are required.\n\n");
        printUsage();
        exit(1);
    }

    if (!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $locale)) {
        fwrite(STDERR, "Error: locale must look like fr, de, pt-BR, etc.\n");
        exit(1);
    }

    if ($batchSize < 1 || $batchSize > 500) {
        fwrite(STDERR, "Error: --batch-size must be between 1 and 500.\n");
        exit(1);
    }

    if ($timeout < 10 || $timeout > 600) {
        fwrite(STDERR, "Error: --timeout must be between 10 and 600 seconds.\n");
        exit(1);
    }

    if ($retries < 1 || $retries > 10) {
        fwrite(STDERR, "Error: --retries must be between 1 and 10.\n");
        exit(1);
    }

    // ── Provider selection ────────────────────────────────────────────────────
    // Auto-detect from env if --provider not given: prefer Claude when
    // ANTHROPIC_API_KEY is set and OPENAI_API_KEY is not, otherwise OpenAI.
    $provider = strtolower(trim((string)($options['provider'] ?? '')));
    if ($provider === '') {
        $hasAnthropic = Config::env('ANTHROPIC_API_KEY', '') !== '';
        $hasOpenAI    = Config::env('OPENAI_API_KEY', '')    !== '';
        $provider = ($hasAnthropic && !$hasOpenAI) ? 'claude' : 'openai';
    }

    if (!in_array($provider, ['openai', 'claude'], true)) {
        fwrite(STDERR, "Error: --provider must be 'openai' or 'claude'.\n");
        exit(1);
    }

    $defaultModel = $provider === 'claude' ? DEFAULT_CLAUDE_MODEL : DEFAULT_OPENAI_MODEL;
    $model = trim((string)($options['model'] ?? $defaultModel));
    $aiService = AiService::create();
    $resolvedProvider = $provider === 'claude' ? 'anthropic' : $provider;

    if (!in_array($resolvedProvider, $aiService->getConfiguredProviders(), true)) {
        $envKey = $provider === 'claude' ? 'ANTHROPIC_API_KEY' : 'OPENAI_API_KEY';
        fwrite(STDERR, "Error: {$envKey} is required for the {$provider} provider.\n");
        exit(1);
    }

    $namespaces = normalizeNamespaces((string)($options['namespaces'] ?? 'common,errors,terminalserver'));
    if ($namespaces === []) {
        fwrite(STDERR, "Error: --namespaces must include at least one of common,errors.\n");
        exit(1);
    }

    $sourceCatalogs = loadSourceCatalogs($namespaces);
    $totalStrings = 0;
    foreach ($sourceCatalogs as $catalog) {
        $totalStrings += count($catalog);
    }

    echo "Creating locale '{$locale}' ({$language}) from source locale '" . SOURCE_LOCALE . "'\n";
    echo "Provider: {$provider}\n";
    echo "Model: {$model}\n";
    echo "Namespaces: " . implode(', ', $namespaces) . "\n";
    echo "Total strings: {$totalStrings}\n";
    if ($dryRun) {
        echo "Dry-run mode enabled: no files will be written.\n";
    }

    $targetDir = __DIR__ . '/../config/i18n/' . $locale;
    if (!$dryRun) {
        ensureTargetDirectory($targetDir, $overwrite);
    }

    $validationWarnings = [];
    foreach ($namespaces as $namespace) {
        $entries = $sourceCatalogs[$namespace];
        $translated = translateCatalog(
            $entries,
            $locale,
            $language,
            $provider,
            $model,
            $batchSize,
            $timeout,
            $retries,
            $pauseMs,
            $validationWarnings,
            $namespace,
            $aiService
        );

        if ($dryRun) {
            echo "Dry run: translated {$namespace}.php (" . count($translated) . " keys)\n";
            continue;
        }

        $targetPath = $targetDir . '/' . $namespace . '.php';
        writeCatalogFile($targetPath, $translated, $overwrite);
        echo "Wrote {$targetPath}\n";
    }

    if (!empty($validationWarnings)) {
        $warningsPath = $targetDir . '/translation_warnings.log';
        $content = implode(PHP_EOL, $validationWarnings) . PHP_EOL;
        if ($dryRun) {
            echo "Warnings generated: " . count($validationWarnings) . "\n";
        } else {
            file_put_contents($warningsPath, $content);
            echo "Validation warnings: " . count($validationWarnings) . " (logged to {$warningsPath})\n";
        }
    }

    echo "Done.\n";
}

function parseArgs(array $argv): array
{
    $options = [];
    $positional = [];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (str_starts_with($arg, '--')) {
            $arg = substr($arg, 2);
            if (strpos($arg, '=') !== false) {
                [$k, $v] = explode('=', $arg, 2);
                $options[$k] = $v;
            } else {
                $options[$arg] = true;
            }
            continue;
        }
        $positional[] = $arg;
    }

    return [$options, $positional];
}

function printUsage(): void
{
    echo "Create a translated i18n locale from config/i18n/en catalogs.\n\n";
    echo "Usage:\n";
    echo "  php scripts/create_translation_catalog.php --locale=fr --language=\"French\"\n\n";
    echo "Options:\n";
    echo "  --locale=<code>         Target locale code (required), e.g. fr, de, pt-BR\n";
    echo "  --language=<name>       Target language name (required), e.g. French\n";
    echo "  --provider=<name>       openai or claude (default: auto-detect from env)\n";
    echo "  --namespaces=<list>     Comma-separated: common,errors,terminalserver (default: common,errors,terminalserver)\n";
    echo "  --model=<id>            Model ID (default: " . DEFAULT_OPENAI_MODEL . " / " . DEFAULT_CLAUDE_MODEL . ")\n";
    echo "  --batch-size=<n>        Keys per API request (default: " . DEFAULT_BATCH_SIZE . ")\n";
    echo "  --timeout=<sec>         HTTP timeout per request (default: " . DEFAULT_TIMEOUT_SECONDS . ")\n";
    echo "  --retries=<n>           Retries for failed batches (default: " . DEFAULT_RETRIES . ")\n";
    echo "  --pause-ms=<n>          Delay between batch requests (default: 0)\n";
    echo "  --overwrite             Overwrite existing locale files\n";
    echo "  --dry-run               Translate but do not write files\n";
    echo "  --help                  Show this message\n\n";
    echo "Environment:\n";
    echo "  OPENAI_API_KEY          Required for OpenAI provider\n";
    echo "  OPENAI_API_BASE         Optional OpenAI base URL (default: https://api.openai.com/v1)\n";
    echo "  ANTHROPIC_API_KEY       Required for Claude provider\n";
    echo "  ANTHROPIC_API_BASE      Optional Claude base URL (default: https://api.anthropic.com/v1)\n";
}

/**
 * @return array<int, string>
 */
function normalizeNamespaces(string $raw): array
{
    $parts = array_filter(array_map('trim', explode(',', $raw)), static fn($v) => $v !== '');
    $parts = array_values(array_unique($parts));
    $allowed = ['common', 'errors', 'terminalserver'];

    $out = [];
    foreach ($parts as $part) {
        if (in_array($part, $allowed, true)) {
            $out[] = $part;
        }
    }
    return $out;
}

/**
 * @param array<int, string> $namespaces
 * @return array<string, array<string, string>>
 */
function loadSourceCatalogs(array $namespaces): array
{
    $catalogs = [];
    foreach ($namespaces as $namespace) {
        $path = __DIR__ . '/../config/i18n/' . SOURCE_LOCALE . '/' . $namespace . '.php';
        if (!is_file($path)) {
            fwrite(STDERR, "Error: missing source catalog {$path}\n");
            exit(1);
        }

        $catalog = require $path;
        if (!is_array($catalog)) {
            fwrite(STDERR, "Error: source catalog did not return array: {$path}\n");
            exit(1);
        }

        $normalized = [];
        foreach ($catalog as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $normalized[$key] = $value;
            }
        }

        $catalogs[$namespace] = $normalized;
    }
    return $catalogs;
}

function ensureTargetDirectory(string $targetDir, bool $overwrite): void
{
    if (is_dir($targetDir)) {
        if (!$overwrite && (is_file($targetDir . '/common.php') || is_file($targetDir . '/errors.php') || is_file($targetDir . '/terminalserver.php'))) {
            fwrite(STDERR, "Error: target locale already exists. Use --overwrite to replace files.\n");
            exit(1);
        }
        return;
    }

    if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        fwrite(STDERR, "Error: failed to create target directory: {$targetDir}\n");
        exit(1);
    }
}

/**
 * @param array<string, string> $entries
 * @param array<int, string> $validationWarnings
 * @return array<string, string>
 */
function translateCatalog(
    array $entries,
    string $locale,
    string $language,
    string $provider,
    string $model,
    int $batchSize,
    int $timeout,
    int $retries,
    int $pauseMs,
    array &$validationWarnings,
    string $namespace,
    AiService $aiService
): array {
    $result = [];
    $keys = array_keys($entries);
    $batches = array_chunk($keys, $batchSize);

    echo "Translating {$namespace}.php in " . count($batches) . " batches...\n";

    foreach ($batches as $index => $batchKeys) {
        $batchNumber = $index + 1;
        echo "  Batch {$batchNumber}/" . count($batches) . " (" . count($batchKeys) . " keys)\n";

        $batchEntries = [];
        foreach ($batchKeys as $key) {
            $batchEntries[] = [
                'key' => $key,
                'text' => $entries[$key],
            ];
        }

        $translatedBatch = null;
        $lastError = null;
        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            try {
                $translatedBatch = requestBatchTranslation(
                    $batchEntries,
                    $locale,
                    $language,
                    $provider,
                    $model,
                    $timeout,
                    $namespace,
                    $aiService
                );
                break;
            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
                fwrite(STDERR, "    attempt {$attempt}/{$retries} failed: {$lastError}\n");
                if ($attempt < $retries) {
                    usleep($attempt * 300000);
                }
            }
        }

        if ($translatedBatch === null) {
            fwrite(STDERR, "Error: batch {$batchNumber} failed after {$retries} attempts.\n");
            if ($lastError !== null) {
                fwrite(STDERR, "Last error: {$lastError}\n");
            }
            exit(1);
        }

        foreach ($batchKeys as $key) {
            $sourceText = $entries[$key];
            $translatedText = $translatedBatch[$key] ?? $sourceText;
            [$isValid, $warning] = validateTranslation($key, $sourceText, $translatedText);
            if (!$isValid) {
                $translatedText = $sourceText;
                $validationWarnings[] = "[{$namespace}] {$warning}";
            }
            $result[$key] = $translatedText;
        }

        if ($pauseMs > 0 && $batchNumber < count($batches)) {
            usleep($pauseMs * 1000);
        }
    }

    return $result;
}

/**
 * @param array<int, array{key:string,text:string}> $entries
 * @return array<string, string>
 */
function requestBatchTranslation(
    array $entries,
    string $locale,
    string $language,
    string $provider,
    string $model,
    int $timeout,
    string $namespace,
    AiService $aiService
): array {
    $resolvedProvider = $provider === 'claude' ? 'anthropic' : $provider;
    $request = new AiRequest(
        'translation_catalog',
        buildSystemPrompt($language, $locale),
        json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"entries":[]}',
        $resolvedProvider,
        $model,
        0.2,
        8096,
        $timeout,
        null,
        [
            'locale' => $locale,
            'language' => $language,
            'namespace' => $namespace,
            'batch_size' => count($entries),
        ]
    );

    $response = $aiService->generateJson($request);
    return decodeTranslationsFromObject($response->getParsedJson(), $entries);
}

/**
 * Decode the JSON translation map returned by any provider.
 *
 * @param array<int, array{key:string,text:string}> $entries
 * @return array<string, string>
 */
function decodeTranslationsFromObject(?array $decoded, array $entries): array
{
    if (!is_array($decoded)) {
        throw new RuntimeException('Model did not return a JSON object.');
    }

    $translations = [];
    foreach ($entries as $entry) {
        $key        = $entry['key'];
        $translated = $decoded[$key] ?? null;
        if (is_string($translated) && $translated !== '') {
            $translations[$key] = normalizeLineEndings($translated);
        }
    }
    return $translations;
}

function buildSystemPrompt(string $language, string $locale): string
{
    return implode("\n", [
        "You translate i18n catalogs from English into {$language} (locale {$locale}).",
        "Rules:",
        "1) Output only a single JSON object where each property name is the original key.",
        "2) Preserve keys exactly and return all keys.",
        "3) Translate values naturally for UI text.",
        "4) Preserve placeholders exactly, including tokens like {name}, %s, %d, %1\$s, and HTML tags.",
        "5) Do not add commentary, markdown, or extra fields.",
    ]);
}


/**
 * @return array{0:bool,1:string}
 */
function validateTranslation(string $key, string $source, string $translated): array
{
    if ($translated === '') {
        return [false, "Key '{$key}' returned empty translation"];
    }

    $sourceTokens = extractTokens($source);
    $translatedTokens = extractTokens($translated);

    if ($sourceTokens !== $translatedTokens) {
        return [false, "Key '{$key}' placeholder mismatch"];
    }

    return [true, ''];
}

/**
 * @return array<string, int>
 */
function extractTokens(string $text): array
{
    $tokens = [];

    if (preg_match_all('/\{[a-zA-Z0-9_.-]+\}/', $text, $m) !== false) {
        foreach ($m[0] as $token) {
            $tokens[$token] = ($tokens[$token] ?? 0) + 1;
        }
    }

    if (preg_match_all('/%(?:\d+\$)?[bcdeEufFgGosxX]/', $text, $m) !== false) {
        foreach ($m[0] as $token) {
            $tokens[$token] = ($tokens[$token] ?? 0) + 1;
        }
    }

    if (preg_match_all('/<\/?[a-zA-Z][^>]*>/', $text, $m) !== false) {
        foreach ($m[0] as $token) {
            $tokens[$token] = ($tokens[$token] ?? 0) + 1;
        }
    }

    ksort($tokens);
    return $tokens;
}

function normalizeLineEndings(string $text): string
{
    return str_replace(["\r\n", "\r"], "\n", $text);
}

/**
 * @param array<string, string> $catalog
 */
function writeCatalogFile(string $path, array $catalog, bool $overwrite): void
{
    if (is_file($path) && !$overwrite) {
        fwrite(STDERR, "Error: file exists. Use --overwrite to replace: {$path}\n");
        exit(1);
    }

    $lines = [];
    $lines[] = '<?php';
    $lines[] = '';
    $lines[] = 'return [';
    foreach ($catalog as $key => $value) {
        $lines[] = "    '" . escapePhpSingleQuoted($key) . "' => '" . escapePhpSingleQuoted($value) . "',";
    }
    $lines[] = '];';
    $lines[] = '';

    $content = implode(PHP_EOL, $lines);
    if (file_put_contents($path, $content) === false) {
        fwrite(STDERR, "Error: failed writing file: {$path}\n");
        exit(1);
    }
}

function escapePhpSingleQuoted(string $value): string
{
    return str_replace(
        ["\\", "'"],
        ["\\\\", "\\'"],
        $value
    );
}
