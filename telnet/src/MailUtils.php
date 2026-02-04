<?php

namespace BinktermPHP\TelnetServer;

/**
 * MailUtils - Message and mail-related utility functions for telnet daemon
 *
 * This class provides static utility methods for handling netmail and echomail
 * operations including sending, quoting, subject normalization, and pagination.
 */
class MailUtils
{
    /**
     * Send a netmail or echomail message via API
     *
     * @param string $apiBase Base URL for API requests
     * @param string $session Session token for authentication
     * @param array $payload Message data to send (to, from, subject, body, etc.)
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function sendMessage(string $apiBase, string $session, array $payload): array
    {
        $result = TelnetUtils::apiRequest($apiBase, 'POST', '/api/messages/send', $payload, $session);
        $success = ($result['status'] ?? 0) === 200 && !empty($result['data']['success']);
        $error = null;

        if (!$success) {
            // Try to get error message from API response
            if (!empty($result['data']['error'])) {
                $error = $result['data']['error'];
            } elseif (!empty($result['data']['message'])) {
                $error = $result['data']['message'];
            } elseif (!empty($result['error'])) {
                $error = 'Network error: ' . $result['error'];
            } else {
                $error = 'HTTP ' . ($result['status'] ?? 'unknown');
            }
        }

        return ['success' => $success, 'error' => $error];
    }

    /**
     * Quote message text for replies
     *
     * Formats the original message body with quote markers (>) and attribution line.
     *
     * @param string $body Original message body to quote
     * @param string $author Author of the original message
     * @return string Quoted message text with attribution
     */
    public static function quoteMessage(string $body, string $author): string
    {
        $lines = explode("\n", $body);
        $quoted = [];
        $quoted[] = '';
        $quoted[] = "On " . date('Y-m-d') . ", {$author} wrote:";
        $quoted[] = '';
        foreach ($lines as $line) {
            $quoted[] = '> ' . $line;
        }
        $quoted[] = '';
        $quoted[] = '';
        return implode("\n", $quoted);
    }

    /**
     * Normalize subject line by removing RE: prefixes
     *
     * @param string $subject Subject line to normalize
     * @return string Subject with RE: prefix removed
     */
    public static function normalizeSubject(string $subject): string
    {
        return preg_replace('/^Re:\\s*/i', '', trim($subject));
    }

    /**
     * Fetch the user's signature from settings (max 4 lines).
     */
    public static function getUserSignature(string $apiBase, string $session): string
    {
        $response = TelnetUtils::apiRequest($apiBase, 'GET', '/api/user/settings', null, $session);
        if (($response['status'] ?? 0) !== 200) {
            return '';
        }

        $settings = $response['data']['settings'] ?? $response['data'] ?? [];
        if (!is_array($settings)) {
            return '';
        }

        $signature = trim((string)($settings['signature_text'] ?? ''));
        if ($signature === '') {
            return '';
        }

        $signature = str_replace(["\r\n", "\r"], "\n", $signature);
        $lines = preg_split('/\n/', $signature) ?: [];
        $lines = array_slice($lines, 0, 4);
        $lines = array_map('rtrim', $lines);

        return implode("\n", $lines);
    }

    /**
     * Append signature to composed text if not already present.
     */
    public static function appendSignatureToCompose(string $text, string $signature): string
    {
        if ($signature === '') {
            return $text;
        }

        $sigLines = preg_split('/\r\n|\r|\n/', $signature) ?: [];
        $sigLines = array_map('rtrim', $sigLines);
        if ($sigLines === []) {
            return $text;
        }

        $lines = preg_split('/\r\n|\r|\n/', rtrim($text, "\r\n")) ?: [];
        while (!empty($lines) && trim((string)end($lines)) === '') {
            array_pop($lines);
        }

        $tail = array_slice($lines, -count($sigLines));
        $alreadyHasSignature = ($tail === $sigLines);
        if ($alreadyHasSignature) {
            return $text;
        }

        $base = rtrim($text);
        return $base === '' ? $signature : $base . "\n\n" . $signature;
    }

    /**
     * Calculate messages per page based on terminal height
     *
     * Accounts for headers, prompts, and UI elements to determine
     * how many messages can fit on screen at once.
     *
     * @param array $state Terminal state containing 'rows' key
     * @return int Number of messages that fit per page (minimum 5)
     */
    public static function getMessagesPerPage(array &$state): int
    {
        $rows = $state['rows'] ?? 24;
        // Be very conservative: header (1), messages (N), blank (1), prompt (1-2), input (1), safety (2) = N + 7
        // So N = rows - 7
        $perPage = max(5, $rows - 7);

        // Log in debug mode
        if (!empty($GLOBALS['telnet_debug'])) {
            echo "[" . date('Y-m-d H:i:s') . "] List view: Screen rows={$rows}, messages per page={$perPage}\n";
        }

        return $perPage;
    }

    /**
     * Get message counts for netmail and echomail
     *
     * Retrieves total counts of netmail messages and echomail messages
     * from all subscribed echoareas.
     *
     * @param string $apiBase Base URL for API requests
     * @param string $session Session token for authentication
     * @return array ['netmail' => int, 'echomail' => int]
     */
    public static function getMessageCounts(string $apiBase, string $session): array
    {
        $counts = ['netmail' => 0, 'echomail' => 0];

        $netmailResponse = TelnetUtils::apiRequest($apiBase, 'GET', '/api/messages/netmail?page=1', null, $session);
        if (!empty($netmailResponse['data']['pagination']['total'])) {
            $counts['netmail'] = (int)$netmailResponse['data']['pagination']['total'];
        }

        $areasResponse = TelnetUtils::apiRequest($apiBase, 'GET', '/api/echoareas?subscribed_only=true', null, $session);
        $areas = $areasResponse['data']['echoareas'] ?? [];
        $totalEcho = 0;
        foreach ($areas as $area) {
            $totalEcho += (int)($area['message_count'] ?? 0);
        }
        $counts['echomail'] = $totalEcho;

        return $counts;
    }
}
