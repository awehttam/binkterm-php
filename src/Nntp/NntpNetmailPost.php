<?php

/*
 * Copyright Matthew Asham and BinktermPHP Contributors
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

namespace BinktermPHP\Nntp;

use BinktermPHP\Binkp\Logger;
use BinktermPHP\MessageHandler;
use PDO;

/**
 * Injects an article POSTed into the netmail newsgroup as outbound netmail via
 * {@see MessageHandler::sendNetmail()} (docs/proposals/NNTPNetmail.md — "Sending").
 *
 * Destination resolution, in priority order:
 *   1. an explicit `X-FTN-To: 227:1/200` header;
 *   2. reply-derivation — `References:` points at an article in this user's
 *      netmail group, so the destination comes from that parent row;
 *   3. the `To:` header — the `(z:n/f.p)` tuple in its display-name comment, or
 *      the `[pP.]fN.nN.zN.tld` host form in its addr-spec (the `tld` is cosmetic;
 *      zone/net/node/point are all explicit).
 *
 * `sendNetmail()` owns origin-address selection per destination, charset,
 * local-sysop routing, credit debits and spooling — this class adds only the
 * NNTP-specific validation (no cross-post, per-user rate limit).
 */
final class NntpNetmailPost
{
    private PDO $db;
    private NntpConfig $config;
    private Logger $logger;
    private int $userId;
    private MessageHandler $handler;

    public function __construct(PDO $db, NntpConfig $config, Logger $logger, int $userId, ?MessageHandler $handler = null)
    {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
        $this->userId = $userId;
        $this->handler = $handler ?? new MessageHandler();
    }

    /**
     * @param array{headers:array<string,string>,raw_headers:array<int,array{name:string,value:string}>,body:string} $article
     * @return array{code:int,text:string}
     */
    public function submit(array $article): array
    {
        if (!$this->config->isNetmailSendAllowed()) {
            return ['code' => 440, 'text' => 'Netmail sending is disabled on this server'];
        }

        $headers = $article['headers'];

        $subject = trim((string)($headers['subject'] ?? ''));
        if ($subject === '') {
            return $this->fail('Missing Subject header');
        }

        $body = $article['body'];
        if (trim($body) === '') {
            return $this->fail('Empty article body');
        }

        $rate = $this->rateLimitError();
        if ($rate !== null) {
            return $this->fail($rate);
        }

        // Parent (reply) row, if References points at one of this user's netmail articles.
        $parent = $this->resolveParent(NntpArticleParser::lastReference($headers));
        $replyToId = $parent !== null ? (int)$parent['id'] : null;

        $dest = $this->resolveDestination($headers, $parent);
        if ($dest === null) {
            return $this->fail('Cannot determine netmail destination');
        }

        if ($this->config->shouldConvertInboundQuotes() && $parent !== null) {
            $quoter = trim((string)($parent['from_name'] ?? ''));
            if ($quoter !== '') {
                $body = NntpQuoteStyle::toFtn($body, $quoter);
            }
        }

        try {
            $ok = $this->handler->sendNetmail(
                $this->userId,
                $dest['address'],
                $dest['name'],
                $subject,
                $body,
                null,        // fromName: resolved by netmail posting policy
                $replyToId,
                false,       // crashmail
                '',          // tagline
                null,        // attachment
                null,        // markupType
                false,       // isFreq
                'UTF-8',
                null,        // pgpMode
                'NNTP'       // tearline component -> "--- BinktermPHP NNTP vX.Y.Z"
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[nntp] netmail post failed for user ' . $this->userId . ': ' . $e->getMessage());
            return $this->fail('Netmail delivery failed: ' . $e->getMessage());
        }

        if (!$ok) {
            return $this->fail('Netmail delivery failed');
        }

        $this->logger->info(sprintf(
            '[nntp] user %d sent netmail to %s <%s>%s',
            $this->userId,
            $dest['name'],
            $dest['address'],
            $replyToId !== null ? ' (reply)' : ''
        ));

        return ['code' => 240, 'text' => 'Netmail sent'];
    }

    /**
     * @param array<string,string>            $headers
     * @param array<string,mixed>|null        $parent
     * @return array{address:string,name:string}|null
     */
    private function resolveDestination(array $headers, ?array $parent): ?array
    {
        // 1. Explicit X-FTN-To.
        $xto = trim((string)($headers['x-ftn-to'] ?? ''));
        $xto = preg_replace('/@.*$/', '', $xto) ?? $xto;
        if ($xto !== '' && self::isFtnAddress($xto)) {
            $name = self::cleanName((string)($headers['x-ftn-to-name'] ?? ''));
            if ($name === '') {
                $name = self::displayName((string)($headers['to'] ?? ''));
            }
            return ['address' => $xto, 'name' => $name !== '' ? $name : 'Sysop'];
        }

        // 2. Reply-derivation from the parent netmail row.
        if ($parent !== null) {
            $addr = trim((string)($parent['reply_address'] ?? ''));
            if ($addr === '' || !self::isFtnAddress($addr)) {
                $addr = trim((string)($parent['from_address'] ?? ''));
            }
            if (self::isFtnAddress($addr)) {
                $name = trim((string)($parent['from_name'] ?? '')) ?: 'Sysop';
                return ['address' => $addr, 'name' => $name];
            }
        }

        // 3. Parse the To: header.
        $to = trim((string)($headers['to'] ?? ''));
        if ($to !== '') {
            $addr = self::addressFromToHeader($to);
            if ($addr !== null) {
                $name = self::displayName($to);
                return ['address' => $addr, 'name' => $name !== '' ? $name : 'Sysop'];
            }
        }

        return null;
    }

    /**
     * Resolve a parent Message-ID to one of this user's *visible* netmail rows.
     *
     * @return array<string,mixed>|null
     */
    private function resolveParent(?string $messageId): ?array
    {
        if ($messageId === null) {
            return null;
        }
        $parsed = NntpMessageId::parse($messageId);
        if ($parsed === null || strcasecmp($parsed['group'], $this->config->getNetmailGroupName()) !== 0) {
            return null;
        }

        $vis = $this->handler->netmailVisibilityFilter($this->userId, 'n');
        $del = $this->handler->netmailNotDeletedFilter($this->userId, 'n');
        $stmt = $this->db->prepare(
            "SELECT n.id, n.from_name, n.from_address, n.reply_address
             FROM netmail n
             WHERE ({$vis['sql']}) AND ({$del['sql']})
               AND (n.message_id = ? OR n.message_id LIKE ?)
             ORDER BY n.id LIMIT 1"
        );
        $stmt->execute([...$vis['params'], ...$del['params'], $parsed['serial'], '% ' . $parsed['serial']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return string|null  error message when a per-user netmail rate limit is exceeded
     */
    private function rateLimitError(): ?string
    {
        $perMinute = $this->config->getNetmailPostsPerMinute();
        $perHour = $this->config->getNetmailPostsPerHour();

        if ($perMinute > 0 && $this->recentSendCount('1 minute') >= $perMinute) {
            return "Rate limit exceeded ({$perMinute} netmail/minute)";
        }
        if ($perHour > 0 && $this->recentSendCount('1 hour') >= $perHour) {
            return "Rate limit exceeded ({$perHour} netmail/hour)";
        }

        return null;
    }

    private function recentSendCount(string $interval): int
    {
        $vis = $this->handler->netmailVisibilityFilter($this->userId, 'n', 'sender');
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM netmail n
             WHERE ({$vis['sql']})
               AND n.date_received >= (NOW() AT TIME ZONE 'UTC') - INTERVAL '{$interval}'"
        );
        $stmt->execute($vis['params']);

        return (int)$stmt->fetchColumn();
    }

    // ── address parsing ────────────────────────────────────────────────────

    public static function isFtnAddress(string $s): bool
    {
        return preg_match('#^\d+:\d+/\d+(\.\d+)?$#', trim($s)) === 1;
    }

    /**
     * Pull an FTN address out of a `To:` header value produced by
     * {@see NntpArticleBuilder::fromHeader()} — the `(z:n/f.p)` display comment or
     * the `[pP.]fN.nN.zN.tld` host form. Returns null when neither is present.
     */
    public static function addressFromToHeader(string $to): ?string
    {
        if (preg_match('/\((\d+:\d+\/\d+(?:\.\d+)?)\)/', $to, $m)) {
            // fromHeader() always writes an explicit ".0" point; drop it.
            return preg_replace('/\.0$/', '', $m[1]) ?? $m[1];
        }

        if (preg_match('/<[^@>]*@([^>]+)>/', $to, $m)
            && preg_match('/(?:p(\d+)\.)?f(\d+)\.n(\d+)\.z(\d+)\./i', $m[1], $h)) {
            $point = (int)($h[1] ?? 0);

            return sprintf('%d:%d/%d%s', (int)$h[4], (int)$h[3], (int)$h[2], $point > 0 ? '.' . $point : '');
        }

        return null;
    }

    /** Display name from a `To:` header (text before the first `(` or `<`, unquoted). */
    public static function displayName(string $to): string
    {
        $head = preg_split('/[<(]/', $to, 2)[0] ?? '';

        return self::cleanName($head);
    }

    /**
     * Normalise a display name: unwrap an RFC 5322 quoted-string and strip any
     * stray leading/trailing double-quote a client left unbalanced (e.g. a
     * `To: "Name <addr>"` the parser split mid-quote).
     */
    public static function cleanName(string $name): string
    {
        $name = trim($name);
        if (strlen($name) >= 2 && $name[0] === '"' && substr($name, -1) === '"') {
            $name = str_replace(['\\"', '\\\\'], ['"', '\\'], substr($name, 1, -1));
        }

        return trim($name, " \t\"");
    }

    /**
     * @return array{code:int,text:string}
     */
    private function fail(string $text): array
    {
        return ['code' => 441, 'text' => $text];
    }
}
