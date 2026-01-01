<?php

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
