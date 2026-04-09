#!/usr/bin/env php
<?php
/**
 * bot_query.php — RAG-based support bot for BinktermPHP.
 *
 * Shells out to query_retrieve.py to embed the question and fetch the most
 * relevant documentation chunks via sqlite-vec, then calls Claude Haiku to
 * produce a grounded answer.
 *
 * CLI:  php bot_query.php "How do I configure echomail?"
 * POST: curl -X POST -H 'Content-Type: application/json' \
 *            -d '{"question":"How do I configure echomail?"}' \
 *            http://your-host/bot_query.php
 *
 * Environment:
 *   ANTHROPIC_API_KEY  — required
 */

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
define('DB_PATH',          __DIR__ . '/binkterm_knowledge.db');
define('RETRIEVE_SCRIPT',  __DIR__ . '/query_retrieve.py');
define('TOP_K',            4);
define('ANTHROPIC_MODEL',  'claude-haiku-4-5-20251001');

// ---------------------------------------------------------------------------
// Read the question
// ---------------------------------------------------------------------------
$question = '';

if (PHP_SAPI === 'cli') {
    if ($argc < 2) {
        fwrite(STDERR, "Usage: php bot_query.php \"your question\"\n");
        exit(1);
    }
    $question = trim($argv[1]);
} else {
    header('Content-Type: text/plain; charset=utf-8');
    $body = file_get_contents('php://input');
    $json = json_decode($body, true);
    if (isset($json['question'])) {
        $question = trim((string)$json['question']);
    } elseif (isset($_POST['question'])) {
        $question = trim((string)$_POST['question']);
    }
}

if ($question === '') {
    error_out("Error: question is empty.\n");
}

// ---------------------------------------------------------------------------
// Validate prerequisites
// ---------------------------------------------------------------------------
$apiKey = getenv('ANTHROPIC_API_KEY');
if (!$apiKey) {
    error_out("Error: ANTHROPIC_API_KEY environment variable is not set.\n");
}

if (!file_exists(DB_PATH)) {
    error_out("Error: knowledge base not found (" . DB_PATH . ").\n"
        . "Run build_index.py first to build it.\n");
}

if (!file_exists(RETRIEVE_SCRIPT)) {
    error_out("Error: retrieval helper not found (" . RETRIEVE_SCRIPT . ").\n");
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Run a command and return [stdout, stderr].
 * @return array{string, string}
 */
function run_cmd(string $cmd): array
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
 * Run a Python command, trying python3 then python.
 * Returns [stdout, last_stderr].
 * @return array{string, string}
 */
function run_python(string $args): array
{
    [$out, $err] = run_cmd("python3 $args");
    if (trim($out) !== '') {
        return [$out, $err];
    }
    return run_cmd("python $args");
}

/**
 * Write an error message to stderr and exit with code 1.
 */
function error_out(string $message): never
{
    fwrite(STDERR, $message);
    exit(1);
}

// ---------------------------------------------------------------------------
// Retrieve relevant chunks via query_retrieve.py
// ---------------------------------------------------------------------------
$escapedScript = escapeshellarg(RETRIEVE_SCRIPT);
$escapedQ      = escapeshellarg($question);
$escapedDb     = escapeshellarg(DB_PATH);

[$retrieveOutput, $retrieveError] = run_python("$escapedScript $escapedQ " . TOP_K . " $escapedDb");

if (!$retrieveOutput || trim($retrieveOutput) === '') {
    $hint = $retrieveError !== '' ? "\nPython error output:\n$retrieveError" : '';
    error_out("Error: query_retrieve.py produced no output.$hint\n");
}

$chunks = json_decode(trim($retrieveOutput), true);
if (!is_array($chunks)) {
    error_out("Error: unexpected output from query_retrieve.py:\n$retrieveOutput\n");
}

if (empty($chunks)) {
    echo "I don't know — no relevant documentation was found for your question.\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Build the system prompt with retrieved context
// ---------------------------------------------------------------------------
$contextParts = [];
foreach ($chunks as $chunk) {
    $source  = $chunk['source'];
    $heading = ($chunk['heading_context'] ?? '') !== '' ? " ({$chunk['heading_context']})" : '';
    $contextParts[] = "--- Source: {$source}{$heading} ---\n{$chunk['content']}";
}
$context = implode("\n\n", $contextParts);

$systemPrompt = <<<PROMPT
You are a helpful support assistant for BinktermPHP, a Fidonet BBS web interface.

Answer the sysop's question using ONLY the documentation context provided below.
- If the answer is not clearly present in the context, respond with "I don't know."
- Do not guess, infer beyond what the context states, or invent configuration values.
- Keep answers concise and practical. Use numbered steps or bullet points when appropriate.
- If the context mentions a relevant config file or command, include it.

DOCUMENTATION CONTEXT:
{$context}
PROMPT;

// ---------------------------------------------------------------------------
// Call the Anthropic API
// ---------------------------------------------------------------------------
$payload = json_encode([
    'model'      => ANTHROPIC_MODEL,
    'max_tokens' => 1024,
    'system'     => $systemPrompt,
    'messages'   => [
        ['role' => 'user', 'content' => $question],
    ],
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$response  = curl_exec($ch);
$httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError !== '') {
    error_out("API request failed: $curlError\n");
}

if ($httpCode !== 200) {
    error_out("Anthropic API returned HTTP $httpCode: $response\n");
}

$data   = json_decode($response, true);
$answer = $data['content'][0]['text'] ?? null;

if ($answer === null) {
    error_out("Unexpected API response format.\n");
}

echo trim($answer) . "\n";
