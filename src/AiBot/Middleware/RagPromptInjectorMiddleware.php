<?php

namespace BinktermPHP\AiBot\Middleware;

use BinktermPHP\AiBot\BotContext;
use BinktermPHP\AiBot\BotMiddlewareInterface;
use BinktermPHP\Binkp\Logger;

/**
 * Retrieves relevant documentation chunks from a sqlite-vec knowledge base
 * and injects them into the bot's system prompt before the AI is called.
 *
 * The incoming chat message is used as the search query. Results are fetched
 * by shelling out to query_retrieve.py (part of tools/support-bot), which
 * handles both embedding and vector search so no PHP ML library is needed.
 *
 * Build the knowledge base first:
 *   cd tools/support-bot && python3 build_index.py
 *
 * Configuration:
 *
 *   {
 *     "class": "RagPromptInjectorMiddleware",
 *     "config": {
 *       "db_path":     "tools/support-bot/binkterm_knowledge.db",
 *       "script_path": "tools/support-bot/query_retrieve.py",
 *       "top_k":       4,
 *       "position":    "append",
 *       "separator":   "\n\n"
 *     }
 *   }
 *
 * Options:
 *   db_path     — path to the sqlite-vec knowledge base, relative to the
 *                 project root or absolute (default: tools/support-bot/binkterm_knowledge.db)
 *   script_path — path to query_retrieve.py, relative or absolute
 *                 (default: tools/support-bot/query_retrieve.py)
 *   top_k       — number of chunks to retrieve (default: 4)
 *   position    — "append" (default) or "prepend"
 *   separator   — string between the existing prompt and the injected context
 *                 (default: two newlines)
 *
 * If the database or script is missing, or the retrieval returns no results,
 * the middleware passes through without modifying the system prompt.
 */
class RagPromptInjectorMiddleware implements BotMiddlewareInterface
{
    private string  $dbPath;
    private string  $scriptPath;
    private int     $topK;
    private string  $position;
    private string  $separator;
    private ?Logger $logger;

    private static string $projectRoot = '';

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config = [], ?Logger $logger = null)
    {
        if (self::$projectRoot === '') {
            self::$projectRoot = realpath(__DIR__ . '/../../../') ?: '';
        }

        $this->dbPath     = $this->resolvePath(
            (string)($config['db_path']     ?? 'tools/support-bot/binkterm_knowledge.db')
        );
        $this->scriptPath = $this->resolvePath(
            (string)($config['script_path'] ?? 'tools/support-bot/query_retrieve.py')
        );
        $this->topK      = max(1, (int)($config['top_k']    ?? 4));
        $this->position  = (string)($config['position']     ?? 'append');
        $this->separator = (string)($config['separator']    ?? "\n\n");
        $this->logger    = $logger;
    }

    public function handle(BotContext $ctx, callable $next): void
    {
        if (!file_exists($this->dbPath)) {
            $this->logger?->warning('[RagPromptInjector] Knowledge base not found — skipping', [
                'db_path' => $this->dbPath,
            ]);
            $next();
            return;
        }

        if (!file_exists($this->scriptPath)) {
            $this->logger?->warning('[RagPromptInjector] query_retrieve.py not found — skipping', [
                'script_path' => $this->scriptPath,
            ]);
            $next();
            return;
        }

        $escapedScript = escapeshellarg($this->scriptPath);
        $escapedQ      = escapeshellarg($ctx->incomingMessage);
        $escapedDb     = escapeshellarg($this->dbPath);

        $this->logger?->debug('[RagPromptInjector] Retrieving context', [
            'question' => substr($ctx->incomingMessage, 0, 100),
            'top_k'    => $this->topK,
        ]);

        [$output, $error] = $this->runPython("$escapedScript $escapedQ {$this->topK} $escapedDb");

        if (trim($output) === '') {
            $this->logger?->warning('[RagPromptInjector] query_retrieve.py produced no output', [
                'error' => trim($error),
            ]);
            $next();
            return;
        }

        $chunks = json_decode(trim($output), true);

        if (!is_array($chunks) || empty($chunks)) {
            $this->logger?->debug('[RagPromptInjector] No relevant chunks found', [
                'question' => substr($ctx->incomingMessage, 0, 100),
            ]);
            $next();
            return;
        }

        // Build context block from retrieved chunks.
        $parts = [];
        foreach ($chunks as $chunk) {
            $source  = (string)($chunk['source'] ?? '');
            $heading = ($chunk['heading_context'] ?? '') !== ''
                ? " ({$chunk['heading_context']})" : '';
            $parts[] = "--- Source: {$source}{$heading} ---\n{$chunk['content']}";
        }
        $contextBlock = "RELEVANT DOCUMENTATION:\n" . implode("\n\n", $parts);

        $this->logger?->debug('[RagPromptInjector] Context injected', [
            'chunks'        => count($chunks),
            'context_bytes' => strlen($contextBlock),
        ]);

        if ($this->position === 'prepend') {
            $ctx->systemPrompt = $contextBlock . $this->separator . $ctx->systemPrompt;
        } else {
            $ctx->systemPrompt = $ctx->systemPrompt . $this->separator . $contextBlock;
        }

        $next();
    }

    /**
     * Run a Python command, trying python3 then python (Windows compatibility).
     *
     * @return array{string, string} [stdout, stderr]
     */
    private function runPython(string $args): array
    {
        [$out, $err] = $this->runCmd("python3 $args");
        if (trim($out) !== '') {
            return [$out, $err];
        }
        return $this->runCmd("python $args");
    }

    /**
     * @return array{string, string} [stdout, stderr]
     */
    private function runCmd(string $cmd): array
    {
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['', 'proc_open failed'];
        }
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return [$out, $err];
    }

    /**
     * Resolve a path that may be relative to the project root or absolute.
     */
    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return '';
        }
        // Already absolute (Unix or Windows)
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\/]/', $path)) {
            return $path;
        }
        return self::$projectRoot . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}
