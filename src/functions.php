<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 * 
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the 
 * following conditions are met:
 * 
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 * 
 */


// Helper function to filter kludge lines from message text
function filterKludgeLines($messageText) {
    // First normalize line endings - split on both \r\n, \n, and \r
    $lines = preg_split('/\r\n|\r|\n/', $messageText);
    $messageLines = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Skip empty lines
        if ($trimmed === '') {
            continue;
        }

        // Skip kludge lines - those that start with specific patterns
        if (preg_match('/^(INTL|FMPT|TOPT|MSGID|REPLY|PID|TZUTC)\s/', $trimmed) ||
            preg_match('/^Via\s+\d+:\d+\/\d+\s+@\d{8}\.\d{6}\.UTC/', $trimmed) ||  // Via lines with timestamp
            strpos($trimmed, "\x01") === 0 ||  // Traditional kludge lines starting with \x01
            strpos($trimmed, 'SEEN-BY:') === 0 ||
            strpos($trimmed, 'PATH:') === 0) {
            continue;
        }

        // Keep all other lines (actual message content, signatures, tearlines)
        $messageLines[] = $line;
    }

    return implode("\n", $messageLines);
}

/**
 * Filter kludge lines but preserve empty lines (for Markdown rendering).
 */
function filterKludgeLinesPreserveEmptyLines($messageText) {
    $lines = preg_split('/\r\n|\r|\n/', $messageText);
    $messageLines = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed !== '') {
            if (preg_match('/^(INTL|FMPT|TOPT|MSGID|REPLY|PID|TZUTC)\s/', $trimmed) ||
                preg_match('/^Via\s+\d+:\d+\/\d+\s+@\d{8}\.\d{6}\.UTC/', $trimmed) ||
                strpos($trimmed, "\x01") === 0 ||
                strpos($trimmed, 'SEEN-BY:') === 0 ||
                strpos($trimmed, 'PATH:') === 0) {
                continue;
            }
        }

        $messageLines[] = $line;
    }

    return implode("\n", $messageLines);
}

/**
 * Check if a message contains a MARKDOWN kludge.
 *
 * @param array $message Message array with kludge_lines/bottom_kludges/message_text
 * @return bool
 */
function hasMarkdownKludge(array $message): bool {
    $kludgeText = '';
    if (!empty($message['kludge_lines'])) {
        $kludgeText .= $message['kludge_lines'];
    }
    if (!empty($message['bottom_kludges'])) {
        $kludgeText .= ($kludgeText !== '' ? "\n" : '') . $message['bottom_kludges'];
    }
    if ($kludgeText !== '' && preg_match('/^\x01MARKDOWN:\s*\d+/mi', $kludgeText)) {
        return true;
    }

    $messageText = $message['message_text'] ?? '';
    if ($messageText === '') {
        return false;
    }
    $lines = preg_split('/\r\n|\r|\n/', $messageText);
    foreach ($lines as $line) {
        if (preg_match('/^\x01MARKDOWN:\s*\d+/i', $line)) {
            return true;
        }
    }

    return false;
}

/**
 * Generate initials from a person's name for echomail quoting
 * Examples: "Mark Anderson" -> "MA", "John" -> "JO", "Mary Jane Smith" -> "MS"
 */
function generateInitials($name) {
    $name = trim($name);
    if (empty($name)) {
        return "??";
    }

    // Split name into parts (remove extra spaces)
    $parts = array_filter(explode(' ', $name));

    if (count($parts) == 1) {
        // Single name: take first two characters
        $single = strtoupper($parts[0]);
        return substr($single, 0, min(2, strlen($single))) ?: "?";
    } else {
        // Multiple parts: take first letter of first and last name
        $first = strtoupper(substr($parts[0], 0, 1));
        $last = strtoupper(substr(end($parts), 0, 1));
        return $first . $last;
    }
}

/**
 * Quote message text intelligently - only quote original lines, not existing quotes
 * Preserves existing quote attribution while adding new quotes with current author's initials
 */
function quoteMessageText($messageText, $initials) {
    $lines = explode("\n", $messageText);
    $quotedLines = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Skip completely empty lines
        if ($trimmed === '') {
            $quotedLines[] = $line;
            continue;
        }

        // Check if line is already quoted (starts with initials> pattern or >)
        if (preg_match('/^[A-Z]{1,3}>\s/', $trimmed) || preg_match('/^>\s/', $trimmed)) {
            // This is already a quoted line, keep it as-is without adding new quote
            $quotedLines[] = $line;
        } else {
            // This is an original line from the current author, quote it
            $quotedLines[] = $initials . "> " . $line;
        }
    }

    return implode("\n", $quotedLines);
}

/**
 * Validate if an address is a proper FidoNet address
 * Returns true if address matches FidoNet format: zone:net/node[.point][@domain]
 */
function isValidFidonetAddress($address) {
    // Match FidoNet address pattern: zone:net/node[.point][@domain]
    return preg_match('/^\d+:\d+\/\d+(?:\.\d+)?(?:@\w+)?$/', trim($address));
}

/**
 * Parse REPLYTO kludge line to extract address and name
 * Format: "REPLYTO 2:460/256 8421559770" -> ['address' => '2:460/256', 'name' => '8421559770']
 * Only returns data if the address is a valid FidoNet address
 */
function parseReplyToKludge($messageText) {
    if (empty($messageText)) {
        return null;
    }

    // Normalize line endings and split into lines
    $lines = preg_split('/\r\n|\r|\n/', $messageText);

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Look for REPLYTO kludge line (must have \x01 prefix)
        if (preg_match('/^\x01REPLYTO\s+(.+)$/i', $trimmed, $matches)) {
            $replyToData = trim($matches[1]);

            // Parse "address name" or just "address"
            if (preg_match('/^(\S+)(?:\s+(.+))?$/', $replyToData, $addressMatches)) {
                $address = trim($addressMatches[1]);
                $name = isset($addressMatches[2]) ? trim($addressMatches[2]) : null;

                // Only return if it's a valid FidoNet address
                if (isValidFidonetAddress($address)) {
                    return [
                        'address' => $address,
                        'name' => $name
                    ];
                }
            }
        }
    }

    return null;
}

// Helper function to check admin access for BinkP functionality
function requireBinkpAdmin() {
    $auth = new \BinktermPHP\Auth();
    $user = $auth->requireAuth();

    if (!$user['is_admin']) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Admin access required for BinkP functionality']);
        exit;
    }

    return $user;
}

/**
 * Generate TZUTC offset string for FidoNet messages
 * TZUTC format: negative offsets include "-" (e.g., "-0500"), positive offsets have no sign (e.g., "0800")
 *
 * @param string|null $timezone Timezone identifier (e.g., "America/New_York"). If null, uses system timezone from BinkpConfig.
 * @return string TZUTC offset string (e.g., "0000", "-0500", "0800")
 */
function generateTzutc($timezone = null) {
    try {
        // Use system timezone if not provided
        if ($timezone === null) {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $timezone = $binkpConfig->getSystemTimezone();
        }

        $tz = new \DateTimeZone($timezone);
        $now = new \DateTime('now', $tz);
        $offset = $now->getOffset();
        $offsetHours = intval($offset / 3600);
        $offsetMinutes = intval(abs($offset % 3600) / 60);

        // TZUTC format: negative offsets include "-", positive offsets have no sign
        if ($offsetHours < 0) {
            return sprintf('-%02d%02d', abs($offsetHours), $offsetMinutes);
        } else {
            return sprintf('%02d%02d', $offsetHours, $offsetMinutes);
        }
    } catch (\Exception $e) {
        // Fallback to UTC if timezone is invalid
        return '0000';
    }
}

