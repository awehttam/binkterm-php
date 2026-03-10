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

namespace BinktermPHP\Robots\Processors;

use BinktermPHP\MessageHandler;
use BinktermPHP\Robots\MessageProcessorInterface;

/**
 * AutoReplyProcessor — automatically replies to matching echomail messages,
 * quoting the original body and kludge lines. Intended for test message routing.
 *
 * Processor config keys (stored in echomail_robots.processor_config JSONB):
 *   sender_username string   Required. Username of the BBS account to post replies as.
 *   reply_text      string   Optional. Text prepended to the quoted body.
 *                            Default: "This is an automated reply to your test message."
 *   quote_kludges   bool     Optional. Include kludge lines in the quote. Default: true.
 *   skip_from_names string[] Optional. from_name values to skip (avoid reply loops).
 *                            The posting user's name is always added automatically.
 */
class AutoReplyProcessor implements MessageProcessorInterface
{
    private \PDO $db;

    /** @var callable|null */
    private $debugCallback = null;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Set a debug output callback. Called with one string per debug line.
     *
     * @param callable|null $fn  fn(string $line): void
     */
    public function setDebugCallback(?callable $fn): void
    {
        $this->debugCallback = $fn;
    }

    /** @param string $msg */
    private function debug(string $msg): void
    {
        if ($this->debugCallback !== null) {
            ($this->debugCallback)($msg);
        }
    }

    public static function getProcessorType(): string
    {
        return 'auto_reply';
    }

    public static function getDisplayName(): string
    {
        return 'Auto-Reply';
    }

    public static function getDescription(): string
    {
        return 'Automatically replies to matching messages, quoting the original body and kludge lines. Useful for testing message routing.';
    }

    /**
     * Process one echomail message: post an auto-reply quoting the original.
     *
     * @param array $message     Row from the echomail table
     * @param array $robotConfig Decoded processor_config JSON
     * @return bool              True if a reply was posted
     */
    public function processMessage(array $message, array $robotConfig): bool
    {
        $senderUsername = trim($robotConfig['sender_username'] ?? '');
        $replyText      = $robotConfig['reply_text'] ?? 'This is an automated reply to your test message.';
        $quoteKludges   = (bool)($robotConfig['quote_kludges'] ?? true);

        if ($senderUsername === '') {
            $this->debug('  ERROR: sender_username not configured in processor_config');
            return false;
        }

        $userId = $this->getUserIdByUsername($senderUsername);
        if ($userId === null) {
            $this->debug("  ERROR: user '{$senderUsername}' not found");
            return false;
        }

        // Build skip list: configured names + the posting user's real_name
        $skipFromNames = array_map('mb_strtolower', (array)($robotConfig['skip_from_names'] ?? []));
        $posterName    = $this->getUserName($userId);
        if ($posterName !== null) {
            $skipFromNames[] = mb_strtolower($posterName);
        }

        $msgFromName = mb_strtolower(trim($message['from_name'] ?? ''));
        if ($msgFromName !== '' && in_array($msgFromName, $skipFromNames, true)) {
            $this->debug("  Skip: message is from '{$message['from_name']}' (in skip list — avoids reply loop)");
            return false;
        }

        // Never reply to messages older than 24 hours
        $received = $message['date_received'] ?? $message['date_written'] ?? null;
        if ($received !== null && (time() - strtotime($received)) > 86400) {
            $this->debug("  Skip: message is more than 24 hours old ({$received})");
            return false;
        }

        // Resolve echo area
        $echoareaId = (int)($message['echoarea_id'] ?? 0);
        if ($echoareaId === 0) {
            $this->debug('  ERROR: message has no echoarea_id');
            return false;
        }

        $stmt = $this->db->prepare("SELECT tag, domain FROM echoareas WHERE id = ?");
        $stmt->execute([$echoareaId]);
        $area = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$area) {
            $this->debug("  ERROR: echoarea {$echoareaId} not found");
            return false;
        }

        // Build reply subject (prefix "Re: " if not already present)
        $origSubject = trim($message['subject'] ?? '');
        $subject     = (stripos($origSubject, 'Re:') === 0) ? $origSubject : "Re: {$origSubject}";

        // Build quoted body
        $body = $this->buildQuotedBody($message, $replyText, $quoteKludges);

        $this->debug(sprintf(
            "  Replying to '%s' in %s@%s | subj='%s' | replyToId=%d",
            $message['from_name'] ?? '?',
            $area['tag'],
            $area['domain'] ?? '',
            $subject,
            (int)$message['id']
        ));

        try {
            $handler = new MessageHandler($this->db);
            $handler->postEchomail(
                $userId,
                $area['tag'],
                $area['domain'] ?? '',
                $message['from_name'] ?? 'All',
                $subject,
                $body,
                (int)($message['id'] ?? 0) ?: null,   // reply_to_id for threading
                null,                                   // no tagline
                true,                                   // skipCredits
                null                                    // no markup
            );
            // Trigger outbound poll so the reply is actually sent
            $handler->flushImmediateOutboundPolls();
        } catch (\Throwable $e) {
            $this->debug('  ERROR posting reply: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Build the quoted reply body, including kludge lines from the original message.
     *
     * @param array  $message      Row from echomail table
     * @param string $replyText    Text to include above the quote (empty to omit)
     * @param bool   $quoteKludges Whether to include kludge lines in the quote
     * @return string
     */
    private function buildQuotedBody(array $message, string $replyText, bool $quoteKludges): string
    {
        $lines = [];

        // Optional preamble
        if ($replyText !== '') {
            $lines[] = $replyText;
            $lines[] = '';
        }

        // Attribution line
        $fromName = $message['from_name'] ?? 'Unknown';
        $dateStr  = !empty($message['date_written'])
            ? date('D, d M Y H:i', strtotime($message['date_written']))
            : '';
        $lines[] = ' * Original message from ' . $fromName . ($dateStr !== '' ? ' (' . $dateStr . ')' : '') . ':';
        $lines[] = '';

        // Quoted kludge lines (^A rendered as ^A so they show in the message body)
        if ($quoteKludges && !empty($message['kludge_lines'])) {
            foreach (explode("\n", rtrim($message['kludge_lines'])) as $kl) {
                $kl = rtrim($kl);
                if ($kl !== '') {
                    // Replace SOH (0x01) with printable ^A so it appears in body text
                    $lines[] = str_replace("\x01", '^A', $kl);
                }
            }
            $lines[] = '';
        }

        // Quoted message body
        $initials = $this->getInitials($fromName);
        $msgText  = rtrim($message['message_text'] ?? '');
        foreach (explode("\n", $msgText) as $line) {
            $lines[] = $initials . '> ' . rtrim($line);
        }

        return implode("\n", $lines);
    }

    /**
     * Generate two-character initials from a name (e.g. "John Doe" → "JD").
     *
     * @param  string $name
     * @return string
     */
    private function getInitials(string $name): string
    {
        $parts    = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        $initials = '';
        foreach ($parts as $part) {
            if ($part !== '') {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
        }

        // Cap at 2 characters; fall back to "??" if name is empty
        return mb_substr($initials ?: '??', 0, 2);
    }

    /**
     * Look up a user's ID by username (case-insensitive).
     *
     * @param  string   $username
     * @return int|null
     */
    private function getUserIdByUsername(string $username): ?int
    {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?)");
        $stmt->execute([$username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    }

    /**
     * Look up a user's real_name (which is used as from_name in echomail).
     *
     * @param  int         $userId
     * @return string|null
     */
    private function getUserName(int $userId): ?string
    {
        $stmt = $this->db->prepare("SELECT real_name FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? ($row['real_name'] ?? null) : null;
    }
}
