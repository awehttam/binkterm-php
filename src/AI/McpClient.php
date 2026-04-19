<?php

namespace BinktermPHP\AI;

/**
 * HTTP client for the BinktermPHP MCP server.
 *
 * Communicates via JSON-RPC over HTTP POST. The MCP server is stateless
 * (sessionIdGenerator: undefined), so each request is self-contained —
 * no session handshake is required.
 *
 * Authentication uses the user's personal bearer key stored in users_meta
 * under keyname 'mcp_serverkey'. The server enforces per-user access
 * controls (sysop-only areas hidden for non-admin users, etc.).
 */
class McpClient
{
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private string $serverUrl,
        private string $bearerToken
    ) {
        $this->serverUrl = rtrim(trim($serverUrl), '/');
    }

    /**
     * List all tools available from the MCP server, converted to Anthropic
     * tool definition format (name, description, input_schema).
     *
     * @return array<int, array{name: string, description: string, input_schema: array<string, mixed>}>
     * @throws \RuntimeException on connection or protocol error
     */
    public function listTools(): array
    {
        $result = $this->sendRequest('tools/list', null);

        $tools = $result['tools'] ?? [];
        if (!is_array($tools)) {
            throw new \RuntimeException('MCP tools/list returned unexpected structure.');
        }

        $converted = [];
        foreach ($tools as $tool) {
            if (!is_array($tool) || empty($tool['name'])) {
                continue;
            }
            // MCP uses 'inputSchema'; Anthropic expects 'input_schema'
            $inputSchema = $tool['inputSchema'] ?? ['type' => 'object', 'properties' => []];
            $converted[] = [
                'name' => (string)$tool['name'],
                'description' => (string)($tool['description'] ?? ''),
                'input_schema' => $inputSchema,
            ];
        }

        return $converted;
    }

    /**
     * Call a named tool with the given arguments.
     *
     * Returns the tool result as a JSON-encoded string suitable for
     * inclusion in an Anthropic tool_result content block.
     *
     * @param string               $name      Tool name (e.g. 'get_echomail_message')
     * @param array<string, mixed> $arguments Tool input arguments
     * @return string JSON-encoded result content
     * @throws \RuntimeException on connection, auth, or protocol error
     */
    public function callTool(string $name, array $arguments): string
    {
        $result = $this->sendRequest('tools/call', [
            'name' => $name,
            'arguments' => $arguments,
        ]);

        // MCP tool result has a 'content' array of content blocks
        $content = $result['content'] ?? [];
        if (!is_array($content)) {
            return json_encode($result) ?: '{}';
        }

        // Concatenate all text blocks into a single string
        $parts = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $parts[] = (string)$block['text'];
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Send a JSON-RPC request to the MCP server and return the result.
     *
     * @param string            $method MCP method (e.g. 'tools/list', 'tools/call')
     * @param array<string, mixed>|null $params  Method parameters (null for no params)
     * @return array<string, mixed> The JSON-RPC result object
     * @throws \RuntimeException on HTTP or JSON-RPC error
     */
    private function sendRequest(string $method, ?array $params): array
    {
        $body = [
            'jsonrpc' => '2.0',
            'method'  => $method,
            'id'      => 1,
        ];
        if ($params !== null) {
            $body['params'] = $params;
        }

        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode MCP request JSON.');
        }

        $headers = [
            'Content-Type: application/json',
            // StreamableHTTPServerTransport requires both types in Accept or it returns 406.
            // The server may respond with either application/json or text/event-stream.
            'Accept: application/json, text/event-stream',
            'Authorization: Bearer ' . $this->bearerToken,
        ];

        $url = $this->serverUrl . '/mcp';
        $raw = $this->httpPost($url, $json, $headers);

        // The MCP server may respond with SSE (text/event-stream) even for simple
        // request-response calls. Extract the JSON payload from SSE data lines if needed.
        $raw = $this->extractJsonFromResponse($raw);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('MCP server returned non-JSON response.');
        }

        if (isset($decoded['error'])) {
            $errMsg = $decoded['error']['message'] ?? json_encode($decoded['error']);
            throw new \RuntimeException('MCP error: ' . $errMsg);
        }

        if (!array_key_exists('result', $decoded)) {
            throw new \RuntimeException('MCP response missing result field.');
        }

        return is_array($decoded['result']) ? $decoded['result'] : [];
    }

    /**
     * If the response is SSE-formatted (text/event-stream), extract the JSON
     * payload from the first non-empty `data:` line. Otherwise return as-is.
     *
     * SSE lines look like:
     *   data: {"jsonrpc":"2.0","id":1,"result":{...}}
     */
    private function extractJsonFromResponse(string $raw): string
    {
        $trimmed = trim($raw);

        // Already looks like JSON — return immediately
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            return $raw;
        }

        // Parse SSE: find the first `data:` line that contains valid JSON
        foreach (explode("\n", $raw) as $line) {
            $line = rtrim($line, "\r");
            if (str_starts_with($line, 'data:')) {
                $payload = trim(substr($line, 5));
                if ($payload !== '' && $payload !== '[DONE]') {
                    return $payload;
                }
            }
        }

        // Fall through — let the caller's json_decode handle the error
        return $raw;
    }

    /**
     * Perform an HTTP POST and return the raw response body.
     *
     * @param string            $url     Full URL
     * @param string            $body    Request body
     * @param array<int, string> $headers HTTP headers
     * @throws \RuntimeException on network or HTTP error
     */
    private function httpPost(string $url, string $body, array $headers): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);

            $raw      = curl_exec($ch);
            $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                throw new \RuntimeException('MCP connection error: ' . $curlErr);
            }

            if ($status === 401) {
                throw new \RuntimeException('MCP authentication failed (invalid bearer key).');
            }
            if ($status >= 400) {
                throw new \RuntimeException("MCP server returned HTTP {$status}.");
            }

            return (string)$raw;
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => implode("\r\n", $headers) . "\r\n",
                'content' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
            ],
        ]);

        $raw = file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new \RuntimeException('MCP connection failed.');
        }

        return $raw;
    }
}
